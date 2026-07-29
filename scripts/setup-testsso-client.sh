#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

API_BASE_URL="http://127.0.0.1:8089/api/v1"
API_HOST_HEADER="Host: sobmoeiservice.moph.go.th"
BACKEND_HEALTH_URL="http://127.0.0.1:8089/up"
SSO_BASE_URL="https://sobmoeiservice.moph.go.th/call"
TESTSSO_BASE_URL="https://sobmoeiservice.moph.go.th/testsso"
TESTSSO_INSTALL_PATH="/var/www/html/testsso"
CONFIG_PATH="/etc/sobmoei/testsso.php"
USER_AGENT="Sobmoei-SSO-Setup/1.0"
ROTATE_EXISTING=false

if [[ "${1:-}" == "--rotate-existing" ]]; then
    ROTATE_EXISTING=true
elif [[ -n "${1:-}" ]]; then
    echo "Usage: sudo bash scripts/setup-testsso-client.sh [--rotate-existing]" >&2
    exit 64
fi

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script with sudo on the AlmaLinux 9 SSO server." >&2
    exit 77
fi

for command_name in curl php install runuser; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command_name}" >&2
        exit 69
    fi
done

temporary_directory="$(mktemp -d)"
login_request="${temporary_directory}/login.json"
response_file="${temporary_directory}/response.json"
auth_headers="${temporary_directory}/auth.headers"
generated_config="${temporary_directory}/testsso.php"
admin_token=""

cleanup() {
    if [[ -n "${admin_token}" && -f "${auth_headers}" ]]; then
        curl \
            --silent \
            --show-error \
            --max-time 20 \
            --user-agent "${USER_AGENT}" \
            --header "${API_HOST_HEADER}" \
            --header "@${auth_headers}" \
            --request POST \
            --output /dev/null \
            "${API_BASE_URL}/auth/logout" || true
    fi

    admin_token=""
    rm -rf -- "${temporary_directory}"
}
trap cleanup EXIT

if ! backend_status="$(
    curl \
        --silent \
        --show-error \
        --max-time 20 \
        --user-agent "${USER_AGENT}" \
        --header "${API_HOST_HEADER}" \
        --output /dev/null \
        --write-out "%{http_code}" \
        "${BACKEND_HEALTH_URL}"
)"; then
    echo "Unable to connect to the local SSO backend." >&2
    echo "Run scripts/prepare-almalinux-runtime.sh and verify port 8089 first." >&2
    exit 1
fi

if [[ "${backend_status}" != "200" ]]; then
    echo "Local SSO backend health check returned HTTP ${backend_status}." >&2
    echo "No credentials were requested and no configuration was changed." >&2
    exit 1
fi

read -r -p "Admin email: " admin_email
read -r -s -p "Admin password: " admin_password
echo

if [[ -z "${admin_email}" || -z "${admin_password}" ]]; then
    echo "Admin email and password are required." >&2
    exit 64
fi

export SETUP_ADMIN_EMAIL="${admin_email}"
export SETUP_ADMIN_PASSWORD="${admin_password}"
php -r '
    $payload = [
        "email" => getenv("SETUP_ADMIN_EMAIL"),
        "password" => getenv("SETUP_ADMIN_PASSWORD"),
        "device_name" => "testsso-server-setup",
    ];
    file_put_contents(
        $argv[1],
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
    );
' "${login_request}"
unset SETUP_ADMIN_PASSWORD admin_password

http_status="$(
    curl \
        --silent \
        --show-error \
        --max-time 20 \
        --user-agent "${USER_AGENT}" \
        --header "${API_HOST_HEADER}" \
        --header "Accept: application/json" \
        --header "Content-Type: application/json" \
        --request POST \
        --data-binary "@${login_request}" \
        --output "${response_file}" \
        --write-out "%{http_code}" \
        "${API_BASE_URL}/auth/login"
)"

if [[ "${http_status}" != "200" ]]; then
    echo "Admin login failed with HTTP ${http_status}. No configuration was changed." >&2
    exit 1
fi

admin_token="$(
    php -r '
        $payload = json_decode(
            file_get_contents($argv[1]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $token = $payload["data"]["token"] ?? null;
        if (
            !is_string($token)
            || $token === ""
            || strlen($token) > 512
            || preg_match("/[\\x00-\\x20\\x7F]/", $token)
        ) {
            exit(1);
        }
        echo $token;
    ' "${response_file}"
)"

if [[ -z "${admin_token}" ]]; then
    echo "The Admin API returned an invalid access token." >&2
    exit 1
fi

{
    echo "Accept: application/json"
    printf "Authorization: Bearer %s\n" "${admin_token}"
} > "${auth_headers}"

http_status="$(
    curl \
        --silent \
        --show-error \
        --max-time 20 \
        --user-agent "${USER_AGENT}" \
        --header "${API_HOST_HEADER}" \
        --header "@${auth_headers}" \
        --output "${response_file}" \
        --write-out "%{http_code}" \
        "${API_BASE_URL}/admin/applications?per_page=100"
)"

if [[ "${http_status}" != "200" ]]; then
    echo "Unable to list applications: HTTP ${http_status}." >&2
    exit 1
fi

application_id="$(
    php -r '
        $payload = json_decode(
            file_get_contents($argv[1]),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        foreach (($payload["data"] ?? []) as $application) {
            if (($application["slug"] ?? null) === "testsso") {
                $id = $application["id"] ?? null;
                if (is_string($id) && preg_match(
                    "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD",
                    $id
                )) {
                    echo $id;
                    exit(0);
                }
            }
        }
    ' "${response_file}"
)"

if [[ -z "${application_id}" ]]; then
    application_request="${temporary_directory}/application.json"
    export TESTSSO_SETUP_BASE_URL="${TESTSSO_BASE_URL}"
    php -r '
        file_put_contents(
            $argv[1],
            json_encode([
                "name" => "Sobmoei SSO Test Client",
                "slug" => "testsso",
                "base_url" => getenv("TESTSSO_SETUP_BASE_URL"),
                "require_organization_match" => true,
                "is_active" => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    ' "${application_request}"
    unset TESTSSO_SETUP_BASE_URL

    http_status="$(
        curl \
            --silent \
            --show-error \
            --max-time 20 \
            --user-agent "${USER_AGENT}" \
            --header "${API_HOST_HEADER}" \
            --header "@${auth_headers}" \
            --header "Content-Type: application/json" \
            --request POST \
            --data-binary "@${application_request}" \
            --output "${response_file}" \
            --write-out "%{http_code}" \
            "${API_BASE_URL}/admin/applications"
    )"

    if [[ "${http_status}" != "201" ]]; then
        echo "Unable to create testsso application: HTTP ${http_status}." >&2
        exit 1
    fi

    application_id="$(
        php -r '
            $payload = json_decode(
                file_get_contents($argv[1]),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            $id = $payload["data"]["id"] ?? null;
            if (
                !is_string($id)
                || !preg_match(
                    "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD",
                    $id
                )
            ) {
                exit(1);
            }
            echo $id;
        ' "${response_file}"
    )"
fi

application_update_request="${temporary_directory}/application-update.json"
export TESTSSO_SETUP_BASE_URL="${TESTSSO_BASE_URL}"
php -r '
    file_put_contents(
        $argv[1],
        json_encode([
            "name" => "Sobmoei SSO Test Client",
            "slug" => "testsso",
            "base_url" => getenv("TESTSSO_SETUP_BASE_URL"),
            "require_organization_match" => true,
            "is_active" => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
    );
' "${application_update_request}"
unset TESTSSO_SETUP_BASE_URL

http_status="$(
    curl \
        --silent \
        --show-error \
        --max-time 20 \
        --user-agent "${USER_AGENT}" \
        --header "${API_HOST_HEADER}" \
        --header "@${auth_headers}" \
        --header "Content-Type: application/json" \
        --request PATCH \
        --data-binary "@${application_update_request}" \
        --output "${response_file}" \
        --write-out "%{http_code}" \
        "${API_BASE_URL}/admin/applications/${application_id}"
)"

if [[ "${http_status}" != "200" ]]; then
    echo "Unable to normalize the testsso application: HTTP ${http_status}." >&2
    exit 1
fi

client_request="${temporary_directory}/client.json"
export TESTSSO_SETUP_CALLBACK="${TESTSSO_BASE_URL}/callback.php"
php -r '
    file_put_contents(
        $argv[1],
        json_encode([
            "redirect_uris" => [getenv("TESTSSO_SETUP_CALLBACK")],
            "allowed_providers" => ["thaid", "provider_id"],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
    );
' "${client_request}"
unset TESTSSO_SETUP_CALLBACK

http_status="$(
    curl \
        --silent \
        --show-error \
        --max-time 20 \
        --user-agent "${USER_AGENT}" \
        --header "${API_HOST_HEADER}" \
        --header "@${auth_headers}" \
        --header "Content-Type: application/json" \
        --request POST \
        --data-binary "@${client_request}" \
        --output "${response_file}" \
        --write-out "%{http_code}" \
        "${API_BASE_URL}/admin/applications/${application_id}/sso-client"
)"

if [[ "${http_status}" == "409" && "${ROTATE_EXISTING}" == "true" ]]; then
    http_status="$(
        curl \
            --silent \
            --show-error \
            --max-time 20 \
            --user-agent "${USER_AGENT}" \
            --header "${API_HOST_HEADER}" \
            --header "@${auth_headers}" \
            --output "${response_file}" \
            --write-out "%{http_code}" \
            "${API_BASE_URL}/admin/applications/${application_id}/sso-client"
    )"

    if [[ "${http_status}" != "200" ]]; then
        echo "Unable to inspect the existing testsso OAuth client: HTTP ${http_status}." >&2
        exit 1
    fi

    if php -r '
        $payload = json_decode(
            file_get_contents($argv[1]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $data = $payload["data"] ?? null;
        $providers = is_array($data["allowed_providers"] ?? null)
            ? $data["allowed_providers"]
            : [];
        sort($providers);
        $valid = is_array($data)
            && ($data["redirect_uris"] ?? null) === [
                "https://sobmoeiservice.moph.go.th/testsso/callback.php",
            ]
            && $providers === ["provider_id", "thaid"]
            && ($data["revoked"] ?? null) === false;
        exit($valid ? 0 : 1);
    ' "${response_file}"; then
        existing_client_matches=true
    else
        existing_client_matches=false
    fi

    if [[ "${existing_client_matches}" == "false" ]]; then
        http_status="$(
            curl \
                --silent \
                --show-error \
                --max-time 20 \
                --user-agent "${USER_AGENT}" \
                --header "${API_HOST_HEADER}" \
                --header "@${auth_headers}" \
                --request DELETE \
                --output "${response_file}" \
                --write-out "%{http_code}" \
                "${API_BASE_URL}/admin/applications/${application_id}/sso-client"
        )"

        if [[ "${http_status}" != "204" ]]; then
            echo "Unable to replace the mismatched testsso OAuth client: HTTP ${http_status}." >&2
            exit 1
        fi

        http_status="$(
            curl \
                --silent \
                --show-error \
                --max-time 20 \
                --user-agent "${USER_AGENT}" \
                --header "${API_HOST_HEADER}" \
                --header "@${auth_headers}" \
                --header "Content-Type: application/json" \
                --request POST \
                --data-binary "@${client_request}" \
                --output "${response_file}" \
                --write-out "%{http_code}" \
                "${API_BASE_URL}/admin/applications/${application_id}/sso-client"
        )"
    else
        http_status="$(
            curl \
                --silent \
                --show-error \
                --max-time 20 \
                --user-agent "${USER_AGENT}" \
                --header "${API_HOST_HEADER}" \
                --header "@${auth_headers}" \
                --request POST \
                --output "${response_file}" \
                --write-out "%{http_code}" \
                "${API_BASE_URL}/admin/applications/${application_id}/sso-client/rotate"
        )"
    fi
elif [[ "${http_status}" == "409" ]]; then
    echo "The testsso OAuth client already exists." >&2
    echo "Re-run with --rotate-existing only if the current client secret is unavailable." >&2
    exit 1
fi

if [[ "${http_status}" != "201" && "${http_status}" != "200" ]]; then
    echo "Unable to issue testsso OAuth credentials: HTTP ${http_status}." >&2
    exit 1
fi

client_id="$(
    php -r '
        $payload = json_decode(
            file_get_contents($argv[1]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $value = $payload["data"]["client_id"] ?? null;
        if (
            !is_string($value)
            || $value === ""
            || strlen($value) > 255
            || preg_match("/[\\x00-\\x20\\x7F]/", $value)
        ) {
            exit(1);
        }
        echo $value;
    ' "${response_file}"
)"
client_secret="$(
    php -r '
        $payload = json_decode(
            file_get_contents($argv[1]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $value = $payload["data"]["client_secret"] ?? null;
        if (
            !is_string($value)
            || strlen($value) < 16
            || strlen($value) > 512
            || preg_match("/[\\x00-\\x1F\\x7F]/", $value)
        ) {
            exit(1);
        }
        echo $value;
    ' "${response_file}"
)"

export TESTSSO_SETUP_SSO_BASE="${SSO_BASE_URL}"
export TESTSSO_SETUP_CLIENT_ID="${client_id}"
export TESTSSO_SETUP_CLIENT_SECRET="${client_secret}"
export TESTSSO_SETUP_REDIRECT="${TESTSSO_BASE_URL}/callback.php"
php -r '
    $configuration = [
        "sso_base_url" => getenv("TESTSSO_SETUP_SSO_BASE"),
        "client_id" => getenv("TESTSSO_SETUP_CLIENT_ID"),
        "client_secret" => getenv("TESTSSO_SETUP_CLIENT_SECRET"),
        "redirect_uri" => getenv("TESTSSO_SETUP_REDIRECT"),
        "scope" => "openid profile organization roles",
        "transaction_ttl_seconds" => 300,
        "session_ttl_seconds" => 1800,
        "connect_timeout_seconds" => 5,
        "request_timeout_seconds" => 15,
    ];
    file_put_contents(
        $argv[1],
        "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            .var_export($configuration, true)
            .";\n"
    );
' "${generated_config}"
unset \
    TESTSSO_SETUP_SSO_BASE \
    TESTSSO_SETUP_CLIENT_ID \
    TESTSSO_SETUP_CLIENT_SECRET \
    TESTSSO_SETUP_REDIRECT \
    client_id \
    client_secret

install -d -o root -g apache -m 0750 "$(dirname "${CONFIG_PATH}")"
install -o root -g apache -m 0640 "${generated_config}" "${CONFIG_PATH}"

if command -v semanage >/dev/null 2>&1; then
    semanage fcontext \
        -a \
        -t httpd_sys_content_t \
        '/etc/sobmoei(/.*)?' 2>/dev/null \
        || semanage fcontext \
            -m \
            -t httpd_sys_content_t \
            '/etc/sobmoei(/.*)?'
fi

if command -v restorecon >/dev/null 2>&1; then
    restorecon -F "${CONFIG_PATH}" || true
fi

if [[ ! -r "${TESTSSO_INSTALL_PATH}/bootstrap.php" ]]; then
    echo "testsso bootstrap not found at ${TESTSSO_INSTALL_PATH}/bootstrap.php." >&2
    exit 1
fi

runuser \
    -u apache \
    -- env TESTSSO_CONFIG_FILE="${CONFIG_PATH}" \
    php -r '
        require $argv[1];
        testsso_config();
    ' "${TESTSSO_INSTALL_PATH}/bootstrap.php"

echo "testsso OAuth client and ${CONFIG_PATH} are ready."
echo "No client secret was printed or stored in shell history."

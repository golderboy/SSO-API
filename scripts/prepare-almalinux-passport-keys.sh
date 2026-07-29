#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

APP_ROOT="/var/www/sso-api"
KEY_DIRECTORY="/etc/sobmoei/oauth"
PRIVATE_KEY="${KEY_DIRECTORY}/oauth-private.key"
PUBLIC_KEY="${KEY_DIRECTORY}/oauth-public.key"
APACHE_USER="apache"
APACHE_GROUP="apache"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script with sudo on the AlmaLinux 9 SSO server." >&2
    exit 77
fi

for command_name in chmod chown getent install openssl php runuser; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command_name}" >&2
        exit 69
    fi
done

if [[ ! -f "${APP_ROOT}/artisan" ]]; then
    echo "Laravel application was not found at ${APP_ROOT}." >&2
    exit 66
fi

if ! getent passwd "${APACHE_USER}" >/dev/null \
    || ! getent group "${APACHE_GROUP}" >/dev/null; then
    echo "The apache user or group does not exist." >&2
    exit 67
fi

configured_key_directory="$(
    runuser -u "${APACHE_USER}" -- php -r '
        require $argv[1]."/vendor/autoload.php";
        $app = require $argv[1]."/bootstrap/app.php";
        $app->make(
            Illuminate\Contracts\Console\Kernel::class
        )->bootstrap();
        $path = config("passport.key_path");

        if (!is_string($path) || trim($path) === "") {
            exit(1);
        }

        echo rtrim($path, "/\\");
    ' "${APP_ROOT}"
)"

if [[ "${configured_key_directory}" != "${KEY_DIRECTORY}" ]]; then
    echo "PASSPORT_KEY_PATH must be exactly ${KEY_DIRECTORY}." >&2
    exit 78
fi

private_exists=false
public_exists=false

if [[ -e "${PRIVATE_KEY}" && ! -s "${PRIVATE_KEY}" ]]; then
    echo "The Passport private key exists but is empty." >&2
    echo "Restore the matching key pair from backup; no key was overwritten." >&2
    exit 1
fi

if [[ -e "${PUBLIC_KEY}" && ! -s "${PUBLIC_KEY}" ]]; then
    echo "The Passport public key exists but is empty." >&2
    echo "Restore the matching key pair from backup; no key was overwritten." >&2
    exit 1
fi

if [[ -s "${PRIVATE_KEY}" ]]; then
    private_exists=true
fi

if [[ -s "${PUBLIC_KEY}" ]]; then
    public_exists=true
fi

if [[ "${private_exists}" != "${public_exists}" ]]; then
    echo "Only one Passport signing key exists." >&2
    echo "Restore the missing matching key from backup; no key was overwritten." >&2
    exit 1
fi

if [[ "${private_exists}" == "false" ]]; then
    install -d \
        -o "${APACHE_USER}" \
        -g "${APACHE_GROUP}" \
        -m 0750 \
        "${KEY_DIRECTORY}"

    runuser -u "${APACHE_USER}" -- \
        php "${APP_ROOT}/artisan" passport:keys
fi

chown -R "root:${APACHE_GROUP}" "${KEY_DIRECTORY}"
chmod 0750 "${KEY_DIRECTORY}"
chmod 0640 "${PRIVATE_KEY}" "${PUBLIC_KEY}"

if command -v getenforce >/dev/null 2>&1 \
    && [[ "$(getenforce)" != "Disabled" ]]; then
    for command_name in restorecon semanage; do
        if ! command -v "${command_name}" >/dev/null 2>&1; then
            echo "SELinux is active but ${command_name} is unavailable." >&2
            exit 69
        fi
    done

    semanage fcontext \
        -a \
        -t httpd_sys_content_t \
        "${KEY_DIRECTORY}(/.*)?" 2>/dev/null \
        || semanage fcontext \
            -m \
            -t httpd_sys_content_t \
            "${KEY_DIRECTORY}(/.*)?"
    restorecon -RF "${KEY_DIRECTORY}"
fi

runuser -u "${APACHE_USER}" -- test -r "${PRIVATE_KEY}"
runuser -u "${APACHE_USER}" -- test -r "${PUBLIC_KEY}"
runuser -u "${APACHE_USER}" -- \
    openssl pkey -in "${PRIVATE_KEY}" -check -noout >/dev/null
runuser -u "${APACHE_USER}" -- \
    openssl pkey -pubin -in "${PUBLIC_KEY}" -noout >/dev/null

echo "Passport signing key pair is present, valid, and readable."
echo "No existing key was rotated or printed."

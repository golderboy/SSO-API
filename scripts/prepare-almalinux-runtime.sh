#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

APP_ROOT="/var/www/sso-api"
APACHE_USER="apache"
APACHE_GROUP="apache"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script with sudo on the AlmaLinux 9 SSO server." >&2
    exit 77
fi

for command_name in chown chmod find getent install mktemp rm runuser; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command_name}" >&2
        exit 69
    fi
done

if [[ ! -f "${APP_ROOT}/artisan" ]]; then
    echo "Laravel application was not found at ${APP_ROOT}." >&2
    exit 66
fi

if ! getent passwd "${APACHE_USER}" >/dev/null; then
    echo "Required user does not exist: ${APACHE_USER}" >&2
    exit 67
fi

if ! getent group "${APACHE_GROUP}" >/dev/null; then
    echo "Required group does not exist: ${APACHE_GROUP}" >&2
    exit 67
fi

install -d -o "${APACHE_USER}" -g "${APACHE_GROUP}" -m 0770 \
    "${APP_ROOT}/storage/framework/cache/data" \
    "${APP_ROOT}/storage/framework/sessions" \
    "${APP_ROOT}/storage/framework/views" \
    "${APP_ROOT}/storage/logs" \
    "${APP_ROOT}/bootstrap/cache"

chown -R \
    "${APACHE_USER}:${APACHE_GROUP}" \
    "${APP_ROOT}/storage" \
    "${APP_ROOT}/bootstrap/cache"

find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" \
    -type d -exec chmod 0770 {} +
find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" \
    -type f -exec chmod 0660 {} +

selinux_active=false

if command -v getenforce >/dev/null 2>&1 \
    && [[ "$(getenforce)" != "Disabled" ]]; then
    selinux_active=true
fi

if [[ "${selinux_active}" == "true" ]]; then
    for command_name in restorecon semanage; do
        if ! command -v "${command_name}" >/dev/null 2>&1; then
            echo "SELinux is active but ${command_name} is unavailable." >&2
            echo "Install policycoreutils-python-utils before continuing." >&2
            exit 69
        fi
    done

    semanage fcontext \
        -a \
        -t httpd_sys_rw_content_t \
        "${APP_ROOT}/storage(/.*)?" 2>/dev/null \
        || semanage fcontext \
            -m \
            -t httpd_sys_rw_content_t \
            "${APP_ROOT}/storage(/.*)?"

    semanage fcontext \
        -a \
        -t httpd_sys_rw_content_t \
        "${APP_ROOT}/bootstrap/cache(/.*)?" 2>/dev/null \
        || semanage fcontext \
            -m \
            -t httpd_sys_rw_content_t \
            "${APP_ROOT}/bootstrap/cache(/.*)?"

    restorecon -RF \
        "${APP_ROOT}/storage" \
        "${APP_ROOT}/bootstrap/cache"
fi

runuser -u "${APACHE_USER}" -- test -w "${APP_ROOT}/storage/logs"
runuser -u "${APACHE_USER}" -- test -w "${APP_ROOT}/bootstrap/cache"

permission_probe="$(
    runuser -u "${APACHE_USER}" -- \
        mktemp "${APP_ROOT}/storage/logs/.sso-write-check.XXXXXX"
)"

case "${permission_probe}" in
    "${APP_ROOT}/storage/logs/.sso-write-check."*)
        rm -f -- "${permission_probe}"
        ;;
    *)
        echo "Unexpected runtime permission probe path." >&2
        exit 70
        ;;
esac

echo "Laravel runtime permissions and SELinux contexts are ready."

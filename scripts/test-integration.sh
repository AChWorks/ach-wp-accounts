#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-php}"
export XDEBUG_MODE=off
wp_version="${WP_VERSION:-7.1.5}"
lab="$(mktemp -d "${TMPDIR:-/tmp}/ach-wpa.XXXXXX")"
socket="$lab/db.sock"
db_pid=
cleanup() {
    status=$?
    if [[ -n "$db_pid" ]]; then
        # PID belongs to the child started below; socket never leaves this fixture.
        mariadb-admin --no-defaults --socket="$socket" -uroot shutdown >/dev/null 2>&1 || kill "$db_pid" 2>/dev/null || true
        wait "$db_pid" 2>/dev/null || true
    fi
    if (( status != 0 )); then
        [[ ! -f "$lab/db.log" ]] || tail -60 "$lab/db.log" >&2
        [[ ! -f "$lab/wordpress/wp-content/debug.log" ]] || tail -60 "$lab/wordpress/wp-content/debug.log" >&2
    fi
    rm -rf -- "$lab"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

if [[ -n "${WP_ARCHIVE:-}" ]]; then
    cp -- "$WP_ARCHIVE" "$lab/wp.tar.gz"
else
    curl --fail --silent --show-error --location --max-time 120 "https://wordpress.org/wordpress-$wp_version.tar.gz" -o "$lab/wp.tar.gz"
fi
tar -xzf "$lab/wp.tar.gz" -C "$lab"
wc_version="${WC_VERSION:-11.3.1}"
if [[ -n "${WC_ARCHIVE:-}" ]]; then
    cp -- "$WC_ARCHIVE" "$lab/wc.zip"
else
    curl --fail --silent --show-error --location --max-time 120 "https://downloads.wordpress.org/plugin/woocommerce.$wc_version.zip" -o "$lab/wc.zip"
fi
unzip -q "$lab/wc.zip" -d "$lab/wordpress/wp-content/plugins"
export ACH_TEST_WC_VERSION="$wc_version"
mkdir "$lab/db"
mariadb-install-db --no-defaults --datadir="$lab/db" --skip-test-db --auth-root-authentication-method=normal >"$lab/init.log" 2>&1 || { cat "$lab/init.log"; exit 1; }
mariadbd --no-defaults --datadir="$lab/db" --socket="$socket" --pid-file="$lab/db.pid" --skip-networking --log-error="$lab/db.log" --innodb-buffer-pool-size=64M --max-connections=40 &
db_pid=$!
for i in $(seq 1 100); do
    if mariadb-admin --no-defaults --socket="$socket" -uroot ping >/dev/null 2>&1; then break; fi
    kill -0 "$db_pid"
    sleep .1
done
mariadb --no-defaults --socket="$socket" -uroot -e "CREATE DATABASE accounts_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mkdir "$lab/wordpress/wp-content/plugins/ach-wp-accounts"
cp -R "$repo/ach-wp-accounts.php" "$repo/includes" "$lab/wordpress/wp-content/plugins/ach-wp-accounts/"
export ACH_TEST_WP="$lab/wordpress" ACH_TEST_DIR="$lab" ACH_TEST_WP_VERSION="$wp_version"
cat > "$lab/wordpress/wp-config.php" <<PHP
<?php
define('DB_NAME', 'accounts_fixture');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost:$socket');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', '$("$php_bin" -r 'echo bin2hex(random_bytes(32));')');
define('AUTH_SALT', '$("$php_bin" -r 'echo bin2hex(random_bytes(32));')');
define('WP_HOME', 'https://accounts.test');
define('WP_SITEURL', 'https://accounts.test');
ini_set('zend.exception_ignore_args', '1');
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('AUTOMATIC_UPDATER_DISABLED', true);
\$table_prefix = 'fixture_';
require_once ABSPATH . 'wp-settings.php';
PHP
# wp-load.php defines ABSPATH before reading this config.
"$php_bin" "$repo/tests/install.php"
"$php_bin" "$repo/tests/integration.php"

"$php_bin" "$repo/tests/providers.php"
"$php_bin" "$repo/tests/abilities.php"
"$php_bin" "$repo/tests/woocommerce.php"
"$php_bin" "$repo/tests/accounts.php"
"$php_bin" "$repo/tests/checkout.php"

#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-php}"
export XDEBUG_MODE=off
wp_version="${WP_VERSION:-7.1.5}"
lab="$(mktemp -d "${TMPDIR:-/tmp}/ach-wpa.XXXXXX")"
socket="$lab/db.sock"
db_pid=
cache_pid=
cleanup() {
    status=$?
    if [[ -n "$cache_pid" ]]; then
        redis-cli -s "$lab/cache.sock" shutdown nosave >/dev/null 2>&1 || kill "$cache_pid" 2>/dev/null || true
        wait "$cache_pid" 2>/dev/null || true
    fi
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
if [[ "${COMPATIBILITY_ONLY:-0}" != 1 ]]; then
    wc_version="${WC_VERSION:-11.3.1}"
    if [[ -n "${WC_ARCHIVE:-}" ]]; then
        cp -- "$WC_ARCHIVE" "$lab/wc.zip"
    else
        curl --fail --silent --show-error --location --max-time 120 "https://downloads.wordpress.org/plugin/woocommerce.$wc_version.zip" -o "$lab/wc.zip"
    fi
    unzip -q "$lab/wc.zip" -d "$lab/wordpress/wp-content/plugins"
    export ACH_TEST_WC_VERSION="$wc_version"
fi
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
if [[ "${ACH_BASELINE_UPGRADE:-0}" == 1 ]]; then
    [[ "${COMPATIBILITY_ONLY:-0}" != 1 && "${ACH_BROWSER_ONLY:-0}" != 1 ]]
    baseline="$(git -C "$repo" rev-parse 'v0.2.0^{commit}')"
    [[ "$baseline" == 3a3c0582db703bfcf545cf117a1896d78f91b79a ]]
    git -C "$repo" archive "$baseline" ach-wp-accounts.php includes assets languages | tar -xf - -C "$lab/wordpress/wp-content/plugins/ach-wp-accounts/"
    echo "Exact baseline $baseline"
else
    cp -R "$repo/ach-wp-accounts.php" "$repo/includes" "$repo/assets" "$repo/languages" "$lab/wordpress/wp-content/plugins/ach-wp-accounts/"
fi
export ACH_TEST_WP="$lab/wordpress" ACH_TEST_DIR="$lab" ACH_TEST_WP_VERSION="$wp_version"
cache_config=
if [[ -n "${OBJECT_CACHE_ARCHIVE:-}" ]]; then
    "$php_bin" -r 'if (!extension_loaded("redis")) exit(1);'
    unzip -q "$OBJECT_CACHE_ARCHIVE" -d "$lab/wordpress/wp-content/plugins"
    cp "$lab/wordpress/wp-content/plugins/redis-cache/includes/object-cache.php" "$lab/wordpress/wp-content/object-cache.php"
    redis-server --port 0 --unixsocket "$lab/cache.sock" --unixsocketperm 700 --save "" --appendonly no --dir "$lab" >"$lab/cache.log" 2>&1 &
    cache_pid=$!
    for i in $(seq 1 100); do
        if [[ "$(redis-cli -s "$lab/cache.sock" ping 2>/dev/null)" == PONG ]]; then break; fi
        kill -0 "$cache_pid"
        sleep .1
    done
    [[ "$(redis-cli -s "$lab/cache.sock" ping)" == PONG ]]
    cache_config="define('WP_REDIS_SCHEME', 'unix');
define('WP_REDIS_PATH', '$lab/cache.sock');
define('WP_REDIS_CLIENT', 'phpredis');
define('WP_REDIS_PREFIX', 'ach-wpa-fixture:');"
fi
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
$cache_config
\$table_prefix = 'fixture_';
require_once ABSPATH . 'wp-settings.php';
PHP
# wp-load.php defines ABSPATH before reading this config.
"$php_bin" "$repo/tests/install.php"
if [[ "${ACH_BASELINE_UPGRADE:-0}" == 1 ]]; then
    "$php_bin" "$repo/tests/baseline-upgrade.php" prepare
    # Reuse the actual native update path against a synthetic stable-release
    # response; build bytes from this candidate without any publication.
    python3 "$repo/scripts/build-release.py" --output "$lab/release-fixture" --tested-wp "$wp_version"
    python3 "$repo/scripts/build-release.py" --candidate --output "$lab/staging-package"
    cmp "$lab/release-fixture/ach-wp-accounts.zip" "$lab/staging-package/ach-wp-accounts.zip"
    "$php_bin" "$repo/tests/updater.php"
    "$php_bin" "$repo/tests/baseline-upgrade.php" verify
    exit 0
fi
if [[ "${COMPATIBILITY_ONLY:-0}" == 1 ]]; then
    "$php_bin" "$repo/tests/compatibility.php"
    if "$php_bin" -r 'exit(version_compare($argv[1], "6.3", ">=") ? 0 : 1);' "$wp_version"; then
        "$php_bin" "$repo/tests/standalone.php"
    else
        "$php_bin" "$repo/tests/compatibility.php" forced-active
    fi
    exit 0
fi
if [[ "${ACH_BROWSER_ONLY:-0}" == 1 ]]; then
    bash "$repo/scripts/test-browser-fixture.sh"
    exit 0
fi
# During development, run selected test programs in the same disposable
# fixture. The default CI path always executes the complete suite.
if [[ -n "${ACH_TEST_CASES:-}" ]]; then
    read -r -a cases <<< "$ACH_TEST_CASES"
    for name in "${cases[@]}"; do
        [[ "$name" =~ ^[a-z][a-z0-9-]*$ && -f "$repo/tests/$name.php" ]]
        "$php_bin" "$repo/tests/$name.php"
    done
    exit 0
fi
if [[ -n "${OBJECT_CACHE_ARCHIVE:-}" ]]; then
    # Verify the actual external backend and cross-process persistence; never
    # report an object-cache pass if WordPress silently used its runtime cache.
    "$php_bin" -r 'require getenv("ACH_TEST_WP")."/wp-load.php"; if (!wp_using_ext_object_cache() || !$GLOBALS["wp_object_cache"]->redis_status() || !wp_cache_set("probe", "persisted", "ach-wpa-fixture", 60)) exit(1);'
    "$php_bin" -r 'require getenv("ACH_TEST_WP")."/wp-load.php"; if (!wp_using_ext_object_cache() || !$GLOBALS["wp_object_cache"]->redis_status() || wp_cache_get("probe", "ach-wpa-fixture") !== "persisted") exit(1); echo "PASS: Redis external object cache, cross-process persistence".PHP_EOL;'
fi
"$php_bin" "$repo/tests/integration.php"

"$php_bin" "$repo/tests/providers.php"
"$php_bin" "$repo/tests/abilities.php"
"$php_bin" "$repo/tests/standalone.php"
"$php_bin" "$repo/tests/woocommerce.php"
"$php_bin" "$repo/tests/accounts.php"
"$php_bin" "$repo/tests/experience.php"
"$php_bin" "$repo/tests/native-registration.php"
"$php_bin" "$repo/tests/native-machine.php"
"$php_bin" "$repo/tests/checkout.php"
"$php_bin" "$repo/tests/purchase.php"
"$php_bin" "$repo/tests/frontend.php"
"$php_bin" "$repo/tests/directory.php"
"$php_bin" "$repo/tests/operations.php"
"$php_bin" "$repo/tests/admin.php"
"$php_bin" "$repo/tests/lifecycle.php"
"$php_bin" "$repo/tests/review-remediation.php"
"$php_bin" "$repo/tests/digits-audit.php"
# Optional pinned, locally provided WP-CLI distribution. No CI download or
# runtime dependency. The commands use only this private fixture.
if [[ -n "${WP_CLI_PHAR:-}" ]]; then
    "$php_bin" "$repo/tests/cli.php"
fi
# Optional exact external integration packages, supplied by the operator.
# Install only into this disposable fixture; no runtime dependency or CI download.
if [[ -n "${BRIDGE_ARCHIVE:-}" || -n "${ADAPTER_ARCHIVE:-}" ]]; then
    : "${BRIDGE_ARCHIVE:?Supply the reviewed Bridge ZIP}"
    : "${ADAPTER_ARCHIVE:?Supply the reviewed MCP Adapter ZIP}"
    unzip -q "$BRIDGE_ARCHIVE" -d "$lab/wordpress/wp-content/plugins"
    unzip -q "$ADAPTER_ARCHIVE" -d "$lab/wordpress/wp-content/plugins"
    "$php_bin" "$repo/tests/bridge.php" activate
    "$php_bin" "$repo/tests/bridge.php"
fi
"$php_bin" "$repo/tests/digits-scale.php"
# Simulate a reviewed stable release without tagging or publishing.
release_source="$lab/release-source"
mkdir "$release_source"
cp -R "$repo/ach-wp-accounts.php" "$repo/includes" "$repo/assets" "$repo/languages" "$repo/LICENSE" "$release_source/"
python3 - "$release_source/ach-wp-accounts.php" <<'PYVERSION'
from pathlib import Path
import re, sys
p = Path(sys.argv[1])
s = p.read_text()
m = re.search(r"(?m)^(\s*\*\s*Version:\s*)(\d+)\.(\d+)\.(\d+)([^\r\n]*)$", s)
if m is None:
    raise SystemExit("Unexpected plugin version header")
major, minor, patch = map(int, m.group(2, 3, 4))
# Always exercise an actual upgrade, even after the first stable release.
next_version = f"{major}.{minor}.{patch + 1}"
p.write_text(s[:m.start()] + m.group(1) + next_version + s[m.end():])
PYVERSION
# Non-runtime files must never leak into the installable artifact.
mkdir -p "$release_source/docs" "$release_source/tests" "$release_source/scripts" "$release_source/assets/dev"
cp "$repo/scripts/audit-digits.php" "$release_source/scripts/"
printf 'fixture only' > "$release_source/docs/internal.md"
printf 'fixture only' > "$release_source/tests/example.php"
printf 'fixture only' > "$release_source/assets/.env"
printf 'fixture only' > "$release_source/assets/dev/tool.js"
python3 "$repo/scripts/build-release.py" --source "$release_source" --output "$lab/release-fixture" --tested-wp "$wp_version"
python3 "$repo/scripts/build-release.py" --source "$release_source" --output "$lab/release-repeat" --tested-wp "$wp_version"
cmp "$lab/release-fixture/ach-wp-accounts.zip" "$lab/release-repeat/ach-wp-accounts.zip"
cmp "$lab/release-fixture/ach-wp-accounts-release.json" "$lab/release-repeat/ach-wp-accounts-release.json"
python3 - "$release_source" "$lab/release-fixture" <<'PYTEST'
import hashlib, json, sys, zipfile
from pathlib import Path
source, output = map(Path, sys.argv[1:])
archive = output / "ach-wp-accounts.zip"
manifest = json.loads((output / "ach-wp-accounts-release.json").read_text())
expected = {"ach-wp-accounts/ach-wp-accounts.php", "ach-wp-accounts/LICENSE"}
for folder, glob in (("includes", "*.php"), ("assets", "*.css"), ("assets", "*.js"), ("languages", "*.mo")):
    expected.update("ach-wp-accounts/" + p.relative_to(source).as_posix() for p in (source / folder).glob(glob))
with zipfile.ZipFile(archive) as z:
    assert set(z.namelist()) == expected, sorted(set(z.namelist()) ^ expected)
    assert z.testzip() is None
    assert all(z.read(n) == (source / n.removeprefix("ach-wp-accounts/")).read_bytes() for n in expected)
assert manifest["sha256"] == hashlib.sha256(archive.read_bytes()).hexdigest()
assert manifest["size"] == archive.stat().st_size
assert manifest["requires_php"] == "8.1", "Approved minimum PHP version changed"
assert manifest["requires_wp"] == "6.3", "Minimum WordPress version mismatch"
print("PASS: deterministic runtime-only release ZIP, exact expected files and manifest")
PYTEST
# The CI staging package uses the exact checkout, including its unreleased
# header, and the same runtime-only layout. No release manifest is permitted.
python3 "$repo/scripts/build-release.py" --candidate --output "$lab/staging-package"
python3 - "$repo" "$lab/staging-package" <<'PY_CANDIDATE'
from pathlib import Path
import sys, zipfile
source, output = map(Path, sys.argv[1:])
assert {p.name for p in output.iterdir()} == {"ach-wp-accounts.zip"}
with zipfile.ZipFile(output / "ach-wp-accounts.zip") as z:
    expected = {"ach-wp-accounts/ach-wp-accounts.php", "ach-wp-accounts/LICENSE"}
    for folder, glob in (("includes", "*.php"), ("assets", "*.css"), ("assets", "*.js"), ("languages", "*.mo")):
        expected.update("ach-wp-accounts/" + p.relative_to(source).as_posix() for p in (source / folder).glob(glob))
    assert set(z.namelist()) == expected
    assert z.testzip() is None
    assert all(z.read(n) == (source / n.removeprefix("ach-wp-accounts/")).read_bytes() for n in expected)
print("PASS: exact runtime-only staging ZIP without release manifest")
PY_CANDIDATE
"$php_bin" "$repo/tests/updater.php"
"$php_bin" "$repo/tests/purge.php"

#!/usr/bin/env bash
# Invoked only from test-integration.sh inside its disposable DB fixture.
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${ACH_TEST_WP:?}" "${ACH_TEST_DIR:?}"
[[ "$ACH_TEST_WP" == "$ACH_TEST_DIR/wordpress" && -f "$ACH_TEST_WP/wp-config.php" ]]
php_bin="${PHP_BIN:-php}"
node_bin="${NODE_BIN:-node}"
php_pid=
proxy_pid=
cleanup() {
    result=$?
    for pid in "$proxy_pid" "$php_pid"; do
        if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
    done
    if (( result != 0 )); then
        tail -n 35 "$ACH_TEST_DIR/php-browser.log" "$ACH_TEST_DIR/caddy-browser.log" >&2 || true
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
read -r php_port tls_port < <(python3 - <<'PY'
import socket
with socket.socket() as a,socket.socket() as b:
    a.bind(('127.0.0.1',0)); b.bind(('127.0.0.1',0))
    print(a.getsockname()[1],b.getsockname()[1])
PY
)
export ACH_BROWSER_URL="https://127.0.0.1:$tls_port"
# Only change the disposable fixture created by the calling runner.
sed -i "s#https://accounts.test#$ACH_BROWSER_URL#g" "$ACH_TEST_WP/wp-config.php"
if [[ -n "${WP_FA_ARCHIVE:-}" ]]; then
    mkdir -p "$ACH_TEST_WP/wp-content/languages"
    unzip -q "$WP_FA_ARCHIVE" -d "$ACH_TEST_WP/wp-content/languages"
    export ACH_TEST_PERSIAN=1
fi
mkdir -p "$ACH_TEST_WP/wp-content/mu-plugins"
cp "$repo/tests/browser-provider.php" "$ACH_TEST_WP/wp-content/mu-plugins/fixture-provider.php"
cp "$repo/tests/browser-express.js" "$ACH_TEST_WP/wp-content/mu-plugins/fixture-express.js"
"$php_bin" "$repo/tests/browser-fixture.php"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1'     -keyout "$ACH_TEST_DIR/browser.key" -out "$ACH_TEST_DIR/browser.crt" >/dev/null 2>&1
cat > "$ACH_TEST_DIR/Caddyfile" <<CFG
{
    admin off
    auto_https disable_redirects
}
$ACH_BROWSER_URL {
    bind 127.0.0.1
    tls $ACH_TEST_DIR/browser.crt $ACH_TEST_DIR/browser.key
    reverse_proxy 127.0.0.1:$php_port
}
CFG
"$php_bin" -S "127.0.0.1:$php_port" -t "$ACH_TEST_WP" "$repo/tests/browser-router.php" >"$ACH_TEST_DIR/php-browser.log" 2>&1 &
php_pid=$!
XDG_CONFIG_HOME="$ACH_TEST_DIR/caddy-config" XDG_DATA_HOME="$ACH_TEST_DIR/caddy-data" caddy run --config "$ACH_TEST_DIR/Caddyfile" --adapter caddyfile >"$ACH_TEST_DIR/caddy-browser.log" 2>&1 &
proxy_pid=$!
for i in $(seq 1 100); do
    if curl -ksSf --max-time 2 "$ACH_BROWSER_URL/wp-login.php" >/dev/null; then break; fi
    kill -0 "$php_pid" "$proxy_pid"
    sleep .1
done
curl -ksSf --max-time 5 "$ACH_BROWSER_URL/wp-login.php" >/dev/null
"$node_bin" "$repo/tests/browser-checkout.cjs"

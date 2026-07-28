#!/bin/sh

set -eu

CHECK_TIMEOUT_SECONDS="${PANTHER_BROWSER_CHECK_TIMEOUT_SECONDS:-15}"
BROWSER_BINARY="${PANTHER_CHROME_BINARY:-}"
OUTPUT_LOG="${PANTHER_BROWSER_CHECK_LOG:-/tmp/panther-browser-check.log}"

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

case "$CHECK_TIMEOUT_SECONDS" in
    ''|*[!0-9]*) fail 'PANTHER_BROWSER_CHECK_TIMEOUT_SECONDS doit être un entier compris entre 1 et 60.' ;;
esac
[ "$CHECK_TIMEOUT_SECONDS" -ge 1 ] && [ "$CHECK_TIMEOUT_SECONDS" -le 60 ] \
    || fail 'PANTHER_BROWSER_CHECK_TIMEOUT_SECONDS doit être un entier compris entre 1 et 60.'
[ -n "$BROWSER_BINARY" ] || fail 'PANTHER_CHROME_BINARY est absente.'
[ -x "$BROWSER_BINARY" ] || fail "Navigateur absent ou non exécutable : $BROWSER_BINARY"
command -v setsid >/dev/null 2>&1 || fail "La commande 'setsid' est requise."
command -v ps >/dev/null 2>&1 || fail "La commande 'ps' est requise."
command -v curl >/dev/null 2>&1 || fail "La commande 'curl' est requise."

runtime_directory="$(mktemp -d /tmp/panther-browser-check.XXXXXX)"
browser_pid=''
log_printed=0

print_log() {
    if [ "$log_printed" -eq 0 ] && [ -f "$OUTPUT_LOG" ]; then
        printf '%s\n' '=== Sortie Chrome for Testing ==='
        cat "$OUTPUT_LOG"
        log_printed=1
    fi
}

stop_browser() {
    [ -n "$browser_pid" ] || return 0

    if kill -0 "$browser_pid" 2>/dev/null; then
        kill -TERM -- "-$browser_pid" 2>/dev/null || kill -TERM "$browser_pid" 2>/dev/null || true
        stop_deadline="$(( $(date +%s) + 5 ))"

        while kill -0 "$browser_pid" 2>/dev/null && [ "$(date +%s)" -lt "$stop_deadline" ]; do
            sleep 1
        done

        if kill -0 "$browser_pid" 2>/dev/null; then
            kill -KILL -- "-$browser_pid" 2>/dev/null || kill -KILL "$browser_pid" 2>/dev/null || true
        fi
    fi

    wait "$browser_pid" 2>/dev/null || true
    browser_pid=''
}

cleanup() {
    status="$?"
    trap - EXIT INT TERM
    stop_browser
    print_log
    rm -rf "$runtime_directory"
    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

export HOME="$runtime_directory/home"
export XDG_CACHE_HOME="$runtime_directory/cache"
export XDG_CONFIG_HOME="$runtime_directory/config"
export XDG_DATA_HOME="$runtime_directory/data"
export XDG_RUNTIME_DIR="$runtime_directory/runtime"

mkdir -p "$HOME" "$XDG_CACHE_HOME" "$XDG_CONFIG_HOME" "$XDG_DATA_HOME" "$XDG_RUNTIME_DIR"
chmod 0700 "$HOME" "$XDG_CACHE_HOME" "$XDG_CONFIG_HOME" "$XDG_DATA_HOME" "$XDG_RUNTIME_DIR"
: > "$OUTPUT_LOG"

started_at="$(date +%s)"
deadline="$((started_at + CHECK_TIMEOUT_SECONDS))"

setsid "$BROWSER_BINARY" \
    --headless \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-gpu \
    --disable-background-networking \
    --disable-component-update \
    --disable-sync \
    --disable-crash-reporter \
    --disable-breakpad \
    --no-first-run \
    --no-default-browser-check \
    --user-data-dir="$runtime_directory/profile" \
    --remote-debugging-port=0 \
    --dump-dom \
    about:blank \
    > "$OUTPUT_LOG" 2>&1 &
browser_pid="$!"

browser_ready=0
while kill -0 "$browser_pid" 2>/dev/null; do
    if grep -q '^DevTools listening on ws://' "$OUTPUT_LOG"; then
        debug_address="$(sed -n 's#^DevTools listening on ws://\([^/]*\)/.*#\1#p' "$OUTPUT_LOG" | tail -n 1)"
        if [ -n "$debug_address" ] \
            && curl --fail --silent --show-error --max-time 2 "http://$debug_address/json/list" \
                > "$runtime_directory/targets.json" 2>> "$OUTPUT_LOG" \
            && grep -Eq '"url"[[:space:]]*:[[:space:]]*"about:blank"' "$runtime_directory/targets.json"; then
            browser_ready=1
            break
        fi
    fi

    process_state="$(ps -o stat= -p "$browser_pid" 2>/dev/null | tr -d ' ' || true)"
    case "$process_state" in
        Z*) break ;;
    esac

    if [ "$(date +%s)" -ge "$deadline" ]; then
        printf 'Erreur : Chrome for Testing n’est pas devenu prêt en %s secondes.\n' "$CHECK_TIMEOUT_SECONDS" >&2
        stop_browser
        print_log
        exit 124
    fi

    sleep 1
done

if [ "$browser_ready" -eq 1 ]; then
    finished_at="$(date +%s)"
    stop_browser
    print_log
    printf 'Résultat : navigateur headless prêt en %s seconde(s), groupe Chrome arrêté, sans ChromeDriver.\n' "$((finished_at - started_at))"
    exit 0
fi

set +e
wait "$browser_pid"
browser_status="$?"
set -e
browser_pid=''
finished_at="$(date +%s)"

print_log

if [ "$browser_status" -eq 0 ] && grep -q '<html' "$OUTPUT_LOG"; then
    printf 'Résultat : DOM headless obtenu en %s seconde(s), sans ChromeDriver.\n' "$((finished_at - started_at))"
    exit 0
fi

fail "Chrome for Testing s’est terminé avant d’être prêt avec le code $browser_status. Consultez $OUTPUT_LOG."

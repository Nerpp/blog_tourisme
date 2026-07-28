#!/bin/sh

set -eu

EXPECTED_BROWSER=/opt/chrome-for-testing/chrome-linux64/chrome
EXPECTED_DRIVER=/opt/chrome-for-testing/chromedriver
PROJECT_ROOT="${PANTHER_PROJECT_ROOT:-/var/www/html}"
VERSION_FILE="$PROJECT_ROOT/docker/panther-browser-version.env"

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

extract_version() {
    printf '%s\n' "$1" | sed -n 's/^[^0-9]*\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*/\1/p'
}

extract_major() {
    printf '%s\n' "$1" | sed -n 's/^\([0-9][0-9]*\)\..*/\1/p'
}

[ -f "$VERSION_FILE" ] \
    || fail "Source de version Panther introuvable : $VERSION_FILE"

# shellcheck disable=SC1090
. "$VERSION_FILE"

: "${CHROME_FOR_TESTING_VERSION:?CHROME_FOR_TESTING_VERSION absente de $VERSION_FILE}"
: "${CHROME_FOR_TESTING_CHROME_SHA256:?CHROME_FOR_TESTING_CHROME_SHA256 absente de $VERSION_FILE}"
: "${CHROME_FOR_TESTING_DRIVER_SHA256:?CHROME_FOR_TESTING_DRIVER_SHA256 absente de $VERSION_FILE}"

printf '%s\n' '=== Configuration Panther ==='
printf 'Source de version : %s\n' "$VERSION_FILE"
printf 'Version configurée : %s\n' "$CHROME_FOR_TESTING_VERSION"
printf 'PATH : %s\n' "$PATH"
env | grep '^PANTHER_' || true

browser_binary="${PANTHER_CHROME_BINARY:-}"
[ -n "$browser_binary" ] \
    || fail 'PANTHER_CHROME_BINARY est absente.'
[ "$browser_binary" = "$EXPECTED_BROWSER" ] \
    || fail "PANTHER_CHROME_BINARY pointe vers $browser_binary au lieu de $EXPECTED_BROWSER."
[ -x "$browser_binary" ] \
    || fail "Le navigateur configuré est absent ou non exécutable : $browser_binary"

driver_binary="$(command -v chromedriver 2>/dev/null || true)"
[ -n "$driver_binary" ] \
    || fail 'ChromeDriver est introuvable dans le PATH utilisé par Panther.'
[ "$driver_binary" = "$EXPECTED_DRIVER" ] \
    || fail "Panther résout ChromeDriver vers $driver_binary au lieu de $EXPECTED_DRIVER."
[ -x "$driver_binary" ] \
    || fail "ChromeDriver est absent ou non exécutable : $driver_binary"

if [ -n "${PANTHER_CHROME_DRIVER_BINARY:-}" ]; then
    [ -x "$PANTHER_CHROME_DRIVER_BINARY" ] \
        || fail "PANTHER_CHROME_DRIVER_BINARY pointe vers un chemin inexistant : $PANTHER_CHROME_DRIVER_BINARY"
    [ "$(readlink -f "$PANTHER_CHROME_DRIVER_BINARY")" = "$(readlink -f "$driver_binary")" ] \
        || fail 'PANTHER_CHROME_DRIVER_BINARY diverge du ChromeDriver réellement résolu dans le PATH.'
fi

candidate_file="$(mktemp)"
trap 'rm -f "$candidate_file"' EXIT HUP INT TERM

record_candidate() {
    candidate="$1"
    if [ -x "$candidate" ]; then
        printf '%s\t%s\n' "$candidate" "$(readlink -f "$candidate")" >> "$candidate_file"
    fi
}

old_ifs="$IFS"
IFS=:
for directory in $PATH; do
    [ -n "$directory" ] || directory=.
    record_candidate "$directory/chromedriver"
done
IFS="$old_ifs"
record_candidate "$PROJECT_ROOT/drivers/chromedriver"
record_candidate "$PROJECT_ROOT/vendor/bin/chromedriver"

printf '%s\n' '=== Pilotes sélectionnables par Panther ==='
if [ -s "$candidate_file" ]; then
    sort -u "$candidate_file"
else
    printf '%s\n' '(aucun)'
fi

unique_driver_count="$(cut -f2 "$candidate_file" | sort -u | sed '/^$/d' | wc -l | tr -d ' ')"
[ "$unique_driver_count" -eq 1 ] \
    || fail "Plusieurs ChromeDriver concurrents peuvent être sélectionnés par Panther ($unique_driver_count binaires distincts)."

browser_output="$("$browser_binary" --version 2>&1)" \
    || fail "Impossible d’exécuter le navigateur : $browser_binary"
driver_output="$("$driver_binary" --version 2>&1)" \
    || fail "Impossible d’exécuter ChromeDriver : $driver_binary"
browser_version="$(extract_version "$browser_output")"
driver_version="$(extract_version "$driver_output")"
browser_major="$(extract_major "$browser_version")"
driver_major="$(extract_major "$driver_version")"

[ -n "$browser_version" ] \
    || fail "Version du navigateur illisible : $browser_output"
[ -n "$driver_version" ] \
    || fail "Version de ChromeDriver illisible : $driver_output"

printf '%s\n' '=== Binaires réellement utilisés ==='
printf 'Navigateur : %s\n' "$browser_binary"
printf 'Version navigateur : %s (majeure %s)\n' "$browser_output" "$browser_major"
printf 'ChromeDriver : %s\n' "$driver_binary"
printf 'Version ChromeDriver : %s (majeure %s)\n' "$driver_output" "$driver_major"

if [ "$browser_major" != "$driver_major" ]; then
    fail 'Incompatibilité Panther : le navigateur et ChromeDriver ne partagent pas la même version majeure. Reconstruisez l’image de test avec le couple Chrome for Testing configuré dans docker/panther-browser-version.env.'
fi

if [ "$browser_version" != "$CHROME_FOR_TESTING_VERSION" ] \
    || [ "$driver_version" != "$CHROME_FOR_TESTING_VERSION" ]; then
    fail "Le couple Panther installé ($browser_version / $driver_version) ne correspond pas à la version configurée $CHROME_FOR_TESTING_VERSION. Reconstruisez l’image PHP."
fi

printf 'Résultat : compatible, couple Chrome for Testing %s.\n' "$CHROME_FOR_TESTING_VERSION"

#!/bin/sh

set -eu

METADATA_URL=https://googlechromelabs.github.io/chrome-for-testing/known-good-versions-with-downloads.json

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

if [ "$#" -ne 1 ]; then
    fail "usage: $0 <version-exacte>"
fi

version="$1"
printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' \
    || fail "version invalide : $version"

command -v curl >/dev/null 2>&1 || fail "la commande 'curl' est introuvable."
command -v sha256sum >/dev/null 2>&1 || fail "la commande 'sha256sum' est introuvable."

script_directory="$(CDPATH= cd "$(dirname "$0")" && pwd)"
project_root="$(dirname "$script_directory")"
version_file="$project_root/docker/panther-browser-version.env"
[ -f "$version_file" ] || fail "source de version introuvable : $version_file"

temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/panther-browser-update.XXXXXX")"
cleanup() {
    if [ -n "${temporary_directory:-}" ] && [ -d "$temporary_directory" ]; then
        rm -rf "$temporary_directory"
    fi
}
trap cleanup EXIT HUP INT TERM

printf 'Vérification de Chrome for Testing %s dans les métadonnées officielles...\n' "$version"
metadata="$(curl --fail --location --silent --show-error "$METADATA_URL")" \
    || fail 'métadonnées Chrome for Testing indisponibles.'
record="$(printf '%s\n' "$metadata" | sed 's/},{"version"/}\
{"version"/g' | grep -F "\"version\":\"$version\"" || true)"
[ -n "$record" ] || fail "la version $version est absente des métadonnées officielles."

chrome_url="$(printf '%s\n' "$record" | grep -o 'https://[^" ]*/linux64/chrome-linux64.zip' || true)"
driver_url="$(printf '%s\n' "$record" | grep -o 'https://[^" ]*/linux64/chromedriver-linux64.zip' || true)"
[ -n "$chrome_url" ] || fail "archive Chrome linux64 absente pour $version."
[ -n "$driver_url" ] || fail "archive ChromeDriver linux64 absente pour $version."

case "$chrome_url" in
    "https://storage.googleapis.com/chrome-for-testing-public/$version/linux64/chrome-linux64.zip") ;;
    *) fail "URL Chrome officielle inattendue : $chrome_url" ;;
esac
case "$driver_url" in
    "https://storage.googleapis.com/chrome-for-testing-public/$version/linux64/chromedriver-linux64.zip") ;;
    *) fail "URL ChromeDriver officielle inattendue : $driver_url" ;;
esac

chrome_archive="$temporary_directory/chrome-linux64.zip"
driver_archive="$temporary_directory/chromedriver-linux64.zip"
curl --fail --location --silent --show-error --retry 5 --retry-all-errors \
    --output "$chrome_archive" "$chrome_url"
curl --fail --location --silent --show-error --retry 5 --retry-all-errors \
    --output "$driver_archive" "$driver_url"

chrome_sha256="$(sha256sum "$chrome_archive" | cut -d' ' -f1)"
driver_sha256="$(sha256sum "$driver_archive" | cut -d' ' -f1)"
updated_file="$temporary_directory/panther-browser-version.env"

sed \
    -e "s/^CHROME_FOR_TESTING_VERSION=.*/CHROME_FOR_TESTING_VERSION=$version/" \
    -e "s/^CHROME_FOR_TESTING_CHROME_SHA256=.*/CHROME_FOR_TESTING_CHROME_SHA256=$chrome_sha256/" \
    -e "s/^CHROME_FOR_TESTING_DRIVER_SHA256=.*/CHROME_FOR_TESTING_DRIVER_SHA256=$driver_sha256/" \
    "$version_file" > "$updated_file"
chmod 0644 "$updated_file"
mv "$updated_file" "$version_file"

printf 'Version Panther mise à jour dans %s : %s\n' "$version_file" "$version"
printf '%s\n' 'Aucune image n’a été reconstruite et aucun test n’a été lancé.'
printf '%s\n' 'Étapes suivantes :'
printf '%s\n' '  docker compose build --no-cache php'
printf '%s\n' '  docker compose up -d --force-recreate php'
printf '%s\n' '  make panther-doctor'
printf '%s\n' '  make test-db-reset'
printf '%s\n' '  docker compose exec -T -e SKIP_TEST_DB_RESET=1 php composer test:e2e'

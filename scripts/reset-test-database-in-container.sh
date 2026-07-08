#!/bin/sh
set -eu

EXPECTED_ENV=test
EXPECTED_DATABASE=app_test

fail() {
    printf '%s\n' "ERROR: $*" >&2
    exit 1
}

if [ "${SKIP_TEST_DB_RESET:-0}" = "1" ]; then
    printf '%s\n' "Reset app_test deja effectue par l'appelant."
    exit 0
fi

export APP_ENV=test
export APP_DEBUG="${APP_DEBUG:-1}"

if [ "$APP_ENV" != "$EXPECTED_ENV" ]; then
    fail "APP_ENV doit etre exactement test avant un reset de fixtures."
fi

resolved_database="$(php -r '
$url = getenv("DATABASE_URL_TEST") ?: getenv("DATABASE_URL") ?: "";
$path = parse_url($url, PHP_URL_PATH);
$database = is_string($path) ? ltrim($path, "/") : "";
echo $database;
')"

if [ "$resolved_database" != "$EXPECTED_DATABASE" ]; then
    fail "Refus de purger les fixtures: la base resolue est '${resolved_database:-inconnue}', attendu '$EXPECTED_DATABASE'."
fi

printf '%s\n' "Base Doctrine test resolue avant purge fixtures: $resolved_database"

php bin/console app:test-database:assert-safe --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console app:test-database:assert-safe --env=test
php bin/console doctrine:fixtures:load --env=test --group=test --no-interaction
php bin/console app:test-database:assert-safe --env=test

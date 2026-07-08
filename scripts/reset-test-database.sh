#!/bin/sh
set -eu

EXPECTED_DATABASE=app_test
COMPOSE=${COMPOSE:-docker compose}

fail() {
    printf '%s\n' "ERROR: $*" >&2
    exit 1
}

resolved_database="$($COMPOSE exec -T php php -r '
$url = getenv("DATABASE_URL_TEST") ?: "";
$path = parse_url($url, PHP_URL_PATH);
$database = is_string($path) ? ltrim($path, "/") : "";
echo $database;
')"

if [ "$resolved_database" != "$EXPECTED_DATABASE" ]; then
    fail "Refus de preparer les tests: DATABASE_URL_TEST cible '${resolved_database:-inconnue}', attendu '$EXPECTED_DATABASE'."
fi

printf '%s\n' "Base Doctrine test resolue avant creation/purge: $resolved_database"

$COMPOSE exec -T -e TEST_DATABASE="$EXPECTED_DATABASE" mysql sh -eu -c '
if [ "${TEST_DATABASE:-}" != "app_test" ]; then
    echo "ERROR: TEST_DATABASE doit etre exactement app_test." >&2
    exit 1
fi

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD manquant dans le conteneur mysql}"
: "${MYSQL_USER:?MYSQL_USER manquant dans le conteneur mysql}"

case "$MYSQL_USER" in
    *[!A-Za-z0-9_-]*|"")
        echo "ERROR: MYSQL_USER contient des caracteres non autorises pour ce script." >&2
        exit 1
        ;;
esac

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=tcp --host=127.0.0.1 --user=root --execute="
CREATE DATABASE IF NOT EXISTS \`app_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER ON \`app_test\`.* TO '\''${MYSQL_USER}'\''@'\''%'\'';
"
'

$COMPOSE exec -T php scripts/reset-test-database-in-container.sh

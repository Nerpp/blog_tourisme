#!/bin/sh
set -eu

project_dir=$(git rev-parse --show-toplevel)
build_dir=$(mktemp -d "${TMPDIR:-/tmp}/estela-production-build.XXXXXX")

cleanup() {
    rm -rf "$build_dir"
}

trap cleanup EXIT HUP INT TERM

cd "$project_dir"

# Copy tracked and new non-ignored files only. In particular, this excludes
# vendor/, local env files, the Symfony private vault key and local media.
git ls-files --cached --others --exclude-standard -z \
    | tar --null --files-from=- --create --file=- \
    | tar --extract --file=- --directory="$build_dir"

cd "$build_dir"

export APP_ENV=prod
export APP_DEBUG=0
export APP_SECRET=ci-production-build-only-not-a-production-secret
export DATABASE_URL='mysql://ci-build:ci-build@127.0.0.1:1/ci_build?serverVersion=8.0&charset=utf8mb4'
export MAILER_DSN='null://null'
export BREVO_MAILER_DSN='null://null'
export MAILER_FROM='ci-build@example.invalid'
export MONOLOG_FROM_EMAIL='ci-build@example.invalid'
export MONOLOG_TO_EMAIL='ci-build@example.invalid'
export OAUTH_GOOGLE_CLIENT_ID='ci-build-client-id'
export OAUTH_GOOGLE_CLIENT_SECRET='ci-build-client-secret'
export COMMENT_AUTO_APPROVE_AFTER=3
export COMMENT_REPORT_THRESHOLD=3

composer validate --strict
composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-progress

test -f vendor/symfony/process/Process.php
test ! -d vendor/doctrine/doctrine-fixtures-bundle

php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
php bin/console lint:container

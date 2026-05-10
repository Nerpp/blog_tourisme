#!/bin/sh
set -e

if [ "${SKIP_COMPOSER_INSTALL:-0}" != "1" ] && [ -f composer.json ] && [ ! -f vendor/autoload_runtime.php ]; then
    composer install --prefer-dist --no-interaction
fi

exec docker-php-entrypoint "$@"

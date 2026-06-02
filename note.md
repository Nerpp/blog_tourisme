URLs locales :

Symfony : http://localhost:8080
phpMyAdmin : http://localhost:8081
Mailpit : http://localhost:8025
Vite : http://localhost:5173

docker compose exec php php bin/console cache:warmup --env=dev
docker compose exec php sh -lc 'vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G --no-progress --error-format=table > var/reports/phpstan-report.txt 2>&1 || true'
URLs locales :

Symfony : http://localhost:8080
phpMyAdmin : http://localhost:8081
Mailpit : http://localhost:8025
Vite : http://localhost:5173

Cmd php Stan :

rm -f var/reports/phpstan-report.txt
rm -f phpstan-strict.neon var/phpstan-strict.neon
rm -rf var/cache/phpstan

mkdir -p var/reports

docker compose exec php sh -lc 'php vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --error-format=table --memory-limit=1G > var/reports/phpstan-report.txt 2>&1 || true'

cat var/reports/phpstan-report.txt
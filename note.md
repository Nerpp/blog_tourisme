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

Lancement des tests ;

docker compose exec php composer test

docker compose exec php composer test:e2e
docker compose exec php composer quality
docker compose exec php composer quality:e2e
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage

make test-all

config de cmd de set up

make setup
make test-all

make test


# 1. Reconstruit les assets CSS / JS
docker compose run --rm node npm run build

# 2. Réinitialise uniquement app_test avec les fixtures Lighthouse stables
docker compose --profile tools run --rm php_lighthouse composer lighthouse:test-db:reset

# 3. Démarre l’instance publique isolée sur le port 8082
docker compose --profile tools up -d php_lighthouse web_lighthouse

# 4. Lance les 22 audits : 11 pages × mobile + desktop
./tools/lighthouse/audit-site.sh

# 5. ouvrir le rapport
xdg-open var/lighthouse/latest-report.html

# 6. Pour arrêter l’environnement Lighthouse après contrôle :
docker compose --profile tools stop php_lighthouse web_lighthouse
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


Depuis la racine du projet, utilise-le dans cet ordre.

Première utilisation ou après modification des fixtures Lighthouse
# Reconstruit les assets publics si tu as modifié CSS, JS ou images
docker compose run --rm node npm run build

# Prépare uniquement app_test avec les fixtures prévues
docker compose --profile tools run --rm php_lighthouse composer lighthouse:test-db:reset

# Démarre le site Lighthouse isolé sur le port 8082
docker compose --profile tools up -d php_lighthouse web_lighthouse

# Lance l’audit des 11 pages déclarées dans config/lighthouse-pages.json
./tools/lighthouse/audit-site.sh

Le script doit auditer l’instance dédiée sur :

http://localhost:8082

Il ne doit pas auditer ton site de développement habituel sur http://localhost:8080. C’est volontaire : la protection évite qu’un reset de fixtures touche ta base de travail.

Les rapports seront générés dans :

var/lighthouse/

Tu y trouveras normalement un rapport HTML et un JSON par page. Ouvre le fichier HTML correspondant à la page que tu veux analyser dans ton navigateur.

Pour relancer Lighthouse après une modification CSS, image ou JavaScript

Si les fixtures n’ont pas changé, tu n’as pas besoin de refaire le reset de la base :

docker compose run --rm node npm run build
./tools/lighthouse/audit-site.sh

Si tu as modifié les fixtures, les slugs des pages Lighthouse, les contenus ou les médias utilisés par les fixtures, relance la préparation complète :

docker compose --profile tools run --rm php_lighthouse composer lighthouse:test-db:reset
docker compose --profile tools up -d php_lighthouse web_lighthouse
./tools/lighthouse/audit-site.sh
Vérifier que le serveur Lighthouse est bien lancé
docker compose --profile tools ps

Tu dois voir php_lighthouse et web_lighthouse actifs. Tu peux aussi ouvrir directement :

http://localhost:8082
Arrêter seulement l’environnement Lighthouse

Quand tu as fini :

docker compose --profile tools stop php_lighthouse web_lighthouse

Donc, dans la pratique, avant un commit qui touche une page publique importante :

docker compose run --rm node npm run build
./tools/lighthouse/audit-site.sh

Et après une évolution des fixtures ou de l’environnement Lighthouse :

docker compose --profile tools run --rm php_lighthouse composer lighthouse:test-db:reset
docker compose --profile tools up -d php_lighthouse web_lighthouse
./tools/lighthouse/audit-site.sh

Le script et le catalogue des pages sont à versionner ; var/lighthouse/ doit rester ignoré par Git.

Le catalogue stable se trouve dans `config/lighthouse-pages.json`. Les rapports HTML,
JSON et le résumé sont écrits sous `var/lighthouse/`, déjà ignoré par Git.

La documentation complète est dans `tools/lighthouse/README.md`.

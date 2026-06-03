# Strategie de tests

Le projet separe volontairement les tests en plusieurs couches. `composer test` reste rapide et ne lance pas Panther.

## Types de tests

- Unit : tests purs dans `tests/Unit/`, sans kernel Symfony ni base de donnees.
- Integration : tests dans `tests/Integration/` avec `KernelTestCase`, le container Symfony reel et la base `app_test`.
- Application / Functional : tests HTTP dans `tests/Functional/` avec le client Symfony.
- E2E / Panther : tests navigateur dans `tests/E2E/`, opt-in car plus lents et dependants de Chromium/ChromeDriver.

## Commandes

```bash
docker compose exec php composer test
docker compose exec php composer test:unit
docker compose exec php composer test:integration
docker compose exec php composer test:functional
docker compose exec php composer test:e2e
docker compose exec php composer test:panther
docker compose exec php composer quality
docker compose exec php composer quality:e2e
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage:clover
```

`composer test` lance uniquement les suites `Unit`, `Integration` et `Functional`. Les tests E2E/Panther restent explicites via `composer test:e2e`, `composer test:panther` ou `composer quality:e2e`.

## Base de test

Les tests utilisent uniquement la base `app_test`, configuree par `.env.test`, `phpunit.xml.dist` et `config/packages/test/doctrine.yaml`.

Preparation :

```bash
docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec php php bin/console doctrine:schema:validate --env=test
```

Ne pas utiliser la base de developpement pour les tests et ne pas lancer `schema:update --force`.

## Couverture

La couverture PHPUnit cible `src/` et exclut les tests E2E/Panther au depart.

```bash
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage
```

Le rapport HTML est genere dans :

```text
var/coverage/index.html
```

Xdebug est installe dans l'image PHP mais desactive par defaut avec `XDEBUG_MODE=off`. Les commandes de couverture l'activent a la demande avec `XDEBUG_MODE=coverage`, pour eviter de ralentir les commandes courantes.

## Fichiers generes

Ne pas commiter :

- `var/coverage/`
- `var/panther/`
- `.phpunit.cache/`
- les screenshots Panther generes en cas d'erreur

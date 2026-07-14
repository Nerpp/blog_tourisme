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
make test-db-reset
```

`make test-db-reset` cree `app_test` si necessaire, accorde les droits au user applicatif sur cette base uniquement, applique les migrations en environnement `test`, puis charge seulement les fixtures du groupe Doctrine `test`.

Les commandes Composer dependantes de la base (`composer test`, `composer test:coverage`, `composer test:e2e`) relancent aussi ce reset dans le conteneur PHP lorsque `app_test` est deja creee et accessible. Depuis l'hote, preferer les cibles Makefile, car elles savent aussi initialiser les droits MySQL apres une recreation du volume.

Ne pas utiliser la base de developpement pour les tests et ne pas lancer `schema:update --force`.

Les fixtures de production futures ne doivent pas rejoindre le groupe Doctrine `test`. Elles devront utiliser un groupe ou un namespace separe et ne jamais etre appelees par `make test-db-reset`.

## Contenus externes dans Panther

Le helper Panther `requestWithExternalEmbedPlaceholders()` ajoute un opt-in aux
navigations E2E qui ouvrent une iframe externe. En environnement `test`
uniquement, les iframes des galeries conservent alors leur URL reelle dans
`data-video-src` mais recoivent un document local minimal via `srcdoc`. Comme
`srcdoc` est prioritaire sur `src`, Chromium ne telecharge et n'execute pas le
document YouTube lorsque le JavaScript de la galerie ouvre la video. Une
miniature YouTube reste elle aussi declarée dans `data-video-thumbnail-src`,
mais le placeholder visuel local est utilise afin de ne pas contacter
`img.youtube.com`.

Les tests continuent de verifier l'hote exact `www.youtube-nocookie.com`, le
chemin d'embed, le titre accessible et les attributs de securite. La detection
globale des entrees navigateur `SEVERE` reste intacte : aucune origine, aucun
`ReferenceError` et aucun message YouTube ne sont filtres.

Le parametre d'opt-in est ignore hors de l'environnement `test`. Le comportement
des environnements de developpement et de production est donc inchange.

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

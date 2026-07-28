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
make panther-doctor
docker compose exec php composer quality
docker compose exec php composer quality:e2e
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage
docker compose exec -e XDEBUG_MODE=coverage php composer test:coverage:clover
```

`composer test` lance uniquement les suites `Unit`, `Integration` et `Functional`. Les tests E2E/Panther restent explicites via `composer test:e2e`, `composer test:panther` ou `composer quality:e2e`.

## Navigateur Panther reproductible

La version complète commune de Chrome for Testing et ChromeDriver, ainsi que
les empreintes SHA-256 des deux archives officielles, sont définies dans
`docker/panther-browser-version.env`. Les archives `linux64` sont téléchargées
et vérifiées uniquement pendant la construction de l’image PHP.

Panther 2.4 ne lit plus `PANTHER_CHROME_DRIVER_BINARY` : le navigateur est fixé
par `PANTHER_CHROME_BINARY` et ChromeDriver est résolu dans le `PATH`. L’image
place uniquement `/opt/chrome-for-testing/chromedriver` en tête du `PATH` et
n’installe pas de pilote concurrent via APT ou dans `drivers/`.

Après un changement de version ou sur une nouvelle machine :

```bash
docker compose build --no-cache php
docker compose up -d --force-recreate php
make panther-doctor
make test-db-reset
docker compose exec -T -e SKIP_TEST_DB_RESET=1 php composer test:e2e
```

`make panther-doctor` affiche la configuration, les chemins et versions exacts,
les versions majeures et tous les pilotes sélectionnables. Il échoue si le
couple installé ne correspond pas à la version commune ou si plusieurs pilotes
sont ambigus. Il exécute uniquement le navigateur et le pilote avec `--version`,
chacun sous un timeout de cinq secondes : aucune session navigateur ou serveur
ChromeDriver n’est démarré. Les cibles Make `e2e`, `quality-e2e` et `test-all`
orchestrent ce prérequis une seule fois, avant le contrôle de démarrage et les
opérations longues. Le script Composer `test:e2e` ne le relance pas. Le doctor
ne réalise aucun accès réseau.

Le contrôle de démarrage réel est volontairement séparé :

```bash
time make panther-browser-check
```

Il lance Chrome seul, en mode headless, sur `about:blank` avec `--dump-dom`,
un port DevTools éphémère, un groupe de processus et un profil temporaires. Il
considère le navigateur prêt lorsque DevTools écoute, puis l’arrête volontairement
au lieu de dépendre de la fin naturelle de `--dump-dom`. L’attente interne est
limitée à 15 secondes par défaut (60 au maximum) et la cible Make à 25 secondes.
Un trap envoie d’abord `TERM`, puis `KILL` si nécessaire, au groupe Chrome et
supprime le profil. Le journal `/tmp/panther-browser-check.log` reste affiché et
disponible pour la CI. Ce contrôle ne lance jamais ChromeDriver.

Les messages DBus, GCM ou Vulkan peuvent apparaître dans un conteneur headless
sans constituer à eux seuls un échec. Ils ne sont pas masqués : le code de sortie,
le timeout et l’intégralité du journal restent utilisés pour détecter un vrai
problème de démarrage.

Une mise à jour est toujours volontaire :

```bash
scripts/update-panther-browser.sh 150.0.7871.124
```

Le script exige une version complète, vérifie dans les métadonnées officielles
que Chrome et ChromeDriver `linux64` existent, télécharge les archives pour en
calculer les empreintes, puis modifie uniquement
`docker/panther-browser-version.env`. Il ne reconstruit pas l’image, ne lance
aucun test et n’effectue aucune opération Git.

## Assets commentaires

`assets/styles/comments.css` est une entrée Vite de styles autonome.
`assets/entries/comments.js` contient uniquement les interactions de réponses.
Une page qui rend le composant commentaire charge le CSS ; le JavaScript n’est
chargé que si des interactions de réponse sont présentes. La page de
notifications conserve ainsi les styles sans télécharger le script.

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

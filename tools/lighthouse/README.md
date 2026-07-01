# Audit Lighthouse reproductible

Les audits utilisent exclusivement le catalogue versionné
[`config/lighthouse-pages.json`](../../config/lighthouse-pages.json) et la base de test
existante `app_test`. La base de développement, servie sur le port `8080`, n’est
jamais préparée ni purgée par cette procédure.

## Préparer les données

La commande suivante vérifie d’abord que la connexion cible exactement
`APP_ENV=test` et `app_test`. Elle s’arrête avant toute suppression si
l’une de ces deux valeurs diffère.

```bash
docker compose --profile tools run --rm php_lighthouse \
  composer lighthouse:test-db:reset
```

Elle recrée uniquement `app_test`, applique les migrations puis charge les fixtures
du projet. Les transactions automatiques de PHPUnit restent réservées à PHPUnit ;
cette préparation est persistante pour l’instance Lighthouse.

## Démarrer l’instance dédiée

```bash
docker compose run --rm node npm run build
docker compose --profile tools up -d php_lighthouse web_lighthouse
```

L’instance d’audit répond sur `http://localhost:8082`. L’endpoint
`/_lighthouse/health` confirme au script l’environnement et le nom de la base. Une
instance de développement sur `http://localhost:8080` est refusée par le script.

## Lancer les audits

Campagne complète mobile et desktop :

```bash
./tools/lighthouse/audit-site.sh
```

La commande exécutée dans le conteneur est :

```bash
npm run audit:lighthouse
```

Quand Chrome tourne dans le conteneur Lighthouse, le script ouvre un proxy local
dans ce conteneur : Lighthouse audite toujours `http://localhost:8082`, et le proxy
transmet vers `web_lighthouse` sur le réseau Compose. Cela conserve l’exemption
Lighthouse normale de `localhost` sans utiliser `--network host`.

Exemples ciblés :

```bash
./tools/lighthouse/audit-site.sh --devices=mobile --page=homepage
./tools/lighthouse/audit-site.sh --devices=desktop --page=article-fixture
./tools/lighthouse/audit-site.sh --runs=3
```

Les pages auditées sont les six pages cœur et cinq détails de fixture : article,
destination, randonnée, visite et lieu. Aucun slug n’est découvert dans la base au
moment de l’audit.

## Rapports

En mode normal, une campagne réussie ne conserve que trois fichiers non versionnés :

```text
var/lighthouse/latest-report.html
var/lighthouse/previous-report.html
var/lighthouse/history.json
```

`latest-report.html` est remplacé uniquement après une campagne Lighthouse
entièrement réussie. Juste avant la publication, l’ancien `latest-report.html` est
copié dans `previous-report.html`. En cas d’échec, le dernier rapport valide et le
rapport précédent restent inchangés.

`history.json` garde un historique compact des 20 dernières campagnes réussies :
date, URL publique auditée, catalogue, nombre de pages, nombre d’audits, minimums et
moyennes par catégorie, nombre d’audits sous 100, page et mode les plus faibles en
Performance, et baseline compacte utile à la comparaison.

Le rapport HTML courant affiche aussi `Évolution depuis le précédent audit` quand un
historique précédent valide existe : minimum précédent, minimum actuel, différence,
indicateur, nouvelles pages passées sous 100, nouveaux audits Lighthouse en échec et
dégradations notables de LCP, FCP, TBT ou CLS.

Pour diagnostiquer un audit en détail :

```bash
./tools/lighthouse/audit-site.sh --keep-raw
```

Avec cette option seulement, les rapports HTML et JSON individuels sont conservés
dans un dossier horodaté :

```text
var/lighthouse/raw/YYYY-MM-DDTHH-MM-SS/
├── homepage-mobile.html
├── homepage-mobile.json
└── …
```

Le chemin du dossier `raw` est indiqué dans le terminal et dans
`latest-report.html`. Sans `--keep-raw`, les fichiers temporaires de génération sont
nettoyés automatiquement.

Lighthouse reste un audit manuel de qualité, séparé de PHPUnit et Panther. Les scores
peuvent varier légèrement selon la machine et sa charge ; l’existence et la
publication des pages du catalogue sont, elles, vérifiées par PHPUnit.

Évite de lancer PHPUnit et une campagne Lighthouse en même temps : ils partagent
volontairement `app_test`.

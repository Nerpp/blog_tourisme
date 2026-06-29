# Audit Lighthouse du site public

La campagne complète utilise les URL publiques découvertes par Symfony et audite `localhost`, pas l’alias Docker `web` :

```bash
./tools/lighthouse/audit-site.sh
```

Options principales :

```bash
./tools/lighthouse/audit-site.sh \
  --base-url=http://localhost:8080 \
  --devices=mobile,desktop \
  --runs=3
```

Pour une vérification courte qui conserve les pages cœur et limite chaque type de page dynamique :

```bash
./tools/lighthouse/audit-site.sh --limit-per-type=1
```

Pour tester rapidement la gestion multi-passages sur l’accueil uniquement :

```bash
./tools/lighthouse/audit-site.sh --devices=mobile --runs=3 --max-urls=1
```

Les audits sont séquentiels. Une erreur sur une page est enregistrée puis la campagne continue. Le code de sortie final est non nul lorsqu’au moins un audit attendu échoue.

## Découverte des URL

```bash
docker compose exec php php bin/console app:lighthouse:urls --format=json
```

La commande utilise le routeur Symfony et les mêmes méthodes de repositories que les contrôleurs publics. Elle inclut les pages cœur, les destinations ayant du contenu public cumulé et les entités publiées. Elle exclut les brouillons, l’administration, l’authentification, les profils et les routes techniques.

## Rapports

Chaque lancement crée un nouveau dossier horodaté :

```text
var/lighthouse/YYYY-MM-DD_HH-MM-SS/
├── raw/
│   ├── mobile/
│   └── desktop/
├── urls.json
├── attempts.tsv
├── summary.json
├── summary.csv
└── summary.md
```

Avec plusieurs passages, tous les rapports bruts sont conservés et les synthèses utilisent la médiane des scores par page et appareil.

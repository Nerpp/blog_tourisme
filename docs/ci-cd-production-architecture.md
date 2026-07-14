# Architecture CI/CD et préparation production

Document de phase 1 — état vérifié le 14 juillet 2026.

Cette phase prépare la chaîne de livraison sans effectuer de commit, de push, de
mutation GitHub ou de déploiement Infomaniak.

## État de référence

- Dépôt : Nerpp/blog_tourisme.
- Branche GitHub par défaut : dev.
- Référence symbolique locale origin/HEAD : encore origin/main, donc obsolète ;
  la resynchroniser plus tard avec git remote set-head origin -a.
- Dev distant : b7ff2228baaecfb3f96c325cf31f85401ac3f45c.
- Main distant : 001d5f31dcfd6b7fae75b478d15fdffce3ef6fde.
- Merge-base : d7a3398dd07f1bb0cd8b5c50f1f04d695610d0d2.
- Divergence main...dev : 1 commit exclusif à main, 280 à dev.
- Différence réelle main..dev : 46 fichiers, 1 784 ajouts, 228 suppressions.
- Tags locaux et distants : aucun.
- Environnements GitHub : aucun.
- Déploiements GitHub : aucun.
- Auto-merge autorisé au niveau du dépôt, mais actif sur aucune PR inspectée.

Le commit de squash main 001d5f3, le commit dev e877b48 et le commit de retour
main vers dev c2ab6f4 ont exactement le même arbre Git
112b1b861fee89d3c603300cc1aa03bdd389c6dd. Main ne contient donc aucun contenu
utile absent du dev actuel. La divergence est une divergence d'ascendance créée
par les squashes dans les deux sens.

## Inventaire des PR ouvertes

| PR | Auteur | Source vers cible | État vérifié | Auto-merge |
|---|---|---|---|---|
| #17 | Nerpp | backup-main-before-first-deployment vers dev | conflictuelle, dirty | non |
| #16 | dependabot[bot] | npm-patches-6cb837f891 vers dev | calcul GitHub encore unknown | non |
| #15 | dependabot[bot] | composer-patches-5412315c19 vers dev | calcul GitHub encore unknown | non |
| #9 | dependabot[bot] | doctrine-patches-79284ed7a0 vers dev | calcul GitHub encore unknown | non |
| #5 | dependabot[bot] | npm multi-f1f74497ec vers dev | calcul GitHub encore unknown | non |
| #4 | dependabot[bot] | symfony/http-foundation-8.0.13 vers dev | calcul GitHub encore unknown | non |
| #3 | dependabot[bot] | symfony/runtime-8.0.12 vers dev | calcul GitHub encore unknown | non |
| #2 | dependabot[bot] | symfony/routing-8.0.13 vers dev | calcul GitHub encore unknown | non |
| #1 | dependabot[bot] | twig/twig-3.27.1 vers dev | calcul GitHub encore unknown | non |

Les PR récemment fusionnées qui expliquent la divergence sont #13, dev vers
main, puis #14, main vers dev. Aucune PR dev vers main n'est ouverte.

## Inventaire des branches distantes

Les colonnes « absents » comptent les commits accessibles depuis la branche
mais pas depuis la branche de comparaison.

| Branche | Dernier SHA | Activité | PR | Absents de dev | Absents de main | Contenu conservé | Candidate après validation | Justification |
|---|---|---:|---:|---:|---:|---|---|---|
| dev | b7ff222 | 2026-07-14 14:48 +02 | — | 0 | 280 | branche primaire | non | intégration |
| main | 001d5f3 | 2026-07-08 09:48 +02 | — | 1 | 0 | branche primaire | non | production |
| backup-main-before-first-deployment | 001d5f3 | 2026-07-08 09:48 +02 | #17 | 1 | 0 | SHA identique à main et futur tag main | oui, après fermeture validée de #17 | doublon exact de main |
| backup/easyadmin-before-removal | 4054a78 | 2026-05-11 10:28 +02 | aucune trouvée | 0 | 10 | commit ancêtre de dev | oui, après tags | tout son historique est dans dev |
| deploy/align-main-with-dev | 6bcfa9c | 2026-07-13 18:21 +02 | aucune ouverte | 2 | 1 | arbre identique au commit dev 868f85d | oui, après alignement et tags | le commit unique ne contient aucun arbre inédit |
| dependabot/composer/composer-patches-5412315c19 | 1e4c37b | 2026-07-13 05:14 UTC | #15 | 1 | 272 | PR et branche seulement | non | PR ouverte |
| dependabot/composer/doctrine-patches-79284ed7a0 | 5363acc | 2026-07-13 05:13 UTC | #9 | 1 | 272 | PR et branche seulement | non | PR ouverte |
| dependabot/composer/symfony/http-foundation-8.0.13 | 9d952bb | 2026-07-02 19:27 UTC | #4 | 1 | 1 | PR et branche seulement | non | PR ouverte |
| dependabot/composer/symfony/routing-8.0.13 | c606553 | 2026-07-02 19:27 UTC | #2 | 1 | 1 | PR et branche seulement | non | PR ouverte |
| dependabot/composer/symfony/runtime-8.0.12 | 2b2d0f3 | 2026-07-02 19:27 UTC | #3 | 1 | 1 | PR et branche seulement | non | PR ouverte |
| dependabot/composer/twig/twig-3.27.1 | 1733a8e | 2026-07-02 19:27 UTC | #1 | 1 | 1 | PR et branche seulement | non | PR ouverte |
| dependabot/npm_and_yarn/multi-f1f74497ec | 8b97d55 | 2026-07-02 19:27 UTC | #5 | 1 | 1 | PR et branche seulement | non | PR ouverte |
| dependabot/npm_and_yarn/npm-patches-6cb837f891 | 6199f3e | 2026-07-13 05:20 UTC | #16 | 1 | 272 | PR et branche seulement | non | PR ouverte |

Une « candidate » n'est pas une autorisation de suppression. Avant toute
suppression, il faut refaire l'inventaire, pousser les tags validés, traiter les
PR associées et obtenir une validation humaine explicite.

## Tags de sauvegarde proposés

Noms réservés :

- backup/pre-cicd-dev-20260714 vers b7ff2228baaecfb3f96c325cf31f85401ac3f45c ;
- backup/pre-cicd-main-20260714 vers 001d5f31dcfd6b7fae75b478d15fdffce3ef6fde.

Commandes futures, à exécuter seulement après validation :

    git fetch origin --prune --tags
    test "$(git rev-parse origin/dev)" = "b7ff2228baaecfb3f96c325cf31f85401ac3f45c"
    test "$(git rev-parse origin/main)" = "001d5f31dcfd6b7fae75b478d15fdffce3ef6fde"
    test -z "$(git tag --list backup/pre-cicd-dev-20260714)"
    test -z "$(git tag --list backup/pre-cicd-main-20260714)"
    git tag -a backup/pre-cicd-dev-20260714 b7ff2228baaecfb3f96c325cf31f85401ac3f45c -m "Backup dev before CI/CD alignment"
    git tag -a backup/pre-cicd-main-20260714 001d5f31dcfd6b7fae75b478d15fdffce3ef6fde -m "Backup main before CI/CD alignment"
    git push origin refs/tags/backup/pre-cicd-dev-20260714
    git push origin refs/tags/backup/pre-cicd-main-20260714

Si les SHA distants changent avant exécution, ne pas réutiliser ces noms avec
d'autres cibles : choisir une nouvelle date ou un suffixe, puis refaire l'audit.

## Alignement dev et main

### Option A — recommandée : rattacher main à dev, puis promouvoir

Créer une branche de maintenance depuis le dev sauvegardé, fusionner le main
validé avec une fusion Git normale, résoudre les conflits en conservant l'arbre
dev prouvé complet, puis ouvrir une PR vers dev. La fusion de maintenance aura
dev comme premier parent et main comme second parent. Une PR normale dev vers
main publiera ensuite le contenu. Cette méthode respecte le contrôle qui
n'autorise que dev comme source de main.

Commandes futures indicatives, avec les SHA remplacés seulement après un nouvel
audit :

    git fetch origin --prune --tags
    DEV_SHA=$(git rev-parse origin/dev)
    MAIN_SHA=$(git rev-parse origin/main)
    test "$DEV_SHA" = "b7ff2228baaecfb3f96c325cf31f85401ac3f45c"
    test "$MAIN_SHA" = "001d5f31dcfd6b7fae75b478d15fdffce3ef6fde"
    test "$(git rev-parse "$MAIN_SHA^{tree}")" = "$(git rev-parse e877b483e974935e829ffa30a4952c17a878acea^{tree})"
    git switch -c maintenance/link-main-into-dev-20260714 "$DEV_SHA"
    git merge --no-ff --no-commit "$MAIN_SHA"
    git diff --name-only --diff-filter=U

La fusion à trois voies actuelle présente de nombreux conflits à cause du vieux
merge-base. Après revue de la liste et uniquement parce que l'égalité des arbres
main/e877b48 prouve que main n'a aucun contenu exclusif, remettre l'index et le
worktree sur l'instantané dev :

    git restore --source="$DEV_SHA" --staged --worktree -- .
    git add -A
    git diff --cached --exit-code "$DEV_SHA"
    git commit -m "Merge dev history for CI/CD alignment"
    git diff --exit-code "$DEV_SHA" HEAD
    test "$(git rev-parse HEAD^2)" = "$MAIN_SHA"

Ensuite seulement : pousser cette branche, ouvrir une PR vers dev, attendre
Quality, vérifier que l'arbre reste égal à DEV_SHA, puis utiliser « Create a
merge commit ». Main devient alors ancêtre de dev. Ouvrir ensuite la PR dev vers
main et utiliser encore un merge commit. Après la première PR,
PROMOTION_BASE_SHA doit être le MAIN_SHA désormais ancêtre des deux branches.

- Commits créés : merge d'ascendance, merge de PR vers dev, puis merge de
  promotion vers main.
- Impact dev : ascendance ajoutée, aucun changement d'arbre.
- Impact main : arbre exactement égal au dev validé après la promotion.
- Future PR dev vers main : comparaison limitée aux changements postérieurs.
- Rollback contenu : nouvelle PR restaurant le tag main ; aucun reset ni
  force-push. L'ascendance réparée reste volontairement en place.
- Risque : moyen, concentré sur la résolution initiale et la PR sans différence
  d'arbre ; les invariants de tree SHA rendent toute perte détectable.

### Option B — merge de maintenance basé sur main

Créer une branche depuis main, y fusionner dev par un merge normal et conserver
l'arbre dev après preuve. Cette branche pourrait ensuite être proposée vers
main.

    git switch -c maintenance/align-main-with-dev origin/main
    PRE_DEV_SHA=$(git rev-parse origin/dev)
    git merge --no-ff --no-commit "$PRE_DEV_SHA"
    git restore --source="$PRE_DEV_SHA" --staged --worktree -- .
    git add -A
    git diff --cached --exit-code "$PRE_DEV_SHA"
    git commit -m "Merge dev history for CI/CD alignment"
    git diff --exit-code "$PRE_DEV_SHA" HEAD

- Commits créés : merge de maintenance, puis merge de PR vers main.
- Impact dev : aucun.
- Impact main : arbre dev et ascendance reliée.
- Future PR : propre après la PR.
- Rollback : tags et PR de restauration ; pas de réécriture.
- Risque : moyen à élevé, car cette PR ne vient pas de dev et serait bloquée par
  le nouveau contrôle Quality. Elle imposerait une exception temporaire
  explicite ou une exécution avant l'activation du contrôle. Option non retenue.

### Option C — PR dev vers main immédiate

Ouvrir directement dev vers main et tenter une fusion. La comparaison affiche
280 commits exclusifs et la fusion à trois voies présente actuellement environ
192 marqueurs de conflit dans la simulation locale. La résolution finirait par
reproduire l'option A, mais dans une PR plus difficile à auditer.

- Commits créés : au moins un merge commit de PR, plus un éventuel merge de
  résolution sur dev.
- Impact : risque de toucher dev et main pendant la résolution.
- Rollback : tags et PR de restauration.
- Risque : élevé ; option non recommandée.

Un reset de main sur dev, un force-push, un rebase global, un squash de retour
ou une stratégie Git ours sont exclus.

## Branches après nettoyage

Après alignement et validation séparée :

1. fermer #17 comme obsolète, car sa tête est exactement le main sauvegardé ;
2. traiter chaque PR Dependabot selon la politique et la CI, sans supprimer sa
   branche manuellement avant résolution ;
3. supprimer seulement les trois branches historiques marquées candidates,
   après re-vérification des tags et des arbres ;
4. créer work depuis un dev propre.

Commandes documentaires pour work, non exécutées pendant la phase 1 :

    git switch dev
    git pull --ff-only origin dev
    git switch -c work
    git push -u origin work

Rôles cibles :

- work : développement quotidien et changements Codex, PR manuelle vers dev,
  Quality obligatoire, aucun déploiement ;
- dev : intégration de work et Dependabot, puis promotion contrôlée ;
- main : production, source normale exclusivement dev, aucun push direct.

## Dependabot et auto-merge

Les quatre écosystèmes ciblent explicitement `dev` et proposent les trois
niveaux semver : patch, minor et major. Les groupes rassemblent uniquement les
patches et les minors ; les majors restent des PR séparées afin de rendre leur
portée évidente. Les plafonds de PR ouvertes sont 10 pour Composer, 10 pour npm,
5 pour Docker et 5 pour GitHub Actions : ces limites empêchent qu'une major soit
masquée par des groupes patch ou minor déjà ouverts. Chaque PR est assignée à
`Nerpp` et reçoit exactement un label de niveau parmi `patch`, `minor` et
`major`, en plus des labels d'écosystème.

| Niveau | PR créée | Assignation | Quality | Auto-merge | Fusion |
| --- | --- | --- | --- | --- | --- |
| Patch | Oui | Nerpp | Obligatoire | Possible après activation | Automatique possible |
| Minor | Oui | Nerpp | Obligatoire | Non | Manuelle |
| Major | Oui | Nerpp | Obligatoire | Non | Manuelle avec migration |

Exemples : `8.1.0 → 8.1.1` est un patch, `8.0 → 8.1` est une mineure et
`8 → 9` est une majeure. Les mises à jour mineures et majeures sont visibles,
mais ne sont jamais auto-fusionnées. Pour une PR groupée, le niveau global est
le plus élevé de toutes les mises à jour directes et transitives. Ainsi, un
patch direct qui entraîne un minor dans `composer.lock` ou `package-lock.json`
classe toute la PR en minor ; un major transitive la classe en major.

Le workflow `Dependabot policy` utilise `pull_request_target`, mais checkout
uniquement le SHA immuable de la base et n'exécute aucun fichier de la branche
Dependabot. Il :

1. exige l'acteur et l'auteur `dependabot[bot]`, une branche Dependabot interne,
   une base `dev` et une PR non draft ;
2. conserve la vérification d'identité et de signatures de
   `dependabot/fetch-metadata@v3` ;
3. lit les manifests et lockfiles aux SHA exacts via l'API GitHub, puis recalcule
   le niveau des changements directs et transitifs avec un script sans réseau ;
4. compare ce résultat aux métadonnées agrégées, et échoue de manière sûre en
   cas de métadonnées absentes, incohérentes ou de version non interprétable ;
5. refuse les changements de mainteneur et les chemins inattendus ;
6. limite GitHub Actions aux lignes `uses:` et Docker aux références d'image ;
7. applique le label global unique et l'assignation même quand l'auto-merge est
   désactivé ;
8. n'autorise le job d'auto-merge que pour un résultat global `patch` valide ;
9. revérifie la tête, la mergeabilité et les règles effectives de `dev`, qui
   doivent imposer Quality et le merge commit ;
10. demande uniquement un auto-merge `MERGE` avec la GitHub App, puis laisse
    Quality et le ruleset décider de la fusion réelle.

Les PR minor nécessitent une revue des notes de version et une validation
manuelle. Les PR major exigent en plus une branche de migration dédiée et un
plan de compatibilité. Une Quality verte est nécessaire mais ne remplace jamais
ces revues humaines.

La variable `DEPENDABOT_AUTOMERGE_ENABLED` absente ou différente de `true`
empêche entièrement le job d'auto-merge, y compris la création du jeton GitHub
App. La classification et l'assignation restent actives. L'état initial et
actuel est désactivé : aucun secret n'est créé et aucun déploiement n'est lié à
ce workflow.

Le `GITHUB_TOKEN` écrit uniquement le label et l'assignation de la PR existante.
Il n'est jamais utilisé pour demander une fusion ni pour créer une PR. GitHub
documente que ses événements ne déclenchent généralement pas de nouveaux
workflows et que les PR créées avec ce token peuvent nécessiter une approbation
manuelle des runs. La GitHub App dédiée produit des événements d'application
normaux pour les opérations de fusion et de promotion. Références :

- https://docs.github.com/en/actions/concepts/security/github_token
- https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/making-authenticated-api-requests-with-a-github-app-in-a-github-actions-workflow
- https://github.com/dependabot/fetch-metadata

Le workflow Dependabot doit lire les deux identifiants depuis les secrets
Dependabot, car un événement initié par Dependabot n'expose pas les secrets
Actions. Le même couple de noms est utilisé séparément dans les secrets Actions
pour la promotion :

- PROMOTION_GITHUB_APP_ID ;
- PROMOTION_GITHUB_APP_PRIVATE_KEY.

Aucune valeur ne doit être placée dans le dépôt ou dans les logs.

## Promotion dev vers main

Le workflow Promote dev to main écoute uniquement la fin réussie du workflow CI
sur un push dev. Il reste inerte tant que PROMOTION_ENABLED n'est pas exactement
true ; la valeur cible initiale est false.

Le préflight en lecture seule :

- vérifie le chemin du workflow CI, le dépôt, l'événement push, dev et le SHA ;
- vérifie que main exige uniquement Quality et les merge commits ;
- ignore un succès devenu obsolète si dev a avancé ;
- exige PROMOTION_BASE_SHA et vérifie qu'il est ancêtre de dev et main ;
- refuse tout commit main exclusif qui ne serait pas un merge de promotion dont
  le second parent appartient à dev ;
- compare les tree SHA et ne crée rien s'ils sont identiques ;
- exige des commits dev réellement nouveaux ;
- recherche l'unique PR ouverte dev vers main et refuse les doublons.

Après ce préflight seulement, la GitHub App crée la PR si elle n'existe pas,
inclut le SHA dev dans son corps, revalide la tête, puis demande un auto-merge
MERGE. La PR déclenche Quality. Le push du merge commit réel sur main déclenche
une nouvelle CI grâce au déclencheur push main. Aucun merge direct, aucune
branche work ou Dependabot vers main, et aucune boucle main vers dev ne sont
créés.

Activation future, dans cet ordre :

1. terminer l'alignement et vérifier les tags ;
2. consolider les rulesets ;
3. installer la GitHub App sur ce seul dépôt ;
4. créer les secrets Actions et Dependabot sans les afficher ;
5. définir PROMOTION_BASE_SHA sur le SHA main exact rattaché à dev lors de
   l'alignement recommandé ;
6. conserver PROMOTION_ENABLED=false et
   DEPENDABOT_AUTOMERGE_ENABLED=false pendant un dernier audit ;
7. activer explicitement chaque variable après validation humaine.

Une alternative PAT n'est pas activée. Si elle était ultérieurement imposée,
seul un fine-grained PAT dédié au dépôt, nommé CI_AUTOMATION_TOKEN, serait
acceptable. Un PAT classique est exclu et nécessiterait une modification
explicite des workflows.

## CI et build de production

Le check requis conserve le nom exact Quality. Ses événements cibles sont :

- push dev ;
- push main ;
- pull_request vers dev ;
- pull_request vers main ;
- workflow_dispatch.

Une PR vers main échoue dans Quality si sa tête n'est pas dev. Le job ne possède
que contents: read et ne référence aucun secret.

Le script scripts/check-production-build.sh crée un répertoire temporaire,
copie uniquement les fichiers suivis ou nouveaux non ignorés, puis exécute :

    composer validate --strict
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --classmap-authoritative
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod
    APP_ENV=prod APP_DEBUG=0 php bin/console lint:container

Il utilise un transport mail null, une adresse example.invalid, une base locale
injoignable sur le port 1 et un APP_SECRET explicitement réservé au build. Il
n'exécute aucune requête, migration, fixture, notification ou clé de vault.

Le script prouve aussi que symfony/process est installé et que
doctrine-fixtures-bundle est absent. Les fixtures sont exclues de services.yaml
et enregistrées seulement par services_dev.yaml et services_test.yaml.

La CI conserve npm ci, npm run build et la vérification de
public/build/manifest.json. public/build reste non versionné. Un futur job de
release pourra l'inclure dans un artefact immuable nommé avec le SHA main, mais
aucun artefact de déploiement n'est publié pendant cette phase.

## Médias et contexte Docker

Les données persistantes concernent principalement :

- public/uploads/avatars ;
- public/uploads/media, dont 360, variants, posters et video-thumbnails.

.dockerignore exclut ces chemins, les env locaux, les clés privées du vault,
config/production/initial-admins.yaml, les sauvegardes, dumps, logs, caches et
couvertures. public/uploads/demo n'est pas exclu : ses démonstrations
volontairement versionnées restent dans le contexte.

Architecture cible Infomaniak :

    releases/
      <release-id>/
    shared/
      public/
        uploads/
    current -> releases/<release-id>

Chaque release doit lier current/public/uploads vers
shared/public/uploads. Un futur rsync avec suppression ne doit jamais appliquer
--delete au contenu partagé. Les médias doivent avoir une sauvegarde dédiée ;
Git et l'artefact applicatif ne sont pas des sauvegardes de médias.

Aucun lien symbolique local ou distant n'est créé dans cette phase.

## Rulesets GitHub cibles

### Interface GitHub

Dans Settings, Rules, Rulesets :

1. créer Protect integration dev pour refs/heads/dev ;
2. enforcement Active, aucun bypass actor ;
3. interdire deletion et non-fast-forward ;
4. exiger une PR, zéro approbation obligatoire, résolution des conversations,
   méthode de fusion merge uniquement ;
5. exiger le status check exact Quality fourni par GitHub Actions, avec branche
   à jour ;
6. modifier Protect production main, identifiant actuel 18447943, avec les
   mêmes règles sur refs/heads/main ;
7. vérifier qu'une PR autre que dev vers main échoue dans Quality ;
8. désactiver puis supprimer seulement après vérification le ruleset main
   redondant 18656732 qui exige CI / test-all.

Le zéro approbation sur dev est délibéré pour permettre Dependabot. La
validation humaine de work reste une règle de processus. La GitHub App ne doit
pas être ajoutée comme bypass actor : elle demande l'auto-merge, mais subit
Quality et toutes les protections.

GitHub ne fournit pas dans ce ruleset de filtre universel simple sur la branche
source d'une PR. La source dev vers main est donc aussi imposée par l'étape
Quality, et par le workflow de promotion.

### API GitHub

Droits requis : administration du dépôt en écriture pour les rulesets. Préférer
GitHub CLI authentifié ou une GitHub App d'administration temporaire et ciblée ;
ne pas utiliser de PAT classique.

Corps cible pour dev :

~~~json
{
  "name": "Protect integration dev",
  "target": "branch",
  "enforcement": "active",
  "bypass_actors": [],
  "conditions": {
    "ref_name": {
      "include": ["refs/heads/dev"],
      "exclude": []
    }
  },
  "rules": [
    {"type": "deletion"},
    {"type": "non_fast_forward"},
    {
      "type": "pull_request",
      "parameters": {
        "required_approving_review_count": 0,
        "dismiss_stale_reviews_on_push": false,
        "required_reviewers": [],
        "require_code_owner_review": false,
        "require_last_push_approval": false,
        "required_review_thread_resolution": true,
        "allowed_merge_methods": ["merge"]
      }
    },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": true,
        "do_not_enforce_on_create": false,
        "required_status_checks": [
          {"context": "Quality", "integration_id": 15368}
        ]
      }
    }
  ]
}
~~~

Le corps main est identique avec le nom Protect production main et
refs/heads/main. Commandes futures :

    gh api --method POST repos/Nerpp/blog_tourisme/rulesets --input dev-ruleset.json
    gh api --method PUT repos/Nerpp/blog_tourisme/rulesets/18447943 --input main-ruleset.json
    gh api repos/Nerpp/blog_tourisme/rules/branches/dev
    gh api repos/Nerpp/blog_tourisme/rules/branches/main
    gh api --method DELETE repos/Nerpp/blog_tourisme/rulesets/18656732

La dernière commande n'est permise qu'après confirmation que Quality apparaît
sur une PR test, que le nouveau ruleset main est actif et que CI / test-all
n'est plus le seul verrou.

## GitHub App et droits

Créer une GitHub App dédiée, installée uniquement sur Nerpp/blog_tourisme :

- Metadata : read, implicite ;
- Contents : write, nécessaire à l'auto-merge ;
- Pull requests : write ;
- aucun droit Actions, Checks, Secrets, Environments ou Deployments.

La clé privée n'est jamais stockée dans Git. Les secrets Actions alimentent la
promotion ; les secrets Dependabot de mêmes noms alimentent le workflow
Dependabot. La rotation et la révocation doivent être documentées.

## Future production Infomaniak

Aucun deploy.yml actif ne doit exister avant confirmation de :

- hôte et port SSH ;
- utilisateur SSH ;
- chemin absolu du site et document root ;
- version et chemin du binaire PHP ;
- disponibilité et version de Composer ;
- disponibilité de rsync ;
- moteur et version MariaDB/MySQL ;
- commandes et rétention des sauvegardes ;
- extensions PHP, notamment GD, EXIF, Intl, mbstring et PDO MySQL ;
- création et bascule atomique des symlinks ;
- comportement et invalidation OPcache ;
- URL et contrat du health check.

Fichiers futurs envisagés, non créés :

- .github/workflows/deploy-production.yml ;
- scripts/deploy/prepare-release.sh ;
- scripts/deploy/backup-database.sh ;
- scripts/deploy/health-check.sh ;
- scripts/deploy/rollback.sh.

Le workflow futur devra :

1. écouter uniquement une CI réussie sur le SHA réellement présent sur main ;
2. utiliser l'Environment GitHub production ;
3. appliquer une concurrence unique et cancel-in-progress: false ;
4. télécharger l'artefact exact nommé avec le SHA main et vérifier son hash ;
5. utiliser SSH avec known_hosts épinglé, sans StrictHostKeyChecking=no ;
6. sauvegarder la base et vérifier la sauvegarde avant toute migration ;
7. créer une nouvelle release sans dépendances dev avec les assets déjà bâtis ;
8. lier les uploads partagés ;
9. compiler le cache et contrôler les migrations ;
10. migrer de façon contrôlée ;
11. basculer current atomiquement si l'hébergement le permet ;
12. vérifier la santé ;
13. revenir au lien précédent en cas d'échec.

Secrets futurs, sans valeur dans ce document :

- INFOMANIAK_SSH_PRIVATE_KEY ;
- INFOMANIAK_DB_PASSWORD ;
- éventuellement la clé de déchiffrement Symfony, selon le mécanisme retenu.

Variables futures :

- INFOMANIAK_SSH_HOST ;
- INFOMANIAK_SSH_PORT ;
- INFOMANIAK_SSH_USER ;
- INFOMANIAK_SSH_KNOWN_HOSTS ;
- INFOMANIAK_DEPLOY_PATH ;
- INFOMANIAK_DOCUMENT_ROOT ;
- INFOMANIAK_PHP_BINARY ;
- INFOMANIAK_DB_HOST, INFOMANIAK_DB_PORT, INFOMANIAK_DB_NAME et
  INFOMANIAK_DB_USER ;
- PRODUCTION_HEALTHCHECK_URL ;
- RELEASE_RETENTION_COUNT.

Les informations doivent être confirmées sans demander ni afficher leurs
valeurs sensibles pendant l'audit.

## Ordre de mise en service

1. Revue des modifications locales de phase 1.
2. Commit et PR manuels séparés, uniquement après validation explicite.
3. Tags de sauvegarde.
4. Alignement dev/main.
5. Fermeture validée de #17 et nettoyage séparé des branches prouvées.
6. Rulesets dev/main consolidés.
7. GitHub App, secrets et variables créés manuellement.
8. Activation Dependabot, observation sur une PR patch.
9. Activation promotion, observation d'une PR dev vers main.
10. Création de work depuis dev.
11. Audit Infomaniak puis phase de déploiement distincte.

La phase 1 n'autorise aucune de ces mutations distantes.

## Alignement d'ascendance dev/main — 14 juillet 2026

- SHA `dev` sauvegardé : `a7d1d684e84f778678ac3df80fcb8106ab90fd1e` ;
- SHA `main` sauvegardé : `001d5f31dcfd6b7fae75b478d15fdffce3ef6fde` ;
- tags : `backup/pre-alignment-dev-20260714` et
  `backup/pre-alignment-main-20260714` ;
- méthode : merge Git réel de `main` dans une branche issue de `dev`, sans
  réécriture d'historique, avec résolution explicite des conflits historiques ;
- commit de raccordement : `2f1994fe9bb7e2662bdaa7af4742c814d79e0587` ;
- aucun fichier applicatif n'a changé dans le commit de raccordement ;
- tree conservé avant et après le merge :
  `357a28cc7a87c883d66fabf635117882c5505860`.

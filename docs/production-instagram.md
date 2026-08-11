# Publication Instagram automatique en production

La publication Instagram des randonnées et visites de ville conserve son architecture asynchrone Symfony Messenger. La requête Studio crée le snapshot et tente d’ajouter un message au transport Doctrine `instagram_async`, sans jamais contacter Meta. Sur l’Hébergement Web mutualisé Infomaniak, un WebCron HTTPS réconcilie ensuite les tâches en attente et consomme la file pendant une fenêtre bornée.

Le transport `failed` reste séparé et n’est jamais consommé ni relancé automatiquement par le WebCron.

## Prérequis Infomaniak

- `INSTAGRAM_USER_ID` reste une variable d’environnement non secrète.
- `INSTAGRAM_ACCESS_TOKEN` reste uniquement dans le Symfony Secrets Vault de production.
- `INSTAGRAM_CRON_SECRET` est un secret distinct, aléatoire, d’au moins 32 caractères sans espace ni caractère de contrôle.
- `APP_PUBLIC_URL` doit être une origine HTTPS publiquement accessible par Meta.
- Dans **Manager > Hébergement Web > Gérer les paramètres avancés > PHP / Apache**, régler `max_execution_time` à **300 secondes**. Infomaniak documente 300 secondes comme maximum de l’offre mutualisée : <https://faq.infomaniak.com/2158>.

Créer le secret via une saisie interactive, sans valeur sur la ligne de commande :

```bash
php bin/console secrets:set INSTAGRAM_CRON_SECRET --env=prod
```

Ne jamais afficher les valeurs de ces variables dans une commande de diagnostic, un journal ou une capture. Le déploiement vérifie uniquement la présence du nom du secret chiffré.

## Migration additive

La migration `Version20260811180000` ajoute uniquement `lock_keys`, la table partagée du composant Symfony Lock. Elle ne modifie aucune table, publication, lot, média ou tâche Messenger Instagram existante.

Le WebCron ne doit pas être activé avant l’application de cette migration :

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
```

## Endpoint WebCron

- URL : `https://estela-exploration.fr/_internal/cron/instagram`
- Fréquence : toutes les **15 minutes**
- Authentification : HTTP Basic dédiée
- Nom d’utilisateur public : `estela-instagram-cron`
- Mot de passe : valeur de `INSTAGRAM_CRON_SECRET`
- Réponse acceptée : HTTP 200 contenant `"accepted":true`

La documentation Infomaniak garantit la configuration d’une URL protégée par mot de passe et une fréquence minimale de 15 minutes en mutualisé, mais ne documente ni header personnalisé, ni choix de méthode HTTP, ni timeout du client WebCron, ni schéma Basic/Digest effectivement négocié par ce client : <https://faq.infomaniak.com/2161>. Infomaniak documente par ailleurs plusieurs protections par mot de passe selon le contexte, ce qui ne constitue pas une garantie propre au WebCron : <https://www.infomaniak.com/fr/support/faq/68/securiser-lacces-web-par-mot-de-passe>.

Pour cette raison, l’endpoint accepte GET, utilisé par le planificateur d’URL Infomaniak, ainsi que POST pour un appel machine compatible. PUT, PATCH et DELETE sont refusés. Le secret est transmis par HTTP Basic et jamais dans l’URL. L’endpoint est stateless, HTTPS obligatoire, exclu du profiler et renvoie systématiquement `Cache-Control: no-store`.

### Création dans le Manager

1. Ouvrir **Hébergement Web > Outils avancés > Planificateur de tâches**.
2. Cliquer sur **Planifier une tâche** et saisir l’URL HTTPS exacte ci-dessus.
3. Indiquer que l’URL est protégée par mot de passe.
4. Saisir `estela-instagram-cron` comme utilisateur et la valeur du secret cron comme mot de passe.
5. Choisir une exécution toutes les 15 minutes.
6. Activer les notifications et l’analyse de réponse.
7. Configurer l’analyse pour exiger le marqueur `"accepted":true`.
8. Lors du premier déploiement, laisser la tâche désactivée jusqu’au test HEAD décrit ci-dessous ; l’activer ensuite, lancer une exécution manuelle et vérifier son journal.

Si le Manager affiche un choix explicite de méthode, sélectionner POST. À défaut, conserver l’appel d’URL GET documenté ci-dessus. Ne jamais ajouter le secret à la query string.

## Test sans publication

HEAD authentifie et valide la configuration, mais ne prend aucun verrou, ne réconcilie aucune publication et ne consomme aucun message :

```bash
curl --silent --show-error --head --user 'estela-instagram-cron' \
  https://estela-exploration.fr/_internal/cron/instagram
```

`curl` demande le mot de passe interactivement. Ne pas l’ajouter après le nom d’utilisateur dans la commande. La réponse attendue est HTTP 204 avec `Cache-Control: no-store`.

Si ce HEAD renvoie `https_required` alors que l’URL publique est bien en HTTPS, vérifier avec Infomaniak la terminaison TLS et configurer uniquement des proxies de confiance via `SYMFONY_TRUSTED_PROXIES`. Ne pas contourner la vérification en faisant confiance à un header `X-Forwarded-Proto` arbitraire.

Une exécution GET/POST correctement authentifiée peut publier les tâches réellement présentes. Ne l’utiliser comme test que lorsque ce comportement est voulu.

## Traitement borné et reprise

Chaque appel accepté :

1. acquiert un verrou partagé global ;
2. réconcilie les publications `pending` ou `processing` abandonnées avec la logique de `app:instagram:dispatch-pending` ;
3. consomme uniquement `instagram_async` ;
4. traite au maximum 5 enveloppes et n’en démarre plus après 240 secondes ;
5. vérifie avant chaque appel Meta qu’une marge suffisante reste disponible ;
6. sauvegarde les containers reçus et remet proprement le lot en `pending` si la marge est insuffisante ;
7. libère le verrou et renvoie une réponse minimale.

La borne temporelle est coopérative : un appel HTTP Meta déjà commencé n’est pas interrompu brutalement. Chaque requête Meta possède néanmoins son propre timeout, et une marge de 120 secondes est exigée avant une séquence de polling. Le prochain WebCron reprend les containers et lots déjà checkpointés sans republier un lot `published`.

Deux appels WebCron concurrents ne consomment jamais simultanément la file : le second renvoie un succès court avec l’état `busy`. Les verrous et protections Doctrine du handler restent la dernière défense contre une redélivrance Messenger.

## Exploitation

Consulter les files sans exposer leur contenu sensible :

```bash
php bin/console messenger:stats --env=prod --no-debug
php bin/console messenger:failed:show --env=prod --no-debug
```

Pour une publication qui reste `pending`, vérifier successivement le journal WebCron, `messenger:stats`, l’accessibilité HTTPS des médias et la validité du token Meta, sans copier de secret dans les journaux.

Le reconciler CLI reste disponible en secours par SSH :

```bash
php bin/console app:instagram:dispatch-pending \
  --env=prod \
  --no-debug \
  --limit=50 \
  --pending-age=300 \
  --processing-age=1800
```

Les messages du transport `failed` ont déjà épuisé leurs retries. Ils restent examinables et ne doivent être relancés qu’après diagnostic explicite :

```bash
php bin/console messenger:failed:retry --env=prod --no-debug
```

Le bouton Studio continue de ne renvoyer que les lots métier manquants.

## Déploiement du correctif

1. Sauvegarder la base.
2. Créer `INSTAGRAM_CRON_SECRET` dans le Vault prod avant le déploiement, car la CI vérifie son nom.
3. Désactiver l’ancien cron CLI toutes les cinq minutes et toute supervision/redémarrage du worker permanent. Un éventuel processus encore actif peut être arrêté proprement avec `php bin/console messenger:stop-workers --env=prod --no-debug`.
4. Déployer le code et les dépendances.
5. Appliquer la migration additive.
6. Vider puis réchauffer le cache prod.
7. Régler `max_execution_time` à 300 secondes dans le Manager.
8. Créer le WebCron sans l’activer.
9. Valider l’endpoint avec HEAD.
10. Activer le WebCron et lancer une exécution manuelle.
11. Vérifier le journal WebCron, `messenger:stats` et les publications déjà en attente.

Les lignes `InstagramPublication` et les messages `messenger_messages` existants sont repris naturellement. Il ne faut vider aucune file, réinitialiser aucun statut ni recréer de snapshot.

La création de `lock_keys` utilise `IF NOT EXISTS` uniquement pour rendre le déploiement tolérant si Symfony Lock l’a créée dans une très courte course avant l’enregistrement de la migration. Le schéma créé est exactement celui du store Doctrine Symfony ; aucune table Instagram ou Messenger n’est concernée.

## Limite à qualifier en production

Infomaniak ne publie ni le timeout HTTP exact de son client WebCron ni son schéma d’authentification négocié. `max_execution_time=300` borne PHP, mais ne prouve pas que le client attendra 240 secondes. La première exécution doit donc confirmer dans le journal du planificateur que HTTP Basic est accepté et que l’appel va à son terme ; en cas d’échec d’authentification ou de coupure plus courte, demander confirmation au support Infomaniak. Les checkpoints rendent une coupure récupérable, mais une qualification réelle reste nécessaire avant de déclarer le délai ou l’authentification garantis par l’infrastructure.

Pour un retour arrière, désactiver d’abord le WebCron. Conserver `lock_keys`, les trois tables Instagram et `messenger_messages` jusqu’à vérification qu’aucun traitement n’est actif ou en attente.

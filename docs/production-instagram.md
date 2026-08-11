# Publication Instagram automatique en production

La publication Instagram des randonnées et visites de ville passe par Symfony Messenger. Le transport Doctrine `instagram_async` constitue la file durable et `failed` reçoit les messages dont les tentatives automatiques sont épuisées. Les requêtes HTTP de publication Estela ne contactent jamais Meta.

## Configuration

- `INSTAGRAM_USER_ID` est une variable d’environnement non secrète.
- `INSTAGRAM_ACCESS_TOKEN` doit exister uniquement dans le Symfony Secrets Vault de production.
- `APP_PUBLIC_URL` doit être une origine HTTPS publiquement accessible par Meta.

Ne jamais afficher les valeurs de ces variables dans une commande de diagnostic, un log ou une capture du profiler. Le déploiement vérifie uniquement la présence du nom du secret chiffré.

La migration `Version20260811120000` crée les tables d’historique Instagram et la table `messenger_messages`. Le transport utilise `auto_setup: false` : la migration doit donc être appliquée avant le démarrage du worker.

## Worker obligatoire

Infomaniak doit superviser en permanence cette commande depuis la racine de l’application :

```bash
php bin/console messenger:consume instagram_async --env=prod --no-debug --keepalive=30 --time-limit=3600 --memory-limit=128M
```

Le keepalive renouvelle le verrou du message pendant un contenu comportant de nombreux lots. Le processus doit être redémarré automatiquement à sa sortie. Le déploiement exécute `messenger:stop-workers` afin qu’un worker supervisé recharge le nouveau code. Sans processus supervisé, les lignes restent en `pending` et aucune publication automatique n’atteint Instagram.

Ajouter également une tâche cron toutes les cinq minutes :

```bash
php bin/console app:instagram:dispatch-pending --env=prod --no-debug
```

Ce reconciler couvre une indisponibilité de la file au moment du commit et un worker interrompu après avoir réclamé une tâche. La racine persistée et les identifiants de conteneurs déjà reçus rendent les redélivrances sûres au mieux de ce que permet l’API Meta.

## Exploitation

Consulter les files sans exposer le contenu sensible :

```bash
php bin/console messenger:stats --env=prod --no-debug
php bin/console messenger:failed:show --env=prod --no-debug
```

Après analyse d’une panne temporaire, les messages échoués peuvent être relancés :

```bash
php bin/console messenger:failed:retry --env=prod --no-debug
```

L’interface Studio offre aussi un renvoi métier : elle ne recrée jamais les lots déjà marqués publiés. Avant une nouvelle tentative, vérifier l’accessibilité HTTPS des médias et la validité du jeton sans jamais copier celui-ci dans les journaux.

## Déploiement et retour arrière

1. sauvegarder la base ;
2. déployer le code et les dépendances ;
3. appliquer les migrations ;
4. réchauffer le cache ;
5. redémarrer le worker supervisé ;
6. vérifier `messenger:stats` et une fiche Studio publiée.

Un retour arrière du code ne doit pas supprimer les trois tables d’historique ni `messenger_messages` : elles contiennent les preuves anti-doublon et les tâches en attente. Les supprimer pourrait provoquer une republication involontaire.

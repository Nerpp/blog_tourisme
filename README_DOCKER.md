# Environnement Docker Symfony + Vite

Ce projet utilise Symfony 8.0 stable, PHP 8.4 FPM, Nginx, MySQL 8.0, phpMyAdmin, Mailpit, Node.js, Vite, Vue 3 et Vuetify.

Webpack Encore n'est pas utilise dans ce projet.

## Lancer le projet

```bash
docker compose up -d --build
```

Au premier demarrage, le conteneur PHP lance `composer install` si le dossier `vendor/` n'existe pas. Le service Node lance `npm install`, puis `npm run dev`.

Les services PHP et Node utilisent `DOCKER_UID` et `DOCKER_GID` depuis `.env` pour eviter de creer des fichiers appartenant a `root` sur l'hote.

## Acces locaux

- Application Symfony : http://localhost:8080
- phpMyAdmin : http://localhost:8081
- Mailpit : http://localhost:8025
- Vite dev server : http://localhost:5173

## Entrer dans le conteneur PHP

```bash
docker compose exec php bash
```

## Commandes Composer

```bash
docker compose exec php composer install
docker compose exec php composer require vendor/package
```

Ne pas installer `symfony/webpack-encore-bundle`.

## Commandes npm

```bash
docker compose run --rm node npm install
docker compose run --rm node npm run dev
docker compose run --rm node npm run build
```

Le serveur Vite est expose sur le port `5173`.

## Base de donnees

La configuration Docker utilise :

```dotenv
DATABASE_URL=mysql://app:app@mysql:3306/app?serverVersion=8.0&charset=utf8mb4
```

La base `app` est creee automatiquement par MySQL au premier demarrage du volume.

Pour creer la base manuellement si necessaire :

```bash
docker compose exec php php bin/console doctrine:database:create --if-not-exists
```

Pour lancer les migrations :

```bash
docker compose exec php php bin/console doctrine:migrations:migrate
```

## Emails locaux

Symfony Mailer est configure avec :

```dotenv
MAILER_DSN=smtp://mailpit:1025
```

Les emails sont consultables dans Mailpit : http://localhost:8025

## Assets Vite

En developpement, Twig charge les scripts depuis le serveur Vite :

```dotenv
VITE_DEV_SERVER_URL=http://localhost:5173
```

En production, generer les assets :

```bash
docker compose run --rm node npm run build
```

Le build Vite produit `public/build/manifest.json`, lu par le helper Twig `vite_entry_script_tags()` et `vite_entry_link_tags()`.

## Arreter les conteneurs

```bash
docker compose down
```

Pour supprimer aussi les volumes Docker locaux :

```bash
docker compose down -v
```

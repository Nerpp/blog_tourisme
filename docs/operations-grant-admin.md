# Attribution de ROLE_ADMIN en SSH

La commande `app:user:grant-admin` ajoute uniquement `ROLE_ADMIN` à un compte existant. Elle est réservée à l’environnement `prod` et fonctionne en simulation par défaut.

L’utilisateur doit :

- s’être connecté au moins une fois afin que son compte existe ;
- avoir une adresse e-mail vérifiée ;
- ne pas être banni.

## Simulation

```bash
php bin/console app:user:grant-admin adresse@example.com \
  --env=prod \
  --no-debug
```

Aucune donnée ni trace d’audit n’est créée pendant la simulation.

## Application

```bash
php bin/console app:user:grant-admin adresse@example.com \
  --apply \
  --env=prod \
  --no-debug
```

L’application conserve tous les rôles existants, ajoute uniquement `ROLE_ADMIN` et crée la trace d’audit dans la même transaction. Un compte déjà administrateur reste inchangé et ne génère pas de nouvel audit. Un super-administrateur n’est pas modifié, car la hiérarchie des rôles lui accorde déjà les droits administratifs.

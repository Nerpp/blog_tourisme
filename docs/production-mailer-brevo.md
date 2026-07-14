# Envoi transactionnel Brevo en production

Cette configuration concerne uniquement l'environnement Symfony `prod`. Le développement continue d'utiliser Mailpit et les tests utilisent leur transport isolé. Aucun identifiant Brevo ne doit être ajouté au dépôt, aux fichiers `.env`, aux fichiers Compose, aux images Docker ou aux journaux.

## Préparer Brevo

1. Se connecter au compte Brevo et ouvrir la rubrique **SMTP et API**.
2. Sélectionner les paramètres SMTP et relever le nom d'utilisateur SMTP.
3. Générer une clé SMTP dédiée à ce site. Une clé API ne doit pas être utilisée comme mot de passe SMTP.
4. Créer ou vérifier dans Brevo l'expéditeur réellement utilisé par le site.
5. Authentifier le domaine d'envoi.
6. Ajouter chez le fournisseur DNS les enregistrements exactement fournis par Brevo.
7. Vérifier DKIM dans Brevo et configurer DMARC selon les recommandations adaptées au domaine. Les valeurs DNS ne sont pas génériques et ne doivent pas être inventées.

L'application utilise actuellement `MAILER_FROM` comme adresse d'expédition. Sa valeur de développement est locale et ne convient pas à la production. Le futur environnement de production devra fournir une adresse appartenant à un expéditeur ou à un domaine validé dans Brevo. Cette adresse publique n'a pas besoin d'être placée dans le coffre-fort, mais elle ne doit pas être inventée avant le choix du domaine.

## Construire le DSN sans l'exposer

Le transport attendu est `brevo+smtp`, sous la forme schématique `brevo+smtp://<identifiant SMTP encodé>:<clé SMTP encodée>@default`. Les deux composants doivent être encodés pour une URL si leur valeur contient des caractères réservés. La valeur complète ne doit être conservée que dans un gestionnaire de secrets ou saisie directement dans la commande interactive Symfony ci-dessous.

Ne jamais passer la valeur du DSN comme argument de ligne de commande. Depuis un terminal privé, lancer :

```bash
docker compose exec php \
    php bin/console secrets:set BREVO_MAILER_DSN --env=prod
```

Lors de la toute première initialisation, Symfony peut refuser de démarrer le conteneur `prod` puisque ce secret n'existe pas encore. Dans ce seul cas, fournir un transport nul non sensible uniquement au processus de création :

```bash
docker compose exec \
    -e BREVO_MAILER_DSN=null://null \
    php php bin/console secrets:set BREVO_MAILER_DSN --env=prod
```

Cette valeur temporaire n'est pas enregistrée dans le coffre-fort ; la commande demande toujours interactivement la véritable valeur à chiffrer. Saisir celle-ci uniquement à l'invite. Vérifier ensuite la présence du nom du secret, sans le révéler :

```bash
docker compose exec php \
    php bin/console secrets:list --env=prod
```

Ne jamais utiliser `--reveal`, `secrets:reveal` ou `secrets:decrypt-to-local` dans le workflow normal.

## Déployer le coffre-fort Symfony

Les fichiers chiffrés de `config/secrets/prod` et la clé publique peuvent être versionnés. Le fichier `prod.decrypt.private.php` ne doit jamais être versionné, copié dans une image ou inclus dans une archive de déploiement.

La solution recommandée pour le futur hébergeur est d'injecter au runtime :

```text
APP_ENV=prod
APP_DEBUG=0
SYMFONY_DECRYPTION_SECRET=<valeur protégée par la plateforme>
```

Le propriétaire du projet peut convertir localement la clé privée au format attendu avec la commande sensible suivante :

```bash
docker compose exec php php -r \
'echo base64_encode(require "config/secrets/prod/prod.decrypt.private.php"), PHP_EOL;'
```

Cette commande doit être exécutée uniquement par le propriétaire. Sa sortie doit être copiée directement dans le gestionnaire de secrets de l'hébergeur, sans être enregistrée dans un fichier, un terminal partagé, un journal, une capture ou Git.

Une autre possibilité consiste à monter `prod.decrypt.private.php` comme secret ou volume protégé au runtime. Dans ce cas, le fichier doit rester hors de Git et de l'image, avec des permissions restrictives. Cette alternative ne doit être retenue que si la plateforme ne permet pas l'injection de `SYMFONY_DECRYPTION_SECRET`.

## Déploiement et cache

Le futur déploiement doit :

1. rendre disponibles le code et les secrets chiffrés de production ;
2. injecter `APP_ENV=prod`, `APP_DEBUG=0` et le secret de déchiffrement au runtime ;
3. injecter la valeur publique `MAILER_FROM` correspondant à l'expéditeur validé ;
4. autoriser les connexions réseau sortantes nécessaires au transport Brevo ;
5. construire puis réchauffer le cache Symfony :

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

Aucun port SMTP entrant n'est nécessaire. Mailpit reste un service de développement et ne doit pas être déployé comme transport de production.

Les emails sont actuellement envoyés de manière synchrone : aucun routage Messenger de `SendEmailMessage` n'est configuré. Si cette architecture devient asynchrone, il faudra documenter et superviser le transport, les tentatives, la file d'échec et la commande réelle du worker avant le déploiement.

## Vérifications de production

Une fois le secret saisi et le secret de déchiffrement disponible localement :

```bash
docker compose exec php php bin/console lint:container --env=prod
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console cache:warmup --env=prod
docker compose exec php php bin/console secrets:list --env=prod
```

La dernière commande doit rester sans option de révélation.

## Email réel de validation

Aucun email réel ne doit être envoyé sans accord explicite, sans adresse destinataire fournie et sans expéditeur validé. Après autorisation seulement :

```bash
docker compose exec php \
    php bin/console mailer:test ADRESSE_DE_TEST --env=prod
```

Cette commande valide directement le transport Mailer. Elle ne remplace pas un test fonctionnel d'un éventuel workflow Messenger. Après l'envoi autorisé, contrôler les journaux transactionnels Brevo, la réception, le spam, l'expéditeur, le sujet, les liens ainsi que les rendus HTML et texte.

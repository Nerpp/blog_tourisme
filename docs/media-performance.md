# Images publiques et cache HTTP

Les photos standards sont traitées par GD et publiées uniquement en WebP. Le
pipeline conserve les proportions, ne dépasse jamais la largeur de la source et
produit deux groupes de variantes :

- socle responsive : 600, 960, 1600 et 1920 px, qualité WebP configurable via
  `app.media.standard_webp_quality` (78 par défaut) ;
- affichage ciblé : 320, 480, 640, 768 et 960 px, qualités 74 à 80 selon la
  petite dimension finale.

Les images Article utilisent leur pipeline WebP dédié 640/960/1280/1600. Les
panoramas 180°/360°, les vidéos et leurs posters ne passent pas dans le pipeline
standard.

Le nom d’une variante standard dépend de la source, de son contenu, de la
version du pipeline et de la qualité principale. Une régénération après un
changement de compression produit donc une nouvelle URL. Les anciennes variantes
non-WebP sont nettoyées seulement après validation du nouveau jeu ; les anciennes
URL WebP sont conservées pendant la transition. Cela rend le cache `immutable`
sûr sans exposer une page déjà mise en cache à une image supprimée.

Pour auditer sans écrire :

```bash
docker compose exec -T php php bin/console app:media:generate-variants --dry-run
```

Pour régénérer explicitement les anciennes photos standards avec la configuration
courante :

```bash
docker compose exec -T php php bin/console app:media:generate-variants --force
```

Si le master d'origine a déjà été nettoyé, la commande reconstruit tout le jeu
depuis le meilleur WebP standard encore présent (source, grande, moyenne, puis
mobile). Ce WebP reste conservé comme source de régénération : les nouvelles
variantes sont créées et validées avant la mise à jour des chemins en base, sans
suppression immédiate des anciennes URL WebP potentiellement encore en cache.

La commande met à jour les chemins de variantes en base ; le placeholder commun
reste exclusivement un fallback Twig. Ses déclinaisons statiques 480/960/1600
évitent de télécharger le master 2200 px dans les cartes et héros sans image.

Les configurations Nginx Docker et Apache `public/.htaccess` appliquent :

- un an avec `immutable` aux assets Vite hashés et variantes média dont le nom
  dépend du contenu ;
- trente jours sans `immutable` aux trois images statiques auditées
  `estela-star-navbar-44.webp`, `destination-card-placeholder-960.webp` et
  `hero-sea-mountain-mobile.webp`, dont le nom reste stable lors d'un remplacement ;
- sept jours avec revalidation aux autres images statiques non versionnées ;
- aucun cache public long aux réponses Symfony dynamiques ou privées.

Un média remplacé reçoit de nouvelles URL de variantes. Une image statique non
versionnée doit conserver la politique courte ou changer de nom lors d’une
modification incompatible avec le cache existant.

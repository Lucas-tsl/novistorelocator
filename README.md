# NOVI Store Locator

Plugin WordPress qui affiche la carte des points de vente (shortcode `[store_locator]`), alimentée par un fichier CSV ou directement par une feuille Google Sheets.

## Installation et mise à jour

1. Générer le paquet : `bin/build-zip.sh` → `dist/novi_storelocator.zip`.
2. Dans WordPress : **Extensions > Ajouter une extension > Téléverser une extension**, choisir le zip, puis **Remplacer la version installée**.

Ne pas utiliser le bouton « Download ZIP » de GitHub : le dossier s'appellerait `novistorelocator-main` et WordPress installerait un second plugin.

### Passage de la 1.3.x à la 1.4.0

La migration est automatique au premier chargement :

- les réglages (`assets/json/settings/settings.json`) sont repris dans les options WordPress ;
- la liste des magasins est copiée dans `wp-content/uploads/novi-storelocator/stores.json`, hors du dossier du plugin, pour survivre aux futures mises à jour ;
- les fichiers devenus inutiles ou dangereux sont supprimés (`novi_storelocator-save.php`, `old_stores.json`, `stores_original.json`, l'ancien `communes.json` de 21 Mo, et `settings.json`, qui exposait la clé API).

Points à vérifier juste après la mise à jour :

- **Clé MapTiler** : en mise à jour par zip, WordPress efface l'ancien dossier avant la migration, donc la clé est perdue. Une alerte s'affiche dans **Store Locator > Paramètres** : il suffit de la saisir à nouveau. En attendant, la carte utilise les tuiles OpenStreetMap.
- **Magasins** : si l'ancien fichier n'est plus là, le plugin repart de la liste livrée avec lui (`assets/data/stores-seed.json`, état de novembre 2025). Relancez un import ou une synchronisation pour être à jour.
- **Titre de la page** : la 1.3 injectait `.single_post_title.text_left{padding:0}` et `p{margin:0}` sur toute la page. Si l'espacement du titre de la page du store locator change, ajoutez la première règle dans **Apparence > Personnaliser > CSS additionnel**.

## Utilisation

| Shortcode | Effet |
|---|---|
| `[store_locator]` | Recherche, carte et liste des magasins les plus proches |
| `[store_locator results="6"]` | Idem avec 6 résultats au lieu du réglage par défaut |
| `[store_locator theme="dark"]` | Thème sombre sur cette page (le thème clair est le réglage par défaut). Les deux thèmes n'utilisent que du noir, du blanc et des gris |
| `[store_locator largeur="pleine"]` | Toute la largeur de la page (par défaut : largeur « large » du thème ; `largeur="contenu"` pour la largeur du texte) |
| `[store_locator_list]` | Liste HTML complète des magasins, par pays puis par ville (référencement) |

### Mettre à jour les magasins

**Store Locator > Magasins** :

- **Synchroniser avec Google Sheets** : collez l'adresse de la feuille dans les paramètres (partage « Tous les utilisateurs disposant du lien », en lecture). Le bouton « Synchroniser maintenant » affiche un rapport avant la mise en ligne. La synchronisation automatique quotidienne est bloquée si la feuille contient moins de la moitié des magasins en ligne.
- **Importer un fichier CSV** : export « Valeurs séparées par des virgules » de la feuille. Les CSV Excel (point-virgule, Windows-1252) sont aussi acceptés.
- **Rapport d'import** : lignes ignorées et raison, doublons, magasins sans code postal, positions calculées depuis l'adresse. Rien n'est appliqué avant validation.
- **Sauvegardes** : les 10 dernières listes remplacées sont restaurables en un clic.
- **Télécharger la liste en CSV** : export réimportable de la liste en ligne.

### Fiches magasin (référencement local)

Chaque magasin a sa propre adresse : `https://votre-site/page-du-store-locator/?magasin=beauty-success-le-bouscat`.

- Sur le site, « Voir la fiche » ouvre une fenêtre par-dessus la carte (sans changer de page) et met à jour l'adresse. Les boutons Précédent et Suivant fonctionnent, et le lien est partageable.
- Ouverte directement (par Google, un lien partagé…), la page affiche la fiche rendue par le serveur avec un titre, une description, une URL canonique et des données structurées `schema.org/Store` propres au magasin (horaires `openingHoursSpecification`, téléphone, enseigne, coordonnées).
- Les fiches sont ajoutées au plan du site : `wp-sitemap.xml` (WordPress) ou le plan des pages de Yoast SEO. Avec Yoast SEO ou Rank Math, le titre, la description et l'URL canonique leur sont transmis.
- Un identifiant inconnu (magasin retiré) redirige de façon permanente (301) vers la page du store locator.

### Côté visiteur

- **Rechercher dans cette zone** : apparaît quand on déplace ou zoome la carte ; liste les points de vente visibles.
- **Voir plus de points de vente** : sous la liste, ajoute les suivants par ordre de distance.
- **Mémoire** : la dernière recherche est réaffichée au retour sur la page (30 jours), et l'application d'itinéraire choisie (Google Maps, Apple Plans, Waze) est proposée en premier. Ces informations restent dans le navigateur du visiteur.

### Alerte et marque

- **E-mail d'alerte** (Paramètres > Google Sheets) : prévenu si la synchronisation automatique échoue, au plus une fois par jour.
- **Marque vendue** (Paramètres > Référencement) : chaque revendeur est décrit aux moteurs de recherche comme vendant les produits de cette marque.

### Horaires

Colonne `horaires`, en texte libre, une ligne ou un segment par groupe de jours (séparateurs `;`, `|` ou retour à la ligne) :

```
Lundi-Samedi 9h30-19h30 ; Dimanche fermé
Lun au ven 9h-12h 14h-19h | Sam 9h-18h
Tous les jours 10h-20h
```

Ces formats donnent le tableau de la semaine, l'état « Ouvert · ferme à 19h » (heure de Paris) et les horaires dans les données structurées. Un texte non reconnu (« Ouvert selon saison ») est affiché tel quel ; le rapport d'import le signale.

### Colonnes du CSV

La première ligne doit contenir les noms de colonnes. Obligatoires : `name`, `latitude`, `longitude`. Reconnues : `id_store`, `active` (1/oui), `address1`, `address2`, `postcode`, `city`, `country`, `phone`, `website`, `icone` (`signature` = marqueur cuivré et mention « Soins en institut », `rouge` = marqueur rouge). `enseigne` (sinon déduite du nom grâce à la liste des enseignes des paramètres), `services` (séparés par des virgules, ex. « Soins visage, Épilation »). Les autres colonnes sont ignorées.

Les filtres affichés au-dessus de la carte sont construits à partir des enseignes et des services. Téléphone : colonne `phone` ou `telephone` (mis en forme automatiquement, ex. 05 56 08 09 10). Les noms saisis en MAJUSCULES sont affichés en casse lisible (« BEAUTY SUCCESS LE BOUSCAT » → enseigne « Beauty Success », titre « Le Bouscat »).

Pour un magasin français dont la latitude/longitude est vide, la position est calculée à partir de l'adresse via le service public de géocodage de l'IGN (data.geopf.fr), dans la limite de 50 par import.

## Structure

```
novi_storelocator.php      Point d'entrée, constantes, activation
includes/settings.php      Réglages (option novi_sl_settings)
includes/storage.php       Fichier des magasins, sauvegardes, migration 1.3 → 1.4
includes/import.php        Lecture et validation du CSV, géocodage
includes/sync.php          Synchronisation Google Sheets (manuelle et quotidienne)
includes/admin.php         Pages d'administration (actions protégées par nonce + droits)
includes/frontend.php      Shortcodes, chargement des ressources, fiche rendue côté serveur, JSON-LD
includes/store.php         Enseigne, casse des noms, horaires, téléphone, identifiants d'URL
includes/seo.php           Fiches indexables : titre, description, canonique, plan du site
assets/js/storelocator.js  Carte, recherche, géolocalisation
assets/data/communes.min.json  Communes françaises (1,7 Mo, 0,6 Mo compressé)
assets/vendor/             Leaflet 1.9.4 et Leaflet.markercluster 1.5.3 (hébergés localement)
tools/build-communes.py    Régénère communes.min.json depuis la base officielle des codes postaux
```

## Tests

Sur un site de test uniquement (le test de synchronisation remplace la liste des magasins) :

```bash
NOVI_SL_TESTS=1 wp eval-file tests/php/run.php          # back-office : import, réglages, synchronisation
npm i playwright && node tests/e2e/storelocator.mjs "https://site-de-test/page-store-locator/"
```

## Sécurité de la clé MapTiler

La clé est forcément visible dans le code des pages (c'est le cas de toute carte). Limitez-la à votre domaine dans votre compte MapTiler : **Account > API keys > Allowed HTTP origins** (`lessenteursgourmandes.fr`).

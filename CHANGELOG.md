# Journal des modifications

## 1.4.0 — 2026-10-08

### Sécurité
- Les réglages ne peuvent plus être modifiés par une requête POST anonyme envoyée à n'importe quelle page du site (risque d'écrasement de la clé API et d'injection de JavaScript dans la page publique). Ils passent par l'API Settings de WordPress (nonce + droit `manage_options`).
- L'import CSV, la restauration, l'export et la synchronisation vérifient le droit `manage_options` et un nonce (fin du CSRF et de l'accès par les abonnés via `admin-ajax.php`).
- Toutes les sorties sont échappées (admin et public) ; le JavaScript construit le DOM avec `textContent` au lieu de concaténer du HTML ; couleurs validées comme hexadécimales.
- Suppression de `novi_storelocator-save.php` (accessible publiquement, en erreur de syntaxe) et de `settings.json` (clé API lisible publiquement).
- Les CSV envoyés ne sont plus conservés dans la médiathèque.
- Garde `ABSPATH` dans chaque fichier PHP.

### Performance
- Base des communes allégée de 21 Mo à 1,7 Mo (0,6 Mo compressé), téléchargée une seule fois au premier focus sur le champ de recherche (auparavant à chaque recherche).
- Recherche des magasins proches en un seul passage (calcul de distance unique, tri), au lieu de 14 passages par rayon avec des recherches d'index imbriquées.
- Leaflet et le clustering sont hébergés localement et chargés en `defer`, uniquement sur les pages qui utilisent le shortcode (y compris avec les constructeurs de pages).
- Délai de recherche réduit de 1 s à 200 ms.

### Corrections
- Les 270 communes sans coordonnées ne provoquent plus d'erreur JavaScript au clic (corrigées ou retirées de la base).
- Icône « signature » manquante et ombre de marqueur cassée : marqueurs SVG colorés (signature, rouge, couleur par défaut réglable).
- Chemins du plugin calculés par WordPress (`plugin_dir_url`) : fonctionne quel que soit le dossier d'installation (l'ancien code supposait un dossier `/public`).
- Crédit cartographique MapTiler / OpenStreetMap affiché, comme l'exigent leurs licences.
- Un CSV invalide ou vide ne remplace plus la liste en ligne.
- Le shortcode n'injecte plus `p{margin:0}` sur toute la page.
- Le nombre de résultats suit le réglage ; tri par distance ; plus d'erreur quand aucun magasin n'est trouvé autour de la position.
- Liste latérale défilant verticalement ; plus de saut de mise en page au survol des suggestions.

### Expérience utilisateur
- Largeur « large » du thème par défaut (alignwide) au lieu de la largeur du texte imposée par les thèmes blocs ; option `[store_locator largeur="pleine"]` ou `largeur="contenu"`.
- « Rechercher dans cette zone » quand le visiteur déplace ou zoome la carte, et « Voir plus de points de vente » sous la liste.
- Le navigateur mémorise la dernière recherche (réaffichée au retour sur la page, pendant 30 jours) et l'application d'itinéraire choisie (proposée en premier).
- Plus de message « Recherche en cours… » pendant la saisie.
- Palette strictement noir / blanc / gris : boutons, filtres, marqueurs (blancs sur fond sombre, noirs sur fond clair). Le fond de carte garde ses couleurs d'origine. Les réglages de couleur ont été retirés.
- Grande carte plein cadre avec la liste des magasins superposée à gauche (repliable). Survol lié : survoler une fiche met son marqueur en avant, survoler un marqueur met sa fiche en avant.
- Fiches hiérarchisées : enseigne en petites capitales, nom du magasin en titre, adresse, état « Ouvert / Fermé », téléphone, alignées à gauche.
- Micro-animations : apparition des fiches en cascade, marqueur qui grossit au survol et rebondit à la sélection, ouverture des menus et de la fiche ; toutes désactivées si le visiteur a demandé moins d'animations.
- Fiche magasin en fenêtre avec sa propre URL (`?magasin=…`) : adresse, téléphone, horaires de la semaine, services, itinéraire.
- « J'Y VAIS » propose le choix de l'application d'itinéraire : Google Maps, Apple Plans ou Waze.
- Filtres par enseigne (détectée depuis le nom du magasin ou une colonne `enseigne`) et par service (colonne `services`, icône « signature »), avec le nombre de points de vente.
- Mobile : bascule Carte / Liste, barre de recherche qui reste visible en haut, déplacement de la carte à deux doigts (un doigt fait défiler la page).
- Ordinateur : zoom de la carte à la molette seulement avec Ctrl (⌘ sur Mac), pour ne plus bloquer le défilement de la page.
- Thème clair (fond blanc, texte noir, police du site) par défaut pour s'intégrer à la page, thème sombre au choix dans les paramètres ou par page (`[store_locator theme="dark"]`).
- Bouton « Autour de moi » : la position n'est plus demandée dès l'ouverture de la page (elle est utilisée automatiquement seulement si le visiteur l'a déjà autorisée).
- Distance affichée sur chaque fiche, téléphone cliquable, lien vers le site web.
- Recherche par nom de magasin et par ville des magasins hors de France (Belgique, Luxembourg, Suisse…), codes postaux saisis sans le zéro initial.
- Regroupement des marqueurs (Leaflet.markercluster).
- Liste et carte reliées : clic sur une fiche → popup ; clic sur un marqueur → fiche mise en évidence.
- Accessibilité : libellé, combobox ARIA avec navigation au clavier (flèches, Entrée, Échap), annonces de statut, vrais liens « J'Y VAIS ».

### Administration
- Alerte par e-mail si la synchronisation automatique échoue (au plus une par jour), adresse réglable.
- Synchronisation Google Sheets (bouton et tâche quotidienne, avec garde-fou si la feuille est tronquée).
- Rapport d'import avant validation : lignes ignorées et raison, doublons, codes postaux manquants.
- Géocodage automatique des magasins français sans coordonnées (service public IGN).
- Données stockées hors du dossier du plugin (options WordPress et `wp-content/uploads/novi-storelocator/`), 10 sauvegardes restaurables, export CSV.
- Fonctions préfixées `novi_sl_`.

### Référencement
- Chaque revendeur est déclaré comme vendant les produits de la marque (`makesOffer` → `Brand`, réglage « Marque vendue ») pour répondre à « où acheter Les Senteurs Gourmandes ».
- Une URL indexable par magasin, rendue côté serveur avec titre, description, canonique et données structurées `Store` (horaires, téléphone, enseigne) ; ajout au plan du site (WordPress, Yoast SEO) ; compatibilité Yoast SEO et Rank Math ; redirection 301 des identifiants inconnus.
- Colonne `horaires` (texte libre interprété) et mise en forme automatique des noms en majuscules et des téléphones.
- Données structurées JSON-LD (`schema.org/Store`) sur la page du store locator (désactivables).
- Nouveau shortcode `[store_locator_list]` : liste HTML complète des magasins.

## 1.3.3
Version d'origine, conservée telle quelle dans le premier commit de `main`.

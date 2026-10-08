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
- Bouton « Autour de moi » : la position n'est plus demandée dès l'ouverture de la page (elle est utilisée automatiquement seulement si le visiteur l'a déjà autorisée).
- Distance affichée sur chaque fiche, téléphone cliquable, lien vers le site web.
- Recherche par nom de magasin et par ville des magasins hors de France (Belgique, Luxembourg, Suisse…), codes postaux saisis sans le zéro initial.
- Regroupement des marqueurs (Leaflet.markercluster).
- Liste et carte reliées : clic sur une fiche → popup ; clic sur un marqueur → fiche mise en évidence.
- Accessibilité : libellé, combobox ARIA avec navigation au clavier (flèches, Entrée, Échap), annonces de statut, vrais liens « J'Y VAIS ».

### Administration
- Synchronisation Google Sheets (bouton et tâche quotidienne, avec garde-fou si la feuille est tronquée).
- Rapport d'import avant validation : lignes ignorées et raison, doublons, codes postaux manquants.
- Géocodage automatique des magasins français sans coordonnées (service public IGN).
- Données stockées hors du dossier du plugin (options WordPress et `wp-content/uploads/novi-storelocator/`), 10 sauvegardes restaurables, export CSV.
- Fonctions préfixées `novi_sl_`.

### Référencement
- Données structurées JSON-LD (`schema.org/Store`) sur la page du store locator (désactivables).
- Nouveau shortcode `[store_locator_list]` : liste HTML complète des magasins.

## 1.3.3
Version d'origine, conservée telle quelle dans le premier commit de `main`.

<?php
/**
 * Tests du back-office (import CSV, synchronisation, URL Google Sheets, géocodage).
 *
 * À lancer UNIQUEMENT sur un site de test (le test de synchronisation remplace la liste des magasins) :
 *   NOVI_SL_TESTS=1 wp eval-file tests/php/run.php
 *
 * Les appels HTTP sont simulés via le filtre pre_http_request : aucun accès réseau.
 *
 * @package NoviStoreLocator
 */

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'NOVI_SL_TESTS' ) ) {
	fwrite( STDERR, "Lancer avec NOVI_SL_TESTS=1 wp eval-file tests/php/run.php (site de test uniquement).\n" );
	exit( 1 );
}

$GLOBALS['novi_sl_failures'] = 0;
$GLOBALS['novi_sl_checks']   = 0;

function novi_sl_assert( $condition, $label ) {
	++$GLOBALS['novi_sl_checks'];
	if ( $condition ) {
		echo "  ok   $label\n";
	} else {
		++$GLOBALS['novi_sl_failures'];
		echo "  FAIL $label\n";
	}
}

/** Simule la prochaine réponse HTTP. */
function novi_sl_mock_http( $code, $body ) {
	remove_all_filters( 'pre_http_request' );
	add_filter(
		'pre_http_request',
		function () use ( $code, $body ) {
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => $code,
					'message' => 'Mock',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
	);
}

function novi_sl_csv( array $rows ) {
	$lines = array( 'id_store,active,name,city,postcode,address1,latitude,longitude,country,icone' );
	foreach ( $rows as $r ) {
		$lines[] = implode( ',', $r );
	}
	return implode( "\n", $lines ) . "\n";
}

echo "URL Google Sheets\n";
novi_sl_assert(
	'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/export?format=csv&gid=42' === novi_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=42' ),
	'lien d\'édition converti en export CSV avec le bon onglet'
);
novi_sl_assert(
	false !== strpos( novi_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/e/2PACX-1vABC/pubhtml' ), 'output=csv' ),
	'lien « publier sur le web » forcé en CSV'
);
novi_sl_assert( '' === novi_sl_normalize_sheet_url( 'http://example.com/a.csv' ), 'URL http refusée' );
novi_sl_assert( '' === novi_sl_normalize_sheet_url( 'javascript:alert(1)' ), 'URL javascript: refusée' );

echo "Réglages\n";
$clean = novi_sl_sanitize_settings(
	array(
		'apikey'        => 'abc"><script>',
		'btncolor'      => 'red;" onmouseover="alert(1)',
		'btncolorbg'    => '#FF0000',
		'results_count' => '500',
	)
);
novi_sl_assert( 'abcscript' === $clean['apikey'], 'clé API nettoyée' );
novi_sl_assert( '' === $clean['btncolor'], 'couleur invalide rejetée' );
novi_sl_assert( '#FF0000' === $clean['btncolorbg'], 'couleur hexadécimale conservée' );
novi_sl_assert( 20 === $clean['results_count'], 'nombre de résultats borné à 20' );
novi_sl_assert( 'light' === $clean['theme'], 'thème clair par défaut' );
novi_sl_assert( 'dark' === novi_sl_sanitize_settings( array( 'theme' => 'dark' ) )['theme'], 'thème sombre accepté' );
novi_sl_assert( 'light' === novi_sl_sanitize_settings( array( 'theme' => '"><script>' ) )['theme'], 'thème inconnu rejeté' );

echo "Import CSV\n";
$r = novi_sl_import_csv(
	novi_sl_csv(
		array(
			array( '1', '1', 'Magasin A', 'Paris', '75001', '1 rue A', '48.86', '2.34', 'France', '' ),
			array( '2', 'oui', 'Magasin B', 'Bourg', '1000', '2 rue B', '"46,20"', '"5,22"', '', 'Signature' ),
			array( '3', '0', 'Fermé', 'Paris', '75002', '', '48.87', '2.35', '', '' ),
			array( '4', '1', 'Sans position', 'Bruxelles', '1000', '', '', '', 'Belgique', '' ),
			array( '5', '1', 'Magasin A', 'Paris', '75001', '1 rue A', '48.86', '2.34', 'France', '' ),
		)
	),
	false
);
novi_sl_assert( 2 === count( $r['stores'] ), '2 magasins valides sur 5 lignes' );
novi_sl_assert( 1 === $r['stats']['inactive'], 'ligne inactive écartée' );
novi_sl_assert( 2 === $r['stats']['skipped'], 'ligne sans position et doublon ignorés' );
novi_sl_assert( '01000' === $r['stores'][1]['postcode'], 'zéro initial du code postal restauré' );
novi_sl_assert( 46.2 === $r['stores'][1]['lat'], 'virgule décimale acceptée' );
novi_sl_assert( 'signature' === $r['stores'][1]['icone'], 'icône normalisée' );
novi_sl_assert( false !== strpos( $r['issues'][0]['message'] . $r['issues'][1]['message'], 'France' ), 'raison explicite pour le magasin étranger sans position' );

$r = novi_sl_import_csv( "name;latitude;longitude\n<b>Été</b>;48,1;2,1\n", false );
novi_sl_assert( 1 === count( $r['stores'] ) && 'Été' === $r['stores'][0]['name'], 'séparateur point-virgule et balises HTML retirées' );
$r = novi_sl_import_csv( "name,latitude,longitude,enseigne,services\nInstitut Rose,48.1,2.1,Partenaire,\"Soins visage, Épilation ; Soins visage\"\n", false );
novi_sl_assert( 'Partenaire' === $r['stores'][0]['brand'] && array( 'Soins visage', 'Épilation' ) === $r['stores'][0]['services'], 'colonnes enseigne et services lues (doublons retirés)' );
$clean = novi_sl_sanitize_settings( array( 'brands' => "Beauty Success\r\n<b>So Cut</b>\n\nBeauty Success" ) );
novi_sl_assert( "Beauty Success\nSo Cut" === $clean['brands'], 'liste des enseignes nettoyée' );
$r = novi_sl_import_csv( "<!DOCTYPE html><html>Connexion Google</html>", false );
novi_sl_assert( false !== strpos( $r['fatal'], 'page web' ), 'page de connexion Google détectée' );
$r = novi_sl_import_csv( "ville,cp\nParis,75001\n", false );
novi_sl_assert( false !== strpos( $r['fatal'], 'Colonnes obligatoires' ), 'en-tête invalide bloquant' );

echo "Mise en forme des magasins\n";
novi_sl_assert( 'Beauty Success Le Bouscat' === novi_sl_title_case( 'BEAUTY SUCCESS LE BOUSCAT' ), 'nom en majuscules remis en casse lisible' );
novi_sl_assert( "Villenave d'Ornon" === novi_sl_title_case( 'VILLENAVE D ORNON' ) && 'Saint-Jean-de-Luz' === novi_sl_title_case( 'SAINT-JEAN-DE-LUZ' ), 'règles françaises (élision, mots composés)' );
novi_sl_assert( 'Talence CC' === novi_sl_title_case( 'TALENCE CC' ), 'sigles conservés en majuscules' );
$st = array( 'name' => 'BEAUTY SUCCESS PESSAC', 'brand' => '', 'city' => 'PESSAC', 'id' => '1' );
novi_sl_assert( 'Beauty Success' === novi_sl_store_brand( $st ) && 'Pessac' === novi_sl_store_short_title( $st ), 'enseigne détectée et nom court' );
novi_sl_assert( '05 56 08 09 10' === novi_sl_format_phone( '0556080910' ) && '+32 2 123 45 67' === novi_sl_format_phone( '+32 2 123 45 67' ), 'téléphone mis en forme' );
$h = novi_sl_parse_hours( "Lun au ven 9h-12h 14h-19h | Sam 9h30-18h; Dimanche fermé" );
novi_sl_assert( array( array( '09:00', '12:00' ), array( '14:00', '19:00' ) ) === $h[0] && array( array( '09:30', '18:00' ) ) === $h[5] && array() === $h[6], 'horaires avec coupure lus jour par jour' );
novi_sl_assert( null === novi_sl_parse_hours( 'Ouvert selon saison' ), 'horaires libres non interprétés (affichés tels quels)' );
$list = array( array( 'name' => 'Magasin Été', 'city' => 'Lyon', 'id' => '1' ), array( 'name' => 'MAGASIN ÉTÉ', 'city' => 'Paris', 'id' => '2' ), array( 'name' => 'Magasin Été', 'city' => 'Paris', 'id' => '3' ) );
novi_sl_assign_slugs( $list );
novi_sl_assert( array( 'magasin-ete', 'magasin-ete-paris', 'magasin-ete-2' ) === array_column( $list, 'slug' ), 'identifiants d\'URL uniques et lisibles' );
$r = novi_sl_import_csv( "name,latitude,longitude,city,horaires,telephone\nBoutique,48.1,2.1,Paris,\"Mardi-Samedi 10h-19h\",0142000000\n", false );
novi_sl_assert( 'boutique' === $r['stores'][0]['slug'] && 'Mardi-Samedi 10h-19h' === $r['stores'][0]['hours_text'] && array( array( '10:00', '19:00' ) ) === $r['stores'][0]['hours'][1], 'colonne horaires importée' );
$desc = novi_sl_store_seo_description( array( 'name' => 'BEAUTY SUCCESS PESSAC', 'brand' => '', 'address1' => '1 av. Eiffel', 'address2' => '', 'postcode' => '33600', 'city' => 'PESSAC', 'country' => 'France', 'hours_text' => 'Lun-Sam 9h-19h', 'phone' => '0556000000' ) );
novi_sl_assert( false !== strpos( $desc, '33600 Pessac' ) && false !== strpos( $desc, 'Lun-Sam 9h-19h' ) && false !== strpos( $desc, '05 56 00 00 00' ), 'description SEO de la fiche (adresse, horaires, téléphone)' );

$schema = novi_sl_store_schema( array( 'name' => 'BEAUTY SUCCESS PESSAC', 'brand' => '', 'slug' => 'beauty-success-pessac', 'address1' => '1 av. Eiffel', 'address2' => '', 'postcode' => '33600', 'city' => 'PESSAC', 'country' => 'France', 'lat' => 44.78, 'lng' => -0.63, 'phone' => '', 'website' => '', 'icone' => '' ) );
novi_sl_assert( isset( $schema['makesOffer']['itemOffered']['brand']['name'] ) && novi_sl_own_brand() === $schema['makesOffer']['itemOffered']['brand']['name'] && 'Beauty Success' === $schema['brand']['name'], 'données structurées : le revendeur vend la marque « ' . novi_sl_own_brand() . ' »' );

echo "Géocodage (réponse simulée)\n";
novi_sl_mock_http( 200, wp_json_encode( array( 'features' => array( array( 'geometry' => array( 'coordinates' => array( 6.1294, 45.8992 ) ), 'properties' => array( 'score' => 0.92 ) ) ) ) ) );
$r = novi_sl_import_csv( novi_sl_csv( array( array( '9', '1', 'Annecy test ' . wp_generate_password( 6, false ), 'Annecy', '74000', '1 rue Royale ' . wp_rand(), '', '', 'France', '' ) ) ), true );
novi_sl_assert( 1 === count( $r['stores'] ) && 45.8992 === $r['stores'][0]['lat'] && 1 === $r['stats']['geocoded'], 'position calculée depuis l\'adresse' );
novi_sl_assert( 'warning' === $r['issues'][0]['level'], 'avertissement « vérifiez la position »' );

echo "Synchronisation planifiée\n";
$before = novi_sl_get_stores();
update_option( NOVI_SL_OPTION, array_merge( novi_sl_get_settings(), array( 'sheet_url' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/export?format=csv&gid=0' ) ) );

novi_sl_mock_http( 200, novi_sl_csv( array( array( '1', '1', 'Seul magasin', 'Paris', '75001', '', '48.86', '2.34', '', '' ) ) ) );
novi_sl_cron_sync();
$status = get_option( 'novi_sl_sync_status' );
novi_sl_assert( ! $status['ok'] && false !== strpos( $status['message'], 'bloquée par sécurité' ), 'feuille tronquée : synchronisation bloquée' );

$GLOBALS['novi_sl_mails'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['novi_sl_mails'][] = $atts;
		return true;
	},
	10,
	2
);
delete_option( 'novi_sl_last_alert' );
update_option( NOVI_SL_OPTION, array_merge( novi_sl_get_settings(), array( 'alert_email' => 'alerte@example.com' ) ) );
novi_sl_mock_http( 403, 'Forbidden' );
novi_sl_cron_sync();
novi_sl_cron_sync();
novi_sl_assert( 1 === count( $GLOBALS['novi_sl_mails'] ) && 'alerte@example.com' === $GLOBALS['novi_sl_mails'][0]['to'] && false !== strpos( $GLOBALS['novi_sl_mails'][0]['message'], 'partagée' ), 'échec : une seule alerte e-mail par jour, avec la raison' );
novi_sl_mock_http( 403, 'Forbidden' );
novi_sl_cron_sync();
$status = get_option( 'novi_sl_sync_status' );
novi_sl_assert( ! $status['ok'] && false !== strpos( $status['message'], 'partagée' ), 'feuille privée : message explicite' );

$rows = array();
foreach ( $before as $i => $s ) {
	$rows[] = array( $s['id'], '1', '"' . str_replace( '"', '""', $s['name'] ) . '"', '"' . $s['city'] . '"', $s['postcode'], '"' . str_replace( '"', '""', $s['address1'] ) . '"', $s['lat'], $s['lng'], '"' . $s['country'] . '"', $s['icone'] );
}
novi_sl_mock_http( 200, novi_sl_csv( $rows ) );
novi_sl_cron_sync();
$status = get_option( 'novi_sl_sync_status' );
novi_sl_assert( $status['ok'], 'feuille complète : synchronisation appliquée (' . $status['message'] . ')' );

remove_all_filters( 'pre_http_request' );
echo "\n{$GLOBALS['novi_sl_checks']} vérifications, {$GLOBALS['novi_sl_failures']} échec(s)\n";
exit( $GLOBALS['novi_sl_failures'] ? 1 : 0 );

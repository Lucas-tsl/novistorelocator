<?php
/**
 * Partie publique : chargement des ressources, shortcodes [store_locator] et [store_locator_list].
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'novi_sl_register_assets' );
add_shortcode( 'store_locator', 'novi_sl_shortcode' );
add_shortcode( 'store_locator_list', 'novi_sl_list_shortcode' );

/**
 * Déclare les ressources ; les charge tout de suite si la page contient le shortcode
 * (styles dans le <head>). Sinon le shortcode les charge lui-même (page builders, widgets…).
 */
function novi_sl_register_assets() {
	$vendor = NOVI_SL_URL . 'assets/vendor/';

	wp_register_style( 'novi-sl-leaflet', $vendor . 'leaflet/leaflet.css', array(), '1.9.4' );
	wp_register_style( 'novi-sl-markercluster', $vendor . 'leaflet.markercluster/MarkerCluster.css', array( 'novi-sl-leaflet' ), '1.5.3' );
	wp_register_style( 'novi-sl', NOVI_SL_URL . 'assets/css/storelocator.css', array( 'novi-sl-markercluster' ), novi_sl_asset_version( 'assets/css/storelocator.css' ) );

	$footer = array(
		'in_footer' => true,
		'strategy'  => 'defer',
	);
	wp_register_script( 'novi-sl-leaflet', $vendor . 'leaflet/leaflet.js', array(), '1.9.4', $footer );
	wp_register_script( 'novi-sl-markercluster', $vendor . 'leaflet.markercluster/leaflet.markercluster.js', array( 'novi-sl-leaflet' ), '1.5.3', $footer );
	wp_register_script( 'novi-sl', NOVI_SL_URL . 'assets/js/storelocator.js', array( 'novi-sl-markercluster' ), novi_sl_asset_version( 'assets/js/storelocator.js' ), $footer );

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'store_locator' ) ) {
			novi_sl_enqueue_assets();
		}
	}
}

/**
 * Version d'un fichier du plugin (date de modification) pour invalider le cache.
 *
 * @param string $relative Chemin relatif au plugin.
 * @return string
 */
function novi_sl_asset_version( $relative ) {
	$path = NOVI_SL_DIR . $relative;
	return file_exists( $path ) ? NOVI_SL_VERSION . '.' . filemtime( $path ) : NOVI_SL_VERSION;
}

/**
 * Charge styles, scripts et configuration (une seule fois par page).
 */
function novi_sl_enqueue_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	if ( ! wp_script_is( 'novi-sl', 'registered' ) ) {
		novi_sl_register_assets();
	}
	wp_enqueue_style( 'novi-sl' );
	wp_enqueue_script( 'novi-sl' );
	wp_add_inline_script(
		'novi-sl',
		'window.noviStoreLocator = ' . wp_json_encode( novi_sl_front_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
		'before'
	);
}

/**
 * Configuration transmise au script.
 *
 * @return array
 */
function novi_sl_front_config() {
	$s = novi_sl_get_settings();

	if ( '' !== $s['apikey'] ) {
		$tiles = array(
			'url'         => 'https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key=' . rawurlencode( $s['apikey'] ),
			'attribution' => '<a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener">&copy; MapTiler</a> <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">&copy; les contributeurs OpenStreetMap</a>',
			'tileSize'    => 512,
			'zoomOffset'  => -1,
		);
	} else {
		$tiles = array(
			'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">&copy; les contributeurs OpenStreetMap</a>',
			'tileSize'    => 256,
			'zoomOffset'  => 0,
		);
	}

	return array(
		'storesUrl'    => novi_sl_stores_url(),
		'communesUrl'  => NOVI_SL_URL . 'assets/data/communes.min.json?v=' . rawurlencode( novi_sl_asset_version( 'assets/data/communes.min.json' ) ),
		'userIconUrl'  => NOVI_SL_URL . 'assets/img/curpos2.png',
		'tiles'        => $tiles,
		'markerColor'  => $s['markercolor'] ? $s['markercolor'] : '#2a81cb',
		'markerColors' => array(
			'signature' => '#bd7639',
			'rouge'     => '#d63638',
		),
		'labels'       => array(
			'signature' => 'Soins en institut',
		),
		'resultsCount' => (int) $s['results_count'],
	);
}

/**
 * Shortcode [store_locator results="4"].
 *
 * @param array|string $atts Attributs.
 * @return string
 */
function novi_sl_shortcode( $atts ) {
	static $instance = 0;
	++$instance;

	$s    = novi_sl_get_settings();
	$atts = shortcode_atts( array( 'results' => $s['results_count'] ), $atts, 'store_locator' );

	novi_sl_enqueue_assets();

	$id      = 'novi-sl-' . $instance;
	$results = max( 1, min( 20, absint( $atts['results'] ) ) );
	$vars    = array();
	if ( $s['btncolor'] ) {
		$vars[] = '--novi-sl-btn-color:' . $s['btncolor'];
	}
	if ( $s['btncolorbg'] ) {
		$vars[] = '--novi-sl-btn-bg:' . $s['btncolorbg'];
	}

	ob_start();
	?>
	<div class="novi-sl" id="<?php echo esc_attr( $id ); ?>" data-results="<?php echo (int) $results; ?>"<?php echo $vars ? ' style="' . esc_attr( implode( ';', $vars ) ) . '"' : ''; ?>>
		<div class="novi-sl__search" role="search">
			<label class="novi-sl__label" for="<?php echo esc_attr( $id ); ?>-input">Trouver un point de vente</label>
			<div class="novi-sl__bar">
				<div class="novi-sl__field">
					<input id="<?php echo esc_attr( $id ); ?>-input" class="novi-sl__input" type="search" placeholder="Entrez votre ville, code postal ou le nom d'un magasin…" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-listbox">
					<ul id="<?php echo esc_attr( $id ); ?>-listbox" class="novi-sl__suggestions" role="listbox" aria-label="Suggestions" hidden></ul>
				</div>
				<button type="button" class="novi-sl__locate">
					<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 3h-2.07A7 7 0 0 0 13 5.07V3h-2v2.07A7 7 0 0 0 5.07 11H3v2h2.07A7 7 0 0 0 11 18.93V21h2v-2.07A7 7 0 0 0 18.93 13H21v-2Zm-9 6a5 5 0 1 1 0-10 5 5 0 0 1 0 10Z"/></svg>
					<span>Autour de moi</span>
				</button>
			</div>
		</div>
		<p class="novi-sl__status" role="status" aria-live="polite"></p>
		<div class="novi-sl__body">
			<div class="novi-sl__map" role="region" aria-label="Carte des points de vente"></div>
			<div class="novi-sl__panel">
				<p class="novi-sl__empty">Saisissez votre ville ou utilisez « Autour de moi » pour trouver les points de vente les plus proches.</p>
				<ol class="novi-sl__results" aria-label="Points de vente les plus proches"></ol>
			</div>
		</div>
		<noscript><p>Activez JavaScript pour afficher la carte des points de vente.</p></noscript>
	</div>
	<?php
	$html = ob_get_clean();

	if ( 1 === $instance && ! empty( $s['jsonld'] ) ) {
		$html .= novi_sl_jsonld();
	}

	return $html;
}

/**
 * Pays affichable (vide pour la France).
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_display_country( array $store ) {
	return novi_sl_is_france( $store['country'] ) ? '' : $store['country'];
}

/**
 * Lien d'itinéraire Google Maps.
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_directions_url( array $store ) {
	return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $store['lat'] . ',' . $store['lng'] );
}

/**
 * Données structurées schema.org des magasins.
 *
 * @return string
 */
function novi_sl_jsonld() {
	$stores = novi_sl_get_stores();
	if ( ! $stores ) {
		return '';
	}
	$items = array();
	foreach ( array_values( $stores ) as $index => $store ) {
		$item = array(
			'@type'   => 'Store',
			'name'    => $store['name'],
			'address' => array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => trim( $store['address1'] . ' ' . $store['address2'] ),
					'postalCode'      => $store['postcode'],
					'addressLocality' => $store['city'],
					'addressCountry'  => $store['country'] ? $store['country'] : 'France',
				)
			),
			'geo'     => array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $store['lat'],
				'longitude' => $store['lng'],
			),
			'hasMap'  => novi_sl_directions_url( $store ),
		);
		if ( $store['phone'] ) {
			$item['telephone'] = $store['phone'];
		}
		if ( ! empty( $store['website'] ) ) {
			$item['url'] = $store['website'];
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $index + 1,
			'item'     => $item,
		);
	}
	$data = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'name'            => 'Points de vente',
		'numberOfItems'   => count( $items ),
		'itemListElement' => $items,
	);
	return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}

/**
 * Shortcode [store_locator_list] : liste HTML complète, par pays puis par ville.
 *
 * @return string
 */
function novi_sl_list_shortcode() {
	$stores = novi_sl_get_stores();
	if ( ! $stores ) {
		return '';
	}

	if ( ! wp_style_is( 'novi-sl', 'enqueued' ) ) {
		wp_enqueue_style( 'novi-sl-list', NOVI_SL_URL . 'assets/css/storelocator.css', array(), novi_sl_asset_version( 'assets/css/storelocator.css' ) );
	}

	$groups = array();
	foreach ( $stores as $store ) {
		$country                                 = $store['country'] && ! novi_sl_is_france( $store['country'] ) ? $store['country'] : 'France';
		$groups[ $country ][ $store['city'] ][] = $store;
	}
	uksort(
		$groups,
		function ( $a, $b ) {
			return 'France' === $a ? -1 : ( 'France' === $b ? 1 : strcasecmp( $a, $b ) );
		}
	);

	ob_start();
	echo '<div class="novi-sl-list">';
	foreach ( $groups as $country => $cities ) {
		ksort( $cities, SORT_NATURAL | SORT_FLAG_CASE );
		echo '<section class="novi-sl-list__country"><h2>' . esc_html( $country ) . '</h2>';
		foreach ( $cities as $city => $city_stores ) {
			echo '<h3>' . esc_html( $city ? $city : '—' ) . '</h3><ul>';
			foreach ( $city_stores as $store ) {
				echo '<li><strong>' . esc_html( $store['name'] ) . '</strong><br>';
				echo esc_html( trim( $store['address1'] . ( $store['address2'] ? ', ' . $store['address2'] : '' ) ) ) . '<br>';
				echo esc_html( trim( $store['postcode'] . ' ' . $store['city'] ) );
				if ( $store['phone'] ) {
					echo '<br><a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $store['phone'] ) ) . '">' . esc_html( $store['phone'] ) . '</a>';
				}
				echo '<br><a href="' . esc_url( novi_sl_directions_url( $store ) ) . '" target="_blank" rel="noopener">Itinéraire</a></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}
	echo '</div>';
	return ob_get_clean();
}

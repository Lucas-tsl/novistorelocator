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
		'tiles'        => $tiles,
		'queryVar'     => NOVI_SL_QUERY_VAR,
		'labels'       => array(
			'signature'  => 'Soins en institut',
			'otherBrand' => 'Autres',
		),
		'dayNames'     => novi_sl_day_names(),
		'filters'      => (bool) $s['filters'],
		'brands'       => novi_sl_brands(),
		'resultsCount' => (int) $s['results_count'],
	);
}

/**
 * Shortcode [store_locator results="4" theme="dark"].
 *
 * @param array|string $atts Attributs.
 * @return string
 */
function novi_sl_shortcode( $atts ) {
	static $instance = 0;
	++$instance;

	$s    = novi_sl_get_settings();
	$atts = shortcode_atts(
		array(
			'results' => $s['results_count'],
			'theme'   => $s['theme'],
			'largeur' => 'large',
		),
		$atts,
		'store_locator'
	);
	$theme = 'light' === $atts['theme'] ? 'light' : 'dark';
	// Largeur : « large » (alignwide, largeur large du thème), « pleine » (alignfull) ou « contenu » (largeur du texte).
	$widths = array(
		'large'   => ' alignwide',
		'pleine'  => ' alignfull',
		'contenu' => '',
	);
	$align  = isset( $widths[ $atts['largeur'] ] ) ? $widths[ $atts['largeur'] ] : $widths['large'];

	novi_sl_enqueue_assets();
	novi_sl_remember_page();

	$id      = 'novi-sl-' . $instance;
	$results = max( 1, min( 20, absint( $atts['results'] ) ) );
	$current = 1 === $instance ? novi_sl_current_store() : null;

	ob_start();
	?>
	<div class="novi-sl novi-sl--<?php echo esc_attr( $theme . $align ); ?>" id="<?php echo esc_attr( $id ); ?>" data-results="<?php echo (int) $results; ?>" data-page-url="<?php echo esc_url( get_permalink() ); ?>"<?php echo $current ? ' data-open-store="' . esc_attr( $current['slug'] ) . '"' : ''; ?>>
		<div class="novi-sl__search" role="search">
			<label class="novi-sl__label" for="<?php echo esc_attr( $id ); ?>-input">Trouver un point de vente</label>
			<div class="novi-sl__bar">
				<div class="novi-sl__field">
					<input id="<?php echo esc_attr( $id ); ?>-input" class="novi-sl__input" type="search" placeholder="Ville, code postal ou nom d'un magasin" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-listbox">
					<ul id="<?php echo esc_attr( $id ); ?>-listbox" class="novi-sl__suggestions" role="listbox" aria-label="Suggestions" hidden></ul>
				</div>
				<button type="button" class="novi-sl__locate">
					<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 3h-2.07A7 7 0 0 0 13 5.07V3h-2v2.07A7 7 0 0 0 5.07 11H3v2h2.07A7 7 0 0 0 11 18.93V21h2v-2.07A7 7 0 0 0 18.93 13H21v-2Zm-9 6a5 5 0 1 1 0-10 5 5 0 0 1 0 10Z"/></svg>
					<span>Autour de moi</span>
				</button>
			</div>
			<div class="novi-sl__views" role="group" aria-label="Affichage">
				<button type="button" class="novi-sl__view" data-view="map" aria-pressed="true">Carte</button>
				<button type="button" class="novi-sl__view" data-view="list" aria-pressed="false">Liste</button>
			</div>
		</div>
		<div class="novi-sl__filters" role="group" aria-label="Filtrer les points de vente" hidden></div>
		<p class="novi-sl__status" role="status" aria-live="polite"></p>
		<div class="novi-sl__body">
			<div class="novi-sl__map" role="region" aria-label="Carte des points de vente"></div>
			<section class="novi-sl__panel" id="<?php echo esc_attr( $id ); ?>-panel" aria-label="Liste des points de vente">
				<header class="novi-sl__panel-head">
					<p class="novi-sl__panel-title">Points de vente</p>
					<button type="button" class="novi-sl__panel-toggle" aria-controls="<?php echo esc_attr( $id ); ?>-panel" aria-expanded="true">
						<span class="novi-sl__panel-toggle-label">Masquer la liste</span>
						<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z"/></svg>
					</button>
				</header>
				<p class="novi-sl__empty">Saisissez votre ville ou utilisez « Autour de moi » pour trouver les points de vente les plus proches.</p>
				<ol class="novi-sl__results" aria-label="Points de vente les plus proches"></ol>
			</section>
			<button type="button" class="novi-sl__panel-open" aria-controls="<?php echo esc_attr( $id ); ?>-panel" hidden>
				<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M3 5h18v2H3zm0 6h18v2H3zm0 6h18v2H3z"/></svg>
				<span>Afficher la liste</span>
			</button>
		</div>
		<dialog class="novi-sl__modal" id="<?php echo esc_attr( $id ); ?>-modal" aria-labelledby="<?php echo esc_attr( $id ); ?>-modal-title"<?php echo $current ? ' open' : ''; ?>>
			<div class="novi-sl__modal-inner">
				<button type="button" class="novi-sl__modal-close" aria-label="Fermer la fiche">
					<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>
				</button>
				<div class="novi-sl__modal-body"><?php echo $current ? novi_sl_render_store_details( $current, $id ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans la fonction. ?></div>
			</div>
		</dialog>
		<noscript><p>Activez JavaScript pour afficher la carte des points de vente.</p></noscript>
	</div>
	<?php
	$html = ob_get_clean();

	if ( 1 === $instance && ! empty( $s['jsonld'] ) ) {
		$html .= $current ? novi_sl_store_jsonld( $current ) : novi_sl_jsonld();
	}

	return $html;
}

/**
 * Menu « J'Y VAIS » (Google Maps, Apple Plans, Waze).
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_render_directions( array $store ) {
	$dest  = $store['lat'] . ',' . $store['lng'];
	$links = array(
		'google' => array( 'Google Maps', 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest ) ),
		'apple'  => array( 'Apple Plans', 'https://maps.apple.com/?daddr=' . rawurlencode( $dest ) . '&dirflg=d' ),
		'waze'   => array( 'Waze', 'https://waze.com/ul?ll=' . rawurlencode( $dest ) . '&navigate=yes' ),
	);
	$title = novi_sl_store_title( $store );
	$html  = '<details class="novi-sl__go"><summary class="novi-sl__btn" aria-label="' . esc_attr( "J'y vais : choisir l'application d'itinéraire vers " . $title ) . '">J\'Y VAIS</summary><div class="novi-sl__go-menu" role="group" aria-label="Itinéraire avec">';
	foreach ( $links as $key => $link ) {
		$html .= '<a class="novi-sl__go-link novi-sl__go-link--' . esc_attr( $key ) . '" href="' . esc_url( $link[1] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( 'Itinéraire vers ' . $title . ' avec ' . $link[0] . ' (nouvel onglet)' ) . '">' . esc_html( $link[0] ) . '</a>';
	}
	return $html . '</div></details>';
}

/**
 * Contenu de la fiche magasin (modale), rendu côté serveur pour les moteurs de recherche.
 * Le script reproduit exactement ce balisage (fillModal dans storelocator.js).
 *
 * @param array  $store Magasin.
 * @param string $id    Identifiant de l'instance.
 * @return string
 */
function novi_sl_render_store_details( array $store, $id ) {
	$brand = novi_sl_store_brand( $store );
	$city  = novi_sl_title_case( $store['city'] );
	$html  = '<article class="novi-sl__sheet" data-slug="' . esc_attr( $store['slug'] ) . '">';
	if ( $brand ) {
		$html .= '<p class="novi-sl__eyebrow">' . esc_html( $brand ) . '</p>';
	}
	$html .= '<h2 class="novi-sl__modal-title" id="' . esc_attr( $id ) . '-modal-title">' . esc_html( novi_sl_store_title( $store ) ) . '</h2>';
	$html .= '<p class="novi-sl__open-state" hidden></p>';
	$html .= '<dl class="novi-sl__facts">';

	$address = array_filter( array( $store['address1'], $store['address2'], trim( $store['postcode'] . ' ' . $city ), novi_sl_is_france( $store['country'] ) ? '' : $store['country'] ) );
	$html   .= '<div class="novi-sl__fact"><dt>Adresse</dt><dd><address>' . implode( '<br>', array_map( 'esc_html', $address ) ) . '</address></dd></div>';

	if ( ! empty( $store['phone'] ) ) {
		$html .= '<div class="novi-sl__fact"><dt>Téléphone</dt><dd><a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $store['phone'] ) ) . '">' . esc_html( novi_sl_format_phone( $store['phone'] ) ) . '</a></dd></div>';
	}

	if ( ! empty( $store['hours'] ) ) {
		$html .= '<div class="novi-sl__fact"><dt>Horaires</dt><dd><table class="novi-sl__week"><tbody>';
		foreach ( novi_sl_day_names() as $i => $day ) {
			$slots = isset( $store['hours'][ $i ] ) ? $store['hours'][ $i ] : array();
			$text  = $slots ? implode( ', ', array_map( function ( $s ) { return str_replace( ':', 'h', $s[0] ) . ' – ' . str_replace( ':', 'h', $s[1] ); }, $slots ) ) : 'Fermé'; // phpcs:ignore
			$html .= '<tr data-day="' . (int) $i . '"><th scope="row">' . esc_html( $day ) . '</th><td>' . esc_html( $text ) . '</td></tr>';
		}
		$html .= '</tbody></table></dd></div>';
	} elseif ( ! empty( $store['hours_text'] ) ) {
		$html .= '<div class="novi-sl__fact"><dt>Horaires</dt><dd>' . nl2br( esc_html( $store['hours_text'] ) ) . '</dd></div>';
	}

	$services = ! empty( $store['services'] ) ? (array) $store['services'] : array();
	if ( 'signature' === $store['icone'] ) {
		$services[] = 'Soins en institut';
	}
	if ( $services ) {
		$html .= '<div class="novi-sl__fact"><dt>Services</dt><dd><ul class="novi-sl__tags">';
		foreach ( array_unique( $services ) as $service ) {
			$html .= '<li>' . esc_html( $service ) . '</li>';
		}
		$html .= '</ul></dd></div>';
	}

	if ( ! empty( $store['website'] ) ) {
		$html .= '<div class="novi-sl__fact"><dt>Site web</dt><dd><a href="' . esc_url( $store['website'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $store['website'] ) ) ) . '</a></dd></div>';
	}

	$html .= '</dl><div class="novi-sl__sheet-actions">' . novi_sl_render_directions( $store ) . '</div>';
	return $html . '</article>';
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
 * Description schema.org d'un magasin.
 *
 * @param array $store Magasin.
 * @return array
 */
function novi_sl_store_schema( array $store ) {
	$item = array(
		'@type'   => 'Store',
		'name'    => novi_sl_store_title( $store ),
		'address' => array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => trim( $store['address1'] . ' ' . $store['address2'] ),
				'postalCode'      => $store['postcode'],
				'addressLocality' => novi_sl_title_case( $store['city'] ),
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
	$own_brand = novi_sl_own_brand();
	if ( $own_brand ) {
		// Le revendeur vend les produits de la marque : « où acheter <marque> ».
		$item['makesOffer'] = array(
			'@type'        => 'Offer',
			'availability' => 'https://schema.org/InStoreOnly',
			'itemOffered'  => array(
				'@type'    => 'Product',
				'name'     => 'Parfums ' . $own_brand,
				'category' => 'Parfums',
				'brand'    => novi_sl_own_brand_schema(),
			),
		);
	}
	$url = isset( $store['slug'] ) ? novi_sl_store_url( $store ) : '';
	if ( $url ) {
		$item['@id'] = $url . '#magasin';
		$item['url'] = $url;
	}
	$brand = novi_sl_store_brand( $store );
	if ( $brand ) {
		$item['brand'] = array(
			'@type' => 'Brand',
			'name'  => $brand,
		);
	}
	if ( ! empty( $store['phone'] ) ) {
		$item['telephone'] = novi_sl_format_phone( $store['phone'] );
	}
	if ( ! empty( $store['website'] ) ) {
		$item['sameAs'] = $store['website'];
	}
	if ( ! empty( $store['hours'] ) ) {
		$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$spec = array();
		foreach ( $store['hours'] as $i => $slots ) {
			foreach ( (array) $slots as $slot ) {
				$spec[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => 'https://schema.org/' . $days[ $i ],
					'opens'     => $slot[0],
					'closes'    => $slot[1],
				);
			}
		}
		if ( $spec ) {
			$item['openingHoursSpecification'] = $spec;
		}
	}
	return $item;
}

/**
 * Encode des données structurées.
 *
 * @param array $data Données.
 * @return string
 */
function novi_sl_jsonld_script( array $data ) {
	return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}

/**
 * Marque vendue par les revendeurs (réglage « Marque vendue », nom du site par défaut).
 *
 * @return string
 */
function novi_sl_own_brand() {
	$settings = novi_sl_get_settings();
	return '' !== trim( (string) $settings['own_brand'] ) ? trim( $settings['own_brand'] ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * Description schema.org de la marque vendue.
 *
 * @return array
 */
function novi_sl_own_brand_schema() {
	return array(
		'@type' => 'Brand',
		'name'  => novi_sl_own_brand(),
		'url'   => home_url( '/' ),
	);
}

/**
 * Données structurées de tous les magasins (page du store locator).
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
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $index + 1,
			'item'     => novi_sl_store_schema( $store ),
		);
	}
	return novi_sl_jsonld_script(
		array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => 'Points de vente ' . novi_sl_own_brand(),
			'about'           => novi_sl_own_brand_schema(),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		)
	);
}

/**
 * Données structurées d'une fiche magasin.
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_store_jsonld( array $store ) {
	return novi_sl_jsonld_script( array( '@context' => 'https://schema.org' ) + novi_sl_store_schema( $store ) );
}

/**
 * Shortcode [store_locator_list] : liste HTML complète, par pays puis par ville, avec lien vers chaque fiche.
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
		$country = $store['country'] && ! novi_sl_is_france( $store['country'] ) ? $store['country'] : 'France';
		$groups[ $country ][ novi_sl_title_case( $store['city'] ) ][] = $store;
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
				$url  = isset( $store['slug'] ) ? novi_sl_store_url( $store ) : '';
				$name = esc_html( novi_sl_store_title( $store ) );
				echo '<li><strong>' . ( $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name ) . '</strong><br>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $name échappé.
				echo esc_html( novi_sl_store_address( $store ) );
				if ( $store['phone'] ) {
					echo '<br><a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $store['phone'] ) ) . '">' . esc_html( novi_sl_format_phone( $store['phone'] ) ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}
	echo '</div>';
	return ob_get_clean();
}

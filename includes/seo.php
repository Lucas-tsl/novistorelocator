<?php
/**
 * Fiches magasin indexables : chaque magasin a sa propre URL (page du store locator + ?magasin=slug).
 * La fiche s'ouvre dans une fenêtre (modale) sur la page du store locator, sans changer de page,
 * mais l'URL, le titre, la description, l'URL canonique et les données structurées sont propres au magasin.
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'NOVI_SL_QUERY_VAR', 'magasin' );

add_action( 'template_redirect', 'novi_sl_store_redirect' );
add_filter( 'document_title_parts', 'novi_sl_document_title' );
add_filter( 'get_canonical_url', 'novi_sl_canonical_url', 20 );
add_action( 'wp_head', 'novi_sl_meta_description', 1 );
add_action( 'init', 'novi_sl_register_sitemap' );

// Extensions SEO courantes.
add_filter( 'wpseo_title', 'novi_sl_seo_plugin_title', 20 );
add_filter( 'wpseo_metadesc', 'novi_sl_seo_plugin_description', 20 );
add_filter( 'wpseo_canonical', 'novi_sl_canonical_url', 20 );
add_filter( 'wpseo_opengraph_url', 'novi_sl_canonical_url', 20 );
add_filter( 'wpseo_opengraph_title', 'novi_sl_seo_plugin_title', 20 );
add_filter( 'wpseo_opengraph_desc', 'novi_sl_seo_plugin_description', 20 );
add_filter( 'wpseo_sitemap_page_content', 'novi_sl_yoast_sitemap' );
add_filter( 'rank_math/frontend/title', 'novi_sl_seo_plugin_title', 20 );
add_filter( 'rank_math/frontend/description', 'novi_sl_seo_plugin_description', 20 );
add_filter( 'rank_math/frontend/canonical', 'novi_sl_canonical_url', 20 );

/**
 * Magasin demandé dans l'URL de la page courante (?magasin=slug), si la page contient le store locator.
 *
 * @return array|null
 */
function novi_sl_current_store() {
	static $store = false;
	if ( false !== $store ) {
		return $store;
	}
	$store = null;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture publique d'un identifiant.
	if ( empty( $_GET[ NOVI_SL_QUERY_VAR ] ) || ! is_singular() ) {
		return $store;
	}
	$post = get_post();
	if ( ! $post || ! has_shortcode( $post->post_content, 'store_locator' ) ) {
		return $store;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$store = novi_sl_find_store( sanitize_title( wp_unslash( $_GET[ NOVI_SL_QUERY_VAR ] ) ) );
	return $store;
}

/**
 * URL publique de la fiche d'un magasin.
 *
 * @param array    $store   Magasin.
 * @param int|null $page_id Page du store locator (par défaut : page mémorisée).
 * @return string
 */
function novi_sl_store_url( array $store, $page_id = null ) {
	$page_id = $page_id ? $page_id : (int) get_option( 'novi_sl_page_id' );
	$base    = $page_id ? get_permalink( $page_id ) : '';
	if ( ! $base ) {
		return '';
	}
	return add_query_arg( NOVI_SL_QUERY_VAR, $store['slug'], $base );
}

/**
 * Identifiant inconnu (magasin supprimé, faute de frappe) : redirection permanente vers la page.
 */
function novi_sl_store_redirect() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $_GET[ NOVI_SL_QUERY_VAR ] ) || ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( $post && has_shortcode( $post->post_content, 'store_locator' ) && ! novi_sl_current_store() ) {
		wp_safe_redirect( remove_query_arg( NOVI_SL_QUERY_VAR ), 301 );
		exit;
	}
}

/**
 * Titre SEO de la fiche (« Beauty Success Le Bouscat : adresse, horaires et téléphone »).
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_store_seo_title( array $store ) {
	$title = novi_sl_store_title( $store );
	$city  = novi_sl_title_case( $store['city'] );
	if ( $city && false === stripos( remove_accents( $title ), remove_accents( $city ) ) ) {
		$title .= ' à ' . $city;
	}
	return $title . ' : adresse, horaires et téléphone';
}

/**
 * Description SEO de la fiche.
 *
 * @param array $store Magasin.
 * @return string
 */
function novi_sl_store_seo_description( array $store ) {
	$parts = array( novi_sl_store_title( $store ) . ', ' . novi_sl_store_address( $store ) . '.' );
	if ( ! empty( $store['hours_text'] ) ) {
		$parts[] = 'Horaires : ' . preg_replace( '/\s*\n\s*/', ', ', $store['hours_text'] ) . '.';
	}
	if ( ! empty( $store['phone'] ) ) {
		$parts[] = 'Tél. ' . novi_sl_format_phone( $store['phone'] ) . '.';
	}
	$parts[] = 'Itinéraire et point de vente ' . get_bloginfo( 'name' ) . '.';
	return wp_html_excerpt( implode( ' ', $parts ), 300, '…' );
}

/**
 * Titre de l'onglet (WordPress sans extension SEO).
 *
 * @param array $parts Parties du titre.
 * @return array
 */
function novi_sl_document_title( $parts ) {
	$store = novi_sl_current_store();
	if ( $store ) {
		$parts['title'] = novi_sl_store_seo_title( $store );
	}
	return $parts;
}

/**
 * Titre pour Yoast SEO / Rank Math.
 *
 * @param string $title Titre calculé par l'extension.
 * @return string
 */
function novi_sl_seo_plugin_title( $title ) {
	$store = novi_sl_current_store();
	return $store ? novi_sl_store_seo_title( $store ) . ' - ' . get_bloginfo( 'name' ) : $title;
}

/**
 * Description pour Yoast SEO / Rank Math.
 *
 * @param string $description Description calculée par l'extension.
 * @return string
 */
function novi_sl_seo_plugin_description( $description ) {
	$store = novi_sl_current_store();
	return $store ? novi_sl_store_seo_description( $store ) : $description;
}

/**
 * URL canonique de la fiche (sinon WordPress indiquerait la page sans ?magasin=).
 *
 * @param string $url URL canonique calculée.
 * @return string
 */
function novi_sl_canonical_url( $url ) {
	$store = novi_sl_current_store();
	if ( $store && $url ) {
		return add_query_arg( NOVI_SL_QUERY_VAR, $store['slug'], remove_query_arg( NOVI_SL_QUERY_VAR, $url ) );
	}
	return $url;
}

/**
 * Meta description quand aucune extension SEO ne s'en charge.
 */
function novi_sl_meta_description() {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return;
	}
	$store = novi_sl_current_store();
	if ( ! $store ) {
		return;
	}
	printf( '<meta name="description" content="%s">' . "\n", esc_attr( novi_sl_store_seo_description( $store ) ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( novi_sl_store_seo_title( $store ) ) );
	printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( novi_sl_store_seo_description( $store ) ) );
}

/**
 * Mémorise la page qui contient le store locator (pour le plan du site et les liens des fiches).
 */
function novi_sl_remember_page() {
	if ( ! is_singular() ) {
		return;
	}
	$id = (int) get_queried_object_id();
	if ( $id && (int) get_option( 'novi_sl_page_id' ) !== $id && 'publish' === get_post_status( $id ) ) {
		update_option( 'novi_sl_page_id', $id, false );
	}
}

/* -------------------------------------------------------------------------
 * Plan du site : une URL par fiche magasin
 * ---------------------------------------------------------------------- */

/**
 * Plan du site natif de WordPress (5.5+).
 */
function novi_sl_register_sitemap() {
	if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
		return;
	}
	require_once NOVI_SL_DIR . 'includes/class-novi-sl-sitemap.php';
	wp_register_sitemap_provider( 'magasins', new Novi_SL_Sitemap_Provider() );
}

/**
 * URLs des fiches pour les plans du site.
 *
 * @return string[]
 */
function novi_sl_store_urls() {
	$urls = array();
	foreach ( novi_sl_get_stores() as $store ) {
		$url = isset( $store['slug'] ) ? novi_sl_store_url( $store ) : '';
		if ( $url ) {
			$urls[] = $url;
		}
	}
	return $urls;
}

/**
 * Plan du site de Yoast SEO (qui remplace celui de WordPress) : ajout des fiches au plan des pages.
 *
 * @param string $content XML supplémentaire.
 * @return string
 */
function novi_sl_yoast_sitemap( $content ) {
	foreach ( novi_sl_store_urls() as $url ) {
		$content .= '<url><loc>' . esc_url( $url ) . '</loc></url>' . "\n";
	}
	return $content;
}

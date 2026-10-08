<?php
/**
 * Réglages du plugin, stockés dans l'option WordPress NOVI_SL_OPTION.
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut des réglages.
 *
 * @return array
 */
function novi_sl_default_settings() {
	return array(
		'apikey'        => '',
		'btncolor'      => '',
		'btncolorbg'    => '',
		'markercolor'   => '',
		'results_count' => 4,
		'sheet_url'     => '',
		'sync_auto'     => 0,
		'jsonld'        => 1,
		'theme'         => 'dark',
	);
}

/**
 * Réglages courants, complétés par les valeurs par défaut.
 *
 * @return array
 */
function novi_sl_get_settings() {
	$saved = get_option( NOVI_SL_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, novi_sl_default_settings() );
}

/**
 * Nettoie les réglages soumis par le formulaire (callback de register_setting).
 *
 * @param mixed $input Valeurs brutes.
 * @return array
 */
function novi_sl_sanitize_settings( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$current  = novi_sl_get_settings();
	$defaults = novi_sl_default_settings();
	$clean    = array();

	// Clé MapTiler : caractères alphanumériques uniquement.
	$clean['apikey'] = isset( $input['apikey'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['apikey'] ) : $current['apikey'];

	foreach ( array( 'btncolor', 'btncolorbg', 'markercolor' ) as $key ) {
		$value         = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		$clean[ $key ] = '' === $value ? '' : (string) sanitize_hex_color( $value );
		if ( '' !== $value && '' === $clean[ $key ] && function_exists( 'add_settings_error' ) ) {
			add_settings_error( NOVI_SL_OPTION, 'novi_sl_' . $key, sprintf( 'La couleur « %s » n\'est pas un code hexadécimal valide (ex : #ff0000). Elle a été ignorée.', esc_html( $value ) ) );
		}
	}

	$count                  = isset( $input['results_count'] ) ? absint( $input['results_count'] ) : $defaults['results_count'];
	$clean['results_count'] = max( 1, min( 20, $count ? $count : $defaults['results_count'] ) );

	$clean['sheet_url'] = '';
	if ( ! empty( $input['sheet_url'] ) ) {
		$url = novi_sl_normalize_sheet_url( trim( (string) $input['sheet_url'] ) );
		if ( $url ) {
			$clean['sheet_url'] = $url;
		} elseif ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( NOVI_SL_OPTION, 'novi_sl_sheet_url', 'L\'adresse de la feuille Google Sheets doit être une URL https valide.' );
		}
	}

	$clean['sync_auto'] = ! empty( $input['sync_auto'] ) && '' !== $clean['sheet_url'] ? 1 : 0;
	$clean['jsonld']    = ! empty( $input['jsonld'] ) ? 1 : 0;
	$clean['theme']     = isset( $input['theme'] ) && in_array( $input['theme'], array( 'dark', 'light' ), true ) ? $input['theme'] : $defaults['theme'];

	return $clean;
}

/**
 * Transforme une URL Google Sheets (édition ou publication) en URL d'export CSV.
 *
 * @param string $url URL saisie.
 * @return string URL https d'export, ou chaîne vide si invalide.
 */
function novi_sl_normalize_sheet_url( $url ) {
	$url = esc_url_raw( $url, array( 'https' ) );
	if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
		return '';
	}

	// Lien « Publier sur le web » (/d/e/<ID>/pub) : forcer le format CSV.
	if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/e/[A-Za-z0-9_-]+/pub#', $url ) ) {
		if ( false === strpos( $url, 'output=csv' ) ) {
			$url = add_query_arg( 'output', 'csv', remove_query_arg( 'output', $url ) );
		}
		return $url;
	}

	// Lien d'édition https://docs.google.com/spreadsheets/d/<ID>/edit#gid=<GID>  →  export CSV.
	if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $url, $m ) ) {
		$gid = '0';
		if ( preg_match( '/[#&?]gid=(\d+)/', $url, $g ) ) {
			$gid = $g[1];
		}
		return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
	}

	return $url;
}

/**
 * Reprogramme la synchronisation quand les réglages changent.
 */
add_action( 'update_option_' . NOVI_SL_OPTION, 'novi_sl_reschedule_sync' );
add_action( 'add_option_' . NOVI_SL_OPTION, 'novi_sl_reschedule_sync' );

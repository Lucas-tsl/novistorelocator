<?php
/**
 * Synchronisation avec une feuille Google Sheets (export CSV), manuelle ou quotidienne.
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * En dessous de ce ratio (nouveaux / actuels), la synchronisation automatique refuse d'appliquer
 * la nouvelle liste : protège contre une feuille vidée ou tronquée par erreur.
 */
define( 'NOVI_SL_SYNC_MIN_RATIO', 0.5 );

add_action( NOVI_SL_CRON_HOOK, 'novi_sl_cron_sync' );

/**
 * (Re)programme la synchronisation quotidienne selon les réglages.
 */
function novi_sl_reschedule_sync() {
	wp_clear_scheduled_hook( NOVI_SL_CRON_HOOK );
	$settings = novi_sl_get_settings();
	if ( ! empty( $settings['sync_auto'] ) && ! empty( $settings['sheet_url'] ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NOVI_SL_CRON_HOOK );
	}
}

/**
 * Télécharge le CSV de la feuille configurée.
 *
 * @return string|WP_Error
 */
function novi_sl_fetch_sheet() {
	$settings = novi_sl_get_settings();
	if ( empty( $settings['sheet_url'] ) ) {
		return new WP_Error( 'novi_sl_no_url', 'Aucune feuille Google Sheets n\'est configurée (onglet Paramètres).' );
	}

	$response = wp_safe_remote_get(
		$settings['sheet_url'],
		array(
			'timeout'             => 20,
			'redirection'         => 5,
			'limit_response_size' => NOVI_SL_MAX_CSV_BYTES + 1,
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'novi_sl_http', 'Impossible de joindre la feuille Google Sheets : ' . $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$hint = in_array( $code, array( 401, 403, 404 ), true ) ? ' Vérifiez que la feuille est partagée en lecture (« Tous les utilisateurs disposant du lien »).' : '';
		return new WP_Error( 'novi_sl_http_code', sprintf( 'La feuille Google Sheets a répondu avec le code %d.', $code ) . $hint );
	}
	return wp_remote_retrieve_body( $response );
}

/**
 * Enregistre l'état de la dernière synchronisation.
 *
 * @param bool   $ok      Succès.
 * @param string $message Message.
 */
function novi_sl_set_sync_status( $ok, $message ) {
	update_option(
		'novi_sl_sync_status',
		array(
			'time'    => time(),
			'ok'      => (bool) $ok,
			'message' => $message,
		),
		false
	);
}

/**
 * Synchronisation planifiée : applique directement la feuille si elle est saine.
 */
function novi_sl_cron_sync() {
	$content = novi_sl_fetch_sheet();
	if ( is_wp_error( $content ) ) {
		novi_sl_set_sync_status( false, $content->get_error_message() );
		return;
	}

	$result = novi_sl_import_csv( $content );
	if ( $result['fatal'] ) {
		novi_sl_set_sync_status( false, $result['fatal'] . ' La liste actuelle a été conservée.' );
		return;
	}

	$current = count( novi_sl_get_stores() );
	$new     = count( $result['stores'] );
	if ( $current > 0 && $new < $current * NOVI_SL_SYNC_MIN_RATIO ) {
		novi_sl_set_sync_status(
			false,
			sprintf( 'La feuille ne contient plus que %1$d magasins valides contre %2$d actuellement : synchronisation automatique bloquée par sécurité. Vérifiez la feuille puis lancez « Synchroniser maintenant » pour valider manuellement.', $new, $current )
		);
		return;
	}

	$saved = novi_sl_save_stores( $result['stores'], 'sync' );
	if ( is_wp_error( $saved ) ) {
		novi_sl_set_sync_status( false, $saved->get_error_message() );
		return;
	}

	$skipped = $result['stats']['skipped'];
	novi_sl_set_sync_status( true, sprintf( '%d magasins importés%s.', $new, $skipped ? sprintf( ', %d lignes ignorées (lancez « Synchroniser maintenant » pour voir le détail)', $skipped ) : '' ) );
}

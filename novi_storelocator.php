<?php
/**
 * Plugin Name:       NOVI Store Locator
 * Description:       Carte des points de vente (shortcode [store_locator]) alimentée par un fichier CSV ou une feuille Google Sheets.
 * Version:           1.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Lucas DUVERNEUIL
 * Text Domain:       novi-storelocator
 */

defined( 'ABSPATH' ) || exit;

define( 'NOVI_SL_VERSION', '1.4.0' );
define( 'NOVI_SL_FILE', __FILE__ );
define( 'NOVI_SL_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVI_SL_URL', plugin_dir_url( __FILE__ ) );
define( 'NOVI_SL_OPTION', 'novi_sl_settings' );
define( 'NOVI_SL_CAP', 'manage_options' );
define( 'NOVI_SL_CRON_HOOK', 'novi_sl_daily_sync' );

require_once NOVI_SL_DIR . 'includes/settings.php';
require_once NOVI_SL_DIR . 'includes/store.php';
require_once NOVI_SL_DIR . 'includes/storage.php';
require_once NOVI_SL_DIR . 'includes/import.php';
require_once NOVI_SL_DIR . 'includes/sync.php';
require_once NOVI_SL_DIR . 'includes/frontend.php';
require_once NOVI_SL_DIR . 'includes/seo.php';

if ( is_admin() ) {
	require_once NOVI_SL_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'novi_sl_activate' );
register_deactivation_hook( __FILE__, 'novi_sl_deactivate' );

/**
 * Activation : migre les données de la 1.3.x et programme la synchronisation si besoin.
 */
function novi_sl_activate() {
	novi_sl_maybe_migrate();
	novi_sl_reschedule_sync();
}

/**
 * Désactivation : retire la tâche planifiée. Les données (réglages, magasins) sont conservées.
 */
function novi_sl_deactivate() {
	wp_clear_scheduled_hook( NOVI_SL_CRON_HOOK );
}

add_action( 'plugins_loaded', 'novi_sl_maybe_migrate' );

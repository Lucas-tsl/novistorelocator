<?php
/**
 * Administration : import CSV avec aperçu, synchronisation Google Sheets, sauvegardes, réglages.
 *
 * Toutes les actions passent par admin-post.php avec vérification des droits et d'un nonce.
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'NOVI_SL_PAGE', 'novi-storelocator' );
define( 'NOVI_SL_SETTINGS_PAGE', 'novi-storelocator-settings' );

add_action( 'admin_menu', 'novi_sl_admin_menu' );
add_action( 'admin_init', 'novi_sl_register_settings' );
add_action( 'admin_enqueue_scripts', 'novi_sl_admin_assets' );
add_action( 'admin_notices', 'novi_sl_admin_notices' );
add_filter( 'plugin_action_links_' . plugin_basename( NOVI_SL_FILE ), 'novi_sl_action_links' );

foreach ( array( 'upload', 'sync_now', 'apply', 'cancel', 'restore', 'export' ) as $novi_sl_action ) {
	add_action( 'admin_post_novi_sl_' . $novi_sl_action, 'novi_sl_handle_' . $novi_sl_action );
}

/**
 * Menus d'administration.
 */
function novi_sl_admin_menu() {
	add_menu_page( 'NOVI Store Locator', 'Store Locator', NOVI_SL_CAP, NOVI_SL_PAGE, 'novi_sl_render_stores_page', 'dashicons-location', 58 );
	add_submenu_page( NOVI_SL_PAGE, 'Magasins', 'Magasins', NOVI_SL_CAP, NOVI_SL_PAGE, 'novi_sl_render_stores_page' );
	add_submenu_page( NOVI_SL_PAGE, 'Paramètres', 'Paramètres', NOVI_SL_CAP, NOVI_SL_SETTINGS_PAGE, 'novi_sl_render_settings_page' );
}

/**
 * Lien « Réglages » dans la liste des extensions.
 *
 * @param array $links Liens.
 * @return array
 */
function novi_sl_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . NOVI_SL_PAGE ) ) . '">Magasins</a>' );
	return $links;
}

/**
 * Déclare l'option via l'API Settings (nonce et droits gérés par options.php).
 */
function novi_sl_register_settings() {
	register_setting(
		'novi_sl',
		NOVI_SL_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'novi_sl_sanitize_settings',
			'default'           => novi_sl_default_settings(),
		)
	);
}

/**
 * Styles et sélecteur de couleur sur les pages du plugin uniquement.
 *
 * @param string $hook Page courante.
 */
function novi_sl_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, NOVI_SL_PAGE ) ) {
		return;
	}
	wp_enqueue_style( 'novi-sl-admin', NOVI_SL_URL . 'assets/css/admin.css', array(), NOVI_SL_VERSION );
}

/* -------------------------------------------------------------------------
 * Messages
 * ---------------------------------------------------------------------- */

/**
 * Mémorise un message à afficher après redirection.
 *
 * @param string $type    success|error|warning|info.
 * @param string $message Message (texte brut).
 */
function novi_sl_flash( $type, $message ) {
	set_transient( 'novi_sl_notice_' . get_current_user_id(), array( $type, $message ), 5 * MINUTE_IN_SECONDS );
}

/**
 * Affiche les messages sur les pages du plugin.
 */
function novi_sl_admin_notices() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, NOVI_SL_PAGE ) || ! current_user_can( NOVI_SL_CAP ) ) {
		return;
	}

	$key    = 'novi_sl_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( is_array( $notice ) ) {
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}

	$settings = novi_sl_get_settings();
	if ( '' === $settings['apikey'] ) {
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( 'Aucune clé MapTiler n\'est renseignée : la carte utilise en attendant les tuiles OpenStreetMap.' ),
			esc_url( admin_url( 'admin.php?page=' . NOVI_SL_SETTINGS_PAGE ) ),
			esc_html( 'Renseigner la clé' )
		);
	}

	$status = get_option( 'novi_sl_sync_status' );
	if ( is_array( $status ) && empty( $status['ok'] ) && ! empty( $settings['sync_auto'] ) ) {
		printf( '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>', esc_html( 'Échec de la dernière synchronisation automatique :' ), esc_html( $status['message'] ) );
	}
}

/* -------------------------------------------------------------------------
 * Actions (admin-post.php)
 * ---------------------------------------------------------------------- */

/**
 * Vérifie droits et nonce pour une action.
 *
 * @param string $action Nom de l'action.
 */
function novi_sl_guard( $action ) {
	if ( ! current_user_can( NOVI_SL_CAP ) ) {
		wp_die( esc_html( 'Vous n\'avez pas les droits suffisants pour effectuer cette action.' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'novi_sl_' . $action );
}

/**
 * Redirige vers la page des magasins.
 */
function novi_sl_redirect() {
	wp_safe_redirect( admin_url( 'admin.php?page=' . NOVI_SL_PAGE ) );
	exit;
}

/**
 * Mémorise un import en attente de validation.
 *
 * @param array  $result Résultat de novi_sl_import_csv().
 * @param string $source upload|sync.
 * @param string $label  Nom du fichier ou de la source.
 */
function novi_sl_set_pending( array $result, $source, $label ) {
	$result['source'] = $source;
	$result['label']  = $label;
	$result['time']   = time();
	set_transient( 'novi_sl_pending_' . get_current_user_id(), $result, HOUR_IN_SECONDS );
}

/**
 * Import en attente de l'utilisateur courant.
 *
 * @return array|null
 */
function novi_sl_get_pending() {
	$pending = get_transient( 'novi_sl_pending_' . get_current_user_id() );
	return is_array( $pending ) ? $pending : null;
}

/**
 * Upload d'un CSV : analyse puis affichage du rapport (rien n'est encore appliqué).
 */
function novi_sl_handle_upload() {
	novi_sl_guard( 'upload' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- fichier vérifié ci-dessous, contenu validé par novi_sl_import_csv().
	$file = isset( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : null;

	if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
		novi_sl_flash( 'error', 'Choisissez un fichier CSV avant de cliquer sur « Analyser le fichier ».' );
		novi_sl_redirect();
	}
	if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		novi_sl_flash( 'error', 'Le fichier n\'a pas pu être envoyé (code ' . (int) $file['error'] . ').' );
		novi_sl_redirect();
	}
	$name = sanitize_file_name( wp_unslash( $file['name'] ) );
	if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'csv', 'txt' ), true ) ) {
		novi_sl_flash( 'error', 'Le fichier n\'est pas au format CSV.' );
		novi_sl_redirect();
	}
	if ( $file['size'] > NOVI_SL_MAX_CSV_BYTES ) {
		novi_sl_flash( 'error', 'Le fichier dépasse 5 Mo.' );
		novi_sl_redirect();
	}

	// Lecture directe depuis le fichier temporaire : rien n'est conservé dans la médiathèque.
	$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$result  = novi_sl_import_csv( (string) $content );
	novi_sl_set_pending( $result, 'upload', $name );
	novi_sl_redirect();
}

/**
 * Téléchargement de la feuille Google Sheets puis affichage du rapport.
 */
function novi_sl_handle_sync_now() {
	novi_sl_guard( 'sync_now' );

	$content = novi_sl_fetch_sheet();
	if ( is_wp_error( $content ) ) {
		novi_sl_flash( 'error', $content->get_error_message() );
		novi_sl_set_sync_status( false, $content->get_error_message() );
		novi_sl_redirect();
	}
	novi_sl_set_pending( novi_sl_import_csv( $content ), 'sync', 'Google Sheets' );
	novi_sl_redirect();
}

/**
 * Validation de l'import en attente.
 */
function novi_sl_handle_apply() {
	novi_sl_guard( 'apply' );

	$pending = novi_sl_get_pending();
	if ( ! $pending ) {
		novi_sl_flash( 'error', 'L\'analyse a expiré : relancez l\'import.' );
		novi_sl_redirect();
	}
	if ( ! empty( $pending['fatal'] ) || empty( $pending['stores'] ) ) {
		novi_sl_flash( 'error', 'Cet import contient une erreur bloquante et ne peut pas être appliqué.' );
		novi_sl_redirect();
	}

	$saved = novi_sl_save_stores( $pending['stores'], $pending['source'] );
	delete_transient( 'novi_sl_pending_' . get_current_user_id() );

	if ( is_wp_error( $saved ) ) {
		novi_sl_flash( 'error', $saved->get_error_message() );
	} else {
		$message = sprintf( '%d magasins sont maintenant en ligne. L\'ancienne liste a été sauvegardée.', count( $pending['stores'] ) );
		novi_sl_flash( 'success', $message );
		if ( 'sync' === $pending['source'] ) {
			novi_sl_set_sync_status( true, $message );
		}
	}
	novi_sl_redirect();
}

/**
 * Abandon de l'import en attente.
 */
function novi_sl_handle_cancel() {
	novi_sl_guard( 'cancel' );
	delete_transient( 'novi_sl_pending_' . get_current_user_id() );
	novi_sl_flash( 'info', 'Import annulé : la liste en ligne n\'a pas été modifiée.' );
	novi_sl_redirect();
}

/**
 * Restauration d'une sauvegarde.
 */
function novi_sl_handle_restore() {
	novi_sl_guard( 'restore' );
	$file   = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
	$result = novi_sl_restore_backup( $file );
	if ( is_wp_error( $result ) ) {
		novi_sl_flash( 'error', $result->get_error_message() );
	} else {
		novi_sl_flash( 'success', 'La sauvegarde a été restaurée. La liste remplacée a elle-même été sauvegardée.' );
	}
	novi_sl_redirect();
}

/**
 * Export de la liste en ligne au format CSV (réimportable).
 */
function novi_sl_handle_export() {
	novi_sl_guard( 'export' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="magasins-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM pour Excel.
	fputcsv( $out, array( 'id_store', 'active', 'name', 'city', 'postcode', 'address1', 'latitude', 'longitude', 'phone', 'address2', 'country', 'icone', 'website', 'enseigne', 'services' ), ',', '"', '' );
	foreach ( novi_sl_get_stores() as $s ) {
		fputcsv( $out, array( $s['id'], '1', $s['name'], $s['city'], $s['postcode'], $s['address1'], $s['lat'], $s['lng'], $s['phone'], $s['address2'], $s['country'], $s['icone'], isset( $s['website'] ) ? $s['website'] : '', isset( $s['brand'] ) ? $s['brand'] : '', isset( $s['services'] ) ? implode( ', ', (array) $s['services'] ) : '' ), ',', '"', '' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

/**
 * Formulaire POST vers admin-post.php avec nonce.
 *
 * @param string $action  Action.
 * @param string $label   Libellé du bouton.
 * @param string $class   Classes du bouton.
 * @param array  $fields  Champs cachés supplémentaires.
 * @param string $confirm Message de confirmation (optionnel).
 */
function novi_sl_action_button( $action, $label, $class = 'button', array $fields = array(), $confirm = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="novi-sl-inline-form"';
	if ( $confirm ) {
		echo ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"';
	}
	echo '>';
	echo '<input type="hidden" name="action" value="' . esc_attr( 'novi_sl_' . $action ) . '">';
	wp_nonce_field( 'novi_sl_' . $action );
	foreach ( $fields as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}
	echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

/**
 * Date lisible dans le fuseau du site.
 *
 * @param int $timestamp Horodatage.
 * @return string
 */
function novi_sl_format_date( $timestamp ) {
	return wp_date( get_option( 'date_format' ) . ' à ' . get_option( 'time_format' ), (int) $timestamp );
}

/**
 * Page « Magasins ».
 */
function novi_sl_render_stores_page() {
	if ( ! current_user_can( NOVI_SL_CAP ) ) {
		return;
	}
	$pending  = novi_sl_get_pending();
	$settings = novi_sl_get_settings();
	$meta     = get_option( 'novi_sl_meta', array() );
	$count    = count( novi_sl_get_stores() );
	$sources  = array(
		'upload'    => 'import CSV',
		'sync'      => 'synchronisation Google Sheets',
		'restore'   => 'restauration d\'une sauvegarde',
		'migration' => 'reprise de la version 1.3',
	);
	?>
	<div class="wrap novi-sl-admin">
		<h1>Store Locator — Magasins</h1>

		<div class="novi-sl-summary">
			<p class="novi-sl-summary__count"><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> magasins en ligne</p>
			<?php if ( ! empty( $meta['updated_at'] ) ) : ?>
				<p>Dernière mise à jour le <?php echo esc_html( novi_sl_format_date( $meta['updated_at'] ) ); ?><?php echo isset( $sources[ $meta['source'] ] ) ? esc_html( ' (' . $sources[ $meta['source'] ] . ')' ) : ''; ?>.</p>
			<?php endif; ?>
			<?php if ( $count ) : ?>
				<?php novi_sl_action_button( 'export', 'Télécharger la liste en CSV', 'button button-small' ); ?>
			<?php endif; ?>
		</div>

		<?php if ( $pending ) : ?>
			<?php novi_sl_render_preview( $pending, $count ); ?>
		<?php else : ?>

			<div class="novi-sl-card">
				<h2>Synchroniser avec Google Sheets</h2>
				<?php if ( $settings['sheet_url'] ) : ?>
					<p>Récupère directement la feuille des magasins, sans téléchargement manuel. Un rapport s'affiche avant toute mise en ligne.</p>
					<?php
					$status = get_option( 'novi_sl_sync_status' );
					if ( is_array( $status ) ) {
						printf(
							'<p class="novi-sl-sync-status %1$s">Dernière synchronisation le %2$s : %3$s</p>',
							esc_attr( $status['ok'] ? 'is-ok' : 'is-error' ),
							esc_html( novi_sl_format_date( $status['time'] ) ),
							esc_html( $status['message'] )
						);
					}
					if ( $settings['sync_auto'] ) {
						$next = wp_next_scheduled( NOVI_SL_CRON_HOOK );
						echo '<p>Synchronisation automatique quotidienne activée' . ( $next ? esc_html( ' — prochaine le ' . novi_sl_format_date( $next ) ) : '' ) . '.</p>';
					}
					novi_sl_action_button( 'sync_now', 'Synchroniser maintenant', 'button button-primary' );
					?>
				<?php else : ?>
					<p>Renseignez l'adresse de la feuille dans les <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . NOVI_SL_SETTINGS_PAGE ) ); ?>">paramètres</a> pour mettre à jour les magasins en un clic, voire automatiquement chaque jour.</p>
				<?php endif; ?>
			</div>

			<div class="novi-sl-card">
				<h2>Importer un fichier CSV</h2>
				<ol>
					<li>Ouvrez la feuille Google Sheets des magasins et faites vos ajouts/modifications. <strong>Ne modifiez jamais la 1ère ligne</strong> (noms des colonnes).</li>
					<li>Faites : Fichier &gt; Télécharger &gt; Valeurs séparées par des virgules (.csv).</li>
					<li>Choisissez ce fichier ci-dessous puis cliquez sur « Analyser le fichier ».</li>
					<li>Vérifiez le rapport, puis validez la mise en ligne.</li>
				</ol>
				<p class="description">Colonnes reconnues : id_store, active, name, address1, address2, postcode, city, country, phone, website, latitude, longitude, icone (valeurs « signature » ou « rouge »), enseigne, services (séparés par des virgules), horaires (ex. « Lundi-Samedi 10h-19h ; Dimanche fermé »). Si la latitude/longitude d'un magasin français est vide, elle est calculée à partir de l'adresse.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="novi_sl_upload">
					<?php wp_nonce_field( 'novi_sl_upload' ); ?>
					<input type="file" name="csv_file" accept=".csv,text/csv" required>
					<button type="submit" class="button button-primary">Analyser le fichier</button>
				</form>
			</div>

			<?php novi_sl_render_backups(); ?>

			<div class="novi-sl-card">
				<h2>Intégration</h2>
				<p>Ajoutez le shortcode <code>[store_locator]</code> dans une page. Options : <code>[store_locator results="6"]</code> pour afficher 6 magasins au lieu de <?php echo (int) $settings['results_count']; ?>.</p>
				<p>Le shortcode <code>[store_locator_list]</code> affiche la liste complète des magasins en HTML, par pays et par ville (utile pour le référencement).</p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Rapport d'import avant validation.
 *
 * @param array $pending Import en attente.
 * @param int   $current Nombre de magasins actuellement en ligne.
 */
function novi_sl_render_preview( array $pending, $current ) {
	$stats  = $pending['stats'];
	$errors = array_filter(
		$pending['issues'],
		function ( $i ) {
			return 'error' === $i['level'];
		}
	);
	$new    = count( $pending['stores'] );
	?>
	<div class="novi-sl-card novi-sl-preview">
		<h2>Rapport d'import — <?php echo esc_html( $pending['label'] ); ?></h2>

		<?php if ( ! empty( $pending['fatal'] ) ) : ?>
			<div class="notice notice-error inline"><p><strong><?php echo esc_html( $pending['fatal'] ); ?></strong> La liste en ligne n'a pas été modifiée.</p></div>
		<?php endif; ?>

		<ul class="novi-sl-stats">
			<li><strong><?php echo (int) $new; ?></strong> magasins prêts à être mis en ligne</li>
			<li><strong><?php echo (int) $stats['inactive']; ?></strong> inactifs (colonne active ≠ 1), non affichés</li>
			<li class="<?php echo $stats['skipped'] ? 'is-error' : ''; ?>"><strong><?php echo (int) $stats['skipped']; ?></strong> lignes ignorées</li>
			<li><strong><?php echo (int) $stats['geocoded']; ?></strong> positions calculées depuis l'adresse</li>
		</ul>

		<?php if ( $current && $new && $new < $current * NOVI_SL_SYNC_MIN_RATIO ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( 'Attention : ce fichier contient %1$d magasins contre %2$d actuellement en ligne. Vérifiez qu\'il est complet avant de valider.', $new, $current ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( $pending['issues'] ) : ?>
			<h3>Détail (<?php echo count( $pending['issues'] ); ?>)</h3>
			<table class="widefat striped novi-sl-issues">
				<thead><tr><th>Ligne</th><th>Magasin</th><th>Problème</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $pending['issues'], 0, 300 ) as $issue ) : ?>
					<tr class="is-<?php echo esc_attr( $issue['level'] ); ?>">
						<td><?php echo (int) $issue['line']; ?></td>
						<td><?php echo esc_html( $issue['name'] ); ?></td>
						<td><?php echo esc_html( $issue['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $new ) : ?>
			<h3>Aperçu (<?php echo esc_html( min( 10, $new ) . ' sur ' . $new ); ?>)</h3>
			<table class="widefat striped">
				<thead><tr><th>Nom</th><th>Adresse</th><th>Pays</th><th>Position</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $pending['stores'], 0, 10 ) as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s['name'] ); ?></td>
						<td><?php echo esc_html( trim( $s['address1'] . ', ' . $s['postcode'] . ' ' . $s['city'], ', ' ) ); ?></td>
						<td><?php echo esc_html( $s['country'] ); ?></td>
						<td><?php echo esc_html( $s['lat'] . ', ' . $s['lng'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p class="novi-sl-actions">
			<?php
			if ( empty( $pending['fatal'] ) && $new ) {
				$label = $errors ? sprintf( 'Mettre en ligne les %d magasins valides', $new ) : sprintf( 'Mettre en ligne les %d magasins', $new );
				novi_sl_action_button( 'apply', $label, 'button button-primary' );
			}
			novi_sl_action_button( 'cancel', 'Annuler', 'button' );
			?>
		</p>
	</div>
	<?php
}

/**
 * Liste des sauvegardes restaurables.
 */
function novi_sl_render_backups() {
	$backups = novi_sl_list_backups();
	?>
	<div class="novi-sl-card">
		<h2>Sauvegardes</h2>
		<?php if ( ! $backups ) : ?>
			<p>Aucune sauvegarde pour l'instant. La liste en ligne est sauvegardée automatiquement avant chaque import (<?php echo (int) NOVI_SL_MAX_BACKUPS; ?> dernières versions conservées).</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Remplacée le</th><th>Magasins</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<tr>
						<td><?php echo esc_html( novi_sl_format_date( $backup['time'] ) ); ?></td>
						<td><?php echo (int) $backup['count']; ?></td>
						<td><?php novi_sl_action_button( 'restore', 'Restaurer cette version', 'button button-small', array( 'backup' => $backup['file'] ), 'Remplacer la liste en ligne par cette sauvegarde ?' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Page « Paramètres » (API Settings : nonce et droits vérifiés par options.php).
 */
function novi_sl_render_settings_page() {
	if ( ! current_user_can( NOVI_SL_CAP ) ) {
		return;
	}
	$s    = novi_sl_get_settings();
	$name = NOVI_SL_OPTION;
	?>
	<div class="wrap novi-sl-admin">
		<h1>Store Locator — Paramètres</h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'novi_sl' ); ?>

			<h2 class="title">Carte</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="novi-sl-apikey">Clé API MapTiler</label></th>
					<td>
						<input type="text" id="novi-sl-apikey" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[apikey]" value="<?php echo esc_attr( $s['apikey'] ); ?>" autocomplete="off">
						<p class="description">Cette clé est visible publiquement dans les pages du site (c'est normal pour une carte). Limitez-la à votre domaine dans votre compte MapTiler : Account &gt; API keys &gt; Allowed HTTP origins.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="novi-sl-theme">Thème</label></th>
					<td>
						<select id="novi-sl-theme" name="<?php echo esc_attr( $name ); ?>[theme]">
							<option value="light" <?php selected( $s['theme'], 'light' ); ?>>Clair : fond blanc, texte noir (recommandé)</option>
							<option value="dark" <?php selected( $s['theme'], 'dark' ); ?>>Sombre : fond noir, texte blanc</option>
						</select>
						<p class="description">Le store locator n'utilise que du noir, du blanc et des gris : boutons, marqueurs et fond de carte suivent le thème. Le thème clair reprend la police de votre site et se fond dans la page. Modifiable page par page : <code>[store_locator theme="dark"]</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="novi-sl-results">Magasins affichés après une recherche</label></th>
					<td><input type="number" id="novi-sl-results" min="1" max="20" class="small-text" name="<?php echo esc_attr( $name ); ?>[results_count]" value="<?php echo (int) $s['results_count']; ?>"></td>
				</tr>
			</table>

			<h2 class="title">Filtres</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Filtres par enseigne et service</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[filters]" value="1" <?php checked( $s['filters'] ); ?>> Afficher les filtres au-dessus de la carte</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="novi-sl-brands">Enseignes</label></th>
					<td>
						<textarea id="novi-sl-brands" class="large-text" rows="5" name="<?php echo esc_attr( $name ); ?>[brands]"><?php echo esc_textarea( $s['brands'] ); ?></textarea>
						<p class="description">Une enseigne par ligne. Un magasin dont le nom commence par une enseigne lui est rattaché (ex. « BEAUTY SUCCESS PESSAC » → Beauty Success). Les autres sont regroupés dans « Autres ». Une colonne <code>enseigne</code> dans le fichier est prioritaire. Les services viennent de la colonne <code>services</code> (séparés par des virgules) et de l'icône « signature » (Soins en institut).</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Google Sheets</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="novi-sl-sheet">Adresse de la feuille</label></th>
					<td>
						<input type="url" id="novi-sl-sheet" class="large-text code" name="<?php echo esc_attr( $name ); ?>[sheet_url]" value="<?php echo esc_attr( $s['sheet_url'] ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…/edit#gid=0">
						<p class="description">Collez simplement l'adresse de la feuille (copiée depuis le navigateur). Elle doit être partagée en lecture : Partager &gt; Accès général &gt; « Tous les utilisateurs disposant du lien ». L'onglet utilisé est celui affiché dans l'adresse (gid).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Synchronisation automatique</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sync_auto]" value="1" <?php checked( $s['sync_auto'] ); ?>> Mettre à jour les magasins chaque jour depuis la feuille</label>
						<p class="description">Par sécurité, la mise à jour automatique est bloquée si la feuille contient moins de la moitié des magasins actuellement en ligne.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="novi-sl-alert">E-mail d'alerte</label></th>
					<td>
						<input type="email" id="novi-sl-alert" class="regular-text" name="<?php echo esc_attr( $name ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>">
						<p class="description">Prévenu si la synchronisation automatique échoue (feuille inaccessible, colonnes modifiées, feuille vidée…). Au plus un e-mail par jour. Laisser vide pour ne pas être alerté.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Référencement</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Données structurées</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[jsonld]" value="1" <?php checked( $s['jsonld'] ); ?>> Décrire les magasins aux moteurs de recherche (JSON-LD schema.org/Store) sur la page du store locator</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="novi-sl-own-brand">Marque vendue</label></th>
					<td>
						<input type="text" id="novi-sl-own-brand" class="regular-text" name="<?php echo esc_attr( $name ); ?>[own_brand]" value="<?php echo esc_attr( $s['own_brand'] ); ?>">
						<p class="description">Chaque revendeur est déclaré aux moteurs de recherche comme vendant les produits de cette marque (offre schema.org) : c'est ce qui permet de répondre à « où acheter <?php echo esc_html( $s['own_brand'] ? $s['own_brand'] : 'la marque' ); ?> près de chez moi ».</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Enregistrer' ); ?>
		</form>
	</div>
	<?php
}

<?php
/**
 * Stockage des magasins hors du dossier du plugin (wp-content/uploads/novi-storelocator/),
 * pour que les données survivent aux mises à jour et réinstallations du plugin.
 *
 * @package NoviStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'NOVI_SL_STORES_FILE', 'stores.json' );
define( 'NOVI_SL_MAX_BACKUPS', 10 );

/**
 * Dossier de stockage (chemin et URL), créé et protégé au besoin.
 *
 * @return array{dir:string,url:string}
 */
function novi_sl_storage() {
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'novi-storelocator/';
	$url     = trailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . 'novi-storelocator/';

	if ( ! is_dir( $dir . 'backups' ) ) {
		wp_mkdir_p( $dir . 'backups' );
		// Pas de listing du dossier ; les sauvegardes ne sont pas servies.
		novi_sl_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		novi_sl_put_contents( $dir . 'backups/index.php', "<?php\n// Silence is golden.\n" );
		novi_sl_put_contents( $dir . 'backups/.htaccess', "Require all denied\nDeny from all\n" );
	}

	return array(
		'dir' => $dir,
		'url' => $url,
	);
}

/**
 * Écriture atomique (fichier temporaire puis renommage) pour ne jamais laisser un fichier à moitié écrit.
 *
 * @param string $path    Chemin final.
 * @param string $content Contenu.
 * @return bool
 */
function novi_sl_put_contents( $path, $content ) {
	$tmp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
	if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false;
	}
	return true;
}

/**
 * Chemin du fichier public des magasins.
 *
 * @return string
 */
function novi_sl_stores_path() {
	$storage = novi_sl_storage();
	return $storage['dir'] . NOVI_SL_STORES_FILE;
}

/**
 * URL publique du fichier des magasins, versionnée pour invalider le cache navigateur.
 *
 * @return string
 */
function novi_sl_stores_url() {
	$storage = novi_sl_storage();
	$path    = $storage['dir'] . NOVI_SL_STORES_FILE;
	$version = file_exists( $path ) ? filemtime( $path ) : NOVI_SL_VERSION;
	return add_query_arg( 'v', $version, $storage['url'] . NOVI_SL_STORES_FILE );
}

/**
 * Liste des magasins actifs (format normalisé).
 *
 * @return array
 */
function novi_sl_get_stores() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = novi_sl_read_stores_file( novi_sl_stores_path() );
	return $cache;
}

/**
 * Lit un fichier JSON de magasins.
 *
 * @param string $path Chemin.
 * @return array
 */
function novi_sl_read_stores_file( $path ) {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_array( $data ) ? $data : array();
}

/**
 * Enregistre une nouvelle liste de magasins, en sauvegardant la précédente.
 *
 * @param array  $stores Magasins normalisés.
 * @param string $source Origine (upload, sync, restauration, migration).
 * @return true|WP_Error
 */
function novi_sl_save_stores( array $stores, $source ) {
	if ( empty( $stores ) ) {
		return new WP_Error( 'novi_sl_empty', 'Aucun magasin valide : la liste actuelle a été conservée.' );
	}

	$json = wp_json_encode( array_values( $stores ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return new WP_Error( 'novi_sl_json', 'Impossible d\'encoder les magasins en JSON.' );
	}

	$path = novi_sl_stores_path();
	if ( file_exists( $path ) ) {
		$storage = novi_sl_storage();
		$base    = $storage['dir'] . 'backups/stores-' . gmdate( 'Ymd-His' );
		$backup  = $base . '.json';
		for ( $n = 2; file_exists( $backup ); $n++ ) {
			$backup = $base . '-' . $n . '.json'; // Deux enregistrements dans la même seconde.
		}
		if ( ! copy( $path, $backup ) ) {
			return new WP_Error( 'novi_sl_backup', 'Impossible de sauvegarder la liste actuelle : import annulé.' );
		}
		novi_sl_prune_backups();
	}

	if ( ! novi_sl_put_contents( $path, $json ) ) {
		return new WP_Error( 'novi_sl_write', 'Impossible d\'écrire le fichier des magasins (droits d\'écriture sur wp-content/uploads ?).' );
	}

	update_option(
		'novi_sl_meta',
		array(
			'updated_at' => time(),
			'count'      => count( $stores ),
			'source'     => $source,
		),
		false
	);

	return true;
}

/**
 * Sauvegardes disponibles, de la plus récente à la plus ancienne.
 *
 * @return array<int,array{file:string,time:int,count:int}>
 */
function novi_sl_list_backups() {
	$storage = novi_sl_storage();
	$files   = glob( $storage['dir'] . 'backups/stores-*.json' );
	$list    = array();
	foreach ( (array) $files as $file ) {
		$name = basename( $file );
		if ( ! preg_match( '/^stores-(\d{8}-\d{6})(?:-(\d+))?\.json$/', $name, $m ) ) {
			continue;
		}
		$date   = DateTime::createFromFormat( 'Ymd-His', $m[1], new DateTimeZone( 'UTC' ) );
		$list[] = array(
			'file'  => $name,
			'time'  => $date ? $date->getTimestamp() : filemtime( $file ),
			'seq'   => isset( $m[2] ) ? (int) $m[2] : 1,
			'count' => count( novi_sl_read_stores_file( $file ) ),
		);
	}
	usort(
		$list,
		function ( $a, $b ) {
			return ( $b['time'] - $a['time'] ) ?: ( $b['seq'] - $a['seq'] );
		}
	);
	return $list;
}

/**
 * Supprime les sauvegardes au-delà de NOVI_SL_MAX_BACKUPS.
 */
function novi_sl_prune_backups() {
	$storage = novi_sl_storage();
	foreach ( array_slice( novi_sl_list_backups(), NOVI_SL_MAX_BACKUPS ) as $old ) {
		wp_delete_file( $storage['dir'] . 'backups/' . $old['file'] );
	}
}

/**
 * Restaure une sauvegarde (la liste actuelle est elle-même sauvegardée avant).
 *
 * @param string $file Nom de fichier de la sauvegarde.
 * @return true|WP_Error
 */
function novi_sl_restore_backup( $file ) {
	if ( ! preg_match( '/^stores-\d{8}-\d{6}(?:-\d+)?\.json$/', (string) $file ) ) {
		return new WP_Error( 'novi_sl_backup_name', 'Sauvegarde invalide.' );
	}
	$storage = novi_sl_storage();
	$stores  = novi_sl_read_stores_file( $storage['dir'] . 'backups/' . $file );
	if ( empty( $stores ) ) {
		return new WP_Error( 'novi_sl_backup_missing', 'Cette sauvegarde est introuvable ou vide.' );
	}
	return novi_sl_save_stores( $stores, 'restore' );
}

/**
 * Migration depuis la 1.3.x : réglages de settings.json vers une option, magasins du dossier
 * du plugin vers le dossier uploads.
 */
function novi_sl_maybe_migrate() {
	if ( version_compare( (string) get_option( 'novi_sl_db_version', '0' ), NOVI_SL_VERSION, '>=' ) ) {
		return;
	}

	// 1. Réglages.
	if ( false === get_option( NOVI_SL_OPTION, false ) ) {
		$legacy   = NOVI_SL_DIR . 'assets/json/settings/settings.json';
		$settings = array();
		if ( is_readable( $legacy ) ) {
			$data = json_decode( (string) file_get_contents( $legacy ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $data ) ) {
				$settings = novi_sl_sanitize_settings( array_merge( novi_sl_default_settings(), $data ) );
			}
		}
		add_option( NOVI_SL_OPTION, $settings ? $settings : novi_sl_default_settings() );
	}

	// 2. Magasins : reprise de l'ancien fichier (mise à jour par FTP), sinon des données
	// livrées avec le plugin (mise à jour par fichier .zip, qui efface l'ancien dossier).
	$migrated = false;
	if ( ! file_exists( novi_sl_stores_path() ) ) {
		foreach ( array( 'assets/json/stores.json', 'assets/data/stores-seed.json' ) as $candidate ) {
			$file = NOVI_SL_DIR . $candidate;
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$rows   = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$result = novi_sl_import_rows( is_array( $rows ) ? $rows : array(), false );
			if ( ! empty( $result['stores'] ) && true === novi_sl_save_stores( $result['stores'], 'migration' ) ) {
				$migrated = true;
				break;
			}
		}
	} else {
		$migrated = true;
	}

	// 3. Nettoyage des fichiers de la 1.3.x restés dans le dossier du plugin (mise à jour par FTP) :
	// sauvegarde PHP accessible publiquement, clé API lisible publiquement, anciens JSON.
	$legacy_files = array(
		'novi_storelocator-save.php',
		'assets/json/old_stores.json',
		'assets/json/stores_original.json',
		'assets/json/communes.json',
	);
	if ( $migrated ) {
		$legacy_files[] = 'assets/json/stores.json';
	}
	if ( get_option( NOVI_SL_OPTION, false ) ) {
		$legacy_files[] = 'assets/json/settings/settings.json';
	}
	foreach ( $legacy_files as $legacy_file ) {
		if ( file_exists( NOVI_SL_DIR . $legacy_file ) ) {
			wp_delete_file( NOVI_SL_DIR . $legacy_file );
		}
	}

	update_option( 'novi_sl_db_version', NOVI_SL_VERSION );
}

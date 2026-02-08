<?php
/**
 * WPress Restore orchestrator: extract, import DB, copy files, flush permalinks.
 *
 * @package WPress_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPress_Restore
 */
class WPress_Restore {

	/**
	 * Run full restore from a .wpress file path.
	 *
	 * @param string   $wpress_path Full path to .wpress file.
	 * @param string   $old_url     Optional. Old site URL for search-replace.
	 * @param string   $old_home    Optional. Old home URL for search-replace.
	 * @param callable $progress   Optional. Callback( string $step, string $message ) for live status.
	 * @return array{ 'success': bool, 'message': string, 'step': string }
	 */
	public static function run( $wpress_path, $old_url = '', $old_home = '', $progress = null ) {
		$wpress_path = self::sanitize_wpress_path( $wpress_path );
		if ( ! $wpress_path || ! is_file( $wpress_path ) ) {
			return array(
				'success' => false,
				'message'  => __( 'Invalid or missing .wpress file.', 'wpress-restore' ),
				'step'     => 'input',
			);
		}

		$notify = is_callable( $progress ) ? $progress : function() {};

		// Raise limits for restore.
		$prev_time  = @ini_get( 'max_execution_time' );
		$prev_mem   = @ini_get( 'memory_limit' );
		@set_time_limit( 0 );
		if ( $prev_mem !== '-1' ) {
			@ini_set( 'memory_limit', '1024M' );
		}

		$notify( 'extract', __( 'Creating temp directory…', 'wpress-restore' ) );
		$extract_dir = self::get_temp_extract_dir();
		if ( ! $extract_dir ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create temporary extraction directory.', 'wpress-restore' ),
				'step'    => 'extract',
			);
		}

		$notify( 'extract', __( 'Extracting archive (this may take several minutes)…', 'wpress-restore' ) );
		$extractor = new WPress_Extractor( $wpress_path );
		if ( ! $extractor->is_valid() ) {
			self::rmdir_recursive( $extract_dir );
			return array(
				'success' => false,
				'message' => __( 'Invalid .wpress archive (missing or corrupt end block).', 'wpress-restore' ),
				'step'    => 'extract',
			);
		}

		$result = $extractor->extract_to( $extract_dir );
		if ( ! $result['success'] ) {
			self::rmdir_recursive( $extract_dir );
			@set_time_limit( $prev_time ?: 30 );
			@ini_set( 'memory_limit', $prev_mem );
			return array(
				'success' => false,
				'message' => $result['message'],
				'step'    => 'extract',
			);
		}

		$database_sql = self::find_database_sql( $extract_dir );
		if ( $database_sql ) {
			$notify( 'database', __( 'Importing database…', 'wpress-restore' ) );
			$import_result = WPress_Database::import_sql( $database_sql, $old_url, site_url(), $old_home, home_url() );
			if ( ! $import_result['success'] ) {
				self::rmdir_recursive( $extract_dir );
				@set_time_limit( $prev_time ?: 30 );
				@ini_set( 'memory_limit', $prev_mem );
				return array(
					'success' => false,
					'message' => $import_result['message'],
					'step'    => 'database',
				);
			}
		} else {
			$notify( 'database', __( 'No database.sql found in archive, skipping.', 'wpress-restore' ) );
		}

		$notify( 'files', __( 'Copying files to WordPress…', 'wpress-restore' ) );
		$copy_result = self::copy_extracted_files_to_wp( $extract_dir );
		if ( ! $copy_result['success'] ) {
			self::rmdir_recursive( $extract_dir );
			@set_time_limit( $prev_time ?: 30 );
			@ini_set( 'memory_limit', $prev_mem );
			return array(
				'success' => false,
				'message' => $copy_result['message'],
				'step'    => 'files',
			);
		}

		$notify( 'cleanup', __( 'Cleaning up temporary files…', 'wpress-restore' ) );
		self::rmdir_recursive( $extract_dir );
		flush_rewrite_rules();

		@set_time_limit( $prev_time ?: 30 );
		@ini_set( 'memory_limit', $prev_mem );

		$notify( 'done', __( 'Restore completed.', 'wpress-restore' ) );
		return array(
			'success' => true,
			'message' => __( 'Restore completed successfully. Please go to Settings → Permalinks and click Save to refresh permalinks.', 'wpress-restore' ),
			'step'    => 'done',
		);
	}

	/**
	 * Sanitize and validate .wpress path (must be under ABSPATH or wp-content/uploads).
	 *
	 * @param string $path Path to .wpress file.
	 * @return string Empty if invalid, else normalized path.
	 */
	public static function sanitize_wpress_path( $path ) {
		$path = trim( $path );
		$path = str_replace( array( '..', "\0" ), '', $path );
		$path = wp_normalize_path( $path );
		$abs  = wp_normalize_path( ABSPATH );
		$up   = wp_normalize_path( wp_upload_dir()['basedir'] ?? ( $abs . 'wp-content/uploads' ) );
		$real = @realpath( $path );
		if ( ! $real || ! is_file( $real ) ) {
			return '';
		}
		$real = wp_normalize_path( $real );
		if ( strpos( $real, $abs ) !== 0 && strpos( $real, $up ) !== 0 ) {
			return '';
		}
		if ( strtolower( substr( $path, -7 ) ) !== '.wpress' ) {
			return '';
		}
		return $real;
	}

	/**
	 * Get a unique temp directory for extraction under wp-content/uploads.
	 *
	 * @return string|false Full path or false.
	 */
	protected static function get_temp_extract_dir() {
		$base = wp_upload_dir();
		if ( ! empty( $base['error'] ) ) {
			$base_dir = wp_normalize_path( ABSPATH . 'wp-content/uploads' );
		} else {
			$base_dir = wp_normalize_path( $base['basedir'] );
		}
		$dir = $base_dir . '/wpress-restore-temp-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		return $dir;
	}

	/**
	 * Find database.sql in extracted tree (root or common paths).
	 *
	 * @param string $extract_dir Extraction root.
	 * @return string|false Full path to database.sql or false.
	 */
	protected static function find_database_sql( $extract_dir ) {
		$candidates = array(
			$extract_dir . DIRECTORY_SEPARATOR . 'database.sql',
			$extract_dir . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'database.sql',
		);
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		// Scan root for database.sql.
		$root_files = @scandir( $extract_dir );
		if ( $root_files && in_array( 'database.sql', $root_files, true ) ) {
			return $extract_dir . DIRECTORY_SEPARATOR . 'database.sql';
		}
		return false;
	}

	/**
	 * Copy extracted files (wp-content and optionally root files) into ABSPATH.
	 * Skip wp-config.php and this plugin's directory.
	 *
	 * @param string $extract_dir Extraction root.
	 * @return array{ 'success': bool, 'message': string }
	 */
	protected static function copy_extracted_files_to_wp( $extract_dir ) {
		$abs            = wp_normalize_path( ABSPATH );
		$plugin_dir_raw = rtrim( WPRESS_RESTORE_PLUGIN_DIR, '/\\' );
		$plugin_dir     = wp_normalize_path( ( realpath( $plugin_dir_raw ) ?: $plugin_dir_raw ) );
		$plugin_slug    = basename( $plugin_dir_raw );
		$skip_config   = $abs . 'wp-config.php';
		$wp_content_ext = $extract_dir . DIRECTORY_SEPARATOR . 'wp-content';
		$wp_content_abs = $abs . 'wp-content';

		if ( ! is_dir( $wp_content_ext ) ) {
			return array( 'success' => true, 'message' => '' );
		}

		// Remove existing wp-content subdirs that exist in the backup so backup fully replaces them.
		// For each top-level dir in extracted wp-content, clear the live counterpart (plugins: keep this plugin).
		$top_level = array_diff( scandir( $wp_content_ext ), array( '.', '..' ) );
		$sep = DIRECTORY_SEPARATOR;
		foreach ( $top_level as $name ) {
			$ext_path = $wp_content_ext . $sep . $name;
			if ( ! is_dir( $ext_path ) ) {
				continue;
			}
			$live_path = $wp_content_abs . $sep . $name;
			if ( ! is_dir( $live_path ) ) {
				continue;
			}
			$entries = array_diff( scandir( $live_path ), array( '.', '..' ) );
			foreach ( $entries as $entry ) {
				$path = $live_path . $sep . $entry;
				$path_norm = wp_normalize_path( realpath( $path ) ?: $path );
				if ( $name === 'plugins' && strpos( $path_norm . $sep, $plugin_dir . $sep ) === 0 ) {
					continue;
				}
				if ( is_dir( $path ) || is_link( $path ) ) {
					self::rmdir_recursive( $path );
				} else {
					@unlink( $path );
				}
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $wp_content_ext, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $wp_content_ext ) + 1 );
			$relative = str_replace( '\\', '/', $relative );
			$target   = $wp_content_abs . '/' . $relative;

			// Skip copying into our plugin folder (symlink-safe: use relative path).
			if ( strpos( $relative, 'plugins/' . $plugin_slug . '/' ) === 0 || $relative === 'plugins/' . $plugin_slug ) {
				continue;
			}

			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: %s: path */
							__( 'Could not create directory: %s', 'wpress-restore' ),
							$target
						),
					);
				}
			} else {
				$dir = dirname( $target );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: %s: path */
							__( 'Could not create directory: %s', 'wpress-restore' ),
							$dir
						),
					);
				}
				if ( @copy( $item->getPathname(), $target ) === false ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: %s: path */
							__( 'Could not copy file: %s', 'wpress-restore' ),
							$target
						),
					);
				}
			}
		}

		// Optionally copy root-level files (e.g. index.php) if present in archive root.
		$root_files = array( 'index.php', 'wp-settings.php', 'wp-blog-header.php', 'wp-load.php', 'xmlrpc.php', 'wp-activate.php', 'wp-links-opml.php', 'wp-cron.php', 'readme.html', 'license.txt' );
		foreach ( $root_files as $name ) {
			$src = $extract_dir . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $src ) ) {
				$target = $abs . $name;
				if ( $target !== $skip_config ) {
					@copy( $src, $target );
				}
			}
		}

		return array( 'success' => true, 'message' => '' );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Path to directory.
	 */
	protected static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}

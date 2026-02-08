<?php
/**
 * Backup folder for .wpress files (upload and list).
 *
 * @package WPress_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPress_Backup_Folder
 */
class WPress_Backup_Folder {

	const FOLDER_NAME = 'wpress-restore-backups';

	/**
	 * Get the full path to the backup folder (under wp-content).
	 * Creates the folder if it does not exist.
	 *
	 * @return string|false Full path or false on failure.
	 */
	public static function get_dir() {
		$wp_content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
		$dir = wp_normalize_path( $wp_content . '/' . self::FOLDER_NAME );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, '<?php // Silence is golden.' );
		}
		return $dir;
	}

	/**
	 * List .wpress files in the backup folder.
	 *
	 * @return array[] List of { 'name' => basename, 'size' => bytes, 'date' => unix mtime, 'path' => full path }
	 */
	public static function list_backups() {
		$dir = self::get_dir();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return array();
		}
		$files = @scandir( $dir );
		if ( ! $files ) {
			return array();
		}
		$list = array();
		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if ( ! is_file( $path ) ) {
				continue;
			}
			if ( strtolower( substr( $file, -7 ) ) !== '.wpress' ) {
				continue;
			}
			$list[] = array(
				'name' => $file,
				'size' => filesize( $path ),
				'date' => filemtime( $path ),
				'path' => $path,
			);
		}
		usort( $list, function( $a, $b ) {
			return $b['date'] - $a['date'];
		});
		return $list;
	}

	/**
	 * Validate filename is a safe basename (no path traversal).
	 *
	 * @param string $filename Filename from user input.
	 * @return bool
	 */
	public static function is_safe_basename( $filename ) {
		$filename = trim( $filename );
		if ( $filename === '' ) {
			return false;
		}
		if ( strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false || strpos( $filename, "\0" ) !== false ) {
			return false;
		}
		if ( $filename === '.' || $filename === '..' ) {
			return false;
		}
		return strtolower( substr( $filename, -7 ) ) === '.wpress';
	}

	/**
	 * Get full path for a backup filename (must be from list_backups).
	 *
	 * @param string $filename Basename only.
	 * @return string|false Full path or false if invalid.
	 */
	public static function get_path_for( $filename ) {
		if ( ! self::is_safe_basename( $filename ) ) {
			return false;
		}
		$dir  = self::get_dir();
		$path = $dir . DIRECTORY_SEPARATOR . $filename;
		if ( ! is_file( $path ) ) {
			return false;
		}
		$real = realpath( $path );
		if ( ! $real || strpos( wp_normalize_path( $real ), wp_normalize_path( $dir ) ) !== 0 ) {
			return false;
		}
		return $real;
	}
}

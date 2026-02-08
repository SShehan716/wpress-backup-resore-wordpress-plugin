<?php
/**
 * Database import and search-replace for WPress Restore.
 *
 * @package WPress_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPress_Database
 */
class WPress_Database {

	const READ_CHUNK = 524288; // 512KB per read for streaming.

	/**
	 * Import SQL file with optional URL search-replace.
	 * Uses streaming read so large database.sql (e.g. from 500MB+ .wpress) does not exhaust memory.
	 *
	 * @param string $sql_file_path Full path to database.sql.
	 * @param string $old_url       Old site URL to replace (optional; if empty, will try to detect from SQL).
	 * @param string $new_url       New site URL (default current site_url()).
	 * @param string $old_home      Old home URL to replace (optional).
	 * @param string $new_home      New home URL (default current home_url()).
	 * @return array{ 'success': bool, 'message': string }
	 */
	public static function import_sql( $sql_file_path, $old_url = '', $new_url = '', $old_home = '', $new_home = '' ) {
		global $wpdb;

		if ( ! is_readable( $sql_file_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'SQL file not found or not readable.', 'wpress-restore' ),
			);
		}

		if ( empty( $new_url ) ) {
			$new_url = site_url();
		}
		if ( empty( $new_home ) ) {
			$new_home = home_url();
		}

		// Detect old URLs from first portion of file (avoids loading entire file).
		if ( empty( $old_url ) || empty( $old_home ) ) {
			$head = self::read_file_head( $sql_file_path, 1024 * 1024 ); // First 1MB.
			if ( $head !== '' ) {
				if ( empty( $old_url ) && preg_match( '/INSERT INTO `?[^`]*options`?[^V]*VALUES\s*\([^)]*\'siteurl\'[^)]*\'([^\']+)\'/s', $head, $m ) ) {
					$old_url = trim( $m[1] );
				}
				if ( empty( $old_home ) && preg_match( '/INSERT INTO `?[^`]*options`?[^V]*VALUES\s*\([^)]*\'home\'[^)]*\'([^\']+)\'/s', $head, $m ) ) {
					$old_home = trim( $m[1] );
				}
			}
		}
		if ( empty( $old_url ) ) {
			$old_url = $new_url;
		}
		if ( empty( $old_home ) ) {
			$old_home = $new_home;
		}

		return self::import_sql_streaming( $sql_file_path, $old_url, $new_url, $old_home, $new_home );
	}

	/**
	 * Prepare a single SQL statement for import: URL replace, table prefix replace, CREATE IF NOT EXISTS, INSERT IGNORE.
	 *
	 * @param string $statement Raw statement.
	 * @param string $old_url   Old site URL.
	 * @param string $new_url   New site URL.
	 * @param string $old_home  Old home URL.
	 * @param string $new_home  New home URL.
	 * @return string
	 */
	protected static function prepare_import_statement( $statement, $old_url, $new_url, $old_home, $new_home ) {
		global $wpdb;
		$statement = str_replace( array( $old_url, $old_home ), array( $new_url, $new_home ), $statement );
		// All-in-One WP Migration uses SERVMASK_PREFIX_ as placeholder; replace with current prefix.
		$statement = str_replace( 'SERVMASK_PREFIX_', $wpdb->prefix, $statement );
		// Avoid "Table already exists" when target DB has tables.
		if ( preg_match( '/^\s*CREATE\s+TABLE\s/i', $statement ) ) {
			$statement = preg_replace( '/^(\s*CREATE\s+TABLE)\s/i', '$1 IF NOT EXISTS ', $statement, 1 );
		}
		// Avoid "Duplicate entry for key PRIMARY" when re-running or target has data.
		if ( preg_match( '/^\s*INSERT\s+INTO\s/i', $statement ) ) {
			$statement = preg_replace( '/^(\s*INSERT)\s+INTO\s/i', '$1 IGNORE INTO ', $statement, 1 );
		}
		return $statement;
	}

	/**
	 * Read first N bytes of file (for URL detection).
	 *
	 * @param string $path File path.
	 * @param int    $max  Max bytes.
	 * @return string
	 */
	protected static function read_file_head( $path, $max = 1048576 ) {
		$h = @fopen( $path, 'rb' );
		if ( ! $h ) {
			return '';
		}
		$s = @fread( $h, $max );
		fclose( $h );
		return $s !== false ? $s : '';
	}

	/**
	 * Import SQL by reading file in chunks; parse statements and run with URL replace.
	 * Keeps memory use bounded (one statement at a time) for large files.
	 *
	 * @param string $sql_file_path Full path to database.sql.
	 * @param string $old_url       Old site URL.
	 * @param string $new_url       New site URL.
	 * @param string $old_home      Old home URL.
	 * @param string $new_home      New home URL.
	 * @return array{ 'success': bool, 'message': string }
	 */
	protected static function import_sql_streaming( $sql_file_path, $old_url, $new_url, $old_home, $new_home ) {
		global $wpdb;

		$handle = @fopen( $sql_file_path, 'rb' );
		if ( ! $handle ) {
			return array(
				'success' => false,
				'message' => __( 'Could not open SQL file.', 'wpress-restore' ),
			);
		}

		$buffer   = '';
		$run      = 0;
		$errors   = array();
		$in_string = false;
		$quote    = null;
		$escaped  = false;

		while ( true ) {
			$chunk = @fread( $handle, self::READ_CHUNK );
			if ( $chunk === false ) {
				fclose( $handle );
				return array(
					'success' => false,
					'message' => __( 'Error reading SQL file.', 'wpress-restore' ),
				);
			}
			if ( $chunk === '' ) {
				break;
			}
			$buffer .= $chunk;
			$len    = strlen( $buffer );
			$i      = 0;
			$start  = 0;

			while ( $i < $len ) {
				$c = $buffer[ $i ];
				if ( $escaped ) {
					$escaped = false;
					$i++;
					continue;
				}
				if ( $in_string ) {
					if ( $c === '\\' && $i + 1 < $len ) {
						$escaped = true;
						$i++;
						continue;
					}
					if ( $c === $quote ) {
						// MySQL: '' (two single quotes) is escaped single quote inside single-quoted string.
						if ( $quote === "'" ) {
							if ( $i + 1 < $len && $buffer[ $i + 1 ] === "'" ) {
								$i += 2;
								continue;
							}
							// At end of buffer we can't tell if this is '' or end-of-string; keep for next chunk.
							if ( $i + 1 >= $len ) {
								break;
							}
						}
						$in_string = false;
					}
					$i++;
					continue;
				}
				if ( ( $c === '"' || $c === "'" ) && ( $i === 0 || $buffer[ $i - 1 ] !== '\\' ) ) {
					$in_string = true;
					$quote     = $c;
					$i++;
					continue;
				}
				// Only split on semicolon when it's followed by newline or end of buffer (avoids splitting inside long string values).
				if ( $c === ';' ) {
					$next_ok = ( $i + 1 >= $len || $buffer[ $i + 1 ] === "\n" || $buffer[ $i + 1 ] === "\r" );
					if ( $next_ok ) {
						$statement = trim( substr( $buffer, $start, $i - $start + 1 ) );
						$start     = $i + 1;
						if ( $statement !== '' && strpos( $statement, '--' ) !== 0 && strpos( $statement, '/*' ) !== 0 ) {
							$statement = self::prepare_import_statement( $statement, $old_url, $new_url, $old_home, $new_home );
							$result    = $wpdb->query( $statement );
							if ( $result === false && ! empty( $wpdb->last_error ) ) {
								$errors[] = $wpdb->last_error;
							} else {
								$run++;
							}
						}
					}
					$i++;
					continue;
				}
				$i++;
			}
			$buffer = substr( $buffer, $start );
		}

		fclose( $handle );

		if ( trim( $buffer ) !== '' ) {
			$statement = self::prepare_import_statement( trim( $buffer ), $old_url, $new_url, $old_home, $new_home );
			if ( $statement !== '' && strpos( $statement, '--' ) !== 0 && strpos( $statement, '/*' ) !== 0 ) {
				$wpdb->query( $statement );
				$run++;
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'success' => false,
				'message' => __( 'Database import had errors: ', 'wpress-restore' ) . implode( '; ', array_slice( array_unique( $errors ), 0, 3 ) ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of statements executed */
				__( 'Imported SQL successfully (%d statements).', 'wpress-restore' ),
				$run
			),
		);
	}

}

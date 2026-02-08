<?php
/**
 * Admin page for WPress Restore.
 *
 * @package WPress_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPress_Restore_Admin_Page
 */
class WPress_Restore_Admin_Page {

	const SLUG = 'wpress-restore';
	const NONCE_ACTION = 'wpress_restore_run';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_wpress_restore_upload_to_backups', array( __CLASS__, 'handle_upload_to_backups' ) );
		add_action( 'admin_post_wpress_restore_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_wpress_restore_path', array( __CLASS__, 'handle_path' ) );
		add_action( 'admin_post_wpress_restore_stream', array( __CLASS__, 'handle_stream' ) );
		add_action( 'admin_post_wpress_restore_direct', array( __CLASS__, 'handle_direct' ) );
	}

	/**
	 * Add menu under Tools.
	 */
	public static function add_menu() {
		add_management_page(
			__( 'WPress Restore', 'wpress-restore' ),
			__( 'WPress Restore', 'wpress-restore' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== 'tools_page_' . self::SLUG ) {
			return;
		}
		$plugin_url = plugin_dir_url( dirname( dirname( __FILE__ ) ) . '/wpress-restore.php' );
		wp_enqueue_style(
			'wpress-restore-admin',
			$plugin_url . 'assets/css/admin.css',
			array(),
			WPRESS_RESTORE_VERSION
		);
		wp_enqueue_script(
			'wpress-restore-admin',
			$plugin_url . 'assets/js/admin.js',
			array(),
			WPRESS_RESTORE_VERSION,
			true
		);
		wp_localize_script( 'wpress-restore-admin', 'wpressRestore', array(
			'streamUrl'   => admin_url( 'admin-post.php' ),
			'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
			'redirectUrl' => add_query_arg( 'page', self::SLUG, admin_url( 'tools.php' ) ),
		) );
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) );
		}

		$message = isset( $_GET['wpress_message'] ) ? sanitize_text_field( wp_unslash( $_GET['wpress_message'] ) ) : '';
		$success = isset( $_GET['wpress_success'] ) && $_GET['wpress_success'] === '1';
		$backups = WPress_Backup_Folder::list_backups();
		$backup_dir = WPress_Backup_Folder::get_dir();
		include WPRESS_RESTORE_PLUGIN_DIR . 'admin/views/form.php';
	}

	/**
	 * Upload .wpress file to backup folder only (no restore). For large files, user can add via FTP to same folder.
	 */
	public static function handle_upload_to_backups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Invalid security token.', 'wpress-restore' ), false ) );
			exit;
		}

		if ( empty( $_FILES['wpress_backup_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wpress_backup_file']['tmp_name'] ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Please select a .wpress file to upload.', 'wpress-restore' ), false ) );
			exit;
		}

		$name = sanitize_file_name( wp_unslash( $_FILES['wpress_backup_file']['name'] ?? '' ) );
		if ( strtolower( substr( $name, -7 ) ) !== '.wpress' ) {
			wp_safe_redirect( self::get_page_url( __( 'File must have .wpress extension.', 'wpress-restore' ), false ) );
			exit;
		}

		$dir = WPress_Backup_Folder::get_dir();
		if ( ! $dir ) {
			wp_safe_redirect( self::get_page_url( __( 'Could not create backup folder.', 'wpress-restore' ), false ) );
			exit;
		}
		$dest = $dir . '/' . $name;
		if ( move_uploaded_file( $_FILES['wpress_backup_file']['tmp_name'], $dest ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Backup file uploaded. Select it from the list below and click Restore.', 'wpress-restore' ), true ) );
		} else {
			wp_safe_redirect( self::get_page_url( __( 'Failed to save uploaded file. Check folder permissions.', 'wpress-restore' ), false ) );
		}
		exit;
	}

	/**
	 * Handle form submit: upload .wpress file then redirect to same form with path (or run restore).
	 */
	public static function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Invalid security token.', 'wpress-restore' ), false ) );
			exit;
		}

		if ( empty( $_FILES['wpress_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wpress_file']['tmp_name'] ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Please select a .wpress file to upload.', 'wpress-restore' ), false ) );
			exit;
		}

		$name = sanitize_file_name( wp_unslash( $_FILES['wpress_file']['name'] ?? '' ) );
		if ( strtolower( substr( $name, -7 ) ) !== '.wpress' ) {
			wp_safe_redirect( self::get_page_url( __( 'File must have .wpress extension.', 'wpress-restore' ), false ) );
			exit;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Upload directory is not writable.', 'wpress-restore' ), false ) );
			exit;
		}
		$dir = $upload_dir['basedir'] . '/wpress-restore-uploads';
		if ( ! wp_mkdir_p( $dir ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Could not create upload directory.', 'wpress-restore' ), false ) );
			exit;
		}
		$dest = $dir . '/' . $name;
		if ( move_uploaded_file( $_FILES['wpress_file']['tmp_name'], $dest ) ) {
			$result = WPress_Restore::run( $dest, '', '' );
			if ( $result['success'] ) {
				wp_safe_redirect( self::get_page_url( $result['message'], true ) );
			} else {
				wp_safe_redirect( self::get_page_url( $result['message'], false ) );
			}
		} else {
			wp_safe_redirect( self::get_page_url( __( 'Failed to save uploaded file.', 'wpress-restore' ), false ) );
		}
		exit;
	}

	/**
	 * Handle form submit: restore from path.
	 */
	public static function handle_path() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Invalid security token.', 'wpress-restore' ), false ) );
			exit;
		}

		$path = isset( $_POST['wpress_path'] ) ? sanitize_text_field( wp_unslash( $_POST['wpress_path'] ) ) : '';
		$old_url  = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$old_home = isset( $_POST['old_home'] ) ? esc_url_raw( wp_unslash( $_POST['old_home'] ) ) : '';

		$result = WPress_Restore::run( $path, $old_url, $old_home );
		wp_safe_redirect( self::get_page_url( $result['message'], $result['success'] ) );
		exit;
	}

	/**
	 * Handle streaming restore: run restore and stream progress lines (STEP:message, DONE:message, ERROR:message).
	 */
	public static function handle_stream() {
		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			echo 'ERROR:' . esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) . "\n";
			exit;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			status_header( 403 );
			echo 'ERROR:' . esc_html__( 'Invalid security token.', 'wpress-restore' ) . "\n";
			exit;
		}

		$path    = '';
		$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$old_home = isset( $_POST['old_home'] ) ? esc_url_raw( wp_unslash( $_POST['old_home'] ) ) : '';

		// Restore from backup list (no path typing).
		$selected = isset( $_POST['selected_backup'] ) ? sanitize_file_name( wp_unslash( $_POST['selected_backup'] ) ) : '';
		if ( $selected !== '' ) {
			$path = WPress_Backup_Folder::get_path_for( $selected );
			if ( ! $path ) {
				echo 'ERROR:' . esc_html__( 'Invalid or missing backup file. Please select a file from the backup list.', 'wpress-restore' ) . "\n";
				exit;
			}
		} elseif ( ! empty( $_FILES['wpress_file']['tmp_name'] ) && is_uploaded_file( $_FILES['wpress_file']['tmp_name'] ) ) {
			$name = sanitize_file_name( wp_unslash( $_FILES['wpress_file']['name'] ?? '' ) );
			if ( strtolower( substr( $name, -7 ) ) !== '.wpress' ) {
				echo 'ERROR:' . esc_html__( 'File must have .wpress extension.', 'wpress-restore' ) . "\n";
				exit;
			}
			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['error'] ) ) {
				echo 'ERROR:' . esc_html__( 'Upload directory is not writable.', 'wpress-restore' ) . "\n";
				exit;
			}
			$dir = $upload_dir['basedir'] . '/wpress-restore-uploads';
			if ( ! wp_mkdir_p( $dir ) ) {
				echo 'ERROR:' . esc_html__( 'Could not create upload directory.', 'wpress-restore' ) . "\n";
				exit;
			}
			$path = $dir . '/' . $name;
			if ( ! move_uploaded_file( $_FILES['wpress_file']['tmp_name'], $path ) ) {
				echo 'ERROR:' . esc_html__( 'Failed to save uploaded file.', 'wpress-restore' ) . "\n";
				exit;
			}
		} else {
			$path = isset( $_POST['wpress_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpress_path'] ) ) ) : '';
			if ( $path !== '' && strpos( $path, '/' ) !== 0 ) {
				echo 'ERROR:' . esc_html__( 'Custom path must start with /. Or select a file from the backup list above.', 'wpress-restore' ) . "\n";
				exit;
			}
		}

		if ( empty( $path ) ) {
			echo 'ERROR:' . esc_html__( 'Please select a backup from the list above, or upload a file first.', 'wpress-restore' ) . "\n";
			exit;
		}

		// Stream progress as plain text (one line per event: STEP:message or DONE:message or ERROR:message).
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'zlib.output_compression', false );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-cache' );
		if ( ob_get_level() ) {
			ob_end_flush();
		}

		$progress = function( $step, $message ) {
			echo 'STEP:' . $step . ':' . $message . "\n";
			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();
		};

		$result = WPress_Restore::run( $path, $old_url, $old_home, $progress );
		if ( $result['success'] ) {
			echo 'DONE:' . $result['message'] . "\n";
		} else {
			echo 'ERROR:' . $result['message'] . "\n";
		}
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
		exit;
	}

	/**
	 * Handle direct restore (no streaming): run restore in one request and redirect.
	 * Use this if streaming restore returns 504 Gateway Timeout.
	 */
	public static function handle_direct() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpress-restore' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Invalid security token.', 'wpress-restore' ), false ) );
			exit;
		}

		$path    = '';
		$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$old_home = isset( $_POST['old_home'] ) ? esc_url_raw( wp_unslash( $_POST['old_home'] ) ) : '';

		$selected = isset( $_POST['selected_backup'] ) ? sanitize_file_name( wp_unslash( $_POST['selected_backup'] ) ) : '';
		if ( $selected !== '' ) {
			$path = WPress_Backup_Folder::get_path_for( $selected );
			if ( ! $path ) {
				wp_safe_redirect( self::get_page_url( __( 'Invalid or missing backup file. Please select a file from the backup list.', 'wpress-restore' ), false ) );
				exit;
			}
		} else {
			$path = isset( $_POST['wpress_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpress_path'] ) ) ) : '';
			if ( $path !== '' && strpos( $path, '/' ) !== 0 ) {
				wp_safe_redirect( self::get_page_url( __( 'Custom path must start with /. Or select a file from the backup list.', 'wpress-restore' ), false ) );
				exit;
			}
		}

		if ( empty( $path ) ) {
			wp_safe_redirect( self::get_page_url( __( 'Please select a backup from the list above, or enter a path.', 'wpress-restore' ), false ) );
			exit;
		}

		$result = WPress_Restore::run( $path, $old_url, $old_home );
		wp_safe_redirect( self::get_page_url( $result['message'], $result['success'] ) );
		exit;
	}

	/**
	 * Get admin page URL with optional message.
	 *
	 * @param string $message Message to show.
	 * @param bool   $success Whether success (green) or error (red).
	 * @return string
	 */
	protected static function get_page_url( $message = '', $success = true ) {
		$url = add_query_arg( 'page', self::SLUG, admin_url( 'tools.php' ) );
		if ( $message !== '' ) {
			$url = add_query_arg( array(
				'wpress_message' => rawurlencode( $message ),
				'wpress_success' => $success ? '1' : '0',
			), $url );
		}
		return $url;
	}
}

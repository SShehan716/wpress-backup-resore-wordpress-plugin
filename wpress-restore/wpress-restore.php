<?php
/**
 * Plugin Name: WPress Restore
 * Plugin URI: https://github.com/wpress-restore/wpress-restore
 * Description: Restore .wpress backup packages created by All-in-One WP Migration. Upload or provide a path to a .wpress file to restore database and files.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: WPress Restore
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpress-restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPRESS_RESTORE_VERSION', '1.0.0' );
define( 'WPRESS_RESTORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRESS_RESTORE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPRESS_RESTORE_PLUGIN_DIR . 'includes/class-wpress-extractor.php';
require_once WPRESS_RESTORE_PLUGIN_DIR . 'includes/class-wpress-database.php';
require_once WPRESS_RESTORE_PLUGIN_DIR . 'includes/class-wpress-restore.php';

if ( is_admin() ) {
	require_once WPRESS_RESTORE_PLUGIN_DIR . 'admin/class-admin-page.php';
	WPress_Restore_Admin_Page::init();
}

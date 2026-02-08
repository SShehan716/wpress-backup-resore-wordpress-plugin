<?php
/**
 * Admin form view: upload and path-to-file options.
 *
 * @package WPress_Restore
 * @var string $message Optional notice message.
 * @var bool   $success Whether message is success (true) or error (false).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wpress-restore-wrap">
	<h1><?php esc_html_e( 'WPress Restore', 'wpress-restore' ); ?></h1>
	<p><?php esc_html_e( 'Restore a WordPress site from a .wpress backup file (created by All-in-One WP Migration). You can upload a file or provide the path to an existing .wpress file on the server.', 'wpress-restore' ); ?></p>
	<p class="description" style="margin-bottom: 16px;">
		<strong><?php esc_html_e( 'Large files (e.g. 500MB+):', 'wpress-restore' ); ?></strong>
		<?php esc_html_e( 'Use "Restore from path" below: upload your .wpress file via FTP or your host\'s file manager to a folder under WordPress (e.g. wp-content/uploads), then enter the full server path. The plugin will extract and import in chunks without loading the whole file into memory.', 'wpress-restore' ); ?>
	</p>
	<div class="notice notice-info" style="margin: 16px 0;">
		<p>
			<strong><?php esc_html_e( 'How long does restore take?', 'wpress-restore' ); ?></strong><br />
			<?php
			echo esc_html(
				__( 'For a ~500MB backup, expect roughly 5–15 minutes on a typical shared or VPS host (extraction + database import + file copy). It can be faster on SSD/dedicated servers or slower under heavy load. The browser will show "Waiting..." or "Processing" until the restore finishes—do not close the tab. If the request times out, try running the restore again or ask your host to raise PHP max_execution_time.', 'wpress-restore' )
			);
			?>
		</p>
	</div>

	<?php if ( $message ) : ?>
		<div class="notice notice-<?php echo $success ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<div class="wpress-restore-boxes">
		<div class="wpress-restore-box">
			<h2><?php esc_html_e( 'Upload .wpress file', 'wpress-restore' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wpress_restore_upload" />
				<?php wp_nonce_field( WPress_Restore_Admin_Page::NONCE_ACTION ); ?>
				<p>
					<label for="wpress_file">
						<input type="file" name="wpress_file" id="wpress_file" accept=".wpress" required />
					</label>
				</p>
				<p class="description">
					<?php
					$max = wp_max_upload_size();
					echo esc_html( sprintf(
						/* translators: %s: max upload size */
						__( 'Maximum upload size: %s. For larger backups, use the path option below after uploading via FTP/SFTP.', 'wpress-restore' ),
						size_format( $max )
					) );
					?>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and restore', 'wpress-restore' ); ?></button>
				</p>
			</form>
		</div>

		<div class="wpress-restore-box">
			<h2><?php esc_html_e( 'Restore from path', 'wpress-restore' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpress_restore_path" />
				<?php wp_nonce_field( WPress_Restore_Admin_Page::NONCE_ACTION ); ?>
				<p>
					<label for="wpress_path"><?php esc_html_e( 'Path to .wpress file', 'wpress-restore' ); ?></label><br />
					<input type="text" name="wpress_path" id="wpress_path" class="large-text" placeholder="<?php echo esc_attr( ABSPATH . 'wp-content/uploads/backup.wpress' ); ?>" value="" />
				</p>
				<p class="description">
					<?php esc_html_e( 'Full server path to the .wpress file (e.g. after uploading via FTP to wp-content/uploads). Must be under WordPress root or wp-content/uploads. Use this for large backups (500MB+).', 'wpress-restore' ); ?>
				</p>
				<p>
					<label for="old_url"><?php esc_html_e( 'Old site URL (optional)', 'wpress-restore' ); ?></label><br />
					<input type="url" name="old_url" id="old_url" class="large-text" placeholder="https://old-site.com" value="" />
				</p>
				<p>
					<label for="old_home"><?php esc_html_e( 'Old home URL (optional)', 'wpress-restore' ); ?></label><br />
					<input type="url" name="old_home" id="old_home" class="large-text" placeholder="https://old-site.com" value="" />
				</p>
				<p class="description">
					<?php esc_html_e( 'If left blank, the plugin will try to detect from the backup. Enter the old site URL and home URL if restore shows wrong domain.', 'wpress-restore' ); ?>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Restore from path', 'wpress-restore' ); ?></button>
				</p>
			</form>
		</div>
	</div>
</div>

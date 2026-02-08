<?php
/**
 * Admin form: upload to backup folder, list backups, restore selected.
 *
 * @package WPress_Restore
 * @var string   $message   Optional notice message.
 * @var bool     $success   Whether message is success (true) or error (false).
 * @var array[]  $backups   List of backup files from WPress_Backup_Folder::list_backups().
 * @var string   $backup_dir Full path to backup folder (for FTP hint).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$backups   = isset( $backups ) ? $backups : array();
$backup_dir = isset( $backup_dir ) ? $backup_dir : '';
?>
<div class="wrap wpress-restore-wrap">
	<h1><?php esc_html_e( 'WPress Restore', 'wpress-restore' ); ?></h1>
	<p><?php esc_html_e( 'Restore a WordPress site from a .wpress backup (All-in-One WP Migration format). Upload a backup to the list below, then select it and click Restore. No path typing required.', 'wpress-restore' ); ?></p>

	<?php if ( $message ) : ?>
		<div class="notice notice-<?php echo $success ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info" style="margin: 16px 0;">
		<p>
			<strong><?php esc_html_e( 'How long does restore take?', 'wpress-restore' ); ?></strong><br />
			<?php echo esc_html( __( 'For a ~500MB backup, expect roughly 5–15 minutes. Do not close the tab until restore finishes. If it times out, try again or ask your host to raise PHP max_execution_time.', 'wpress-restore' ) ); ?>
		</p>
	</div>

	<!-- 1. Upload to backup folder -->
	<div class="wpress-restore-box" style="margin-bottom: 24px;">
		<h2><?php esc_html_e( '1. Add backup file', 'wpress-restore' ); ?></h2>
		<p class="description" style="margin-bottom: 12px;">
			<?php esc_html_e( 'Upload a .wpress file to the backup folder. It will appear in the list below. For files larger than your server upload limit, add the file via FTP to the same folder.', 'wpress-restore' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="wpress_restore_upload_to_backups" />
			<?php wp_nonce_field( WPress_Restore_Admin_Page::NONCE_ACTION ); ?>
			<p>
				<input type="file" name="wpress_backup_file" id="wpress_backup_file" accept=".wpress" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload to backup list', 'wpress-restore' ); ?></button>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: max upload size */
					esc_html__( 'Max upload via form: %s. Larger files: upload via FTP to this folder on the server:', 'wpress-restore' ),
					esc_html( size_format( wp_max_upload_size() ) )
				);
				?>
				<code><?php echo esc_html( $backup_dir ? $backup_dir : 'wp-content/wpress-restore-backups' ); ?></code>
			</p>
		</form>
	</div>

	<!-- 2. Backup list + Restore selected -->
	<div class="wpress-restore-box" style="margin-bottom: 24px;">
		<h2><?php esc_html_e( '2. Backup files — select and restore', 'wpress-restore' ); ?></h2>
		<?php if ( empty( $backups ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'No backup files yet. Upload a .wpress file above (or add one via FTP to the folder above), then it will appear here.', 'wpress-restore' ); ?>
			</p>
		<?php else : ?>
			<form id="wpress-restore-select-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( WPress_Restore_Admin_Page::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="wpress_restore_stream" />
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 40px;"><?php esc_html_e( 'Select', 'wpress-restore' ); ?></th>
							<th><?php esc_html_e( 'File', 'wpress-restore' ); ?></th>
							<th><?php esc_html_e( 'Size', 'wpress-restore' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wpress-restore' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<tr>
								<td>
									<input type="radio" name="selected_backup" value="<?php echo esc_attr( $backup['name'] ); ?>" id="backup_<?php echo esc_attr( sanitize_key( $backup['name'] ) ); ?>" required />
								</td>
								<td><label for="backup_<?php echo esc_attr( sanitize_key( $backup['name'] ) ); ?>"><?php echo esc_html( $backup['name'] ); ?></label></td>
								<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['date'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top: 12px;">
					<label for="old_url_restore"><?php esc_html_e( 'Old site URL (optional)', 'wpress-restore' ); ?></label>
					<input type="url" name="old_url" id="old_url_restore" class="regular-text" placeholder="https://old-site.com" style="margin-left: 8px;" />
					<label for="old_home_restore" style="margin-left: 16px;"><?php esc_html_e( 'Old home URL (optional)', 'wpress-restore' ); ?></label>
					<input type="url" name="old_home" id="old_home_restore" class="regular-text" placeholder="https://old-site.com" style="margin-left: 8px;" />
				</p>
				<p style="margin-top: 12px;">
					<button type="submit" class="button button-primary button-hero" id="wpress-restore-selected-btn"><?php esc_html_e( 'Restore selected backup', 'wpress-restore' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	</div>

	<!-- 3. Advanced: restore from custom path -->
	<details class="wpress-restore-box" style="margin-bottom: 24px;">
		<summary style="cursor: pointer;"><?php esc_html_e( 'Advanced: Restore from custom path', 'wpress-restore' ); ?></summary>
		<p class="description" style="margin-top: 10px;">
			<?php esc_html_e( 'If your .wpress file is elsewhere on the server, enter the full path below.', 'wpress-restore' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpress-advanced-path-form">
			<input type="hidden" name="action" value="wpress_restore_stream" />
			<?php wp_nonce_field( WPress_Restore_Admin_Page::NONCE_ACTION ); ?>
			<p>
				<label for="wpress_path"><?php esc_html_e( 'Full server path', 'wpress-restore' ); ?></label><br />
				<input type="text" name="wpress_path" id="wpress_path" class="large-text" placeholder="/home/username/public_html/wp-content/uploads/backup.wpress" style="margin-top: 4px;" />
			</p>
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Restore from path', 'wpress-restore' ); ?></button>
			</p>
		</form>
	</details>
</div>

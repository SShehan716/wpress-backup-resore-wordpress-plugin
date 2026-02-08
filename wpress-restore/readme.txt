=== WPress Restore ===

Contributors: wpress-restore
Tags: backup, restore, migration, wpress, all-in-one wp migration
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restore .wpress backup packages created by All-in-One WP Migration. Upload or provide a path to restore database and files.

== Description ==

WPress Restore allows you to restore a full WordPress site from a `.wpress` backup file created by the All-in-One WP Migration plugin. You can either upload the file through the admin or provide the server path to an existing file (e.g. after uploading via FTP).

**Features:**

* Extract .wpress archives (All-in-One WP Migration format)
* Import the database with automatic URL search-replace (old site URL → current)
* Copy wp-content (themes, plugins, uploads) into your WordPress installation
* Optional manual old URL / home URL for search-replace
* Skips overwriting wp-config.php and the WPress Restore plugin itself

**Usage:**

1. Go to **Tools → WPress Restore**.
2. Either upload a .wpress file or enter the full server path to a .wpress file (e.g. after uploading via FTP to `wp-content/uploads`).
3. Optionally enter the old site URL and home URL if the backup was from a different domain.
4. Click **Upload and restore** or **Restore from path**.
5. After restore, go to **Settings → Permalinks** and click **Save** to refresh permalinks.

For very large backups, PHP upload limits may apply. Use the path option and upload the .wpress file via FTP/SFTP to a directory under your WordPress root or `wp-content/uploads`, then enter that path.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin** and install.
2. Activate the plugin.
3. Go to **Tools → WPress Restore** to restore a .wpress backup.

== Frequently Asked Questions ==

= Can I restore backups from All-in-One WP Migration? =

Yes. WPress Restore reads the same .wpress format created by All-in-One WP Migration.

= The backup is larger than my PHP upload limit =

Upload the .wpress file via FTP or your host's file manager to a folder under WordPress (e.g. `wp-content/uploads`). Then use the "Restore from path" option and enter the full server path to the file.

= White screen after restore? =

A blank/white screen usually means a fatal PHP error (often from a restored plugin or theme). Try:

1. **Disable plugins:** Via FTP or file manager, rename `wp-content/plugins` to `wp-content/plugins_disabled`. If the site loads, a plugin is the cause; rename back and enable plugins one by one to find which one.
2. **Switch theme:** If the site still shows white with plugins disabled, rename `wp-content/themes/your-active-theme` to add `.bak` and WordPress will fall back to a default theme.
3. **See the error:** In `wp-config.php` add before "That's all": `define('WP_DEBUG', true);` and `define('WP_DEBUG_LOG', true);`. Reload the page, then check `wp-content/debug.log` for the fatal error message.

== Changelog ==

= 1.0.0 =
* Initial release. Extract .wpress, import database with URL replace, copy files, flush permalinks.

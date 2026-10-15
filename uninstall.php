<?php
/**
 * Uninstall cleanup. Runs only on delete, never on deactivate.
 *
 * Names are duplicated as literals because WordPress loads this file without
 * the autoloader; keep them in sync with the classes that own them:
 * Config::OPTION, Environment::OPTION, SelfTest::OPTION, ::CDN_CHOICE_OPTION
 * and ::FOLDER, Htaccess' state option, Queue::STATE_OPTION, ::HOOK and its
 * lock, Library's three meta keys, Updater's cache. The .htaccess blocks are
 * already gone - a plugin is always deactivated before it can be deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

/*
 * The generated .webp/.avif files, found through the attachments that have
 * them (their _lean_images_bytes row), before that meta is deleted below.
 * Bounded by time: on a library too large to finish in one request some files
 * stay behind - harmless once the delivery rules are gone. The settings page's
 * "Remove generated files" does it in batches beforehand.
 */
$lean_images_deadline = time() + 20;
$lean_images_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_lean_images_bytes')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$lean_images_uploads = wp_upload_dir(null, false);
$lean_images_basedir = empty($lean_images_uploads['error']) ? trailingslashit(wp_normalize_path($lean_images_uploads['basedir'])) : '';

foreach ($lean_images_ids as $lean_images_id) {
	if (time() > $lean_images_deadline || '' === $lean_images_basedir) {
		break;
	}

	// As Encoder::is_in_uploads(): paths from metadata stay inside uploads.
	$lean_images_main = wp_normalize_path((string) get_attached_file((int) $lean_images_id));
	if (0 !== strpos($lean_images_main, $lean_images_basedir) || preg_match('#(^|/)\.\.(/|$)#', $lean_images_main)) {
		continue;
	}

	$lean_images_paths = [$lean_images_main];
	$lean_images_meta = wp_get_attachment_metadata((int) $lean_images_id);
	foreach ((array) ($lean_images_meta['sizes'] ?? []) as $lean_images_size) {
		$lean_images_paths[] = dirname($lean_images_main) . '/' . wp_basename((string) ($lean_images_size['file'] ?? ''));
	}

	foreach ($lean_images_paths as $lean_images_path) {
		foreach (['.webp', '.avif'] as $lean_images_extension) {
			if (file_exists($lean_images_path . $lean_images_extension)) {
				wp_delete_file($lean_images_path . $lean_images_extension);
			}
		}
	}
}

$lean_images_options = [
	'lean_images_settings',
	'lean_images_environment',
	'lean_images_selftest',
	'lean_images_cdn_choice',
	'lean_images_htaccess',
	'lean_images_queue',
	'lean_images_lock',
];

foreach ($lean_images_options as $lean_images_option) {
	delete_option($lean_images_option);
}

wp_clear_scheduled_hook('lean_images_work');
delete_site_transient('lean_images_update'); // Updater's cached update.json.

foreach (['_lean_images_status', '_lean_images_bytes', '_lean_images'] as $lean_images_key) {
	delete_post_meta_by_key($lean_images_key);
}

// The self-test's three sample images.
$lean_images_uploads = wp_upload_dir(null, false);
if (empty($lean_images_uploads['error'])) {
	$lean_images_folder = trailingslashit($lean_images_uploads['basedir']) . 'lean-images-selftest';

	foreach (['test.jpg', 'test.jpg.webp', 'test.jpg.avif'] as $lean_images_file) {
		wp_delete_file("{$lean_images_folder}/{$lean_images_file}");
	}

	if (is_dir($lean_images_folder)) {
		@rmdir($lean_images_folder); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only succeeds when empty, which is the point.
	}
}

unset(
	$lean_images_deadline,
	$lean_images_ids,
	$lean_images_id,
	$lean_images_main,
	$lean_images_paths,
	$lean_images_meta,
	$lean_images_size,
	$lean_images_path,
	$lean_images_extension,
	$lean_images_options,
	$lean_images_option,
	$lean_images_key,
	$lean_images_uploads,
	$lean_images_basedir,
	$lean_images_folder,
	$lean_images_file
);

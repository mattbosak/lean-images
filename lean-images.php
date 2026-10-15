<?php
/**
 * Plugin Name:       Lean Images
 * Plugin URI:        https://github.com/mattbosak/lean-images
 * Description:       Serves AVIF and WebP versions of your images to browsers that support them, made on your own server.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Matt Bosak
 * Author URI:        https://github.com/mattbosak
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lean-images
 * Update URI:        https://github.com/mattbosak/lean-images
 */

/**
 * Copyright (c) 2026 Matt Bosak. All rights reserved.
 */

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

define('LEAN_IMAGES_FILE', __FILE__);
define('LEAN_IMAGES_DIR', __DIR__);
define('LEAN_IMAGES_BASENAME', plugin_basename(__FILE__));
define('LEAN_IMAGES_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

require_once __DIR__ . '/src/autoload.php';

/**
 * Blocks activation on multisite - not supported yet: the network shares one
 * main .htaccess, so the sites would overwrite each other's rules - and on a
 * server with no way to encode WebP at all, where the plugin would have nothing
 * to do. Everything short of that (no AVIF, no ImageMagick, a web server that
 * ignores .htaccess) is a reasonable state to activate into and is reported by
 * the self-test instead.
 *
 * The self-test itself can't run here: during activation the plugin's other
 * hooks (its image editor among them) aren't registered yet, so it would
 * report its own editor missing. Clearing the cached result makes it run on
 * the next admin page instead.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if (is_multisite()) {
			wp_die(
				esc_html__('Lean Images doesn\'t support multisite networks yet. It works on single-site WordPress installs.', 'lean-images'),
				esc_html__('Plugin requirement check failed', 'lean-images'),
				['back_link' => true]
			);
		}

		if (!Environment::can_encode_webp()) {
			wp_die(
				esc_html__('Lean Images needs the Imagick PHP extension, or GD with WebP support. This server has neither, so there is nothing to convert images with. Ask your host to enable Imagick.', 'lean-images'),
				esc_html__('Plugin requirement check failed', 'lean-images'),
				['back_link' => true]
			);
		}

		Htaccess::write();
		delete_option(SelfTest::OPTION);
	}
);

/**
 * Takes the delivery rules out, so the site serves its JPEG and PNG files again
 * immediately, and stops the background queue. Generated files stay;
 * reactivating restores delivery.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		Htaccess::remove();
		Queue::pause();
	}
);

/**
 * Boots on plugins_loaded, once other plugins have loaded.
 */
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->init();
	}
);

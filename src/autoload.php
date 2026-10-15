<?php
/**
 * PSR-4-style autoloader for the LeanImages\ namespace.
 */

if (!defined('ABSPATH')) {
	exit;
}

spl_autoload_register(
	static function ($class) {
		$prefix = 'LeanImages\\';

		if (0 !== strncmp($prefix, $class, strlen($prefix))) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$path = LEAN_IMAGES_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';

		if (file_exists($path)) {
			require $path;
		}
	}
);

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Updates from the plugin's GitHub releases, through WordPress's own update
 * process: the header's Update URI makes core skip wordpress.org for this
 * plugin and ask the update_plugins_{hostname} filter instead.
 *
 * Every release carries update.json (version, zip URL, requirements,
 * changelog), read from .../releases/latest/download/update.json - a plain
 * download URL, not the REST API, so many sites behind one server IP can't run
 * into GitHub's API rate limit. Drafts and pre-releases are never "latest".
 *
 * Everything specific to this plugin is in the constants, so the class works
 * for another plugin with only those changed.
 */
final class Updater
{

	private const REPOSITORY = 'https://github.com/mattbosak/lean-images';
	private const MANIFEST_URL = self::REPOSITORY . '/releases/latest/download/update.json';
	private const PACKAGE_PREFIX = self::REPOSITORY . '/releases/download/';
	private const PACKAGE_FILE = 'lean-images.zip';
	private const CACHE_KEY = 'lean_images_update';
	private const CACHE_SECONDS = 12 * HOUR_IN_SECONDS;

	/** After a failed check: long enough not to slow every admin page. */
	private const RETRY_SECONDS = HOUR_IN_SECONDS;

	public function register(): void
	{
		// The hook is per hostname, so every plugin updating from GitHub shares it;
		// check() answers only for this one.
		add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);

		// "Check again" on Dashboard → Updates clears core's cache; this one goes too.
		add_action('delete_site_transient_update_plugins', [self::class, 'forget']);
		add_action('upgrader_process_complete', [self::class, 'forget']);
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public function check($update, $plugin_data, $plugin_file)
	{
		if (LEAN_IMAGES_BASENAME !== $plugin_file) {
			return $update;
		}

		$manifest = self::manifest();
		if (null === $manifest) {
			return $update;
		}

		// Core compares the version itself: an equal or lower one is listed as
		// "no update".
		return [
			'id' => self::REPOSITORY,
			'slug' => \dirname(LEAN_IMAGES_BASENAME),
			'version' => $manifest['version'],
			'url' => self::REPOSITORY,
			'package' => $manifest['package'],
			'requires' => (string) ($manifest['requires'] ?? ''),
			'tested' => (string) ($manifest['tested'] ?? ''),
			'requires_php' => (string) ($manifest['requires_php'] ?? ''),
		];
	}

	/**
	 * The latest release's update.json, cached.
	 */
	public static function manifest(): ?array
	{
		$cached = get_site_transient(self::CACHE_KEY);
		if (\is_array($cached) && \array_key_exists('manifest', $cached)) {
			return $cached['manifest'];
		}

		$manifest = null;
		$response = wp_remote_get(
			(string) apply_filters('lean_images_update_manifest_url', self::MANIFEST_URL),
			['timeout' => 10, 'headers' => ['Accept' => 'application/json']]
		);

		if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
			$manifest = self::validated(\json_decode(wp_remote_retrieve_body($response), true));
		}

		set_site_transient(self::CACHE_KEY, ['manifest' => $manifest], null === $manifest ? self::RETRY_SECONDS : self::CACHE_SECONDS);

		return $manifest;
	}

	/**
	 * The manifest when it offers a newer version than the one installed.
	 */
	public static function newer(): ?array
	{
		$manifest = self::manifest();

		return null !== $manifest && \version_compare($manifest['version'], LEAN_IMAGES_VERSION, '>') ? $manifest : null;
	}

	public static function forget(): void
	{
		delete_site_transient(self::CACHE_KEY);
	}

	/**
	 * The manifest reduced to fields in the shape WordPress expects - core prints
	 * the version fields into "View details" unescaped - or null. The zip must be
	 * exactly this version's own release download from this repository: nothing
	 * else is ever handed to the upgrader.
	 *
	 * @param mixed $data
	 */
	private static function validated($data): ?array
	{
		if (!\is_array($data) || !\is_string($data['version'] ?? null) || !\preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
			return null;
		}

		$package = (string) apply_filters('lean_images_update_package_prefix', self::PACKAGE_PREFIX) . "v{$data['version']}/" . self::PACKAGE_FILE;
		if (($data['package'] ?? null) !== $package) {
			return null;
		}

		$manifest = ['version' => $data['version'], 'package' => $package];

		foreach (['requires', 'tested', 'requires_php'] as $key) {
			if (\is_string($data[$key] ?? null) && \preg_match('/^\d+(\.\d+){0,2}$/', $data[$key])) {
				$manifest[$key] = $data[$key];
			}
		}

		if (\is_string($data['last_updated'] ?? null) && \preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $data['last_updated'])) {
			$manifest['last_updated'] = $data['last_updated'];
		}

		// Escaped where it's shown (UpdateInfo::format_changelog()).
		if (\is_array($data['changelog'] ?? null)) {
			$manifest['changelog'] = $data['changelog'];
		}

		return $manifest;
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * The delivery rules: a browser that accepts AVIF gets photo.jpg.avif for
 * photo.jpg when that file exists, else WebP when it accepts that and the file
 * exists, else the JPEG. Every image response carries Vary: Accept so caches
 * keep the formats apart.
 *
 * Two blocks, both marked "Lean Images":
 * - The types and rewrite rules, at the end of the site's main .htaccess
 *   (WordPress's file). Not in the uploads folder: a .htaccess with rewrite
 *   rules of its own switches off every parent folder's rewrite rules there,
 *   and Apache's inherit options don't bring them back - a security plugin's
 *   "no PHP in uploads" rule stopped working (CLAUDE.md, "Delivery"). At the
 *   end, so rules above it (hotlink protection) still see the original request.
 * - The Vary header in the uploads folder's .htaccess - scoped to uploads, and
 *   a directive that doesn't affect anyone else's.
 */
final class Htaccess
{

	public const MARKER = 'Lean Images';

	/**
	 * Hash of the rules last written and the files they went to, to rewrite
	 * them when they change - or 'suspended' while the self-test has delivery
	 * paused.
	 */
	private const STATE_OPTION = 'lean_images_htaccess';

	public function register(): void
	{
		add_action('admin_init', [self::class, 'ensure_current']);
	}

	/**
	 * The formats delivered: switched on and, for AVIF, possible on this server.
	 * Turning a format off stops its existing files being served without
	 * deleting them. AVIF that's on but can't be made gets no rule either - it
	 * would serve nothing real, only the self-test's own AVIF file, which then
	 * reached browsers that should have got WebP.
	 *
	 * @return string[]
	 */
	public static function formats(): array
	{
		return \array_keys(\array_filter([
			'avif' => Config::avif_enabled() && Environment::can_encode_avif(),
			'webp' => Config::webp_enabled(),
		]));
	}

	/**
	 * Where the main .htaccess is and how the uploads address looks from it.
	 * Null when the uploads aren't under the site's own address (another host,
	 * a CDN) or WordPress's files are laid out in a way this can't follow.
	 *
	 * @return array{dir: string, prefix: string, url_path: string, uploads: string}|null
	 */
	public static function location(): ?array
	{
		$uploads = wp_upload_dir(null, false);
		if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
			return null;
		}

		$home = wp_parse_url(home_url('/'));
		$site = wp_parse_url(site_url('/'));
		$base = wp_parse_url((string) $uploads['baseurl']);

		$host = static fn ($url) => \strtolower(($url['host'] ?? '') . ':' . ($url['port'] ?? ''));
		if (!\is_array($home) || !\is_array($site) || !\is_array($base) || $host($base) !== $host($home)) {
			return null;
		}

		$home_path = trailingslashit($home['path'] ?? '/');
		$site_path = trailingslashit($site['path'] ?? '/');
		$upload_path = trailingslashit($base['path'] ?? '/');

		if (0 !== \strpos($upload_path, $home_path) || 0 !== \strpos($site_path, $home_path)) {
			return null;
		}

		// WordPress in a subfolder of the site's address: ABSPATH is that
		// subfolder, and the main .htaccess sits one level (or more) up.
		$dir = trailingslashit(wp_normalize_path(ABSPATH));
		$sub = (string) \substr($site_path, \strlen($home_path));
		if ('' !== $sub) {
			if ('/' . $sub !== \substr($dir, -\strlen('/' . $sub))) {
				return null;
			}
			$dir = (string) \substr($dir, 0, -\strlen($sub));
		}

		$prefix = untrailingslashit((string) \substr($upload_path, \strlen($home_path)));

		// Characters that would need escaping in a rewrite rule: not worth it.
		if ('' === $prefix || \preg_match('/[\s%$\\\\"]/', $prefix)) {
			return null;
		}

		return [
			'dir' => $dir,
			'prefix' => $prefix,
			'url_path' => untrailingslashit($upload_path),
			'uploads' => trailingslashit(wp_normalize_path($uploads['basedir'])),
		];
	}

	/**
	 * For the main .htaccess: the types and the rewrite rules. The rule's
	 * pattern is the address relative to that file's folder; the target is an
	 * absolute path, so WordPress's RewriteBase doesn't matter.
	 *
	 * The types matter more than they look: in a .htaccess, Apache drops the
	 * rule's T= type at the internal redirect, so the file's extensions decide -
	 * every one of them. photo.jpg.webp came out as image/jpeg on an Apache
	 * that didn't know .webp (Ubuntu 22.04's).
	 *
	 * @return string[]
	 */
	public static function main_rules(?array $location = null): array
	{
		$location = $location ?? self::location();
		if (null === $location) {
			return [];
		}

		$lines = [
			'<IfModule mod_mime.c>',
			"\tAddType image/avif .avif .avifs",
			"\tAddType image/webp .webp",
			'</IfModule>',
		];

		$formats = self::formats();
		if (!$formats) {
			return $lines;
		}

		$pattern = '^' . \preg_quote($location['prefix']) . '/(.+)\\.(jpe?g|png)$';

		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = "\tRewriteEngine On";
		foreach ($formats as $format) {
			$lines[] = "\tRewriteCond %{HTTP_ACCEPT} image/{$format}";
			$lines[] = "\tRewriteCond %{REQUEST_FILENAME}.{$format} -f";
			$lines[] = "\tRewriteRule {$pattern} {$location['url_path']}/\$1.\$2.{$format} [NC,T=image/{$format},L]";
		}
		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * For the uploads folder's .htaccess: Vary on the JPEG/PNG responses too,
	 * or a cache could store the JPEG and hand it to every browser. (Apache adds
	 * Vary itself only when a rule matched.) (?i) because FilesMatch is
	 * case-sensitive.
	 *
	 * @return string[]
	 */
	public static function uploads_rules(): array
	{
		return [
			'<IfModule mod_headers.c>',
			"\t<FilesMatch \"(?i)\\.(jpe?g|png|avif|webp)$\">",
			"\t\tHeader merge Vary Accept",
			"\t</FilesMatch>",
			'</IfModule>',
		];
	}

	/**
	 * Each file and its block, keyed by path - for writing, and for showing on
	 * the settings screen.
	 *
	 * @return array<string, string[]>
	 */
	public static function blocks(): array
	{
		$location = self::location();
		if (null === $location) {
			return [];
		}

		return [
			$location['dir'] . '.htaccess' => self::main_rules($location),
			$location['uploads'] . '.htaccess' => self::uploads_rules(),
		];
	}

	/**
	 * A path as shown to people: relative to the site's folder.
	 */
	public static function display_path(string $path): string
	{
		$location = self::location();
		$dir = null !== $location ? $location['dir'] : trailingslashit(wp_normalize_path(ABSPATH));

		return 0 === \strpos($path, $dir) ? (string) \substr($path, \strlen($dir)) : wp_basename(\dirname($path)) . '/.htaccess';
	}

	/**
	 * @return bool Whether both files could be written.
	 */
	public static function write(): bool
	{
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$blocks = self::blocks();
		$written = (bool) $blocks;
		foreach ($blocks as $path => $lines) {
			// A new site has no uploads folder until its first upload.
			$written = wp_mkdir_p(\dirname($path)) && insert_with_markers($path, self::MARKER, $lines) && $written;
		}

		update_option(self::STATE_OPTION, [
			'hash' => self::hash($blocks),
			'written' => $written,
			'files' => \array_keys($blocks),
		], false);

		return $written;
	}

	/**
	 * Rewrites the rules when they changed (a setting, the server probe, a plugin
	 * update, the site's address) or went missing - security plugins sometimes
	 * rewrite a .htaccess whole. For administrators only: admin_init also runs
	 * for anonymous admin-ajax.php requests. Two option reads and two small file
	 * reads.
	 */
	public static function ensure_current(): void
	{
		if (!current_user_can('manage_options') || self::is_suspended()) {
			return;
		}

		$state = get_option(self::STATE_OPTION);
		$blocks = self::blocks();

		$current = \is_array($state) && self::hash($blocks) === ($state['hash'] ?? '');
		foreach (\array_keys($blocks) as $path) {
			$current = $current && false !== \strpos((string) @\file_get_contents($path), '# BEGIN ' . self::MARKER);
		}

		if (!$current) {
			self::remove_blocks(\array_diff((array) ($state['files'] ?? []), \array_keys($blocks)));
			self::write();
		}
	}

	/**
	 * Takes both blocks out - wherever they were last written, too - and forgets
	 * the state.
	 */
	public static function remove(): void
	{
		$state = get_option(self::STATE_OPTION);
		delete_option(self::STATE_OPTION);

		self::remove_blocks(\array_unique(\array_merge((array) ($state['files'] ?? []), \array_keys(self::blocks()))));
	}

	/**
	 * Takes the rules out and keeps them out - including through setting changes -
	 * until write() puts them back, which only SelfTest::run() and activation do.
	 */
	public static function suspend(): void
	{
		self::remove();
		update_option(self::STATE_OPTION, ['suspended' => true], false);
	}

	public static function is_suspended(): bool
	{
		$state = get_option(self::STATE_OPTION);

		return \is_array($state) && !empty($state['suspended']);
	}

	public static function is_written(): bool
	{
		$state = get_option(self::STATE_OPTION);

		return \is_array($state) && !empty($state['written']);
	}

	/**
	 * .htaccess files between the main one and the uploads folder that have
	 * rewrite rules of their own - each switches off the main file's rules
	 * (ours included) for the folders below it.
	 *
	 * @return string[] Paths as shown to people.
	 */
	public static function overriding_files(): array
	{
		$location = self::location();
		if (null === $location || 0 !== \strpos($location['uploads'], $location['dir'])) {
			return [];
		}

		$found = [];
		$path = $location['dir'];
		foreach (\explode('/', \trim((string) \substr($location['uploads'], \strlen($location['dir'])), '/')) as $folder) {
			$path .= $folder . '/';
			$contents = self::without_block((string) @\file_get_contents($path . '.htaccess'));

			if (\preg_match('/^\s*Rewrite(Engine|Rule)\b/mi', $contents)) {
				$found[] = self::display_path($path . '.htaccess');
			}
		}

		return $found;
	}

	/**
	 * Removes each file's whole block, markers included - insert_with_markers()
	 * with no lines would leave an empty block behind. Deletes a file only if
	 * nothing else is left in it.
	 *
	 * @param string[] $paths
	 */
	private static function remove_blocks(array $paths): void
	{
		foreach ($paths as $path) {
			if (!\is_file($path) || !\is_writable($path)) {
				continue;
			}

			$contents = (string) \file_get_contents($path);
			$cleaned = self::without_block($contents);

			if ($cleaned === $contents) {
				continue;
			}

			if ('' === \trim($cleaned)) {
				@\unlink($path);
			} else {
				\file_put_contents($path, $cleaned, LOCK_EX);
			}
		}
	}

	private static function without_block(string $contents): string
	{
		$marker = \preg_quote(self::MARKER, '/');

		return (string) \preg_replace("/(?:\\r?\\n)?# BEGIN {$marker}\\r?\\n.*?# END {$marker}[ \\t]*(?:\\r?\\n)?/s", '', $contents);
	}

	/**
	 * @param array<string, string[]> $blocks
	 */
	private static function hash(array $blocks): string
	{
		return \md5((string) wp_json_encode($blocks));
	}
}

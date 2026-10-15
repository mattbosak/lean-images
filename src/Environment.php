<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * What this server can do with images, found by actually doing it: encoding,
 * decoding bundled samples, timing an AVIF encode. A format in ImageMagick's
 * list is not proof - ImageMagick lists AVIF on hosts where libheif can't open
 * common AVIF files (CLAUDE.md, "AVIF and HEIC inputs").
 *
 * The probe runs from the self-test and is cached in an option; everything else
 * reads the cache.
 */
final class Environment
{

	public const OPTION = 'lean_images_environment';

	/**
	 * AVIF slower than this for the 1500 × 1000 probe image moves AVIF out of the
	 * upload request, into the background queue. Measured: 0.23 s on a shared host
	 * with ImageMagick 7.1, 1.2-1.5 s on ImageMagick 6 with an old libaom.
	 */
	private const AVIF_INLINE_SECONDS = 0.8;

	/**
	 * Reads the format lists only. That's enough to refuse activation on a server
	 * with no encoder at all; the probe below is what proves encoding works.
	 */
	public static function can_encode_webp(): bool
	{
		return self::imagick_lists('WEBP') || self::gd_has_webp();
	}

	/**
	 * The engine conversion runs on: Imagick when it can make WebP, otherwise
	 * GD (CLAUDE.md, "GD fallback"). Before the first probe, the format lists
	 * decide.
	 */
	public static function engine(): ?string
	{
		$probe = self::cached();

		if (null === $probe) {
			return self::imagick_lists('WEBP') ? 'imagick' : (self::gd_has_webp() ? 'gd' : null);
		}

		if (!empty($probe['imagick']['webp'])) {
			return 'imagick';
		}

		return !empty($probe['gd']['webp']) ? 'gd' : null;
	}

	/**
	 * The engine that makes AVIF: the conversion engine when it can, otherwise
	 * GD when GD can - hosts whose ImageMagick was built without libheif but
	 * whose PHP has GD with libavif (CLAUDE.md, "AVIF through GD"). Null when
	 * neither can.
	 */
	public static function avif_engine(): ?string
	{
		$engine = self::engine();
		if (null === $engine) {
			return null;
		}

		$probe = self::cached();

		if (null === $probe) {
			if ('imagick' === $engine && self::imagick_lists('AVIF')) {
				return 'imagick';
			}

			return \function_exists('imageavif') ? 'gd' : null;
		}

		if (!empty($probe[$engine]['avif'])) {
			return $engine;
		}

		return !empty($probe['gd']['avif']) ? 'gd' : null;
	}

	public static function can_encode_avif(): bool
	{
		return null !== self::avif_engine();
	}

	/**
	 * The engine that opens AVIF uploads, going down WordPress's editor list:
	 * Imagick first when it's the engine, GD first otherwise (this plugin's
	 * Imagick editor steps aside then; core's comes after GD). Probe results
	 * only - null before the first probe.
	 */
	public static function avif_reader(): ?string
	{
		$probe = self::cached();
		$order = 'imagick' === self::engine() ? ['imagick', 'gd'] : ['gd', 'imagick'];

		foreach ($order as $engine) {
			if (!empty($probe[$engine]['reads_avif'])) {
				return $engine;
			}
		}

		return null;
	}

	/**
	 * False on hosts too slow to encode AVIF during an upload without making it
	 * drag. GD always counts as slow: its AVIF took 1.3-2.7 s per 1500px image
	 * where ImageMagick took 0.4 s on the same machine. Unknown speed (no probe
	 * yet) counts as fast.
	 */
	public static function avif_during_upload(): bool
	{
		if ('gd' === self::avif_engine()) {
			return false;
		}

		$seconds = self::cached()['timing']['avif'] ?? null;
		$limit = (float) apply_filters('lean_images_avif_inline_seconds', self::AVIF_INLINE_SECONDS);

		return null === $seconds || $seconds <= $limit;
	}

	public static function cached(): ?array
	{
		$probe = get_option(self::OPTION);

		return \is_array($probe) ? $probe : null;
	}

	/**
	 * @return array{
	 *     checked: int,
	 *     imagick: ?array{extension: string, version: string, webp: bool, avif: bool, reads_avif: bool, reads_heic: bool},
	 *     gd: ?array{version: string, webp: bool, avif: bool, reads_avif: bool},
	 *     timing: array{webp?: float, avif?: float}
	 * }
	 */
	public static function probe(): array
	{
		$result = [
			'checked' => \time(),
			'imagick' => self::probe_imagick(),
			'gd' => self::probe_gd(),
			'timing' => [],
		];

		if ($result['imagick']) {
			$result['timing'] = self::time_encoders($result['imagick']);
		}

		update_option(self::OPTION, $result, false);

		// WordPress caches which editor handles each type - for a day, with a
		// persistent object cache - and the editors' answers depend on this probe.
		wp_cache_delete('wp_image_editor_choose', 'image_editor');

		return $result;
	}

	public static function imagick_lists(string $format): bool
	{
		if (!\extension_loaded('imagick') || !\class_exists('Imagick')) {
			return false;
		}

		try {
			return [] !== \Imagick::queryFormats($format);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * imagewebp() is only defined when GD was built with WebP, but gd_info() is
	 * checked as well - it's the documented way to ask.
	 */
	public static function gd_has_webp(): bool
	{
		if (!\function_exists('imagewebp') || !\function_exists('gd_info')) {
			return false;
		}

		return !empty(\gd_info()['WebP Support']);
	}

	private static function probe_imagick(): ?array
	{
		if (!\extension_loaded('imagick') || !\class_exists('Imagick')) {
			return null;
		}

		Encoder::limit_threads();

		return [
			'extension' => (string) \phpversion('imagick'),
			'version' => (string) (\Imagick::getVersion()['versionString'] ?? ''),
			'webp' => self::imagick_encodes('WEBP', 'RIFF'),
			'avif' => self::imagick_encodes('AVIF', 'ftyp'),
			'reads_avif' => self::imagick_reads(LEAN_IMAGES_DIR . '/assets/probe/still.avif'),
			'reads_heic' => self::imagick_reads(LEAN_IMAGES_DIR . '/assets/probe/still.heic'),
		];
	}

	/**
	 * Encodes a small image and checks the result starts like the format should
	 * (RIFF for WebP, an ftyp box for AVIF) - a broken delegate can return an
	 * empty or wrong blob without throwing.
	 */
	private static function imagick_encodes(string $format, string $magic): bool
	{
		try {
			$image = new \Imagick();
			$image->newPseudoImage(64, 64, 'gradient:#3858e9-#00a32a');
			$image->setImageFormat($format);
			$blob = $image->getImageBlob();
			$image->clear();

			$offset = 'ftyp' === $magic ? 4 : 0;

			return $magic === \substr($blob, $offset, 4);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private static function imagick_reads(string $path): bool
	{
		try {
			$image = new \Imagick($path);
			$ok = 64 === $image->getImageWidth();
			$image->clear();

			return $ok;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Like the Imagick probe, by encoding: GD can report AVIF support while
	 * its libavif has no encoder.
	 */
	private static function probe_gd(): ?array
	{
		if (!\function_exists('gd_info')) {
			return null;
		}

		$info = \gd_info();

		return [
			'version' => (string) ($info['GD Version'] ?? ''),
			'webp' => self::gd_has_webp() && self::gd_encodes('imagewebp', 'RIFF', 0),
			'avif' => \function_exists('imageavif') && self::gd_encodes('imageavif', 'ftyp', 4),
			'reads_avif' => \function_exists('imagecreatefromavif') && false !== @\imagecreatefromavif(LEAN_IMAGES_DIR . '/assets/probe/still.avif'),
		];
	}

	private static function gd_encodes(string $function, string $magic, int $offset): bool
	{
		try {
			$image = \imagecreatetruecolor(64, 64);
			\imagefill($image, 0, 0, \imagecolorallocate($image, 56, 88, 233));
			\ob_start();
			$ok = $function($image, null, 50);
			$blob = (string) \ob_get_clean();
			\imagedestroy($image);

			return $ok && $magic === \substr($blob, $offset, 4);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Seconds to encode a 1500 × 1000 image with the plugin's settings. The
	 * image is generated (a plasma fractal - busy enough to be closer to a
	 * photo than a gradient), so nothing large has to ship with the plugin.
	 *
	 * @return array{webp?: float, avif?: float}
	 */
	private static function time_encoders(array $imagick): array
	{
		$timing = [];

		try {
			$image = new \Imagick();
			$image->newPseudoImage(1500, 1000, 'plasma:fractal');
			$image->stripImage();

			$formats = ['webp' => ['WEBP', Config::webp_quality()], 'avif' => ['AVIF', Config::avif_quality()]];
			foreach ($formats as $key => [$format, $quality]) {
				if (empty($imagick[$key])) {
					continue;
				}

				$copy = clone $image;
				$copy->setImageFormat($format);
				$copy->setImageCompressionQuality($quality);
				$copy->setCompressionQuality($quality);

				$start = \microtime(true);
				$copy->getImageBlob();
				$timing[$key] = \round(\microtime(true) - $start, 3);
				$copy->clear();
			}

			$image->clear();
		} catch (\Throwable $e) {
			Encoder::log('encoder timing failed: ' . $e->getMessage());
		}

		return $timing;
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Turns in-memory pixels into <file>.webp and <file>.avif next to a JPEG or
 * PNG WordPress just wrote.
 *
 * Settings reproduce the desktop CLI: strip everything, WebP quality 75, AVIF
 * quality 50, encoder defaults otherwise (CLAUDE.md, "Encoding: parity with the
 * desktop CLI"). One addition: non-sRGB images are converted to sRGB before
 * stripping, so a Display P3 phone photo doesn't come out dull.
 *
 * Keep rules: WebP only if smaller than its JPEG/PNG, AVIF only if smaller
 * than whatever a browser would otherwise get (the WebP, or the JPEG/PNG). A
 * file not kept is deleted, so .htaccess falls through to the next format.
 */
final class Encoder
{

	/**
	 * The ICC's sRGB profile, redistributed unchanged under the ICC's terms -
	 * see README.md, "License".
	 */
	private const SRGB_PROFILE = '/assets/sRGB_v4_ICC_preference.icc';

	private const SOURCE_TYPES = ['image/jpeg', 'image/png'];

	private static bool $threads_limited = false;

	/**
	 * Off during an upload request, on in the queue. Decides whether a slow host
	 * may make AVIF right now (Environment::avif_during_upload()).
	 */
	private static bool $background = false;

	public static function set_background(bool $background): void
	{
		self::$background = $background;
	}

	/**
	 * Whether AVIF is wanted but was left out of this upload because the host is
	 * too slow - the attachment then waits in the queue for it.
	 */
	public static function defers_avif(): bool
	{
		return !self::$background
			&& Config::avif_enabled()
			&& Environment::can_encode_avif()
			&& !Environment::avif_during_upload();
	}

	/**
	 * Keeps AVIF encoding from taking every core on shared hosting, where the
	 * host would otherwise throttle the whole site. Process-wide in ImageMagick,
	 * so it's set once per request.
	 */
	public static function limit_threads(): void
	{
		if (self::$threads_limited || !\class_exists('Imagick')) {
			return;
		}

		self::$threads_limited = true;
		$threads = (int) apply_filters('lean_images_imagick_threads', 2);

		if ($threads > 0) {
			try {
				\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, $threads);
			} catch (\Throwable $e) {
				self::log('could not limit ImageMagick threads: ' . $e->getMessage());
			}
		}
	}

	/**
	 * @param \Imagick|\GdImage $pixels      The image as just written; not modified.
	 * @param string            $source_path The JPEG/PNG the siblings belong to.
	 * @param string            $source_mime Its type.
	 * @return array<string, array{status: string, bytes?: int, mode?: string}>
	 *         Per format: status 'written', 'larger' (not kept) or 'failed'.
	 */
	public static function write_siblings(object $pixels, string $source_path, string $source_mime): array
	{
		if (!\in_array($source_mime, self::SOURCE_TYPES, true) || !self::is_in_uploads($source_path)) {
			return [];
		}

		$webp_on = Config::webp_enabled();
		$avif_on = Config::avif_enabled() && Environment::can_encode_avif() && !self::defers_avif();
		if (!$webp_on && !$avif_on) {
			return [];
		}

		\clearstatcache(true, $source_path);
		$source_bytes = (int) @\filesize($source_path);
		if ($source_bytes <= 0) {
			return [];
		}

		// One encode function per engine: fn (format, quality, lossless) => bytes.
		if ($pixels instanceof \Imagick) {
			self::limit_threads();

			try {
				$base = self::prepare($pixels);
			} catch (\Throwable $e) {
				self::log("could not prepare {$source_path}: " . $e->getMessage());
				return [];
			}

			$gd_avif = 'gd' === Environment::avif_engine();

			$encode = static fn (string $format, int $quality, bool $lossless): ?string => 'avif' === $format && $gd_avif
				? self::encode_gd_from($base, $quality)
				: self::encode(
					$base,
					\strtoupper($format),
					$quality,
					'webp' === $format ? ['webp:lossless' => $lossless ? 'true' : 'false'] : []
				);
		} elseif ($pixels instanceof \GdImage) {
			// GD keeps no metadata and no colour profile: nothing to prepare.
			$encode = static fn (string $format, int $quality, bool $lossless): ?string => self::encode_gd(
				$pixels,
				$format,
				$lossless ? IMG_WEBP_LOSSLESS : $quality
			);
		} else {
			return [];
		}

		$results = [];
		$limit = $source_bytes;

		if ($webp_on) {
			$results['webp'] = self::encode_webp($encode, $source_path, $limit, 'image/png' === $source_mime);
			if ('written' === $results['webp']['status']) {
				$limit = $results['webp']['bytes'];
			}
		}

		if ($avif_on) {
			$results['avif'] = self::keep_if_smaller($encode('avif', Config::avif_quality(), false), $source_path . '.avif', $limit);
		}

		if (isset($base)) {
			$base->clear();
		}

		return $results;
	}

	/**
	 * PNGs are tried both ways: lossless WebP is often smaller for logos and
	 * screenshots, and lossy blurs their text. For lossless, Imagick's quality is
	 * compression effort, not fidelity - 100 is the smallest.
	 *
	 * @return array{status: string, bytes?: int, mode?: string}
	 */
	private static function encode_webp(callable $encode, string $source_path, int $limit, bool $try_lossless): array
	{
		$blob = $encode('webp', Config::webp_quality(), false);
		$mode = 'lossy';

		if ($try_lossless) {
			$lossless = $encode('webp', 100, true);
			if (null !== $lossless && (null === $blob || \strlen($lossless) < \strlen($blob))) {
				$blob = $lossless;
				$mode = 'lossless';
			}
		}

		return self::keep_if_smaller($blob, $source_path . '.webp', $limit) + ['mode' => $mode];
	}

	/**
	 * GD writes to the output buffer when given no file. AVIF at libavif's
	 * default speed: faster settings cost up to 25% in size (measured), and on
	 * GD hosts AVIF is made in the background anyway.
	 */
	private static function encode_gd(\GdImage $image, string $format, int $quality): ?string
	{
		\ob_start();

		try {
			$ok = 'webp' === $format
				? \imagewebp($image, null, $quality)
				: (\function_exists('imageavif') && \imageavif($image, null, $quality));
		} catch (\Throwable $e) {
			$ok = false;
			self::log("GD {$format} encoding failed: " . $e->getMessage());
		} finally {
			$blob = (string) \ob_get_clean();
		}

		return $ok && '' !== $blob ? $blob : null;
	}

	/**
	 * AVIF from ImageMagick's pixels through GD, on hosts whose ImageMagick
	 * can't make AVIF but whose GD can. The prepared frame (upright, sRGB) goes
	 * over as an uncompressed 8-bit PNG: lossless, transparency kept, and
	 * truecolor - GD makes a palette image from a palette PNG.
	 */
	private static function encode_gd_from(\Imagick $base, int $quality): ?string
	{
		$copy = clone $base;
		$alpha = false;

		try {
			$alpha = (bool) $copy->getImageAlphaChannel();
			$copy->setImageFormat($alpha ? 'PNG32' : 'PNG24');
			$copy->setOption('png:compression-level', '0');
			$image = @\imagecreatefromstring($copy->getImageBlob());
		} catch (\Throwable $e) {
			self::log('handing the image to GD failed: ' . $e->getMessage());
			return null;
		} finally {
			$copy->clear();
		}

		if (!$image instanceof \GdImage) {
			self::log('GD could not read the image ImageMagick handed over');
			return null;
		}

		if ($alpha) {
			\imagealphablending($image, false);
			\imagesavealpha($image, true);
		}

		$blob = self::encode_gd($image, 'avif', $quality);
		\imagedestroy($image);

		return $blob;
	}

	/**
	 * A single upright, sRGB, metadata-free frame to encode from.
	 */
	private static function prepare(\Imagick $pixels): \Imagick
	{
		$image = $pixels->getImage(); // A copy of the current frame only.

		self::orient($image);
		self::to_srgb($image);
		$image->stripImage(); // Siblings keep nothing, not even the profile: they're sRGB now.

		return $image;
	}

	/**
	 * Everything but the colour profile goes: EXIF (GPS, camera serial), IPTC,
	 * XMP, comments. Call orient() first - the orientation tag is part of the
	 * EXIF.
	 */
	public static function remove_metadata(\Imagick $image): void
	{
		foreach ($image->getImageProfiles('*', false) as $name) {
			if (!\in_array(\strtolower($name), ['icc', 'icm'], true)) {
				$image->removeImageProfile($name);
			}
		}

		if (\method_exists($image, 'deleteImageProperty')) {
			$image->deleteImageProperty('comment');
		}
	}

	/**
	 * Turns the pixels upright when the orientation tag says they aren't, and
	 * resets the tag. WordPress rotates JPEGs itself before resizing, leaving
	 * orientation 1 - this catches the rest (a PNG with an orientation tag, or
	 * PHP without the exif extension, where WordPress can't read the tag), since
	 * the tag is about to be stripped and browsers honour it.
	 *
	 * @return bool Whether the pixels were changed (dimensions may have swapped).
	 */
	public static function orient(\Imagick $image): bool
	{
		$orientation = $image->getImageOrientation();
		if (\Imagick::ORIENTATION_UNDEFINED === $orientation || \Imagick::ORIENTATION_TOPLEFT === $orientation) {
			return false;
		}

		if (\method_exists($image, 'autoOrient')) {
			$image->autoOrient();
		} else {
			switch ($orientation) {
				case \Imagick::ORIENTATION_TOPRIGHT:
					$image->flopImage();
					break;
				case \Imagick::ORIENTATION_BOTTOMRIGHT:
					$image->rotateImage('#000', 180);
					break;
				case \Imagick::ORIENTATION_BOTTOMLEFT:
					$image->flipImage();
					break;
				case \Imagick::ORIENTATION_LEFTTOP:
					$image->transposeImage();
					break;
				case \Imagick::ORIENTATION_RIGHTTOP:
					$image->rotateImage('#000', 90);
					break;
				case \Imagick::ORIENTATION_RIGHTBOTTOM:
					$image->transverseImage();
					break;
				case \Imagick::ORIENTATION_LEFTBOTTOM:
					$image->rotateImage('#000', 270);
					break;
			}
		}

		$image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

		return true;
	}

	/**
	 * Converts through the embedded profile when it isn't an sRGB one (Display
	 * P3, Adobe RGB, CMYK). Without a profile, only CMYK is converted - an
	 * untagged RGB image is sRGB by convention.
	 */
	private static function to_srgb(\Imagick $image): void
	{
		$profiles = $image->getImageProfiles('icc', false);

		if (!$profiles) {
			if (\Imagick::COLORSPACE_CMYK === $image->getImageColorspace()) {
				$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
			}
			return;
		}

		if (false !== \stripos((string) $image->getImageProperty('icc:description'), 'srgb')) {
			return;
		}

		$srgb = @\file_get_contents(LEAN_IMAGES_DIR . self::SRGB_PROFILE);
		if (false === $srgb) {
			self::log('sRGB profile missing from assets/ - colours of non-sRGB images are not converted');
			return;
		}

		try {
			$image->profileImage('icc', $srgb);
		} catch (\Throwable $e) {
			self::log('sRGB conversion failed: ' . $e->getMessage());
		}
	}

	/**
	 * @param array<string, string> $options
	 */
	private static function encode(\Imagick $base, string $format, int $quality, array $options = []): ?string
	{
		$image = clone $base;

		try {
			$image->setImageFormat($format);
			foreach ($options as $key => $value) {
				$image->setOption($key, $value);
			}
			// The WebP coder reads the image-level quality, the AVIF (HEIC) coder
			// the wand-level one - set both.
			$image->setImageCompressionQuality($quality);
			$image->setCompressionQuality($quality);

			return $image->getImageBlob();
		} catch (\Throwable $e) {
			self::log("{$format} encoding failed: " . $e->getMessage());
			return null;
		} finally {
			$image->clear();
		}
	}

	/**
	 * A stale sibling is deleted whenever a new one isn't kept - otherwise
	 * regenerating a size could leave the previous image's pixels being served
	 * under its name.
	 *
	 * @return array{status: string, bytes?: int}
	 */
	private static function keep_if_smaller(?string $blob, string $target, int $limit): array
	{
		if (null === $blob) {
			self::remove($target);
			return ['status' => 'failed'];
		}

		$bytes = \strlen($blob);
		if ($bytes >= $limit) {
			self::remove($target);
			return ['status' => 'larger', 'bytes' => $bytes];
		}

		if (!self::write_atomic($target, $blob)) {
			self::remove($target);
			return ['status' => 'failed'];
		}

		return ['status' => 'written', 'bytes' => $bytes];
	}

	/**
	 * Temp file + rename, so a request arriving mid-write gets the old file or
	 * the new one, never half of one. Permissions as core gives its own saves.
	 */
	private static function write_atomic(string $target, string $blob): bool
	{
		$temp = $target . '.' . wp_generate_password(8, false) . '.tmp';

		if (false === \file_put_contents($temp, $blob)) {
			@\unlink($temp);
			self::log("could not write {$temp}");
			return false;
		}

		$stat = @\stat(\dirname($target));
		if ($stat) {
			@\chmod($temp, $stat['mode'] & 0000666);
		}

		if (!@\rename($temp, $target)) {
			@\unlink($temp);
			self::log("could not move {$temp} into place");
			return false;
		}

		return true;
	}

	private static function remove(string $path): void
	{
		if (\file_exists($path)) {
			@\unlink($path);
		}
	}

	/**
	 * Only files under the uploads folder get siblings - that's the folder the
	 * .htaccess rules serve. Other plugins also use image editors for files
	 * elsewhere, which shouldn't collect .webp/.avif copies. A ".." anywhere
	 * fails: paths come from attachment metadata, which isn't to be trusted to
	 * stay inside.
	 */
	public static function is_in_uploads(string $path): bool
	{
		if (wp_is_stream($path)) {
			return false;
		}

		$uploads = wp_upload_dir(null, false);
		if (!empty($uploads['error']) || empty($uploads['basedir'])) {
			return false;
		}

		$path = wp_normalize_path($path);

		return 0 === \strpos($path, trailingslashit(wp_normalize_path($uploads['basedir'])))
			&& !\preg_match('#(^|/)\.\.(/|$)#', $path);
	}

	public static function log(string $message): void
	{
		if (\defined('WP_DEBUG') && WP_DEBUG) {
			\error_log('Lean Images: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

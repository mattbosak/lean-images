<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * What happens to the uploaded original WordPress keeps (original_image, or
 * the main file when nothing was scaled): never shown on pages, but publicly
 * reachable by URL.
 *
 *  - JPEG, PNG, WebP: metadata removed losslessly (MetadataStripper).
 *  - HEIC (WordPress 6.7+ turns the upload into a JPEG main image): replaced by
 *    a full-size JPEG at quality 92, and the HEIC deleted. HEIC metadata can't
 *    be removed losslessly from PHP with reasonable effort, and phone HEICs
 *    carry GPS. The JPEG keeps the colour profile (Display P3 on iPhones) - a
 *    better archive than converting it to sRGB - and nothing else.
 */
final class Originals
{

	private const HEIC_QUALITY = 92;

	/**
	 * @param array $metadata The attachment's metadata - possibly not saved yet
	 *                        (during wp_generate_attachment_metadata).
	 * @return array The metadata, with original_image pointing at the JPEG when
	 *               a HEIC was replaced. The caller saves it.
	 */
	public static function tidy(int $attachment_id, array $metadata): array
	{
		$main = get_attached_file($attachment_id);
		if (!\is_string($main) || '' === $main || !Encoder::is_in_uploads($main)) {
			return $metadata;
		}

		$original = empty($metadata['original_image']) ? $main : \dirname($main) . '/' . wp_basename((string) $metadata['original_image']);
		if (!\file_exists($original)) {
			return $metadata;
		}

		if (\in_array(\strtolower((string) \pathinfo($original, PATHINFO_EXTENSION)), ['heic', 'heif'], true)) {
			// Only a HEIC that WordPress kept as the original next to its JPEG
			// (6.7+) - never the main file itself, as on 6.5 and 6.6.
			if ($original === $main) {
				return $metadata;
			}

			$jpeg = self::replace_heic($original);
			if (null !== $jpeg) {
				$metadata['original_image'] = wp_basename($jpeg);
			}

			return $metadata;
		}

		if (apply_filters('lean_images_strip_metadata', true)) {
			MetadataStripper::strip($original);
		}

		return $metadata;
	}

	/**
	 * @return string|null Path of the JPEG that replaced the HEIC.
	 */
	private static function replace_heic(string $heic): ?string
	{
		$editor = wp_get_image_editor($heic);
		if (!$editor instanceof Editor\ImagickEditor) {
			return null; // GD can't read HEIC; the HEIC stays.
		}

		$dir = \dirname($heic);
		$name = wp_unique_filename($dir, \pathinfo($heic, PATHINFO_FILENAME) . '-original.jpg');

		// Converting to another type makes core re-read the quality from
		// wp_editor_set_quality at save time, so the quality is set there.
		$quality = static fn () => self::HEIC_QUALITY;
		add_filter('wp_editor_set_quality', $quality, 99);
		$editor->set_siblings(false); // An original is never served - no WebP/AVIF for it.
		$saved = $editor->save("{$dir}/{$name}", 'image/jpeg');
		remove_filter('wp_editor_set_quality', $quality, 99);

		if (is_wp_error($saved) || !\file_exists($saved['path'])) {
			Encoder::log("could not replace {$heic}: " . (is_wp_error($saved) ? $saved->get_error_message() : 'no file'));
			return null;
		}

		wp_delete_file($heic);

		return $saved['path'];
	}
}

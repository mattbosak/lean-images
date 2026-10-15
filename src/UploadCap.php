<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Caps uploads to the site's Large size (Settings → Media) by setting
 * WordPress's own "big image" threshold per image. Oversized uploads get a
 * -scaled main file; the original stays on disk as original_image.
 *
 * WordPress fits the image into a threshold × threshold square, so this
 * computes the longest side that fits the Large *box* for this particular
 * image: Large 1920×1080 + a landscape 4032×3024 → 1440×1080 → threshold 1440.
 */
final class UploadCap
{

	public function register(): void
	{
		add_filter('big_image_size_threshold', [$this, 'threshold'], 10, 4);
		add_filter('intermediate_image_sizes_advanced', [$this, 'drop_sizes_beyond_main'], 10, 2);
	}

	/**
	 * WordPress makes sub-sizes from the original upload, not from the -scaled
	 * file, so capping the main image alone isn't enough: core's 1536x1536 and
	 * 2048x2048 sizes would still be made from a 4032px original, and appear in
	 * srcset, undoing the cap. The Large size itself would come out as a
	 * duplicate of the -scaled file. Measured on the first test run - see
	 * CLAUDE.md, "Upload cap".
	 *
	 * So every size that would come out as large as the main image, or larger,
	 * is dropped. WordPress then uses the main image wherever such a size is
	 * asked for. $image_meta already holds the scaled dimensions here.
	 *
	 * @param array $sizes
	 * @param array $image_meta
	 * @return array
	 */
	public function drop_sizes_beyond_main($sizes, $image_meta)
	{
		$main_w = (int) ($image_meta['width'] ?? 0);
		$main_h = (int) ($image_meta['height'] ?? 0);

		if (!\is_array($sizes) || $main_w <= 0 || $main_h <= 0) {
			return $sizes;
		}

		foreach ($sizes as $name => $size) {
			// The same check core makes against the original: false means the
			// result would be no smaller than the image it's made from.
			$dims = image_resize_dimensions(
				$main_w,
				$main_h,
				(int) ($size['width'] ?? 0),
				(int) ($size['height'] ?? 0),
				$size['crop'] ?? false
			);

			if (!$dims) {
				unset($sizes[$name]);
			}
		}

		return $sizes;
	}

	/**
	 * @param int|false $threshold     WordPress's value (2560 unless filtered).
	 * @param array     $imagesize     wp_getimagesize() result: the stored size,
	 *                                 before any EXIF rotation.
	 * @param string    $file          The uploaded file.
	 * @param int       $attachment_id
	 * @return int|false False means "don't scale".
	 */
	public function threshold($threshold, $imagesize, $file, $attachment_id)
	{
		[$box_w, $box_h] = self::box();

		// Asked without an image: WordPress's REST index does that, for the block
		// editor's in-browser image processing (WordPress 7.1). One number has to
		// stand for the box - its longer side.
		if (!\is_string($file) || '' === $file) {
			return $box_w > 0 || $box_h > 0 ? \max($box_w, $box_h) : $threshold;
		}

		if (Animation::is_animated($file)) {
			return false; // Scaling would flatten the animation to its first frame.
		}

		if ($box_w <= 0 && $box_h <= 0) {
			return $threshold; // No Large size set at all - keep WordPress's default.
		}

		$width = (int) ($imagesize[0] ?? 0);
		$height = (int) ($imagesize[1] ?? 0);
		if ($width <= 0 || $height <= 0) {
			return $threshold;
		}

		// The box applies to the image as displayed. WordPress scales first and
		// rotates afterwards, so a portrait phone photo stored sideways has to be
		// measured with its sides swapped.
		if (\is_string($file) && self::is_stored_sideways($file, $imagesize)) {
			[$width, $height] = [$height, $width];
		}

		$scale = \min(
			$box_w > 0 ? $box_w / $width : INF,
			$box_h > 0 ? $box_h / $height : INF
		);

		if ($scale >= 1) {
			// Fits already. False rather than $threshold, so WordPress's own 2560
			// cap can't shrink an image the site's Large size allows.
			return false;
		}

		return \max(1, (int) \round(\max($width, $height) * $scale));
	}

	/**
	 * The Large size, as [width, height]; 0 means no limit on that side.
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function box(): array
	{
		$box = apply_filters(
			'lean_images_max_dimensions',
			[(int) get_option('large_size_w'), (int) get_option('large_size_h')]
		);

		return [\max(0, (int) ($box[0] ?? 0)), \max(0, (int) ($box[1] ?? 0))];
	}

	/**
	 * EXIF orientations 5-8 are the 90°/270° ones. Only JPEGs are checked -
	 * WordPress only auto-rotates JPEGs (maybe_exif_rotate()).
	 */
	private static function is_stored_sideways(string $file, array $imagesize): bool
	{
		if ('image/jpeg' !== ($imagesize['mime'] ?? '') || !\function_exists('exif_read_data')) {
			return false;
		}

		$exif = @\exif_read_data($file);

		return \is_array($exif) && \in_array((int) ($exif['Orientation'] ?? 1), [5, 6, 7, 8], true);
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Upload-time work that isn't part of an editor save: no sub-sizes for
 * animations, siblings for a main image WordPress never re-saves, and the
 * attachment's recorded status.
 */
final class Attachments
{

	public function register(): void
	{
		add_filter('sanitize_file_name', [$this, 'rename_sibling_lookalike']);
		add_filter('upload_mimes', [$this, 'allow_avifs']);
		add_filter('intermediate_image_sizes_advanced', [$this, 'skip_sizes_for_animations'], 10, 3);
		// Late, after other plugins have finished changing the metadata. Fires
		// for new uploads ('create') and for resumed or regenerated sub-sizes
		// ('update').
		add_filter('wp_generate_attachment_metadata', [$this, 'after_metadata'], 20, 3);
	}

	/**
	 * An upload named like a generated file - "logo.png.avif", or a browser's
	 * "Save image as" of a served AVIF - becomes "logo-png.avif". Otherwise it
	 * would sit where the rules look for logo.png's AVIF: anyone allowed to
	 * upload could replace the image other people's pages show, and conversion
	 * would later overwrite or delete that upload. WordPress keeps "png" as an
	 * inner extension, so the name gets this far unchanged.
	 *
	 * @param string $filename
	 */
	public function rename_sibling_lookalike($filename): string
	{
		return (string) \preg_replace('/\.(jpe?g|png)\.(webp|avif)$/i', '-$1.$2', (string) $filename);
	}

	/**
	 * ".avifs" is the extension some tools give animated AVIF. WordPress doesn't
	 * know it and refuses the upload. It's registered as image/avif - the type
	 * browsers expect for any AVIF, animated or not - so the file is treated as
	 * an image, detected as animated, and left as it is. (Attachments an older
	 * snippet stored as image/avif-sequence keep working; their type isn't
	 * changed.)
	 *
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public function allow_avifs($mimes): array
	{
		$mimes = (array) $mimes;
		$mimes['avifs'] = 'image/avif';

		return $mimes;
	}

	/**
	 * Any resize of an animation is its first frame only, so an animated upload
	 * gets no sub-sizes at all and is used at full size everywhere.
	 *
	 * @param array $sizes
	 * @param array $image_meta
	 * @param int   $attachment_id
	 * @return array
	 */
	public function skip_sizes_for_animations($sizes, $image_meta, $attachment_id)
	{
		$file = get_attached_file((int) $attachment_id);

		if (\is_string($file) && '' !== $file && Animation::is_animated($file)) {
			Library::record((int) $attachment_id, 'animated');

			return [];
		}

		return $sizes;
	}

	/**
	 * @param array  $metadata
	 * @param int    $attachment_id
	 * @param string $context
	 * @return array
	 */
	public function after_metadata($metadata, $attachment_id, $context = 'create')
	{
		$attachment_id = (int) $attachment_id;

		if (!\is_array($metadata) || empty($metadata['file'])) {
			return $metadata;
		}

		$file = get_attached_file($attachment_id);
		if (!\is_string($file) || '' === $file || !\file_exists($file)) {
			return $metadata;
		}

		// The original's metadata goes (or a HEIC original becomes a JPEG). The
		// metadata is returned to core, which saves it - updating it separately
		// here would be overwritten.
		$metadata = Originals::tidy($attachment_id, $metadata);

		// The file's real type, not the attachment's: a HEIC upload's main file
		// is a JPEG by now.
		if (!\in_array(wp_get_image_mime($file), ['image/jpeg', 'image/png'], true)) {
			return $metadata;
		}

		if (Animation::is_animated($file)) {
			Library::record($attachment_id, 'animated'); // An APNG.

			return $metadata;
		}

		$this->fill_main_file_gap($file);

		// Recorded only now that every size is on disk. A slow host left AVIF
		// out of this upload, so the attachment waits in the queue for it.
		if (Encoder::defers_avif()) {
			Library::record($attachment_id, 'waiting');
			Queue::start();
		} else {
			Library::record($attachment_id, 'done');
		}

		return $metadata;
	}

	/**
	 * WordPress re-saves the main image only when it scales it (-scaled) or
	 * rotates it (-rotated). An upload already within the cap and upright stays
	 * the main image untouched, so no editor save - and no siblings - ever
	 * happen for it. This fills that gap.
	 */
	private function fill_main_file_gap(string $file): void
	{
		if (self::has_current_sibling($file)) {
			return;
		}

		$editor = wp_get_image_editor($file);
		if ($editor instanceof Editor\SiblingWriter) {
			$editor->write_siblings_for_loaded_file();
		}
	}

	/**
	 * Either sibling, at least as new as the file, counts: when only one exists
	 * the other was a format that came out larger and wasn't kept.
	 */
	private static function has_current_sibling(string $file): bool
	{
		$source_time = (int) @\filemtime($file);

		foreach (['.webp', '.avif'] as $extension) {
			$time = @\filemtime($file . $extension);
			if (false !== $time && $time >= $source_time) {
				return true;
			}
		}

		return false;
	}
}

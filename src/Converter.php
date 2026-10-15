<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Makes or removes the siblings of one attachment outside the upload request:
 * the existing library, images an upload left waiting (AVIF on a slow host),
 * "Convert now" and "Convert everything again".
 *
 * Pixels come from the original upload when there is one - never from
 * WordPress's own JPEG sizes, which would mean compressing twice. Exceptions:
 * an image edited in WordPress's media editor (its sizes come from the edited
 * file, so the siblings must too) and a missing original (the main file is
 * the best that's left).
 */
final class Converter
{

	/**
	 * Converts, records the outcome and never throws: a failure is counted and
	 * the attachment goes back to the queue (or to "failed" after three).
	 */
	public static function process(int $attachment_id): string
	{
		try {
			$status = self::convert($attachment_id);
			Library::record($attachment_id, $status);

			return $status;
		} catch (\Throwable $e) {
			Library::record_failure($attachment_id, self::readable($e->getMessage()));

			return 'failed';
		}
	}

	/**
	 * ImageMagick's messages carry the server path in quotes - `this' in
	 * ImageMagick 6, 'this' in 7 - and a source reference ("@ error/jpeg.c/
	 * JPEGErrorHandler/356"). Neither means anything in the Media Library, and
	 * paths shouldn't be shown at all: any left (other libraries' messages) lose
	 * the site's folders, the uploads folder included, wherever it lives.
	 */
	public static function readable(string $message): string
	{
		$message = (string) \preg_replace("/\\s*[`'][^`']*'/", '', $message);
		$message = (string) \preg_replace('/\s*@\s*(error|warning)\/\S+/', '', $message);

		$uploads = wp_upload_dir(null, false);
		$folders = \array_filter([ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ?? '']);
		foreach ($folders as $folder) {
			$folder = trailingslashit(wp_normalize_path($folder));
			$message = \str_ireplace([$folder, \str_replace('/', '\\', $folder)], '', $message);
		}

		return \trim($message, " \t\n\r.:") . '.';
	}

	/**
	 * @return string The status to record: 'done' or 'animated'.
	 * @throws \RuntimeException With a message fit for the Media Library column.
	 */
	public static function convert(int $attachment_id): string
	{
		$main = get_attached_file($attachment_id);
		if (!\is_string($main) || '' === $main || !\file_exists($main)) {
			throw new \RuntimeException(__('The image file is missing from the uploads folder.', 'lean-images'));
		}

		if (!\in_array(wp_get_image_mime($main), ['image/jpeg', 'image/png'], true)) {
			throw new \RuntimeException(__('Only JPEG and PNG images are converted.', 'lean-images'));
		}

		if (Animation::is_animated($main)) {
			return 'animated';
		}

		$files = Library::files($attachment_id);
		$source = self::source($attachment_id, $main);

		Encoder::set_background(true);
		try {
			$editor = wp_get_image_editor($source);
			if (is_wp_error($editor)) {
				throw new \RuntimeException(\sprintf(
					/* translators: %s: error message from WordPress. */
					__('Couldn\'t read the original file: %s', 'lean-images'),
					$editor->get_error_message()
				));
			}

			if (!$editor instanceof Editor\SiblingWriter) {
				throw new \RuntimeException(__('Another plugin or code snippet replaces WordPress\'s image editors, so images can\'t be converted.', 'lean-images'));
			}

			// The same rotation WordPress applied before making the sizes.
			$meta = wp_get_attachment_metadata($attachment_id);
			if ($source !== $main && !empty($meta['image_meta'])) {
				$editor->maybe_exif_rotate();
			}

			foreach ($files as $file) {
				if (!\file_exists($file['path']) || $file['width'] <= 0 || $file['height'] <= 0) {
					continue;
				}

				$result = $editor->write_siblings_for($file['path'], $file['width'], $file['height'], $file['crop']);
				if (is_wp_error($result)) {
					throw new \RuntimeException(\sprintf(
						/* translators: 1: file name, 2: error message. */
						__('Couldn\'t convert %1$s: %2$s', 'lean-images'),
						wp_basename($file['path']),
						$result->get_error_message()
					));
				}
			}
		} finally {
			Encoder::set_background(false);
		}

		// Files WordPress made before the plugin still carry EXIF (GPS included):
		// conversion never rewrites them, so their metadata is removed losslessly.
		// A no-op for files the plugin's editor made.
		if (apply_filters('lean_images_strip_metadata', true)) {
			foreach ($files as $file) {
				MetadataStripper::strip($file['path']);
			}
		}

		$meta = wp_get_attachment_metadata($attachment_id);
		if (\is_array($meta)) {
			$tidied = Originals::tidy($attachment_id, $meta);
			if ($tidied !== $meta) {
				wp_update_attachment_metadata($attachment_id, $tidied);
			}
		}

		return 'done';
	}

	/**
	 * Deletes an attachment's siblings and forgets its state - "Remove
	 * generated files". The JPEG/PNG files are untouched.
	 */
	public static function remove(int $attachment_id): void
	{
		foreach (Library::files($attachment_id) as $file) {
			foreach (['.webp', '.avif'] as $extension) {
				if (\file_exists($file['path'] . $extension)) {
					wp_delete_file($file['path'] . $extension);
				}
			}
		}

		Library::forget($attachment_id);
	}

	/**
	 * Edited images are named "-e" + a 13-digit timestamp by WordPress's media
	 * editor; their sizes were made from the edited file.
	 */
	private static function source(int $attachment_id, string $main): string
	{
		if (\preg_match('/-e\d{13}$/', (string) \pathinfo($main, PATHINFO_FILENAME))) {
			return $main;
		}

		$original = wp_get_original_image_path($attachment_id);

		return \is_string($original) && \file_exists($original) ? $original : $main;
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Per-image state and the Library numbers.
 *
 * Each image attachment carries:
 *
 *  - _lean_images_status - done, waiting, failed or animated. No row at all
 *    means "never converted" (uploaded before the plugin); the Library panel
 *    counts those as waiting too.
 *  - _lean_images_bytes - "source,avif_view,webp_view,generated,files": bytes
 *    of the JPEG/PNG files, bytes an AVIF browser and a WebP-only browser
 *    download for those same files, disk used by the .webp/.avif files and
 *    their count. One row instead of five, still summable in SQL.
 *  - _lean_images - attempts, last error, last update.
 *
 * Per-file details (the Media Library's Details table) are read from disk
 * when shown, so they can't drift from what's actually there.
 */
final class Library
{

	public const STATUS_KEY = '_lean_images_status';
	public const BYTES_KEY = '_lean_images_bytes';
	public const DETAILS_KEY = '_lean_images';

	/**
	 * Attachment types whose files get siblings: JPEG and PNG, and HEIC from
	 * WordPress 6.7, which turns a HEIC upload into JPEG files (the attachment
	 * keeps its HEIC type). On 6.5 and 6.6 a HEIC stays a HEIC with no sizes -
	 * nothing to convert; counting it made it "Failed" after three tries.
	 *
	 * @return string[]
	 */
	public static function convertible_types(): array
	{
		$types = ['image/jpeg', 'image/png'];
		if (\version_compare(get_bloginfo('version'), '6.7', '>=')) {
			\array_push($types, 'image/heic', 'image/heif');
		}

		return $types;
	}

	/**
	 * The files an attachment serves: the main file and every distinct size -
	 * not original_image, which is never shown on pages.
	 *
	 * @return array<int, array{label: string, path: string, name: string, width: int, height: int, crop: bool|array}>
	 */
	public static function files(int $attachment_id): array
	{
		$meta = wp_get_attachment_metadata($attachment_id);
		$main = get_attached_file($attachment_id);

		// Every file found here gets read, rewritten (metadata) and has files
		// written or deleted next to it - so nothing outside the uploads folder.
		if (!\is_array($meta) || !\is_string($main) || '' === $main || !Encoder::is_in_uploads($main)) {
			return [];
		}

		$scaled = !empty($meta['original_image']);
		$files = [
			$main => [
				'label' => $scaled ? __('Full (scaled)', 'lean-images') : __('Full', 'lean-images'),
				'path' => $main,
				'name' => 'full',
				'width' => (int) ($meta['width'] ?? 0),
				'height' => (int) ($meta['height'] ?? 0),
				'crop' => false,
			],
		];

		$registered = wp_get_registered_image_subsizes();
		$main_ratio = !empty($meta['height']) ? $meta['width'] / $meta['height'] : 0;

		foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
			if (empty($size['file'])) {
				continue;
			}

			$path = \dirname($main) . '/' . wp_basename((string) $size['file']);
			if (isset($files[$path])) {
				continue; // Two size names sharing one file.
			}

			$width = (int) ($size['width'] ?? 0);
			$height = (int) ($size['height'] ?? 0);

			// The size's crop setting if it's still registered; otherwise inferred:
			// an aspect ratio that differs from the main image's means it was cropped.
			$crop = $registered[$name]['crop'] ?? ($height > 0 && $main_ratio > 0 && \abs($width / $height - $main_ratio) > 0.01);

			$files[$path] = [
				'label' => \ucfirst(\str_replace(['_', '-'], ' ', (string) $name)),
				'path' => $path,
				'name' => (string) $name,
				'width' => $width,
				'height' => $height,
				'crop' => $crop,
			];
		}

		return \array_values($files);
	}

	/**
	 * Stores an attachment's status and recomputes its byte totals from disk.
	 */
	public static function record(int $attachment_id, string $status, string $error = ''): void
	{
		$details = get_post_meta($attachment_id, self::DETAILS_KEY, true);
		$details = \is_array($details) ? $details : [];
		$details['updated'] = \time();
		$details['error'] = $error;
		if ('done' === $status || 'animated' === $status) {
			$details['attempts'] = 0;
		}

		update_post_meta($attachment_id, self::STATUS_KEY, $status);
		update_post_meta($attachment_id, self::DETAILS_KEY, $details);
		update_post_meta($attachment_id, self::BYTES_KEY, \implode(',', self::byte_totals($attachment_id)));
	}

	/**
	 * Counts one more failed attempt. Under three it goes back to the queue;
	 * at three it's marked failed and left alone until someone asks again.
	 */
	public static function record_failure(int $attachment_id, string $error): void
	{
		$details = get_post_meta($attachment_id, self::DETAILS_KEY, true);
		$attempts = (\is_array($details) ? (int) ($details['attempts'] ?? 0) : 0) + 1;

		self::record($attachment_id, $attempts >= 3 ? 'failed' : 'waiting', $error);

		$details = (array) get_post_meta($attachment_id, self::DETAILS_KEY, true);
		$details['attempts'] = $attempts;
		update_post_meta($attachment_id, self::DETAILS_KEY, $details);
	}

	public static function forget(int $attachment_id): void
	{
		delete_post_meta($attachment_id, self::STATUS_KEY);
		delete_post_meta($attachment_id, self::BYTES_KEY);
		delete_post_meta($attachment_id, self::DETAILS_KEY);
	}

	public static function status(int $attachment_id): string
	{
		return (string) get_post_meta($attachment_id, self::STATUS_KEY, true);
	}

	public static function error(int $attachment_id): string
	{
		$details = get_post_meta($attachment_id, self::DETAILS_KEY, true);

		return \is_array($details) ? (string) ($details['error'] ?? '') : '';
	}

	/**
	 * What each served file weighs on disk and what each kind of browser gets
	 * for it: the AVIF if there is one, else the WebP, else the file itself.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int, 4: int} source, avif_view, webp_view, generated bytes, generated files
	 */
	public static function byte_totals(int $attachment_id): array
	{
		$totals = [0, 0, 0, 0, 0];

		foreach (self::files($attachment_id) as $file) {
			$source = self::size_of($file['path']);
			if (null === $source) {
				continue;
			}

			$webp = self::size_of($file['path'] . '.webp');
			$avif = self::size_of($file['path'] . '.avif');

			$totals[0] += $source;
			$totals[1] += $avif ?? $webp ?? $source;
			$totals[2] += $webp ?? $source;
			$totals[3] += (int) $webp + (int) $avif;
			$totals[4] += (null !== $webp ? 1 : 0) + (null !== $avif ? 1 : 0);
		}

		return $totals;
	}

	public static function size_of(string $path): ?int
	{
		$size = @\filesize($path);

		return false === $size ? null : (int) $size;
	}

	/**
	 * Lossless WebP files are a VP8L chunk; lossy ones VP8 (inside VP8X when
	 * there's alpha). Reading the chunk names is enough to tell.
	 */
	public static function webp_is_lossless(string $path): bool
	{
		$head = (string) @\file_get_contents($path, false, null, 0, 64);

		return false !== \strpos($head, 'VP8L');
	}

	/**
	 * The Library panel's numbers.
	 *
	 * @return array{total: int, done: int, waiting: int, failed: int, animated: int, unconverted: int, source: int, avif_view: int, webp_view: int, generated: int, files: int}
	 */
	public static function stats(): array
	{
		global $wpdb;

		$types = self::types_sql();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COALESCE(s.meta_value, '') AS status, COUNT(*) AS n
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = 'attachment' AND (p.post_mime_type IN ({$types}) OR s.meta_value = 'animated')
				GROUP BY status",
				self::STATUS_KEY
			)
		);

		$stats = ['total' => 0, 'done' => 0, 'waiting' => 0, 'failed' => 0, 'animated' => 0, 'unconverted' => 0];
		foreach ((array) $rows as $row) {
			$count = (int) $row->n;
			$stats['total'] += $count;
			$key = '' === $row->status ? 'unconverted' : $row->status;
			if (isset($stats[$key])) {
				$stats[$key] += $count;
			}
		}

		// SUBSTRING_INDEX picks the nth comma-separated number; SUM casts it.
		// The aliases avoid "generated", a reserved word since MySQL 8.
		$sums = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					SUM(SUBSTRING_INDEX(meta_value, ',', 1)) AS sum_source,
					SUM(SUBSTRING_INDEX(SUBSTRING_INDEX(meta_value, ',', 2), ',', -1)) AS sum_avif_view,
					SUM(SUBSTRING_INDEX(SUBSTRING_INDEX(meta_value, ',', 3), ',', -1)) AS sum_webp_view,
					SUM(SUBSTRING_INDEX(SUBSTRING_INDEX(meta_value, ',', 4), ',', -1)) AS sum_generated,
					SUM(SUBSTRING_INDEX(meta_value, ',', -1)) AS sum_files
				FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::BYTES_KEY
			),
			ARRAY_A
		);

		foreach (['source', 'avif_view', 'webp_view', 'generated', 'files'] as $key) {
			$stats[$key] = (int) ($sums["sum_{$key}"] ?? 0);
		}

		return $stats;
	}

	/**
	 * The next attachment for the queue: one explicitly waiting first, then -
	 * when a bulk conversion is running - one never converted. Newest first, so
	 * a site's recent images improve first.
	 *
	 * @param int[] $skip Already tried in this run.
	 */
	public static function next_to_convert(bool $include_unconverted, array $skip): ?int
	{
		global $wpdb;

		$not_in = $skip ? 'AND p.ID NOT IN (' . \implode(',', \array_map('intval', $skip)) . ')' : '';

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'waiting'
				WHERE p.post_type = 'attachment' {$not_in}
				ORDER BY p.ID DESC LIMIT 1",
				self::STATUS_KEY
			)
		);

		if (!$id && $include_unconverted) {
			$types = self::types_sql();
			$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
					WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$types}) AND s.meta_id IS NULL {$not_in}
					ORDER BY p.ID DESC LIMIT 1",
					self::STATUS_KEY
				)
			);
		}

		return $id ? (int) $id : null;
	}

	/**
	 * The next attachment that has generated files, for "Remove generated files".
	 *
	 * @param int[] $skip
	 */
	public static function next_with_files(array $skip): ?int
	{
		global $wpdb;

		$not_in = $skip ? 'AND post_id NOT IN (' . \implode(',', \array_map('intval', $skip)) . ')' : '';

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s {$not_in} ORDER BY post_id DESC LIMIT 1", self::BYTES_KEY)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * How many attachments still have generated files - what "Remove generated
	 * files" has left to do.
	 */
	public static function count_with_files(): int
	{
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BYTES_KEY)
		);
	}

	/**
	 * Moves every attachment with one status to another in a single query -
	 * "Convert everything again" (done → waiting) and "Try failed again"
	 * (failed → waiting). The object cache can show the old status for a
	 * moment; the queue itself reads the database.
	 */
	public static function move_status(string $from, string $to): int
	{
		global $wpdb;

		$moved = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = %s AND meta_value = %s", $to, self::STATUS_KEY, $from)
		);

		wp_cache_delete('last_changed', 'posts');

		return (int) $moved;
	}

	private static function types_sql(): string
	{
		return "'" . \implode("','", \array_map('esc_sql', self::convertible_types())) . "'";
	}
}

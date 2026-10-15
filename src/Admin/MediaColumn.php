<?php

namespace LeanImages\Admin;

use LeanImages\Animation;
use LeanImages\Config;
use LeanImages\Converter;
use LeanImages\Environment;
use LeanImages\Library;
use LeanImages\Queue;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * The "Lean Images" column in the Media Library's list view, its "Convert now"
 * and "Try again" links, and the "Convert with Lean Images" bulk action.
 *
 * Numbers are read from disk when the row is shown, so they always describe
 * the files that are actually there.
 */
final class MediaColumn
{

	private const COLUMN = 'lean_images';
	private const BULK_ACTION = 'lean_images_convert';

	public function register(): void
	{
		add_filter('manage_media_columns', [$this, 'add_column']);
		add_action('manage_media_custom_column', [$this, 'render_column'], 10, 2);
		add_filter('bulk_actions-upload', [$this, 'add_bulk_action']);
		add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);
		add_action('admin_post_lean_images_convert', [$this, 'handle_convert']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column($columns): array
	{
		$added = [];
		foreach ((array) $columns as $key => $label) {
			if ('date' === $key) {
				$added[self::COLUMN] = __('Lean Images', 'lean-images');
			}
			$added[$key] = $label;
		}

		if (!isset($added[self::COLUMN])) {
			$added[self::COLUMN] = __('Lean Images', 'lean-images');
		}

		return $added;
	}

	public function render_column(string $column, int $attachment_id): void
	{
		if (self::COLUMN !== $column || !wp_attachment_is_image($attachment_id)) {
			return;
		}

		$status = Library::status($attachment_id);
		$convertible = \in_array(get_post_mime_type($attachment_id), Library::convertible_types(), true);

		echo '<div class="li-col">';

		if ('done' === $status) {
			$this->render_done($attachment_id);
		} elseif ('waiting' === $status) {
			echo '<span><span class="li-pill li-pill-info">' . esc_html__('Waiting', 'lean-images') . '</span></span>';
			echo '<span class="li-meta">' . esc_html__('In the queue', 'lean-images') . $this->convert_link($attachment_id, __('Convert now', 'lean-images')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in convert_link().
		} elseif ('failed' === $status) {
			echo '<span><span class="li-pill li-pill-err">' . esc_html__('Failed', 'lean-images') . '</span></span>';
			echo '<span class="li-error">' . esc_html(Library::error($attachment_id)) . '</span>';
			$retry = $this->convert_link($attachment_id, __('Try again', 'lean-images'));
			if ('' !== $retry) {
				echo '<span class="li-meta">' . \ltrim($retry, ' ·') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in convert_link().
			}
		} elseif ('animated' === $status) {
			$frames = Animation::frame_count((string) get_attached_file($attachment_id));
			echo '<span><span class="li-pill li-pill-neutral">' . esc_html__('Animated', 'lean-images') . '</span></span>';
			echo '<span class="li-meta">' . esc_html(\sprintf(
				/* translators: %s: number of frames. */
				_n('%s frame. Animations are left as they are.', '%s frames. Animations are left as they are.', (int) $frames, 'lean-images'),
				number_format_i18n((int) $frames)
			)) . '</span>';
		} elseif ($convertible) {
			echo '<span class="li-meta">' . esc_html__('Not converted yet', 'lean-images') . $this->convert_link($attachment_id, __('Convert now', 'lean-images')) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in convert_link().
		} else {
			echo '<span class="li-meta">' . esc_html__('Not converted: only JPEG and PNG images are.', 'lean-images') . '</span>';
		}

		echo '</div>';
	}

	/**
	 * @param string[] $actions
	 * @return string[]
	 */
	public function add_bulk_action($actions): array
	{
		$actions = (array) $actions;
		if (current_user_can('manage_options')) {
			$actions[self::BULK_ACTION] = __('Convert with Lean Images', 'lean-images');
		}

		return $actions;
	}

	/**
	 * Queues the selected images rather than converting them in this request -
	 * a few dozen photos would outrun PHP's time limit.
	 *
	 * @param string $redirect
	 * @param string $action
	 * @param int[]  $ids
	 */
	public function handle_bulk_action($redirect, $action, $ids): string
	{
		if (self::BULK_ACTION !== $action || !current_user_can('manage_options')) {
			return (string) $redirect;
		}

		$queued = 0;
		foreach ((array) $ids as $id) {
			$id = (int) $id;
			if (\in_array(get_post_mime_type($id), Library::convertible_types(), true) && 'animated' !== Library::status($id)) {
				Library::record($id, 'waiting');
				$queued++;
			}
		}

		if ($queued) {
			Queue::start();
		}

		return add_query_arg(['lean-images-notice' => 'queued', 'lean-images-count' => $queued], (string) $redirect);
	}

	public function handle_convert(): void
	{
		$id = isset($_GET['attachment']) ? \absint($_GET['attachment']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.

		if (!$id || !current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to do that.', 'lean-images'), '', ['response' => 403]);
		}

		check_admin_referer('lean_images_convert_' . $id);

		if ('attachment' !== get_post_type($id) || !\in_array(get_post_mime_type($id), Library::convertible_types(), true)) {
			wp_die(esc_html__('Only JPEG and PNG images can be converted.', 'lean-images'), '', ['response' => 400]);
		}

		$status = Converter::process($id);
		$codes = ['done' => 'converted', 'animated' => 'animated'];

		Notices::redirect($codes[$status] ?? 'convert-failed', 0, admin_url('upload.php?mode=list'));
	}

	public function enqueue(string $hook_suffix): void
	{
		if ('upload.php' !== $hook_suffix) {
			return;
		}

		wp_register_style('lean-images-media', false, [], LEAN_IMAGES_VERSION);
		wp_enqueue_style('lean-images-media');
		wp_add_inline_style('lean-images-media', self::css());
	}

	/**
	 * "N% smaller" per format, over the files that have that format - plus the
	 * per-size table behind Details.
	 */
	private function render_done(int $attachment_id): void
	{
		$files = Library::files($attachment_id);
		$rows = [];
		$sums = ['webp' => [0, 0], 'avif' => [0, 0]]; // [source bytes, format bytes]

		foreach ($files as $file) {
			$source = Library::size_of($file['path']);
			if (null === $source) {
				continue;
			}

			$row = ['file' => $file, 'source' => $source];
			foreach (['webp', 'avif'] as $format) {
				$row[$format] = Library::size_of($file['path'] . '.' . $format);
				if (null !== $row[$format]) {
					$sums[$format][0] += $source;
					$sums[$format][1] += $row[$format];
				}
			}
			$rows[] = $row;
		}

		$percent = static fn (array $sum): int => (int) \round(100 * (1 - $sum[1] / \max(1, $sum[0])));

		if ($sums['avif'][0]) {
			echo '<span class="li-fmt">' . wp_kses(\sprintf(/* translators: %s: percentage. */ __('AVIF <b>%s smaller</b>', 'lean-images'), $percent($sums['avif']) . '%'), ['b' => []]) . '</span>';
		} elseif (Config::avif_enabled() && Environment::can_encode_avif()) {
			echo '<span class="li-fmt li-muted">' . esc_html__('AVIF not kept: larger than WebP', 'lean-images') . '</span>';
		}

		if ($sums['webp'][0]) {
			$mode = !empty($files[0]) && Library::webp_is_lossless($files[0]['path'] . '.webp') ? __('lossless', 'lean-images') : '';
			echo '<span class="li-fmt">' . wp_kses(\sprintf(/* translators: %s: percentage. */ __('WebP <b>%s smaller</b>', 'lean-images'), $percent($sums['webp']) . '%'), ['b' => []])
				. ('' !== $mode ? ' <span class="li-muted">(' . esc_html($mode) . ')</span>' : '') . '</span>';
		}

		if (\in_array(get_post_mime_type($attachment_id), ['image/heic', 'image/heif'], true)) {
			echo '<span class="li-muted">' . esc_html__('Uploaded as HEIC.', 'lean-images') . '</span>';
		}

		echo '<details class="li-details"><summary>' . esc_html(\sprintf(
			/* translators: %s: number of image files. */
			_n('%s file · Details', '%s files · Details', \count($rows), 'lean-images'),
			number_format_i18n(\count($rows))
		)) . '</summary>';

		$source_label = 'image/png' === wp_get_image_mime((string) get_attached_file($attachment_id)) ? 'PNG' : 'JPEG';
		echo '<table class="li-sizes"><thead><tr><th>' . esc_html__('Size', 'lean-images') . '</th><th>' . esc_html__('Dimensions', 'lean-images') . '</th><th>' . esc_html($source_label) . '</th><th>WebP</th><th>AVIF</th></tr></thead><tbody>';

		foreach ($rows as $row) {
			$smallest = \min(\array_filter([$row['source'], $row['webp'], $row['avif']], static fn ($size) => null !== $size));
			echo '<tr><td>' . esc_html($row['file']['label']) . '</td><td>' . esc_html($row['file']['width'] . ' × ' . $row['file']['height']) . '</td>';
			foreach (['source', 'webp', 'avif'] as $key) {
				$size = $row[$key];
				echo '<td' . (null !== $size && $size === $smallest ? ' class="li-best"' : '') . '>' . (null === $size ? '<span class="li-muted">—</span>' : esc_html(size_format($size, 1))) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$original = wp_get_original_image_path($attachment_id);
		if (\is_string($original) && $original !== get_attached_file($attachment_id) && \file_exists($original)) {
			$dimensions = @\getimagesize($original);
			echo '<p class="li-muted">' . esc_html(\sprintf(
				/* translators: 1: width, 2: height, 3: file size. */
				__('Original %1$s × %2$s, %3$s - kept, but never shown on pages.', 'lean-images'),
				$dimensions[0] ?? '?',
				$dimensions[1] ?? '?',
				size_format((int) \filesize($original), 1)
			)) . '</p>';
		}

		echo '</details>';
	}

	/**
	 * " · Convert now" - for administrators only, like the bulk action: it
	 * converts in the request, and anyone who can upload could otherwise run it
	 * in a loop.
	 */
	private function convert_link(int $attachment_id, string $label): string
	{
		if (!current_user_can('manage_options')) {
			return '';
		}

		$url = wp_nonce_url(
			admin_url('admin-post.php?action=lean_images_convert&attachment=' . $attachment_id),
			'lean_images_convert_' . $attachment_id
		);

		return ' · <a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	private static function css(): string
	{
		return <<<'CSS'
.fixed .column-lean_images { width: 15em; }
.li-col { display: grid; gap: 3px; }
.li-col .li-fmt { font-variant-numeric: tabular-nums; }
.li-col .li-fmt b { font-weight: 600; }
.li-col .li-muted, .li-col .li-meta { color: #646970; }
.li-col .li-error { color: #b32d2e; }
.li-col .li-pill { display: inline-flex; align-items: center; gap: 6px; padding: 1px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.li-col .li-pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.li-col .li-pill-info { background: #f0f6fc; color: #0a4b78; }
.li-col .li-pill-err { background: #fcf0f1; color: #b32d2e; }
.li-col .li-pill-neutral { background: #f0f0f1; color: #50575e; }
.li-col .li-details summary { cursor: pointer; color: var(--wp-admin-theme-color, #2271b1); width: fit-content; }
.li-col .li-details[open] { width: max-content; max-width: min(36em, 80vw); }
.li-col .li-sizes { border-collapse: collapse; margin: 6px 0; background: #fff; border: 1px solid #dcdcde; font-variant-numeric: tabular-nums; }
.li-col .li-sizes th, .li-col .li-sizes td { padding: 4px 8px; border-top: 1px solid #dcdcde; text-align: right; white-space: nowrap; }
.li-col .li-sizes th:nth-child(-n+2), .li-col .li-sizes td:nth-child(-n+2) { text-align: left; }
.li-col .li-sizes thead th { background: #f6f7f7; border-top: 0; font-weight: 600; }
.li-col .li-best { color: #007017; font-weight: 600; }
.li-col p { margin: 2px 0 0; }
CSS;
	}
}

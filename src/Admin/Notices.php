<?php

namespace LeanImages\Admin;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Messages after an admin-post action redirects back: the action adds
 * ?lean-images-notice=<code>&lean-images-count=<n> to the return URL, this
 * shows the message and WordPress strips the arguments from the address bar.
 */
final class Notices
{

	private const ARG = 'lean-images-notice';
	private const COUNT_ARG = 'lean-images-count';

	public function register(): void
	{
		add_action('admin_notices', [$this, 'show']);
		add_filter('removable_query_args', [$this, 'removable_args']);
	}

	/**
	 * Redirects back to where the action came from (or $fallback) with a notice,
	 * and ends the request.
	 */
	public static function redirect(string $code, int $count = 0, string $fallback = ''): void
	{
		$url = wp_get_referer() ?: ($fallback ?: SettingsPage::url());
		$url = remove_query_arg([self::ARG, self::COUNT_ARG], $url);

		wp_safe_redirect(add_query_arg([self::ARG => $code, self::COUNT_ARG => $count], $url));
		exit;
	}

	public function show(): void
	{
		$code = isset($_GET[self::ARG]) ? sanitize_key(wp_unslash($_GET[self::ARG])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ('' === $code) {
			return;
		}

		$count = isset($_GET[self::COUNT_ARG]) ? \absint($_GET[self::COUNT_ARG]) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		$messages = [
			'converted' => ['success', __('Image converted.', 'lean-images')],
			'convert-failed' => ['error', __('The image couldn\'t be converted. The Lean Images column shows why.', 'lean-images')],
			'animated' => ['info', __('This image is animated, so it\'s left as it is.', 'lean-images')],
			/* translators: %s: number of images. */
			'queued' => ['success', \sprintf(_n('%s image will be converted in the background.', '%s images will be converted in the background.', $count, 'lean-images'), number_format_i18n($count))],
			'selftest' => ['success', __('Test finished. The Status panel shows the result.', 'lean-images')],
			'cdn-off' => ['success', __('AVIF turned off. Visitors get WebP or the original JPEG and PNG.', 'lean-images')],
			'cdn-needs-webp' => ['error', __('AVIF stays on: WebP is turned off, and with both off nothing would be served. Turn WebP on in the settings first.', 'lean-images')],
			'cdn-kept' => ['success', __('AVIF stays on. Lean Images won\'t warn about Cloudflare again unless the test result changes.', 'lean-images')],
		];

		if (!isset($messages[$code])) {
			return;
		}

		[$type, $message] = $messages[$code];
		\printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr($type),
			esc_html($message)
		);
	}

	/**
	 * @param string[] $args
	 * @return string[]
	 */
	public function removable_args($args): array
	{
		return \array_merge((array) $args, [self::ARG, self::COUNT_ARG]);
	}
}

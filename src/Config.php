<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * The plugin's settings, read from one option and each passed through a filter.
 *
 * The quality defaults reproduce the desktop ImageMagick workflow these settings
 * were measured against - WebP 75 and AVIF 50 gave byte-identical output to it.
 * See CLAUDE.md, "Encoding: parity with the desktop CLI".
 */
final class Config
{

	public const OPTION = 'lean_images_settings';

	private const DEFAULTS = [
		'avif' => true,
		'webp' => true,
		'avif_quality' => 50,
		'webp_quality' => 75,
	];

	public static function avif_enabled(): bool
	{
		return (bool) apply_filters('lean_images_avif_enabled', self::get('avif'));
	}

	public static function webp_enabled(): bool
	{
		return (bool) apply_filters('lean_images_webp_enabled', self::get('webp'));
	}

	public static function avif_quality(): int
	{
		return self::clamp_quality(apply_filters('lean_images_avif_quality', self::get('avif_quality')));
	}

	public static function webp_quality(): int
	{
		return self::clamp_quality(apply_filters('lean_images_webp_quality', self::get('webp_quality')));
	}

	/**
	 * Stored values over defaults. Unknown keys in the stored option are dropped,
	 * so a renamed or removed setting can't linger and be read by mistake.
	 *
	 * @return array{avif: bool, webp: bool, avif_quality: int, webp_quality: int}
	 */
	public static function all(): array
	{
		$stored = get_option(self::OPTION, []);

		if (!\is_array($stored)) {
			$stored = [];
		}

		return \array_merge(self::DEFAULTS, \array_intersect_key($stored, self::DEFAULTS));
	}

	/**
	 * Sanitize callback for the settings form (register_setting()). Core calls
	 * it twice on the first save of a new option - once for update_option(),
	 * once more for the add_option() inside it - so messages are added once.
	 *
	 * @param mixed $input
	 * @return array{avif: bool, webp: bool, avif_quality: int, webp_quality: int}
	 */
	public static function sanitize($input): array
	{
		static $messages_added = false;

		$input = \is_array($input) ? $input : [];
		$old = self::all();

		$new = [
			// A disabled checkbox isn't submitted. When the server can't make AVIF,
			// the saved choice is kept for the day it can.
			'avif' => Environment::can_encode_avif() ? !empty($input['avif']) : $old['avif'],
			'webp' => !empty($input['webp']),
			'avif_quality' => self::clamp_quality($input['avif_quality'] ?? $old['avif_quality']),
			'webp_quality' => self::clamp_quality($input['webp_quality'] ?? $old['webp_quality']),
		];

		if (!$new['avif'] && !$new['webp']) {
			if (!$messages_added) {
				add_settings_error(self::OPTION, 'lean-images-no-format', __('Choose at least one format. With both off, Lean Images has nothing to make.', 'lean-images'));
			}
			$messages_added = true;

			return $old;
		}

		$quality_changed = $new['avif_quality'] !== $old['avif_quality'] || $new['webp_quality'] !== $old['webp_quality'];
		if ($quality_changed && !$messages_added) {
			add_settings_error(
				self::OPTION,
				'lean-images-quality',
				__('Settings saved. New quality settings apply to new uploads. To update existing images, use Convert everything again.', 'lean-images'),
				'success'
			);
		}
		$messages_added = true;

		// Turning AVIF back on undoes an earlier "Turn off AVIF" Cloudflare choice.
		if ($new['avif'] && 'avif-off' === get_option(SelfTest::CDN_CHOICE_OPTION)) {
			delete_option(SelfTest::CDN_CHOICE_OPTION);
		}

		return $new;
	}

	/**
	 * @return bool|int
	 */
	private static function get(string $key)
	{
		return self::all()[$key];
	}

	/**
	 * Applied after the filter, so a filter returning something out of range
	 * can't reach an encoder.
	 *
	 * @param mixed $value
	 */
	private static function clamp_quality($value): int
	{
		return \max(1, \min(100, (int) $value));
	}
}

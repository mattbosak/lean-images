<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Gives this plugin a "View Details" modal on the Plugins page, sourced from
 * plugin-info.json. When an update is available, the version, requirements and
 * changelog come from the incoming release's update.json instead - that's what
 * someone deciding whether to update needs to read.
 */
final class UpdateInfo
{

	public function register(): void
	{
		add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
		add_filter('plugins_api', [$this, 'inject_plugin_info'], 10, 3);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_modal_styles']);
	}

	/**
	 * @param string[] $plugin_meta
	 * @return string[]
	 */
	public function add_plugin_row_meta(array $plugin_meta, string $plugin_file): array
	{
		if (LEAN_IMAGES_BASENAME !== $plugin_file) {
			return $plugin_meta;
		}

		$slug = \dirname(LEAN_IMAGES_BASENAME);

		$plugin_meta[] = \sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
			esc_url(network_admin_url("plugin-install.php?tab=plugin-information&plugin={$slug}&TB_iframe=true&width=600&height=550")),
			esc_attr__('More information about Lean Images', 'lean-images'),
			esc_html__('View details', 'lean-images')
		);

		return $plugin_meta;
	}

	/**
	 * @param false|object|array $result
	 * @param object              $args
	 * @return false|object|array
	 */
	public function inject_plugin_info($result, string $action, $args)
	{
		$slug = \dirname(LEAN_IMAGES_BASENAME);

		if ('plugin_information' !== $action || !isset($args->slug) || $slug !== $args->slug) {
			return $result;
		}

		$info = $this->read_plugin_info_json();
		if (!$info) {
			return $result; // Fall back to WordPress' default "not found" handling.
		}

		$plugin_data = get_plugin_data(LEAN_IMAGES_FILE);
		$incoming = Updater::newer();

		// Array order is tab order, and WordPress opens the first section.
		// Empty sections are dropped rather than rendered as blank tabs.
		// 'reviews' is absent because core skips it unless $api->ratings is
		// populated, which only happens for wordpress.org-hosted plugins.
		$sections = \array_filter([
			'description' => $this->format_description($info),
			'installation' => $this->format_installation($info),
			'faq' => $this->format_faq($info),
			'screenshots' => $this->format_screenshots($info),
			'changelog' => $this->format_changelog(null !== $incoming ? ['changelog' => $incoming['changelog'] ?? []] : $info),
		]);

		return (object) [
			'name' => $plugin_data['Name'],
			'slug' => $slug,
			'version' => $incoming['version'] ?? $plugin_data['Version'],
			'author' => $plugin_data['Author'],
			'requires' => $incoming['requires'] ?? $info['requirements']['wordpress'] ?? '',
			'tested' => $incoming['tested'] ?? $info['requirements']['tested'] ?? '',
			'requires_php' => $incoming['requires_php'] ?? $info['requirements']['php'] ?? '',
			'downloaded' => 0,
			'last_updated' => $incoming['last_updated'] ?? $info['last_updated'] ?? '',
			'download_link' => $incoming['package'] ?? '',
			'sections' => $sections,
		];
	}

	private function read_plugin_info_json(): ?array
	{
		$path = LEAN_IMAGES_DIR . '/plugin-info.json';

		if (!\file_exists($path)) {
			return null;
		}

		$data = \json_decode((string) \file_get_contents($path), true);

		return \is_array($data) ? $data : null;
	}

	private function format_description(array $info): string
	{
		$out = '<p>' . esc_html($info['description'] ?? '') . '</p>';

		if (!empty($info['features']) && \is_array($info['features'])) {
			$out .= $this->render_heading_list(__('Key Features', 'lean-images'), $info['features'], true);
		}

		return $out;
	}

	private function format_installation(array $info): string
	{
		if (empty($info['installation']) || !\is_array($info['installation'])) {
			return '';
		}

		// No heading - WordPress already labels the tab "Installation", and one
		// here would add a translatable string for no gain.
		$out = '<ol>';
		foreach ($info['installation'] as $step) {
			$out .= '<li>' . wp_kses((string) $step, ['strong' => [], 'code' => []]) . '</li>';
		}

		return "{$out}</ol>";
	}

	private function format_faq(array $info): string
	{
		if (empty($info['faq']) || !\is_array($info['faq'])) {
			return '';
		}

		$out = '';
		foreach ($info['faq'] as $entry) {
			if (empty($entry['question']) || empty($entry['answer'])) {
				continue;
			}

			$out .= '<h4>' . esc_html((string) $entry['question']) . '</h4>';
			$out .= '<p>' . wp_kses((string) $entry['answer'], ['strong' => [], 'em' => [], 'code' => []]) . '</p>';
		}

		return $out;
	}

	/**
	 * Emits the markup core already styles: an <ol> of <li>, each holding an
	 * <img> and a <p> caption. wp-admin/css/common.css carries dedicated rules
	 * for #section-screenshots ol / li img / li p (list-style:none, max-width
	 * 100%, height auto, a drop shadow, italic captions), so matching this
	 * shape lets the tab render natively without additional CSS.
	 *
	 * Two constraints from core's install_plugin_information(), both of which
	 * apply only to plugins hosted outside wordpress.org:
	 *
	 *  1. Every section is run through links_add_base_url() with a base of
	 *     https://wordpress.org/plugins/{slug}/, rewriting BOTH src and href.
	 *     A relative image path would be silently rewritten to a 404 on a
	 *     server outside this install - hence plugins_url(), always absolute.
	 *  2. wp_kses() strips width and height from <img>, allowing only src,
	 *     class and alt - so the sizing above has to come from CSS anyway.
	 *
	 * A caption whose file is missing is skipped rather than rendered, so
	 * writing the caption before exporting the PNG shows nothing instead of a
	 * broken image.
	 */
	private function format_screenshots(array $info): string
	{
		if (empty($info['screenshots']) || !\is_array($info['screenshots'])) {
			return '';
		}

		$items = '';
		foreach ($info['screenshots'] as $shot) {
			$file = (string) ($shot['file'] ?? '');

			// basename() keeps a traversal-ish value in the JSON from
			// resolving anywhere but the screenshots directory.
			$file = \basename($file);
			if ('' === $file || !\file_exists(LEAN_IMAGES_DIR . "/screenshots/{$file}")) {
				continue;
			}

			$caption = (string) ($shot['caption'] ?? '');

			$items .= '<li>';
			$items .= \sprintf(
				'<img src="%s" alt="%s" />',
				esc_url(plugins_url("screenshots/{$file}", LEAN_IMAGES_FILE)),
				esc_attr($caption)
			);
			if ('' !== $caption) {
				$items .= '<p>' . esc_html($caption) . '</p>';
			}
			$items .= '</li>';
		}

		// Built first so a list with no surviving files returns '' and the tab
		// is dropped, rather than emitting an empty <ol>.
		return '' === $items ? '' : "<ol>{$items}</ol>";
	}

	/**
	 * The details modal is an iframe rendering plugin-install.php, where
	 * admin_enqueue_scripts does fire (iframe_header() dispatches it). Scoped
	 * to this plugin's own modal so it can't restyle anyone else's, and
	 * registered against a false-src handle, so no extra file ships or is
	 * requested.
	 *
	 * One rule only. Core sets `list-style-type: disc` on BOTH ul and ol under
	 * `#plugin-information .section`, so an ordered list of installation steps
	 * renders as bullets. Two IDs (0,2,0,1) outrank core's one ID + one class
	 * + one type (0,1,1,1), which is the same lever core itself pulls for
	 * #section-screenshots.
	 */
	public function enqueue_modal_styles(string $hook_suffix): void
	{
		if ('plugin-install.php' !== $hook_suffix) {
			return;
		}

		$requested = isset($_GET['plugin']) ? sanitize_key(wp_unslash($_GET['plugin'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display check, no state change.
		if (\dirname(LEAN_IMAGES_BASENAME) !== $requested) {
			return;
		}

		wp_register_style('lean-images-modal', false, [], LEAN_IMAGES_VERSION);
		wp_enqueue_style('lean-images-modal');
		wp_add_inline_style(
			'lean-images-modal',
			'#plugin-information #section-installation ol{list-style-type:decimal;}'
		);
	}

	private function format_changelog(array $info): string
	{
		if (empty($info['changelog']) || !\is_array($info['changelog'])) {
			return '';
		}

		$out = '';
		foreach ($info['changelog'] as $version => $entries) {
			// Not translated - a version number, not display copy. $allow_bold
			// stays false (the default): changelog entries are plain text.
			$out .= $this->render_heading_list((string) $version, (array) $entries);
		}

		return $out;
	}

	/**
	 * @param string $heading Must already be translated - a variable passed to
	 *                        __() here wouldn't be picked up by extraction tools.
	 * @param bool $allow_bold Permits only a <strong> lead-in, no attributes.
	 */
	private function render_heading_list(string $heading, array $items, bool $allow_bold = false): string
	{
		if (!$items) {
			return '';
		}

		$out = '<h4>' . esc_html($heading) . '</h4><ul>';
		foreach ($items as $item) {
			$item = (string) $item;
			$out .= '<li>' . ($allow_bold ? wp_kses($item, ['strong' => []]) : esc_html($item)) . '</li>';
		}
		$out .= '</ul>';

		return $out;
	}
}

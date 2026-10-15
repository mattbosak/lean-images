<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Puts the plugin's image editors first in WordPress's list: Imagick, then GD
 * for hosts without it. Core's own editors stay behind them.
 *
 * And keeps uploads on the server: WordPress 7.1 makes the sizes of a
 * block-editor upload in the browser (on HTTPS and localhost) and stores them
 * without any image editor - so they got no WebP or AVIF, only the main file
 * did. With that turned off, every upload goes through these editors again,
 * as on every version before 7.1 (CLAUDE.md, "WordPress 7.1's in-browser image
 * processing").
 */
final class Editors
{

	public function register(): void
	{
		// Priority 20, after the usual priority-10 snippets and plugins that
		// replace the whole list, so the plugin's editor still comes first. The
		// self-test reports anything that runs later and pushes it out.
		add_filter('wp_image_editors', [$this, 'prepend'], 20);

		// Late, so it holds against the usual priority-10 filters; a site that
		// really wants the browser processing can still turn it on above 99.
		add_filter('wp_client_side_media_processing_enabled', '__return_false', 99);
	}

	/**
	 * @param string[] $editors
	 * @return string[]
	 */
	public function prepend($editors): array
	{
		$editors = \is_array($editors) ? $editors : [];

		return \array_values(\array_unique(\array_merge([Editor\ImagickEditor::class, Editor\GdEditor::class], $editors)));
	}
}

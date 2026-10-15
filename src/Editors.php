<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Puts the plugin's image editors first in WordPress's list: Imagick, then GD
 * for hosts without it. Core's own editors stay behind them.
 */
final class Editors
{

	public function register(): void
	{
		// Priority 20, after the usual priority-10 snippets and plugins that
		// replace the whole list, so the plugin's editor still comes first. The
		// self-test reports anything that runs later and pushes it out.
		add_filter('wp_image_editors', [$this, 'prepend'], 20);
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

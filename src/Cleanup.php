<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Deletes a file's .webp and .avif whenever WordPress deletes the file itself.
 *
 * Not here yet: removing every generated file on uninstall or on request -
 * see CLAUDE.md, "Cleanup".
 */
final class Cleanup
{

	public function register(): void
	{
		// Late, so a plugin that cancels the deletion (by returning '') runs
		// first and the siblings stay with the file they belong to.
		add_filter('wp_delete_file', [$this, 'delete_siblings'], 99);
	}

	/**
	 * WordPress passes every file it deletes through this filter - the main
	 * file, each size, the original, edited versions - so this covers deleting
	 * an attachment, regenerating sizes and the media editor's restore alike.
	 * The path is returned unchanged: the file itself is still WordPress's to
	 * delete.
	 *
	 * wp_delete_file() is used for the siblings too, so offload and CDN plugins
	 * hear about them. It re-enters this filter with a .webp/.avif path, which
	 * the extension check lets straight through.
	 *
	 * @param string $file
	 * @return string
	 */
	public function delete_siblings($file)
	{
		if (!\is_string($file) || !\preg_match('/\.(jpe?g|png)$/i', $file) || !Encoder::is_in_uploads($file)) {
			return $file;
		}

		foreach (['.webp', '.avif'] as $extension) {
			if (\file_exists($file . $extension)) {
				wp_delete_file($file . $extension);
			}
		}

		return $file;
	}
}

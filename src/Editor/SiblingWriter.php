<?php

namespace LeanImages\Editor;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * What the plugin's editors (Imagick and GD) offer beyond core's: writing a
 * file's WebP/AVIF siblings outside a normal save. Code that needs this checks
 * for the interface, not a particular engine.
 */
interface SiblingWriter
{

	/**
	 * Off for a file that's never served (a replaced HEIC original).
	 */
	public function set_siblings(bool $siblings): void;

	/**
	 * Siblings for the file the editor loaded, with no resize.
	 *
	 * @return array<string, array{status: string, bytes?: int, mode?: string}>
	 */
	public function write_siblings_for_loaded_file(): array;

	/**
	 * Siblings for an existing file of the same image at the given size, made
	 * from the loaded pixels; the existing file isn't rewritten.
	 *
	 * @param bool|array $crop
	 * @return array<string, array{status: string, bytes?: int, mode?: string}>|\WP_Error
	 */
	public function write_siblings_for(string $target, int $width, int $height, $crop);
}

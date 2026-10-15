<?php

namespace LeanImages\Editor;

use LeanImages\Encoder;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * WordPress's GD editor, writing WebP and AVIF siblings next to every JPEG or
 * PNG it saves - the fallback for hosts without Imagick.
 *
 * Resizing stays core's (imagecopyresampled - softer than ImageMagick's
 * Lanczos; GD has nothing sharper). GD writes no metadata at all and does no
 * colour management: a Display P3 photo comes out dull, which is why Imagick is
 * preferred whenever it's there. WebP output is byte-identical to ImageMagick's
 * for the same pixels (same libwebp); AVIF quality 50 matches ImageMagick's 50
 * in size and fidelity within a few percent (CLAUDE.md, "GD fallback").
 */
final class GdEditor extends \WP_Image_Editor_GD implements SiblingWriter
{

	private bool $siblings = true;

	public function set_siblings(bool $siblings): void
	{
		$this->siblings = $siblings;
	}

	/**
	 * Unlike core's Imagick editor, core's GD _save() writes the $image it's
	 * given - the resized copy - so that's what the siblings are made from.
	 */
	protected function _save($image, $filename = null, $mime_type = null)
	{
		$saved = parent::_save($image, $filename, $mime_type);

		if ($this->siblings && !is_wp_error($saved)) {
			Encoder::write_siblings($image, $saved['path'], $saved['mime-type']);
		}

		return $saved;
	}

	public function write_siblings_for_loaded_file(): array
	{
		$loaded = $this->load();
		if (true !== $loaded) {
			return [];
		}

		return Encoder::write_siblings($this->image, $this->file, $this->mime_type);
	}

	/**
	 * Core's _resize() returns a new, resized image and leaves the loaded one
	 * alone - but it also records the new dimensions as the editor's size
	 * (update_size()). Left like that, every later size is computed from the
	 * wrong dimensions: on the first test run a 150 × 150 crop came out as the
	 * top-left corner of the photo. Core's make_subsize() restores the size
	 * after each call; so does this.
	 */
	public function write_siblings_for(string $target, int $width, int $height, $crop)
	{
		$loaded = $this->load();
		if (true !== $loaded) {
			return $loaded;
		}

		if ($width === $this->size['width'] && $height === $this->size['height']) {
			return Encoder::write_siblings($this->image, $target, (string) wp_get_image_mime($target));
		}

		$orig_size = $this->size;

		$resized = $this->_resize($width, $height, $crop);
		$this->size = $orig_size;

		if (!is_wp_error($resized) && ($width !== \imagesx($resized) || $height !== \imagesy($resized))) {
			\imagedestroy($resized);
			$resized = $this->_resize($width, $height, true); // Exact dimensions, centre crop.
			$this->size = $orig_size;
		}

		if (is_wp_error($resized)) {
			return $resized;
		}

		$result = Encoder::write_siblings($resized, $target, (string) wp_get_image_mime($target));
		\imagedestroy($resized);

		return $result;
	}
}

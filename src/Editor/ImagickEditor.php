<?php

namespace LeanImages\Editor;

use LeanImages\Encoder;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * WordPress's Imagick editor with two changes: the resize itself, and a WebP
 * and AVIF version written next to every JPEG or PNG it saves, from the same
 * in-memory pixels - so each file is compressed exactly once.
 *
 * Every route that makes image files goes through an editor - uploads,
 * sub-sizes, the -scaled and -rotated main files, edits in the media editor,
 * thumbnail regeneration - so this one class covers all of them.
 */
final class ImagickEditor extends \WP_Image_Editor_Imagick implements SiblingWriter
{

	private bool $siblings = true;

	/**
	 * Steps aside when the probe found that this ImageMagick can't make WebP
	 * (a build without the delegate) - WordPress then picks the GD editor.
	 */
	public static function test($args = [])
	{
		return parent::test($args) && 'gd' !== \LeanImages\Environment::engine();
	}

	/**
	 * Turns AVIF and HEIC down when the probe couldn't open its sample of that
	 * format here - ImageMagick listing a format doesn't prove it can read it
	 * (CLAUDE.md, "AVIF and HEIC inputs"). WordPress then tries the next editor:
	 * GD, for AVIF, when GD can.
	 */
	public static function supports_mime_type($mime_type)
	{
		$probe = \LeanImages\Environment::cached()['imagick'] ?? null;

		if (null !== $probe) {
			if ('image/avif' === $mime_type && empty($probe['reads_avif'])) {
				return false;
			}

			if (\in_array($mime_type, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true) && empty($probe['reads_heic'])) {
				return false;
			}
		}

		return parent::supports_mime_type($mime_type);
	}

	public function set_siblings(bool $siblings): void
	{
		$this->siblings = $siblings;
	}

	/**
	 * HEIC and AVIF store rotation as a transform (irot/imir) that libheif
	 * applies while decoding, so the pixels arrive upright. A leftover EXIF
	 * orientation tag must not rotate them a second time when metadata is
	 * stripped (Encoder::orient()).
	 */
	public function load()
	{
		$loaded = parent::load();

		if (true === $loaded) {
			Encoder::limit_threads();

			if (\in_array($this->mime_type, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/avif'], true)) {
				$this->image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
			}
		}

		return $loaded;
	}

	/**
	 * Core's thumbnail_image() as of WordPress 7.1.3, except the resize step.
	 * Core resamples with a Triangle filter at support 2.0, pre-samples big
	 * reductions with sampleImage() and unsharp-masks JPEG output: 10-32%
	 * smaller files, but visibly softer (CLAUDE.md, "Why the plugin replaces
	 * WordPress's resize"). Here the resize is ImageMagick's own default - the
	 * filter it picks itself, Lanczos for photos and Mitchell for images with
	 * alpha or a palette - which is what the desktop CLI used.
	 *
	 * Everything else (metadata, PNG handling, alpha, bit depth) is core's code
	 * unchanged. When core changes thumbnail_image(), diff it against this.
	 *
	 * $filter_name is ignored on purpose: the filter is the point of this
	 * override.
	 */
	protected function thumbnail_image($dst_w, $dst_h, $filter_name = 'FILTER_TRIANGLE', $strip_meta = true)
	{
		/** This filter is documented in wp-includes/class-wp-image-editor-imagick.php */
		if (apply_filters('image_strip_meta', $strip_meta)) {
			$this->strip_meta(); // Fail silently if not supported.
		}

		try {
			// PNG handling, unchanged from core - see core's comment there for
			// why indexed PNGs with true alpha need this.
			$is_png = false;
			$is_indexed_png = false;
			$is_indexed_png_with_alpha_channel = false;
			$is_indexed_png_with_true_alpha_transparency = false;

			if ('image/png' === $this->mime_type) {
				$is_png = true;

				if ('3' === $this->image->getImageProperty('png:IHDR.color-type-orig')) {
					$is_indexed_png = true;

					if ($this->image->getImageAlphaChannel()) {
						$is_indexed_png_with_alpha_channel = true;

						if (1 < $this->image->getImageChannelDepth(\Imagick::CHANNEL_ALPHA)) {
							$is_indexed_png_with_true_alpha_transparency = true;
						}
					}
				}
			}

			$this->image->resizeImage($dst_w, $dst_h, \Imagick::FILTER_UNDEFINED, 1);

			if ('image/jpeg' === $this->mime_type) {
				$this->image->setOption('jpeg:fancy-upsampling', 'off'); // Kept from core.
			}

			if ($is_png) {
				$this->image->setOption('png:compression-filter', '5');
				$this->image->setOption('png:compression-level', '9');
				$this->image->setOption('png:compression-strategy', '1');

				if ($is_indexed_png) {
					if ($is_indexed_png_with_alpha_channel) {
						$this->image->setOption('png:include-chunk', 'tRNS');
					} else {
						$this->image->setOption('png:exclude-chunk', 'all');
					}

					$this->image->quantizeImage(256, $this->image->getColorspace(), 0, false, false);

					if (\Imagick::COLORSPACE_GRAY === $this->image->getImageColorspace() && !$is_indexed_png_with_true_alpha_transparency) {
						$this->image->setOption('png:format', 'png8');
					}
				} else {
					$this->image->setOption('png:exclude-chunk', 'all');
				}
			}

			if (\Imagick::ALPHACHANNEL_UNDEFINED === $this->image->getImageAlphaChannel()) {
				$this->image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
			}

			/** This filter is documented in wp-includes/class-wp-image-editor-imagick.php */
			$max_depth = apply_filters('image_max_bit_depth', $this->image->getImageDepth(), $this->image->getImageDepth());
			$this->image->setImageDepth($max_depth);
		} catch (\Exception $e) {
			return new \WP_Error('image_resize_error', $e->getMessage());
		}
	}

	/**
	 * Core's _save() writes $this->image and ignores the $image it's given, so
	 * the siblings are made from $this->image too - the pixels that were just
	 * written. Sibling failures never fail the save itself.
	 */
	protected function _save($image, $filename = null, $mime_type = null)
	{
		[, , $output_mime] = $this->get_output_format($filename, $mime_type);

		if (\in_array($output_mime, ['image/jpeg', 'image/png'], true) && apply_filters('lean_images_strip_metadata', true)) {
			$this->remove_metadata();
		}

		$saved = parent::_save($image, $filename, $mime_type);

		if ($this->siblings && !is_wp_error($saved)) {
			Encoder::write_siblings($this->image, $saved['path'], $saved['mime-type']);
		}

		return $saved;
	}

	/**
	 * WordPress keeps EXIF, IPTC and XMP in the JPEGs and PNGs it makes - a
	 * phone's GPS coordinates included, sent to every visitor who gets the JPEG
	 * (Outlook, social previews). Only the colour profile stays.
	 *
	 * Covers every file WordPress writes - sizes, -scaled, -rotated, edits - but
	 * never the uploaded original, which WordPress doesn't re-save; that one is
	 * handled losslessly elsewhere (CLAUDE.md, "Originals").
	 */
	private function remove_metadata(): void
	{
		try {
			if (Encoder::orient($this->image)) {
				$this->update_size(); // A 90° turn swaps width and height.
			}
			Encoder::remove_metadata($this->image);
		} catch (\Throwable $e) {
			Encoder::log('could not remove metadata: ' . $e->getMessage()); // Never fail a save over it.
		}
	}

	/**
	 * Siblings for the file this editor loaded, with no resize. For the main
	 * image when WordPress keeps the upload as-is (see Attachments).
	 *
	 * @return array<string, array{status: string, bytes?: int, mode?: string}>
	 */
	public function write_siblings_for_loaded_file(): array
	{
		$loaded = $this->load();
		if (true !== $loaded) {
			return [];
		}

		return Encoder::write_siblings($this->image, $this->file, $this->mime_type);
	}

	/**
	 * Siblings for an existing JPEG/PNG of a library image, made from the pixels
	 * this editor holds (normally the original upload): resized the same way
	 * WordPress made that file, then encoded - the existing file itself is never
	 * rewritten. Like core's make_subsize(), the loaded image is restored after.
	 *
	 * The result must match the existing file's dimensions exactly, since it's
	 * served under that file's URL and srcset width. When resizing with the
	 * size's own crop setting comes out a pixel off (the size's definition
	 * changed since upload, or a different source), it's redone as a centre crop
	 * to exactly those dimensions.
	 *
	 * @param bool|array $crop
	 * @return array<string, array{status: string, bytes?: int, mode?: string}>|\WP_Error
	 */
	public function write_siblings_for(string $target, int $width, int $height, $crop)
	{
		$loaded = $this->load();
		if (true !== $loaded) {
			return $loaded;
		}

		$orig_size = $this->size;
		$orig_image = $this->image->getImage();

		try {
			if ($width !== $this->size['width'] || $height !== $this->size['height']) {
				$resized = $this->resize($width, $height, $crop);

				if (is_wp_error($resized) || $width !== $this->size['width'] || $height !== $this->size['height']) {
					$this->image->clear();
					$this->image = $orig_image->getImage();
					$this->size = $orig_size;
					$resized = $this->resize($width, $height, true);
				}

				if (is_wp_error($resized)) {
					return $resized;
				}
			}

			return Encoder::write_siblings($this->image, $target, (string) wp_get_image_mime($target));
		} finally {
			$this->image->clear();
			$this->image = $orig_image;
			$this->size = $orig_size;
		}
	}
}

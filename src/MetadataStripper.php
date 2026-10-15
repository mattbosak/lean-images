<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Removes metadata from JPEG, PNG and WebP files without touching the image
 * data: the file's structure is walked and only metadata blocks are dropped,
 * so nothing is recompressed.
 *
 * For the files WordPress never re-saves: the uploaded original (kept, never
 * shown on pages, but reachable by URL) and the JPEG/PNG sizes of images
 * uploaded before the plugin. Phones embed GPS coordinates in all of them.
 *
 * Kept: the colour profile, what's needed to decode the image correctly, and
 * the orientation - WordPress reads it from the original file whenever it
 * regenerates sizes (WP_Image_Editor::maybe_exif_rotate()), so it's rewritten
 * as a minimal EXIF block holding nothing else. Camera details shown in the
 * Media Library live in the database (image_meta) and are unaffected.
 *
 * The file's modification time is kept: the siblings' freshness checks
 * compare against it.
 */
final class MetadataStripper
{

	/**
	 * @return bool Whether the file was changed.
	 */
	public static function strip(string $path): bool
	{
		$data = @\file_get_contents($path);
		if (false === $data || \strlen($data) < 16) {
			return false;
		}

		if ("\xFF\xD8" === \substr($data, 0, 2)) {
			$stripped = self::strip_jpeg($data);
		} elseif ("\x89PNG\r\n\x1a\n" === \substr($data, 0, 8)) {
			$stripped = self::strip_png($data);
		} elseif ('RIFF' === \substr($data, 0, 4) && 'WEBP' === \substr($data, 8, 4)) {
			$stripped = self::strip_webp($data);
		} else {
			return false;
		}

		if (null === $stripped || $stripped === $data) {
			return false;
		}

		return self::replace($path, $stripped);
	}

	/**
	 * Keeps APP0 (JFIF), APP2 ICC_PROFILE and APP14 (Adobe - its transform flag
	 * decides how CMYK/YCCK data is decoded) and every non-APP segment; drops
	 * APP1 (EXIF, XMP), APP13 (IPTC), COM and the other APPn. Anything after
	 * the image's EOI goes too: MPF secondary images (depth maps, HDR gain maps).
	 */
	private static function strip_jpeg(string $data): ?string
	{
		$length = \strlen($data);
		$out = "\xFF\xD8";
		$app0 = '';
		$orientation = 1;
		$pos = 2;

		while ($pos + 4 <= $length) {
			if ("\xFF" !== $data[$pos]) {
				return null; // Not where a marker should be: leave the file alone.
			}

			$marker = \ord($data[$pos + 1]);

			if (0xFF === $marker) {
				$pos++; // Fill byte.
				continue;
			}

			if (0xDA === $marker) {
				// Start of scan: everything from here to the image's EOI is image
				// data, copied as is.
				$end = self::jpeg_image_end($data, $pos);
				if (null === $end) {
					return null;
				}

				$header = $orientation > 1 ? self::exif_segment($orientation) : '';

				return "\xFF\xD8" . $app0 . $header . \substr($out, 2) . \substr($data, $pos, $end - $pos);
			}

			$segment_length = \unpack('n', \substr($data, $pos + 2, 2))[1];
			if ($segment_length < 2 || $pos + 2 + $segment_length > $length) {
				return null;
			}

			$segment = \substr($data, $pos, 2 + $segment_length);
			$payload = \substr($segment, 4);

			if (0xE0 === $marker && '' === $app0) {
				$app0 = $segment; // JFIF/JFXX: must stay first.
			} elseif (0xE1 === $marker && 0 === \strncmp($payload, "Exif\0\0", 6)) {
				$orientation = self::tiff_orientation(\substr($payload, 6));
			} elseif ((0xE2 === $marker && 0 === \strncmp($payload, "ICC_PROFILE\0", 12)) || (0xEE === $marker && 0 === \strncmp($payload, 'Adobe', 5))) {
				$out .= $segment;
			} elseif (($marker < 0xE0 || $marker > 0xEF) && 0xFE !== $marker) {
				$out .= $segment; // Tables, frame header, restart interval...
			}

			$pos += 2 + $segment_length;
		}

		return null;
	}

	/**
	 * Offset just past the EOI that ends the image starting its scan at $pos.
	 * Walks scan data marker by marker, because a progressive JPEG has several
	 * scans with tables between them, and a second image (MPF) can follow -
	 * the last FF D9 in the file isn't necessarily this image's.
	 */
	private static function jpeg_image_end(string $data, int $pos): ?int
	{
		$length = \strlen($data);

		while ($pos < $length - 1) {
			$marker = \ord($data[$pos + 1]);

			if (0xD9 === $marker) {
				return $pos + 2;
			}

			// A segment with a length (SOS, DHT, DQT, DRI... between scans).
			$segment_length = \unpack('n', \substr($data, $pos + 2, 2))[1];
			$pos += 2 + $segment_length;

			// Entropy-coded data: FF 00 is a stuffed byte, FF D0-D7 a restart
			// marker; any other FF xx is the next marker.
			while (false !== ($next = \strpos($data, "\xFF", $pos))) {
				$byte = \ord($data[$next + 1] ?? "\x00");
				if (0x00 === $byte || ($byte >= 0xD0 && $byte <= 0xD7) || 0xFF === $byte) {
					$pos = $next + 1;
					continue;
				}
				$pos = $next;
				break;
			}

			if (false === $next) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Drops tEXt, zTXt, iTXt, tIME and eXIf (keeping only the orientation, in a
	 * minimal eXIf, when it isn't 1). Colour chunks (iCCP, sRGB, gAMA, cHRM) and
	 * everything else stay.
	 */
	private static function strip_png(string $data): ?string
	{
		$out = \substr($data, 0, 8);
		$pos = 8;
		$length = \strlen($data);

		while ($pos + 12 <= $length) {
			$chunk_length = \unpack('N', \substr($data, $pos, 4))[1];
			$type = \substr($data, $pos + 4, 4);
			$total = 12 + $chunk_length;

			if ($pos + $total > $length) {
				return null;
			}

			if ('eXIf' === $type) {
				$orientation = self::tiff_orientation(\substr($data, $pos + 8, $chunk_length));
				if ($orientation > 1) {
					$tiff = self::orientation_tiff($orientation);
					$out .= \pack('N', \strlen($tiff)) . 'eXIf' . $tiff . \pack('N', \crc32('eXIf' . $tiff));
				}
			} elseif (!\in_array($type, ['tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
				$out .= \substr($data, $pos, $total);
			}

			$pos += $total;
			if ('IEND' === $type) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Drops the EXIF and XMP chunks and clears their flags in VP8X; a non-1
	 * orientation is kept in a minimal EXIF chunk. The RIFF size is rewritten.
	 */
	private static function strip_webp(string $data): ?string
	{
		$chunks = '';
		$pos = 12;
		$length = \strlen($data);
		$has_exif = false;
		$vp8x_at = null;

		while ($pos + 8 <= $length) {
			$type = \substr($data, $pos, 4);
			$chunk_length = \unpack('V', \substr($data, $pos + 4, 4))[1];
			$total = 8 + $chunk_length + ($chunk_length & 1);

			if ($pos + 8 + $chunk_length > $length) {
				return null;
			}

			if ('EXIF' === $type) {
				$orientation = self::tiff_orientation(\substr($data, $pos + 8, $chunk_length));
				if ($orientation > 1) {
					$tiff = self::orientation_tiff($orientation);
					$chunks .= 'EXIF' . \pack('V', \strlen($tiff)) . $tiff . (\strlen($tiff) & 1 ? "\0" : '');
					$has_exif = true;
				}
			} elseif ('XMP ' !== $type) {
				if ('VP8X' === $type) {
					$vp8x_at = \strlen($chunks);
				}
				$chunks .= \substr($data, $pos, $total);
			}

			$pos += $total;
		}

		if (null !== $vp8x_at) {
			// Flags byte: 0x08 EXIF, 0x04 XMP.
			$flags = (\ord($chunks[$vp8x_at + 8]) & ~0x0C) | ($has_exif ? 0x08 : 0);
			$chunks[$vp8x_at + 8] = \chr($flags);
		}

		return 'RIFF' . \pack('V', 4 + \strlen($chunks)) . 'WEBP' . $chunks;
	}

	/**
	 * The Orientation tag (0x0112) from a TIFF structure - an EXIF payload -
	 * or 1 when there's none or it can't be read.
	 */
	private static function tiff_orientation(string $tiff): int
	{
		if (\strlen($tiff) < 8) {
			return 1;
		}

		$little = 'II' === \substr($tiff, 0, 2);
		$short = $little ? 'v' : 'n';
		$long = $little ? 'V' : 'N';

		$ifd = \unpack($long, \substr($tiff, 4, 4))[1];
		if ($ifd + 2 > \strlen($tiff)) {
			return 1;
		}

		$entries = \unpack($short, \substr($tiff, $ifd, 2))[1];
		for ($i = 0; $i < $entries; $i++) {
			$entry = $ifd + 2 + 12 * $i;
			if ($entry + 12 > \strlen($tiff)) {
				break;
			}
			if (0x0112 === \unpack($short, \substr($tiff, $entry, 2))[1]) {
				$value = \unpack($short, \substr($tiff, $entry + 8, 2))[1];

				return $value >= 1 && $value <= 8 ? $value : 1;
			}
		}

		return 1;
	}

	/**
	 * A TIFF structure holding one tag: Orientation.
	 */
	private static function orientation_tiff(int $orientation): string
	{
		return 'II' . \pack('v', 42) . \pack('V', 8) // Header, IFD0 at offset 8.
			. \pack('v', 1) // One entry:
			. \pack('v', 0x0112) . \pack('v', 3) . \pack('V', 1) . \pack('v', $orientation) . \pack('v', 0) // Orientation, SHORT, 1 value.
			. \pack('V', 0); // No next IFD.
	}

	private static function exif_segment(int $orientation): string
	{
		$payload = "Exif\0\0" . self::orientation_tiff($orientation);

		return "\xFF\xE1" . \pack('n', 2 + \strlen($payload)) . $payload;
	}

	/**
	 * Atomic, with the original modification time restored.
	 */
	private static function replace(string $path, string $contents): bool
	{
		$mtime = @\filemtime($path);
		$temp = $path . '.' . wp_generate_password(8, false) . '.tmp';

		if (false === \file_put_contents($temp, $contents)) {
			@\unlink($temp);
			return false;
		}

		$stat = @\stat($path);
		if ($stat) {
			@\chmod($temp, $stat['mode'] & 0000777);
		}

		if (!@\rename($temp, $path)) {
			@\unlink($temp);
			return false;
		}

		if (false !== $mtime) {
			@\touch($path, $mtime);
		}

		return true;
	}
}

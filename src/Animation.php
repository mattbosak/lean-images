<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Tells animated images from still ones by reading file structure only - no
 * pixel decoding, so a check costs well under a millisecond even for large
 * files.
 *
 * ImageMagick can't answer this: it reads only the first image of an animated
 * AVIF or APNG and reports one frame for both. See CLAUDE.md, "Animated
 * images", for the test set this logic was checked against.
 */
final class Animation
{

	/**
	 * Brands that mark an ISOBMFF file as AVIF or HEIF. libavif animations carry
	 * only avis/msf1, never avif/mif1 - both kinds have to match.
	 */
	private const ISOBMFF_BRANDS = ['avif', 'avis', 'heic', 'heix', 'mif1', 'msf1'];

	/** @var array<string, int|null> Keyed by path + mtime, so an overwritten file is re-read. */
	private static array $cache = [];

	public static function is_animated(string $path): bool
	{
		return (self::frame_count($path) ?? 1) > 1;
	}

	/**
	 * Number of frames, or null when the file isn't a GIF, PNG, WebP or
	 * AVIF/HEIF (a JPEG, for instance - never animated). GIF and WebP counting
	 * stops at 2, which is enough to answer "animated?" without walking a long
	 * animation.
	 */
	public static function frame_count(string $path): ?int
	{
		$key = $path . '|' . (int) @\filemtime($path);
		if (\array_key_exists($key, self::$cache)) {
			return self::$cache[$key];
		}

		$handle = @\fopen($path, 'rb');
		if (false === $handle) {
			return null; // Not cached: the file may simply not exist yet.
		}

		$count = null;
		foreach (['gif_frames', 'png_frames', 'webp_frames', 'isobmff_frames'] as $method) {
			$count = self::$method($handle);
			if (null !== $count) {
				break;
			}
		}
		\fclose($handle);

		return self::$cache[$key] = $count;
	}

	/**
	 * Walks the block structure properly rather than searching for byte
	 * patterns, which also occur inside compressed image data.
	 *
	 * @param resource $f
	 */
	private static function gif_frames($f): ?int
	{
		\fseek($f, 0);
		$header = (string) \fread($f, 13);
		if (13 !== \strlen($header) || !\in_array(\substr($header, 0, 6), ['GIF87a', 'GIF89a'], true)) {
			return null;
		}

		$flags = \ord($header[10]);
		if ($flags & 0x80) {
			\fseek($f, 3 * (2 << ($flags & 7)), SEEK_CUR); // Global colour table.
		}

		$frames = 0;
		while ('' !== ($byte = (string) \fread($f, 1))) {
			$byte = \ord($byte);

			if (0x3B === $byte) {
				break; // Trailer.
			}

			if (0x21 === $byte) {
				\fread($f, 1); // Extension label.
				self::skip_gif_sub_blocks($f);
				continue;
			}

			if (0x2C !== $byte) {
				break; // Corrupt - count what was found so far.
			}

			$frames++;
			if ($frames > 1) {
				return $frames;
			}

			$descriptor = (string) \fread($f, 9);
			if (9 !== \strlen($descriptor)) {
				break;
			}

			$local_flags = \ord($descriptor[8]);
			if ($local_flags & 0x80) {
				\fseek($f, 3 * (2 << ($local_flags & 7)), SEEK_CUR); // Local colour table.
			}

			\fread($f, 1); // LZW minimum code size.
			self::skip_gif_sub_blocks($f);
		}

		return \max($frames, 1);
	}

	/**
	 * @param resource $f
	 */
	private static function skip_gif_sub_blocks($f): void
	{
		while ('' !== ($length = (string) \fread($f, 1)) && 0 < ($length = \ord($length))) {
			\fseek($f, $length, SEEK_CUR);
		}
	}

	/**
	 * APNG keeps a still PNG's structure and adds an acTL chunk, which the spec
	 * requires before the first IDAT.
	 *
	 * @param resource $f
	 */
	private static function png_frames($f): ?int
	{
		\fseek($f, 0);
		if ("\x89PNG\r\n\x1a\n" !== \fread($f, 8)) {
			return null;
		}

		while (8 === \strlen($chunk = (string) \fread($f, 8))) {
			$length = \unpack('N', $chunk)[1];
			$type = \substr($chunk, 4, 4);

			if ('acTL' === $type) {
				$data = (string) \fread($f, 4);

				return 4 === \strlen($data) ? \max(1, \unpack('N', $data)[1]) : 1;
			}

			if ('IDAT' === $type || 'IEND' === $type) {
				return 1;
			}

			\fseek($f, $length + 4, SEEK_CUR); // Chunk data + CRC.
		}

		return 1;
	}

	/**
	 * @param resource $f
	 */
	private static function webp_frames($f): ?int
	{
		\fseek($f, 0);
		$header = (string) \fread($f, 12);
		if (12 !== \strlen($header) || 'RIFF' !== \substr($header, 0, 4) || 'WEBP' !== \substr($header, 8, 4)) {
			return null;
		}

		$frames = 0;
		while (8 === \strlen($chunk = (string) \fread($f, 8))) {
			$type = \substr($chunk, 0, 4);
			$length = \unpack('V', $chunk, 4)[1];
			$padded = $length + ($length & 1); // Chunks are padded to an even size.

			if ('VP8X' === $type) {
				$flags = \ord((string) \fread($f, 1));
				if (!($flags & 0x02)) {
					return 1; // Animation flag not set.
				}
				\fseek($f, $padded - 1, SEEK_CUR);
				continue;
			}

			if ('VP8 ' === $type || 'VP8L' === $type) {
				return 1; // Simple, still WebP.
			}

			if ('ANMF' === $type && ++$frames > 1) {
				return $frames;
			}

			\fseek($f, $padded, SEEK_CUR);
		}

		return \max($frames, 1);
	}

	/**
	 * AVIF/HEIF: an image sequence lives in a moov track, and the track's frame
	 * count is its sample count (stsz, or the compact stz2). A file with a moov
	 * but a single sample is still. Boxes are skipped by size, so the media
	 * data itself (mdat) is never read.
	 *
	 * @param resource $f
	 */
	private static function isobmff_frames($f): ?int
	{
		$size = (int) \fstat($f)['size'];
		$top = self::find_boxes($f, 0, $size, ['ftyp', 'moov']);

		if (!isset($top['ftyp'])) {
			return null;
		}

		[$start, $end] = $top['ftyp'][0];
		\fseek($f, $start);
		$brands = \str_split((string) \fread($f, \min($end - $start, 256)), 4);
		unset($brands[1]); // Minor version, not a brand.

		if (!\array_intersect($brands, self::ISOBMFF_BRANDS)) {
			return null;
		}

		if (!isset($top['moov'])) {
			return 1;
		}

		$frames = 1;
		foreach (self::descend($f, $top['moov'][0], ['trak', 'mdia', 'minf', 'stbl']) as [$stbl_start, $stbl_end]) {
			$tables = self::find_boxes($f, $stbl_start, $stbl_end, ['stsz', 'stz2']);
			foreach ($tables as $type => $ranges) {
				// Full box: 4 bytes version/flags, then for stsz a 4-byte sample
				// size, for stz2 3 reserved bytes + 1 field-size byte.
				\fseek($f, $ranges[0][0] + 8);
				$data = (string) \fread($f, 4);
				if (4 === \strlen($data)) {
					$frames = \max($frames, \unpack('N', $data)[1]);
				}
			}
		}

		return $frames;
	}

	/**
	 * Every [start, end] content range of the wanted box types directly inside
	 * the given range.
	 *
	 * @param resource $f
	 * @param string[] $types
	 * @return array<string, array<int, array{0: int, 1: int}>>
	 */
	private static function find_boxes($f, int $from, int $to, array $types): array
	{
		$found = [];
		$position = $from;

		while ($position + 8 <= $to) {
			\fseek($f, $position);
			$header = (string) \fread($f, 8);
			if (8 !== \strlen($header)) {
				break;
			}

			$box_size = \unpack('N', $header)[1];
			$type = \substr($header, 4, 4);
			$header_size = 8;

			if (1 === $box_size) {
				$large = (string) \fread($f, 8); // 64-bit size follows.
				if (8 !== \strlen($large)) {
					break;
				}
				$box_size = \unpack('J', $large)[1];
				$header_size = 16;
			} elseif (0 === $box_size) {
				$box_size = $to - $position; // Box runs to the end of its parent.
			}

			if ($box_size < $header_size) {
				break; // Corrupt; stop rather than loop.
			}

			if (\in_array($type, $types, true)) {
				$found[$type][] = [$position + $header_size, \min($position + $box_size, $to)];
			}

			$position += $box_size;
		}

		return $found;
	}

	/**
	 * Follows a box path (e.g. trak → mdia → minf → stbl) through every
	 * matching branch - a file can hold several tracks.
	 *
	 * @param resource $f
	 * @param array{0: int, 1: int} $range
	 * @param string[] $path
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function descend($f, array $range, array $path): array
	{
		$type = \array_shift($path);
		$ranges = self::find_boxes($f, $range[0], $range[1], [$type])[$type] ?? [];

		if (!$path) {
			return $ranges;
		}

		$results = [];
		foreach ($ranges as $child) {
			$results = \array_merge($results, self::descend($f, $child, $path));
		}

		return $results;
	}
}

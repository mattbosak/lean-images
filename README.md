# Lean Images

Serves AVIF and WebP versions of your images to every browser that supports them,
made on your own server as you upload. Your JPEG and PNG files stay exactly where they
are, so nothing changes for anyone else - and switching the plugin off puts everything
back instantly.

If it saves you an afternoon, you can [buy me a coffee](https://ko-fi.com/mattbosak).
Entirely optional - the plugin is free and GPL either way.

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [FAQ](#faq)
- [For developers](#for-developers)
- [Support the project](#support-the-project)
- [License](#license)

## What it does

- **Converts as you upload.** Every size WordPress makes of an image also gets an AVIF
  and a WebP version, made by ImageMagick (or GD) on your own server. No external
  service, no API key, no quota.
- **Keeps images sharp.** Each size is made from your original upload - resized once,
  compressed once.
- **Changes nothing on your pages.** The server picks the best format for each browser
  under the original image address, so themes, page builders and page caches keep
  working.
- **Caps oversized uploads** to the Large size you set in Settings → Media. The original
  is kept, but never shown on pages. (PNGs from WordPress 6.8 on - earlier versions
  don't scale PNGs down.)
- **Removes location data.** GPS coordinates and camera details are taken out of the
  uploaded original and every image file WordPress makes - without recompressing them.
  iPhone HEIC photos are replaced with a full-size JPEG.
- **Leaves animations alone.** Animated GIF, WebP and AVIF files (`.avifs` too) are not
  converted or resized.
- **Converts the images you already have**, in the background, from their originals.
- **Checks your server.** A built-in test shows what the host can do and spells out the
  fix when something is missing.

## Requirements

- WordPress 6.5+, PHP 8.1+, a single site (multisite networks aren't supported yet)
- The Imagick PHP extension (recommended) or GD with WebP support. AVIF needs an
  ImageMagick built with AVIF support, or GD with AVIF - when only GD has it, GD makes
  the AVIF files in the background.
- Apache or LiteSpeed to deliver the converted files. On nginx the files are still made,
  and the plugin shows the configuration to add.
- OpenLiteSpeed isn't supported for delivery: it sometimes sends AVIF or WebP to browsers
  that didn't ask for them. The built-in test catches this and pauses delivery, so
  visitors get the original JPEG and PNG files.

## Installation

1. Upload the plugin to `/wp-content/plugins/` and activate it through the Plugins
   screen.
2. Open **Media → Lean Images** and check the Status panel. If the server needs a
   change, the fix is shown right there.
3. Optional: set **Settings → Media → Large size** to the largest size your site
   actually displays.
4. Convert the images you already have with the button in the Library panel.

## FAQ

**Does it change my image addresses or my page HTML?**
No. Pages keep linking to the same `.jpg` and `.png` files; the server sends the AVIF or
WebP version under that address when the browser accepts it.

**Where does it write its server rules?**
At the end of your site's main `.htaccess`, after WordPress's own rules, plus file types
and a cache header in `wp-content/uploads/.htaccess`. At the end, so anything above them
- a security plugin's rules, hotlink protection - keeps working as before.

**What happens if I deactivate or delete it?**
Deactivating removes its server rules, so visitors get your original JPEG and PNG files
again immediately. Deleting also removes the AVIF and WebP files it made - on a large
site, use **Remove generated files** on the settings screen first, which works in
batches.

**What happens to the original photo I upload?**
It's kept, but never shown on your pages. Location and camera details are removed from
it without recompressing the image; the orientation is kept so WordPress can still turn
it upright. iPhone HEIC photos are replaced with a full-size JPEG.

**My host has no ImageMagick. Does it still work?**
Yes, with GD - most hosts have it. Resized images are slightly softer, colours aren't
converted from Display P3, HEIC isn't supported, and AVIF is made in the background
because GD is slow at it.

**My host runs nginx. Will it work?**
Images are converted, but nginx doesn't read `.htaccess` files, so they aren't sent to
visitors until a few lines are added to the server configuration. The Status panel shows
them, ready to copy.

**I use Cloudflare. Anything to know?**
Cloudflare's free plan can cache one format and send it to every browser. The Status
panel detects Cloudflare and lets you turn AVIF off there, or keep it if you have
Cloudflare's "Vary for images" turned on.

## For developers

WP-CLI:

```
wp lean-images status [--run]          # server test and library numbers
wp lean-images convert <id>...         # convert these images now
wp lean-images convert --all [--force] # everything not converted yet (--force: everything)
wp lean-images selftest                # exits 1 when delivery or conversion is broken
wp lean-images cleanup [--yes]         # remove every generated WebP/AVIF file
```

Filters, all optional:

| Hook                          | Purpose                                         | Default                 |
| ----------------------------- | ----------------------------------------------- | ----------------------- |
| `lean_images_avif_enabled`    | Make AVIF versions                              | setting, on             |
| `lean_images_webp_enabled`    | Make WebP versions                              | setting, on             |
| `lean_images_avif_quality`    | AVIF quality, 1-100                             | setting, `50`           |
| `lean_images_webp_quality`    | WebP quality, 1-100                             | setting, `75`           |
| `lean_images_max_dimensions`  | Upload cap as `[width, height]`, 0 = no limit  | Settings → Media, Large |
| `lean_images_strip_metadata`  | Remove EXIF/IPTC/XMP from originals and the files WordPress makes | `true` |
| `lean_images_imagick_threads` | ImageMagick threads per request, 0 = no limit   | `2`                     |
| `lean_images_avif_inline_seconds` | Slowest AVIF encode (1500 × 1000 test image) still done during upload | `0.8` |

Read `CLAUDE.md` before changing anything - it documents the architecture, the
measurements behind the defaults, and the WordPress and ImageMagick behaviour the plugin
relies on.

## Support the project

This plugin is free and always will be. If it saved you time, a coffee is a nice way to
say so: [ko-fi.com/mattbosak](https://ko-fi.com/mattbosak).

Bug reports and pull requests are just as welcome, and cost nothing.

## License

GPLv2 or later. See the license header in `lean-images.php`.

`assets/sRGB_v4_ICC_preference.icc` is the International Color Consortium's sRGB
profile, Copyright 2007 International Color Consortium, redistributed unchanged under the
ICC's terms (free to use and distribute provided the file, including its copyright tag,
is not changed). It's used to convert Display P3 and other non-sRGB images to sRGB.

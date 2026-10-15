# Maintainer notes

What isn't captured in the code comments (kept short on purpose): the reasoning, the
measurements behind the defaults, and the WordPress, ImageMagick and server behaviour the
plugin relies on. Machine-specific notes - test sites, paths - live in
`CLAUDE.local.md`, which isn't part of the repository.

## Status

Feature-complete; 1.0.0 is the first release. Checked on WordPress 6.5 (the minimum) and
7.1; on Apache 2.4 (Windows and Ubuntu), LiteSpeed Enterprise, nginx 1.18 and 1.22, and
OpenLiteSpeed 1.9 (which fails - see "Delivery"); on ImageMagick 6.9 and 7.1, GD only,
and ImageMagick without AVIF next to GD with AVIF. What still needs a real-world check is under "Still to verify in the
real world".

When adding a class, also add it to the `required` list in the release workflow's
"Verify zip contents" step.

## What this is

A WordPress plugin that serves AVIF and WebP versions of uploaded images, generated on the
server with the same settings as the author's desktop ImageMagick workflow, so images no
longer have to be optimized locally before upload. Built for public distribution across
many client sites - "plug-n-play": activate it and it adapts to whatever the site and
host provide.

In one paragraph: uploads are capped to the site's own "Large" size, WordPress makes its
usual JPEG/PNG files, and for every one of them the plugin writes `<file>.webp` and
`<file>.avif` next to it, from the same in-memory pixels. An `.htaccess` rule in the
uploads folder then hands each browser the best format it accepts, under the original
URL. Page HTML is never changed.

## Hard rules

- **Never serve a format the browser didn't ask for.** A sibling is only served when the
  request's `Accept` header names that format *and* the file exists; every image response
  carries `Vary: Accept`.
- **The plugin being off must leave a working, stock site.** Deactivation removes the
  `.htaccess` blocks, so the site falls straight back to its JPEG/PNG files. JPEG/PNG stay
  the files WordPress records as the images - never replaced by WebP (see "Why JPEG
  stays the main file").
- **Originals are never re-encoded**, with exactly one exception: HEIC originals (see
  "HEIC uploads"). Metadata removal from originals must be lossless.
- **Generated files are always named `<served file name>.<webp|avif>`.** Rewrite rules,
  deletion, uninstall and stats all rely on that pattern and nothing else - so an upload
  named that way (`logo.png.avif`) is renamed (`logo-png.avif`); see "Security".
- **Never weaken the site's other rules.** The rewrite rules go at the end of the main
  `.htaccess`, never in a subfolder (see "Delivery").
- **Animated images are never touched** - no sub-sizes, no siblings.

## Architecture

Thin bootstrap, `Plugin` orchestrator, one responsibility per class, each with
`register()` (see "Code conventions").

- `lean-images.php` - header (incl. `Update URI`), constants, autoloader. Activation:
  hard-block via `wp_die()` on multisite (not supported yet: the network shares one main
  `.htaccess`) and if neither Imagick nor GD can write WebP; otherwise write the
  `.htaccess` blocks and clear the cached self-test result, so the test runs on the next
  admin page. Deactivation: remove the `.htaccess` blocks.
- `src/Plugin.php` - orchestrator.
- `src/Config.php` - settings getters (WebP quality 75, AVIF quality 50, formats on/off),
  each through `apply_filters`; stored in one option.
- `src/Environment.php` - what this host can do: Imagick/GD present, which formats each
  can read and write, whether Imagick really decodes AVIF/HEIC (not just lists it), AVIF
  encode speed, and so which engine does which job. Cached in an option, refreshed by
  the self-test.
- `src/Editors.php` - registers the plugin's editors at the front of `wp_image_editors`
  (priority 20, after typical snippets that replace the list).
- `src/Editor/ImagickEditor.php` - extends `WP_Image_Editor_Imagick`. Replaces
  WordPress's resize (see "Why the plugin replaces WordPress's resize") and, after each
  JPEG/PNG save, writes the WebP/AVIF siblings from the same in-memory image.
- `src/Editor/GdEditor.php` - the same for `WP_Image_Editor_GD`.
- `src/Editor/SiblingWriter.php` - the interface both editors implement, so callers
  don't care which engine runs.
- `src/Encoder.php` - the shared encode step: settings, PNG lossless-vs-lossy, keep only
  if smaller, atomic writes.
- `src/Animation.php` - frame counting from file structure (GIF, APNG, WebP, AVIF).
- `src/UploadCap.php` - the per-image `big_image_size_threshold` callback.
- `src/Attachments.php` - post-upload work on `wp_generate_attachment_metadata`: the
  main-file gap, HEIC replacement, original metadata removal, no sub-sizes for
  animations, queueing anything missed.
- `src/Originals.php` + `src/MetadataStripper.php` - which originals to clean and when;
  lossless metadata removal for JPEG/PNG/WebP.
- `src/Library.php` - per-image state and the library numbers. `src/Converter.php` -
  converts one existing image from its original. `src/Queue.php` - background worker
  (WP-Cron) for the existing library, slow-host AVIF, and retries.
- `src/Htaccess.php` - writes/removes the rules: types and rewrites in the main
  `.htaccess`, `Vary` in the uploads folder's.
- `src/SelfTest.php` - loopback checks, Site Health test, admin notices.
- `src/Cleanup.php` - deletes siblings when WordPress deletes a file.
- `src/Admin/SettingsPage.php`, `src/Admin/MediaColumn.php`, `src/Admin/Api.php`,
  `src/Admin/Notices.php` - admin screen, Media Library column and bulk action, the REST
  endpoints the screen's progress uses, notices after admin-post redirects.
- `src/Cli.php` - WP-CLI commands.
- `src/Updater.php` - self-updates from GitHub releases. `src/UpdateInfo.php` - the
  "View details" modal, from `plugin-info.json`.
- `uninstall.php`.

## How an upload flows

1. **Upload** (any route - see "Upload routes covered").
2. **Animated?** `Animation` reads the file structure. Animated → no sub-sizes, no
   siblings, width/height kept, done.
3. **Cap.** `UploadCap` tells WordPress the threshold that fits this image inside the
   Large-size box; oversized images get a `-scaled` main file.
4. **WordPress makes its files**, using the plugin's editor for every resize and save.
   Each JPEG/PNG save also writes `.webp` and `.avif` next to it from the same pixels
   (AVIF deferred to the queue on slow hosts).
5. **Post-processing** (`Attachments`): main-file gap, HEIC replacement, lossless
   metadata removal from the original, anything missing goes to the queue.
6. **Delivery.** A request for `photo-768x480.jpg` from a browser accepting AVIF gets
   `photo-768x480.jpg.avif`; one accepting only WebP gets the `.webp`; anything else gets
   the JPEG.

## Encoding: parity with the desktop CLI (verified)

The settings come from the author's desktop app, which ran two
ImageMagick passes: `mogrify -strip -interlace Plane -auto-orient -format webp|avif`, then
`mogrify -resize "1500x1500>"` on the output. That second pass sets no quality, so the
encoders' defaults decide the final file: verified byte-identical to *resize, strip,
WebP quality 75* and *AVIF quality 50* (6 test photos for WebP, 2 for AVIF).

The app's original "resize first came out bigger" observation was quality inheritance,
not order: without `-quality`, ImageMagick reuses the source JPEG's estimated quality
(93-94 for camera JPEGs), while a WebP source carries none, so the encoder default (75)
applies. **Always set quality explicitly** - otherwise output size depends on how the
uploaded JPEG happened to be saved.

The plugin does it in one pass: same numbers, 1-5% larger than the two-pass files, and
measurably closer to the source (SSIM), because the image is compressed once.

Settings, final:

- Resize with ImageMagick's default filter (`Imagick::FILTER_UNDEFINED` - Lanczos for
  photos, Mitchell for images with alpha or a palette, same choice the CLI makes).
- Convert to sRGB *before* stripping when the image has a non-sRGB profile (iPhone Display
  P3, CMYK). Stripping a P3 profile without converting dulls colours; the desktop app has
  this bug. Uses the bundled sRGB ICC profile (licence: see "Settled during the build").
- Strip all metadata.
- WebP quality 75 (`setImageCompressionQuality` - the WebP coder reads the image-level
  value), encoder's default method.
- AVIF quality 50 (`setCompressionQuality` - the HEIC/AVIF coder reads the wand-level
  value; set both to be safe), ImageMagick's default speed. WordPress itself sets
  `heic:speed 7`; measured within ±10% on size, faster - an option if uploads feel slow.
- `-interlace Plane` from the app is dropped: no effect on WebP/AVIF.

On a shared host with ImageMagick 7.1.1 (PHP 8.5): WebP byte-identical to the local CLI,
AVIF 0.14% larger (different encoder build). WebP 0.18 s, AVIF 0.23 s for a 1500px image.

Through WordPress the result is **within 0.1% of the CLI, not byte-identical**: core's
resize step ends with `setImageDepth()` (the `image_max_bit_depth` filter), which rounds
the resized pixels to 8 bits before anything is saved; the CLI hands the encoder the
unrounded result. Measured: 84,304 vs 84,330 bytes, no visible difference. Matching
exactly would mean keeping a second full copy of every resized image in memory - not
worth it. ImageMagick's Q16 vs Q16-HDRI builds differ by a similar margin.

## Why the plugin replaces WordPress's resize (measured)

`WP_Image_Editor_Imagick::thumbnail_image()` resizes with `FILTER_TRIANGLE` and
`filter:support 2.0`, adds `unsharpMaskImage(0.25, 0.25, 8, 0.065)` for JPEG sources,
pre-samples with `sampleImage()` when shrinking below ~11% of the area, keeps ICC/ICM
profiles, and defaults to quality 82 (JPEG) / 86 (WebP).

Same images, WebP 75, WordPress-style resize vs the CLI's: **10-32% smaller, but visibly
blurrier** - fine star fields vanish, rock texture smears. Rejected; the author chose the
CLI's sharpness.

Consequence, accepted: the JPEG sub-sizes are produced by the plugin's resize too, so
they're slightly sharper and larger than stock WordPress. They are rarely served
(Outlook, social previews, very old browsers).

## Why JPEG stays the main file

Considered and rejected: making WebP the main file (WordPress supports it via
`image_editor_output_format`) and rewriting WebP → AVIF, which would save the disk space
of the JPEG sub-sizes. Rejected because JPEG is still needed: Outlook desktop doesn't
render WebP (RSS-to-email newsletters, WooCommerce emails), and social share previews are
unreliable with WebP. Existing libraries would also have needed URL rewriting in post
content and page-builder data.

## WordPress internals this relies on (source-verified against trunk, 2026-10)

- `wp_create_image_subsizes()`: the `big_image_size_threshold` filter receives
  `($threshold, $imagesize, $file, $attachment_id)`; `$imagesize` is the *stored*
  (unrotated) size; WordPress scales first, then rotates. PNGs are scaled from
  WordPress 6.8; 6.5-6.7 skip them ("Do not scale (large) PNG images", #48736), so on
  those versions a PNG above the cap keeps its full size and every sub-size - core
  behaviour, not replicated here (verified on 6.5: a 2400 × 800 PNG stayed as it was).
- `_wp_make_subsizes()` makes every sub-size from the **original upload** after
  `maybe_exif_rotate()` - not from the `-scaled` file. That's what lets the plugin work
  from original pixels without compressing twice.
- `WP_Image_Editor::maybe_exif_rotate()` reads Orientation from the **file**
  (`exif_read_data`), including when sizes are regenerated later from the original - so
  lossless metadata removal must keep Orientation.
- When an upload is within the threshold and its orientation is already 1, the uploaded
  file itself becomes the main image and **no editor save happens** - see "The main-file
  gap".
- `wp_delete_file` filter fires for every file WordPress deletes.
- `update_plugins_{$hostname}` filter (WP 5.8+) for plugins with an `Update URI` header:
  `apply_filters("update_plugins_{$hostname}", false, $plugin_data, $plugin_file,
  $locales)`.
- WP 6.7+: HEIC uploads are converted to JPEG through the output-format mechanism; the
  HEIC stays as `original_image`.

## Upload cap

The cap is the site's own Settings → Media "Large" size (`large_size_w`,
`large_size_h`), applied per image through `big_image_size_threshold`:

- WordPress fits images into a threshold × threshold square, so the plugin computes, per
  image, the longest side that fits the Large *box*: Large 1920×1080 + landscape
  4032×3024 → 1440×1080 → threshold 1440. Uses the **displayed** orientation (swap
  width/height when EXIF says 90°/270°), since `$imagesize` is unrotated.
- 0 in one field = no limit on that side. 0 in both = leave WordPress's 2560 default.
- Already within the box → no scaling (and see "The main-file gap").
- **Sizes beyond the main image are dropped** (`intermediate_image_sizes_advanced`).
  The plan first assumed WordPress would skip them by itself; the first test run proved
  otherwise. Sub-sizes are made from the *original*, so a 1920px upload capped to 1500
  still got a 1536×960 copy, a 4032px one a 1536×2048 copy - both in `srcset`, undoing
  the cap - and the Large size came out as a byte-identical duplicate of the `-scaled`
  file. Every size `image_resize_dimensions()` says would be no smaller than the capped
  main image is now removed; WordPress serves the main image wherever one is asked for
  (`srcset` then tops out at the main image, "large" resolves to it).
- New uploads only; existing images keep their sizes.
- The original is kept as `original_image`, never served on pages.
- Note for site setup: WordPress's default Large is 1024×1024; the author's desktop
  default was 1500.

## Generated files

- `photo-768x480.jpg` → `photo-768x480.jpg.webp` + `photo-768x480.jpg.avif`, for the
  main file and every sub-size (including `-scaled`, `-rotated` and `-e<timestamp>`
  edited files). Not for `original_image` - never served. Appending the extension avoids
  `photo.jpg` / `photo.png` collisions and gives `.htaccess` a direct existence check.
- Whatever sizes the site registers are covered: WordPress decides the sizes, the plugin
  only changes how each is resized and saved.
- WebP kept only if smaller than its JPEG/PNG; AVIF kept only if smaller than its WebP.
  Tiny thumbnails sometimes lose; `.htaccess` then falls through to the next format.
- PNG sources: lossless and lossy WebP both tried, smaller kept (lossy blurs text in
  screenshots and logos). AVIF from PNG: lossy only.
- Writes are atomic (temp file + rename), so a half-written file can never be served.
- **The JPEG/PNG files WordPress writes are stripped too** (decided 2026-10-15): core
  keeps EXIF, IPTC and XMP in every size it makes - measured: a phone photo's GPS
  coordinates in all its thumbnails, served to anyone who gets the JPEG. The plugin's
  editor removes everything but the colour profile before each JPEG/PNG save (sizes,
  `-scaled`, `-rotated`, edits). Pixels are turned upright first when the orientation
  tag says so, because the tag goes with the EXIF - WordPress normally rotates already,
  but can't without PHP's exif extension. Filter `lean_images_strip_metadata` (default
  true) turns this off. The uploaded original is never re-saved by WordPress; it's
  covered by the lossless removal in "Originals".
- Skipped inputs: GIF, SVG, WebP and AVIF uploads (already modern formats), animations.

## The main-file gap

The editor-based approach only sees files WordPress resizes or re-saves. Thumbnails
always are; the main image is re-saved only when it gets scaled (`-scaled`) or rotated
(`-rotated`). An upload already within the cap and upright - e.g. the author's
desktop-optimized JPEGs - becomes the main image untouched, so no siblings get made for
it. `Attachments` checks the main file after `wp_generate_attachment_metadata` and
converts it if its siblings are missing.

## Delivery: `.htaccess`

Decided: `.htaccess` only, no `<picture>` fallback. With JPEG as the main file, a rewrite
that doesn't work just means JPEG gets served - never a broken image.

Two blocks, both marked `Lean Images`, written with `insert_with_markers()` (which keeps
everything else in the file):

- **`AddType` and the rewrite rules at the end of the site's main `.htaccess`** -
  WordPress's file, in the folder of the home address (`Htaccess::location()`: ABSPATH,
  or its parent when WordPress lives in a subfolder of the site's address). At the end,
  so rules above it - hotlink protection, security plugins' rules - still see the
  original request. `AddType` sits here, with the rules, because the rules depend on it:
  in a `.htaccess`, Apache drops the rule's `T=` type at the internal redirect and goes
  by the file's extensions - all of them - so on an Apache that doesn't know `.webp`
  (Ubuntu 22.04's) `photo.jpg.webp` went out as `image/jpeg`.
- **The `Vary` header in `wp-content/uploads/.htaccess`** - scoped to uploads. Apache
  adds `Vary: Accept` by itself only when a rule matched, so the JPEG/PNG answers need
  this. The uploads folder is created if it doesn't exist yet.

**Why not all in the uploads folder** (the first design): a `.htaccess` with rewrite
rules of its own switches off every parent folder's rewrite rules for that folder.
Verified on Apache 2.4: a security plugin's "no PHP in uploads" rule in the main file
(Solid Security writes one, `RewriteRule ^wp\-content/uploads/.*\.php… - [F]`) stopped
blocking - a PHP file in uploads ran (200 instead of 403). `RewriteOptions Inherit` and
`InheritBefore` don't help: inherited rules are matched against the path relative to the
uploads folder, where `^wp-content/uploads/` never matches. cPanel's hotlink protection
is the same kind of rule. With the rules in the main file, that rule keeps blocking (403)
and a missing image gets WordPress's own 404 page again.

The rule's pattern is the uploads address relative to the main file's folder; its target
is an absolute path, so WordPress's `RewriteBase` doesn't matter. When the uploads aren't
under the site's own address (another host, a CDN) or the layout can't be worked out,
nothing is written and the self-test says so. Another `.htaccess` with rewrite rules
between the main file and uploads (some plugins write one in `wp-content/`) switches the
main file's rules off for uploads too - the self-test names it
(`Htaccess::overriding_files()`).

**Verified on Apache 2.4** (Windows over FastCGI, and Ubuntu) as written below:
Chrome/Firefox/Safari 17 `Accept` headers get AVIF, Safari 15's WebP, an old browser's
the JPEG; PNGs the same; a size without an AVIF falls through to WebP;
uppercase `.JPG` works; GIFs untouched. **LiteSpeed Enterprise** verified on a shared
host: AVIF (made by GD there - see "AVIF through GD"), WebP and the JPEG fallback each
reached the right client in all 16 self-test requests, `Vary` present (it applies
`Header`, unlike OpenLiteSpeed), `Content-Type` right for AVIF and WebP. OpenLiteSpeed
fails - see below.

Only formats that are on **and can be made here** get a rewrite (`Htaccess::formats()`,
also used for the nginx configuration and the self-test's expectations);
`Htaccess::ensure_current()` (admin requests by administrators - `admin_init` also runs
for anonymous admin-ajax.php calls: two option reads, two small file reads) rewrites the
blocks when the rules change - a setting, the server probe, the site's address - or a
security plugin wiped a file, and takes them out of files they were written to before.
`SelfTest::run()` probes the server before writing the rules for that reason.

```apache
# Main .htaccess, after WordPress's block:
# BEGIN Lean Images
<IfModule mod_mime.c>
  AddType image/avif .avif .avifs
  AddType image/webp .webp
</IfModule>
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/avif
  RewriteCond %{REQUEST_FILENAME}.avif -f
  RewriteRule ^wp\-content/uploads/(.+)\.(jpe?g|png)$ /wp-content/uploads/$1.$2.avif [NC,T=image/avif,L]
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME}.webp -f
  RewriteRule ^wp\-content/uploads/(.+)\.(jpe?g|png)$ /wp-content/uploads/$1.$2.webp [NC,T=image/webp,L]
</IfModule>
# END Lean Images

# wp-content/uploads/.htaccess:
# BEGIN Lean Images
<IfModule mod_headers.c>
  <FilesMatch "(?i)\.(jpe?g|png|avif|webp)$">
    Header merge Vary Accept
  </FilesMatch>
</IfModule>
# END Lean Images
```

`Vary` is set on JPEG/PNG responses too, so no cache stores the JPEG and serves it to
everyone. Page HTML never changes, so page caches are unaffected. `FilesMatch` gets
`(?i)` because it's case-sensitive and uploads can be `.JPG`.

The nginx configuration the self-test shows (built for the site's uploads path and the
enabled formats) was **verified on nginx 1.22** (Windows, serving the uploads folder) and
**nginx 1.18 with WordPress itself on nginx + PHP-FPM** (Ubuntu): same results as Apache,
`Vary` on every response. Two `map` blocks default to `".none"`, a suffix no file has, so
`try_files $uri$avif $uri$webp $uri` skips a format the browser doesn't accept. The
location also carries a `types` block naming all four image types: nginx 1.18's
`mime.types` (Ubuntu 22.04) has no AVIF, so AVIF went out as `application/octet-stream` -
shown in pages (browsers sniff images) but downloaded when opened directly. A `types` block
inside a location replaces the whole list, hence all four. On 1.18 without the
configuration the self-test reports "nginx" (from the `Server` header) and shows it.

Known limits:

- nginx ignores `.htaccess`; so do setups where nginx serves static files in front of
  Apache. The self-test detects both and shows an nginx snippet; nothing is delivered
  until it's added (files are still generated).
- **OpenLiteSpeed can't do this reliably** (1.9.3 on Ubuntu, Example vhost with
  `autoLoadHtaccess 1`). It applies the rules correctly to clients that match them, but
  when the **same address** is requested again, a client that matches neither rule
  (old browser, `*/*`, no `Accept`) often gets the AVIF or WebP an earlier client got -
  the actual bytes, with the matching `Content-Type` - in 13-15 of 15 rounds of four
  clients. A query string hides it: a unique one per request fully (0 of 20), the same
  one every time partly with `curl` (8 of 15) and fully through `wp_remote_get()` (0 of
  10). That's why the self-test's original design (a unique query string) never saw it.
  Not the cause: the rules' location (vhost-level rewrite rules: same fault), the
  in-memory static file cache (all four cache sizes set to 0: same), HTTP version,
  `Accept-Encoding`, `User-Agent`, `Connection: close`, `Cache-Control: no-cache`, a
  freshly copied file. The first round after a restart is usually right.
  Also: no `Vary` header (OLS ignores `Header`), `AddType` ignored (`.avifs` →
  `application/octet-stream`), and **`.htaccess` changes are only read on a restart** - in
  both directions: rules taken out keep being applied (to every client, AVIF even to old
  browsers) until the next restart.
  Handled by the self-test, which pauses delivery there - see "Self-test".
- Cloudflare's free plan ignores `Vary: Accept`, so a cached AVIF could reach a browser
  without AVIF support (rare by 2026: all current browsers decode AVIF; Safari since
  16.x). Self-test detects Cloudflare (`cf-ray`) and recommends turning AVIF off on that
  site, or Cloudflare's paid "Vary for images".
- "Save image as" can save AVIF content under a `.jpg` name.
- Offloaded uploads (S3/CDN, e.g. WP Offload Media) aren't supported.

## Animated images

Policy (decided): animated GIF, APNG, animated WebP and animated AVIF - whatever the
extension - get **no sub-sizes and no siblings**; width/height stay in the metadata. The
full file is used everywhere. Animated thumbnails would mean resizing every frame for
every size (100 frames × 6 sizes = 600 resizes plus re-encoding).

Detection must read file structure, because **ImageMagick can't see AVIF or APNG
animation** - it reads only the first frame/image and reports 1 frame. `Animation`
parses: GIF image descriptors, PNG `acTL` before `IDAT`, WebP `VP8X` animation flag and
`ANMF` chunks, and for AVIF/HEIF the ISOBMFF `moov → trak → mdia → minf → stbl → stsz`
sample count (walking boxes by size, never reading `mdat`). Tested against:

| File | Detector | Actual (ffprobe) |
|---|---|---|
| 7 real-world animated AVIFs (libavif) | 325, 100, 26, 14, 88, 106, 36 frames | identical |
| ffmpeg animated AVIF | 20 | 20 |
| AVIF "sequence" with 1 frame | still | still |
| Animated WebP / APNG / GIF | animated | animated |
| Still AVIF / WebP / PNG / GIF | still | still |

Each check under 1 ms. Test set source:
https://colinbendell.github.io/webperf/animated-gif-decode/avif.html

### Gotcha: libheif rejects "animation-only" AVIFs

libavif-encoded animations carry only the `avis`, `msf1`, `miaf` and `MA1B` brands - no
`mif1`/`avif`. libheif, which ImageMagick uses for AVIF, refuses them in every version
tested ("File does not include any supported brands"). ffmpeg-made animations include
`avif`/`mif1` and a still primary image, so they open (first frame only).

| | ImageMagick 7.1.1 / libheif 1.19 | ImageMagick 6.9.11 / libheif 1.12 | GD, PHP 8.3 (Windows) | GD, PHP 8.1 (Ubuntu 22.04) |
|---|---|---|---|---|
| libavif animations | fails | fails | first frame | no AVIF support |
| ffmpeg animation | first frame | first frame | first frame | no AVIF support |
| still AVIF | opens | opens | opens | no AVIF support |

Irrelevant to the plugin as designed (animations never reach an editor), but it explains
the old snippet - see "The GD snippet this replaces".

`.avifs` uploads are allowed (`upload_mimes`: `avifs` → `image/avif`, the type browsers
expect for any AVIF) and `.htaccess` serves them as `image/avif`. Tested: a libavif
animation uploaded as `.avifs` is accepted, recorded as animated with its dimensions
(800×450), gets no sizes. Attachments the old snippet stored as `image/avif-sequence`
keep that type and keep working as plain files.

## AVIF and HEIC inputs

Still AVIF and HEIC open fine in Imagick on both ImageMagick 6 and 7. But a listed format
doesn't prove a working decoder, so `Environment` decodes a small bundled still AVIF and
HEIC during the self-test. If Imagick fails, AVIF inputs on that site go to GD (if GD has
AVIF); if neither works, those uploads get no sub-sizes. How: `ImagickEditor::
supports_mime_type()` turns AVIF / HEIC down when the probe couldn't open its sample, and
WordPress moves on down the editor list (this plugin's GD editor next). Until this was
added (2026-10-15) the docs claimed it but nothing did it - an ImageMagick that *lists*
AVIF without decoding it would get every AVIF upload and fail. WordPress caches its
editor choice per type in the global `image_editor` cache group for a day (persistent
with Redis/Memcached), so `Environment::probe()` deletes `wp_image_editor_choose` from
it. Verified with faked probes: the GD editor resizes and saves the AVIF.

## HEIC uploads (WP 6.7+)

On 6.5 and 6.6 WordPress doesn't convert HEIC: the upload stays a HEIC file with no
sizes, and the plugin leaves it alone (`Library::convertible_types()` counts HEIC only
from 6.7, and `Originals` never replaces a HEIC that is the main file). Verified on 6.5;
before this, the bulk conversion tried it three times and showed it as "Failed".

After WordPress converts the upload to JPEG (its attachment type stays `image/heic`),
`Originals` replaces the HEIC original with `<name>-original.jpg` - full size, quality
92 (set through `wp_editor_set_quality`, because core re-reads quality when converting
types), metadata stripped, no siblings (it's never served) - points `original_image` at
it and deletes the HEIC. Regenerated sizes later come from this JPEG. This is the one
deliberate re-encode of an original: HEIC metadata can't be removed losslessly from PHP
with reasonable effort, and phone HEICs carry GPS.

Changed from the plan: the JPEG **keeps the colour profile** (Display P3 on iPhones)
instead of being converted to sRGB - the better archive, and browsers render P3 JPEGs
correctly. Only served siblings are converted to sRGB.

Orientation: HEIC (and AVIF) store rotation as a transform that libheif applies while
decoding, so `ImagickEditor::load()` resets the orientation to top-left for those
types - a leftover EXIF tag must not rotate the pixels a second time. Verified with a real
iPhone HEIC - see "Settled during the build".

Tested: a 2400×1500 HEIC upload → `-scaled` JPEG and sizes with siblings,
`phone-original.jpg` at quality 92, the HEIC gone, `original_image` updated.

## Originals: lossless metadata removal

Phones embed GPS coordinates; siblings are stripped, but the kept original is publicly
reachable by URL. Runs after WordPress has finished sub-sizes and read `image_meta`
(camera, aperture, copyright - stored in the database, unaffected).

- JPEG: drop APP1 (EXIF, XMP), APP13 (IPTC), COM, and other APPn except APP0 (JFIF),
  APP2 `ICC_PROFILE` and APP14 (Adobe - needed to decode CMYK/YCCK correctly); drop data
  after EOI (MPF secondary images - depth maps, gain maps; HDR gain maps are lost,
  accepted). Then write a minimal APP1 EXIF containing only Orientation when it isn't 1
  (see "WordPress internals").
- PNG: drop `tEXt`, `zTXt`, `iTXt`, `eXIf`, `tIME`; keep `iCCP`, `sRGB`, `gAMA`,
  `cHRM`.
- WebP: drop `EXIF` and `XMP ` chunks, clear the matching `VP8X` flags; keep `ICCP`.
- Image data bytes are copied untouched; written atomically; the modification time is
  restored (siblings' freshness checks compare against it).

Built as `MetadataStripper` (the file formats) and `Originals` (which files, when).
Runs on every upload (`Attachments::after_metadata()`) and in `Converter` - which also
strips every served JPEG/PNG of an image uploaded before the plugin, since conversion
never rewrites those and WordPress's own sizes keep EXIF (GPS included). For files the
plugin's editor made it's a no-op. PNG/WebP: a non-1 orientation is kept the same way
(minimal `eXIf` / `EXIF` chunk). Scan data is walked marker by marker to find the
image's own EOI - a progressive JPEG has tables between scans, and an MPF image after
it ends in FF D9 too.

Tested on 8 files (GPS + orientation, progressive, ICC + XMP + comment, CMYK with
Adobe APP14, a JPEG with a second image appended, PNG with text + eXIf, WebP with EXIF +
XMP, animated WebP): ImageMagick's pixel signature identical before and after in all
eight, only orientation / ICC / Adobe left, all decode cleanly, mtime kept. Upload test:
a phone JPEG's original keeps only Orientation, and regenerating from it still starts
upright (3024×4032).

## GD fallback (hosts without Imagick)

`Editor\GdEditor` extends core's GD editor; both editors implement `SiblingWriter`, so
`Converter` and `Attachments` don't care which engine runs. `Environment::engine()`
picks Imagick when it can encode WebP, else GD; `ImagickEditor::test()` steps aside when
the probe says Imagick can't (a build without the WebP delegate). Known downsides:
softer resizing (core's `imagecopyresampled`), no colour management (P3 images dulled),
no HEIC. GD's pixel buffers count against PHP's `memory_limit` (Imagick's don't).
Ubuntu 22.04's PHP 8.1 GD has no AVIF at all - such hosts get WebP only.

Calibration (same 1500px pixels, PHP 8.3 on Windows): **GD WebP 75 is byte-identical to
ImageMagick's** (same libwebp); **GD AVIF 50 matches ImageMagick AVIF 50** within 3% in
size and similar SSIM, so the same settings apply. But GD's AVIF took 1.3-2.7 s per
image vs 0.4 s, and speed 8 instead of libavif's default cost up to 25% in size - so on
GD hosts AVIF is always made in the background (`Environment::avif_during_upload()`).

Gotcha found in testing: core's GD `_resize()` returns a new image but also records the
new size as the editor's size (`update_size()`). Making several sizes from one loaded
image then computes every later crop from the wrong dimensions - a 150 × 150 thumbnail
came out as the photo's top-left corner. Core's `make_subsize()` restores the size;
`GdEditor::write_siblings_for()` does too.

Tested with WP-CLI on a php.ini that leaves Imagick out (`php -c no-imagick.ini
wp-cli.phar …`), with Apache's cron kept out by a temporary must-use plugin: GD editor
chosen, WebP during upload, AVIF from the queue, crops correct after the fix, PNG
transparency kept.

## AVIF through GD (Imagick hosts whose ImageMagick can't make AVIF)

ImageMagick has no AVIF coder of its own: it reads and writes AVIF/HEIC through libheif,
which needs an AV1 encoder (libaom) to write. Hosts build ImageMagick without libheif
often enough - seen on a LiteSpeed Enterprise shared host: ImageMagick 6.9, no AVIF
write, no AVIF or HEIC read - while the same PHP's GD has libavif ("GD supported file
formats" lists AVIF in Site Health → Info → Media Handling). It's the build, not the
version: Ubuntu 22.04's ImageMagick 6.9.11 (libheif + libaom) makes AVIF fine.

`Environment::avif_engine()` names the engine that makes AVIF: the conversion engine if
the probe says it can, else GD if GD can, else none. Everything else stays on Imagick
(resizing, sRGB, orientation, WebP); `Encoder::encode_gd_from()` hands the prepared
frame to GD as an uncompressed 8-bit PNG (lossless; `PNG32` with transparency, `PNG24`
without - a palette PNG would give GD a palette image) and encodes with `imageavif()` at
the AVIF quality. Like every GD AVIF it's made in the background
(`avif_during_upload()` is false when `avif_engine()` is `gd`). The Status Engines row
and an info note (`avif-gd`) say so.

Measured with the cached probe set to "ImageMagick can't make AVIF" (GD with libavif,
PHP 8.3), against ImageMagick's own AVIF of the same images: photos 2-5%
smaller and slightly closer to the source (RMSE 4.00 vs 4.12 on a landscape photo, same
pattern on a phone photo, an EXIF-rotated one and a CMYK JPEG - all upright, sRGB,
right size); a transparent logo PNG 19% larger (6.2 vs 5.2 KB) and closer, transparency
kept; every size got its AVIF. 5 images × 4-7 sizes took 42 s. A real upload: JPEG sizes
and WebP during the request, all AVIFs from the queue (WP-Cron through Apache) 1.5-3 s
later.

## Speed, CPU and memory

- Imagick thread limit 2 (`setResourceLimit(RESOURCETYPE_THREAD, 2)`), so shared hosts
  don't throttle the site during AVIF encoding.
- One conversion worker per site at a time.
- The probe times one WebP and one AVIF encode of a generated 1500 × 1000 plasma-fractal
  image (busy like a photo; nothing large has to ship). Threshold 0.8 s
  (`lean_images_avif_inline_seconds`): faster → AVIF written during upload; slower → WebP
  during upload, AVIF via the queue (`Encoder::defers_avif()`; the attachment is recorded
  "waiting" and the queue started). Measured: 0.35 s for the probe image with
  ImageMagick 7.1.1 on a desktop; real photos: 0.23 s on a shared host with ImageMagick
  7.1.1, 1.2-1.5 s per 1500px image on Ubuntu 22.04 / ImageMagick 6 / old libaom (WebP
  sizes there matched ImageMagick 7 within 0.02%; AVIF 1-13% smaller from the older
  encoder). The probe on that Ubuntu: 1.0-1.4 s, so AVIF goes to the queue there, as
  intended.

## Background queue

- Used for: the existing library (bulk), slow-host AVIF, retries, "Convert with Lean
  Images", "Convert everything again", "Remove generated files" (same loop, remove mode).
- **Per-image state** (`Library`): `_lean_images_status` (done / waiting / failed /
  animated; no row = never converted, counted as waiting), `_lean_images_bytes` -
  `"source,avif_view,webp_view,generated,files"`, one row still summable in SQL with
  `SUBSTRING_INDEX`, where avif_view / webp_view are what each kind of browser downloads -
  and `_lean_images` (attempts, last error, updated). Per-file details are read from disk
  when shown, never stored, so they can't drift.
- **Two drivers, one loop** (`Queue::work()`): WP-Cron (single events, `spawn_cron()`
  right away, ~20 s per run, rescheduled 5 s after) and the settings page, which calls
  `POST /lean-images/v1/work` (~8 s batches) while open, because WP-Cron only runs on
  visits. Whichever doesn't hold the lock answers "busy"; the page then just polls.
  Bulk conversion includes never-converted images (`all`); uploads and the bulk action
  only work through images marked waiting.
- **The queue state is read from the database every time** (`Queue::state()` drops the
  option from the request's cache first). Found on WordPress 6.5: a `wp lean-images
  convert --all` started while WP-Cron was already working looped forever, holding the
  lock. The cron worker finished the queue and marked it inactive; the CLI process still
  had "active" cached, and its own `update_option()` to inactive changed no row, so core
  never refreshed the cache.
- **Lock in SQL, not `add_option()`.** The plan said `add_option()` was atomic - it isn't:
  core writes `INSERT … ON DUPLICATE KEY UPDATE`, which also "succeeds" when the row
  exists, after checking the possibly cached option. `INSERT IGNORE` claims it (one
  affected row for exactly one process); a stale lock (60 s expiry) is taken over with a
  conditional `UPDATE`. Not a transient either - those can vanish on a cache flush.
- 3 attempts, then "failed" with a readable error in the Media Library column (server
  paths and ImageMagick's `@ error/…` references stripped). An image that fails with tries
  to spare keeps the queue active for the next run - first version paused the queue
  because a run skips what it already tried, leaving the image "waiting" forever.
  "Try again" / "Try N failed again" give one more attempt each.
- Existing library (`Converter`): decodes the original (`wp_get_original_image_path()`;
  the edited file for media-editor edits, `-e` + 13 digits; the main file if the
  original is gone), applies WordPress's EXIF rotation, then for every file the
  attachment serves resizes the same way and writes only its siblings
  (`ImagickEditor::write_siblings_for()`) - existing JPEGs are never rewritten. Output
  must match the recorded dimensions exactly; a mismatch is redone as a centre crop.
  Verified: stock WordPress sizes (2560 `-scaled`, 2048, crops) all matched, and the
  bytes equal the upload path's for the same size.
- Locally `spawn_cron()` starts the cron run within a second, so small jobs often finish
  before the page's first request comes back - the page handles a job that's already done.

## Self-test

Runs on the first admin page after activation, on the settings screen (cached, plus "Run
test again"), as a Site Health test (re-run when the cached result is over 12 h old), and
via WP-CLI. **Not inside the activation hook**: during activation the plugin's own hooks
aren't registered yet (plugins_loaded has already fired), so the editor check would report
the plugin's own editor as missing. Activation clears the cached result instead, and
`run_if_never_run()` picks it up on `admin_init`. Errors show as an admin notice on the
Dashboard, Plugins and Media screens.

- Copies three test files - JPEG red, WebP green, AVIF blue - to
  `uploads/lean-images-selftest/` and requests `test.jpg` from the site itself as four
  clients: a current browser's `Accept`, Safari 15's, an old browser's, and `*/*`. Which
  file came back is identified by its bytes, not the `Content-Type`.
  1. Each client once **with a unique query string**, so no cache answers: what the
     server's rules do. Right format, `Vary`, Cloudflare and the failure codes come from
     these four.
  2. Three rounds of all four on the **plain address**, the way browsers ask - only to
     catch AVIF/WebP sent to a client that didn't ask for it (OpenLiteSpeed; a cache
     ignoring `Vary`). Cloudflare cache hits (`cf-cache-status` HIT/STALE/UPDATING/
     REVALIDATED) are left out: Cloudflare has its own warning and the owner's choice.
     Caught OpenLiteSpeed in 10 of 10 runs (1-5 wrong of 16).
- **Unsafe delivery pauses it.** That's an error, `unsafe-delivery`: `Htaccess::suspend()`
  takes the rules out and keeps them out (`ensure_current()` skips while suspended, setting
  changes included). Every `SelfTest::run()` writes the rules first - a re-test lifts the
  pause - but only a test that shows delivery working keeps them: if the server was paused
  and the new result is unconfirmed (no-rewrite, loopback, wrong-format, …), the rules go
  out again with a "Delivery stays paused" message. Needed for OpenLiteSpeed: it doesn't
  read the rules the test just wrote, so the test sees "no-rewrite", and leaving them in
  the file would bring the fault back at its next restart. On a `Server: LiteSpeed`
  response the message says to restart now (OLS keeps applying removed rules until then).
  Activation also writes the rules and clears the suspension - the way out after moving
  to a server that works. Status shows a "Paused" pill, the Delivery/Web server rows and
  the server-rules text say why. Verified on OLS end to end (detect, pause, restart → all
  JPEG, re-test → stays paused, back on Apache → lifted), and from a second site pointed
  at OLS.
- Failure paths verified by simulation: rewriting off → "no-rewrite"; the old snippet's
  `AVIF_GD_Selector_Editor` replacing the editors → "editor-overridden", naming the class;
  loopback blocked → "loopback" warning with the coloured-test-image check for a browser.
- **Content-Type** of AVIF/WebP answers is checked too (the format itself is judged by
  bytes): the right file under another type is a "wrong-type" warning naming the type
  sent; on nginx the configuration is shown with it (`SelfTest::needs_nginx_config()`).
  Found on nginx 1.18 (see "Delivery").
- `lean_images_selftest_url` filters the test image's URL - for pointing a site's test at
  another server that serves the same uploads folder (how the OLS checks were done, from
  a temporary must-use plugin).
- Detects: nginx, nginx in front of Apache serving images, OpenLiteSpeed pending restart,
  missing `mod_rewrite`/`mod_headers`, a `.htaccess` between the main one and uploads
  with rewrite rules of its own (named), an unwritable `.htaccess`, uploads served from
  another address, Cloudflare, loopback requests blocked (shows how to check in the
  browser instead).
- Reports: Imagick/GD, ImageMagick version, real AVIF/HEIC decode, AVIF encode time.
- Warns when another plugin rewrites images to WebP/AVIF too (CompressX, EWWW,
  ShortPixel, Imagify, LiteSpeed Cache's WebP option), or when something else overrides
  `wp_image_editors` (e.g. the old GD snippet left in place).

## Upload routes covered

Everything that ends in `wp_generate_attachment_metadata()`: Media Library grid and list
uploads, the media modal and drag & drop in the editor, the REST API (block editor,
mobile app), WooCommerce product images, `media_sideload_image()` (importers, template
imports), `wp media import`, image edits, Regenerate Thumbnails / `wp media regenerate`.
Not covered: files copied in over FTP, and plugins that write images to their own folders
without creating attachments.

## Admin UI

`Admin\SettingsPage` - WordPress's postbox panels, inline CSS/JS through false-src
handles, the Library panel's body rendered in PHP for both the page and every REST
answer. Forms that change state (Run test again, the Cloudflare choice, "Convert now") go
through admin-post with nonces and redirect back with `Admin\Notices`; only the Library
panel needs JavaScript. Savings are phrased as what browsers download ("Browsers that
show AVIF download 73% less image data…"), which is what the numbers measure; animations
uploaded before the plugin aren't counted under "Animated, skipped" (only uploads it saw
get that status).

Media → Lean Images, plus a "Settings" link in the plugin row. Four panels:

- **Status** - pill + one-line summary, then the self-test checklist (delivery, web
  server, engines, CDN, other optimizers), "Run test again", last-checked time. The
  **Engines** row names the engine per job - resizing and WebP, AVIF (with during upload
  / in the background, and ImageMagick's AVIF timing), AVIF uploads, HEIC uploads (WP
  6.7+) - or says nothing can do it. Information only: per job the order is fixed,
  ImageMagick → GD → nothing (`Environment::engine()`, `avif_engine()`, `avif_reader()`).
  It replaced separate image engine / AVIF / AVIF speed / AVIF-and-HEIC rows. Problems
  come with their fix in place: the nginx configuration with a Copy button; for
  Cloudflare a choice between "Turn off AVIF" and "Keep AVIF" (for paid plans with "Vary
  for images"), remembered until the test result changes. Blocking problems (e.g.
  `wp_image_editors` overridden) also raise an admin notice naming the class
  responsible.
- **Library** - converted / waiting / failed / animated counts with a stacked bar,
  average saving per format, disk used by generated files. "Convert N waiting images"
  with progress and Pause (one image per request; leaving the page hands the rest to the
  queue). "Try N failed again", listing what still fails and why.
- **Settings** - formats (AVIF disabled with a reason when the host can't make it), AVIF
  quality 50 and WebP quality 75 as number + slider, largest image size read-only from the
  Large size with a link to Media settings, and a fixed statement on originals. Saving a
  changed quality points to "Convert everything again".
- **Server rules and maintenance** - the `.htaccess` blocks, per file (shown on demand), "Convert
  everything again" (recreates all siblings with the current settings; the old files keep
  being served until each new one is written), "Remove generated files" (in-page
  confirmation - `confirm()` isn't used), WP-CLI commands.

Media Library list view gets a "Lean Images" column: "N% smaller" per format (WebP marked
lossless/lossy for PNG; "AVIF not kept: larger than WebP"), a note for HEIC uploads,
"Details" opening a per-size table (JPEG/PNG vs WebP vs AVIF bytes, smallest highlighted,
the original's size and metadata status), "Convert now" for waiting images, "Try again"
plus the error for failed ones, "Animated" with the frame count. The bulk actions menu
gets "Convert with Lean Images".

Decided: original-file handling (lossless metadata removal, HEIC replacement) is fixed
behaviour, not a setting.

Design: native to WordPress 7.0's "Modern" admin scheme - WordPress's own card, notice and
button styles, colours from `--wp-admin-theme-color` so every admin colour scheme works,
one primary action per section, tabular numbers. No gradients, emoji, glowing badges or
decorative charts. CSS/JS inline through false-src handles (`wp_register_style(…,
false)` + `wp_add_inline_style()`), so no extra files ship or are requested.

## WP-CLI

`Cli`, registered when WP-CLI is loaded:

- `wp lean-images status [--run]` - last (or fresh) server test, library numbers.
- `wp lean-images convert <id>...` - now, with a warning per failure (exit 1 if any);
  `--all` works the queue (failed ones retried), `--force` also redoes finished images.
- `wp lean-images selftest` - exit 1 when the test finds an error.
- `wp lean-images cleanup [--yes]` - remove every generated file.

Bulk commands start the queue without kicking WP-Cron and work it in 10 s batches
through `Queue::work()`, waiting when another worker holds the lock. Tested: all four,
including the failing test image and a cleanup / convert --all round trip.

## Cleanup

- File deleted by WordPress → `wp_delete_file` filter also deletes its `.webp`/`.avif`
  (priority 99, so a plugin cancelling the deletion runs first).
- Deactivate → both `.htaccess` blocks removed, site serves JPEG/PNG again instantly. Siblings
  stay; reactivating restores delivery.
- Uninstall (`uninstall.php`, self-contained) → deletes siblings of every attachment
  with a `_lean_images_bytes` row (bounded to ~20 s; leftovers are harmless once the
  rules are gone), then options, the update cache, postmeta, the cron hook and the
  self-test folder. Large libraries: "Remove generated files" on the settings screen does
  it in batches first, and the screen says so. Tested with `wp plugin uninstall
  --skip-delete`: 112 files and every row gone.
- **Never delete or update the plugin through WordPress on a site whose plugin folder is
  a link to the source** (a symlink or junction for development): WordPress deletes the
  plugin folder's contents - through the link, that's the source. Use `wp plugin
  uninstall --skip-delete`; test updates only up to detection.

## Updates and releases

Single **public** repo `github.com/mattbosak/lean-images`; releases published from it. No
own server, no tokens on client sites.

- Header `Update URI: https://github.com/mattbosak/lean-images`, so WordPress never
  offers a same-named wordpress.org plugin as an update, and asks this plugin through
  `update_plugins_github.com`. That hook is shared with any other GitHub-updated plugin:
  `Updater` must only answer for its own `$plugin_file`.
- `Updater` reads `https://github.com/mattbosak/lean-images/releases/latest/download/update.json`
  (a release asset: version, zip URL, requirements, changelog), cached ~12 h. Plain
  download URLs aren't subject to GitHub's API rate limits, which matters when many sites
  share one server IP.
- `UpdateInfo` shows the incoming version's changelog in "View details" when an update
  is available, the installed one otherwise.
- Release workflow (`.github/workflows/release.yml`): manual dispatch with a version;
  validates it, requires a changelog entry in `plugin-info.json`, lints, bumps the
  header, builds the zip in a folder named exactly `lean-images/`, verifies the zip's
  contents, builds and checks `update.json`, and only then commits and publishes the
  release (`gh release create` with both files attached).
- `Updater` keeps everything plugin-specific in constants, so it stays reusable. It
  only hands WordPress a zip from this repository's own release downloads
  (`PACKAGE_PREFIX`), caches the manifest 12 h (a failed check: 1 h), and forgets the
  cache on "Check again" and after an update. Filters `lean_images_update_manifest_url`
  and `lean_images_update_package_prefix` exist for testing against a local manifest.
- Tested with a local manifest: WordPress lists the update with the right package,
  "View details" shows the incoming version and changelog, a manifest pointing at any
  other zip is refused, and with no reachable release the check fails quietly. A local
  test manifest's package must follow the same pattern under the filtered prefix:
  `<prefix>v<version>/lean-images.zip`.

## The GD snippet this replaces

Before this plugin, a custom snippet did part of the job: it replaced `wp_image_editors`
with an Imagick subclass refusing `.avif`/`.avifs` inputs (so GD handled them);
registered `.avifs` as `image/avif-sequence`; skipped sub-sizes for `.avifs` and animated
WebP (counting frames by decoding the whole animation with Imagick); and cut those
attachments' metadata down to file + filesize (no width/height).

It most likely existed because Imagick couldn't open AVIFs on older servers (ImageMagick
< 7; see the libheif gotcha - animation-only AVIFs fail even on current versions).
By the naming convention it assumed (`.avif` = still, `.avifs` = animated), its
behaviour was by design.

**It must be removed before installing Lean Images**: both replace `wp_image_editors`, and
whichever runs last wins. Each part is covered by the plugin: animation detection by file
structure (any extension), per-host AVIF decode probe, `.avifs` support. The self-test
warns if it's still active. Decided: no repair of metadata for attachments uploaded while
the snippet was active.

## Requirements

WordPress 6.5+ (AVIF support; HEIC handling activates on 6.7+), single site (not
multisite), PHP 8.1+ (GD's AVIF), Imagick (preferred) or GD with WebP. Apache,
LiteSpeed Enterprise, or nginx with the offered configuration for delivery; on
OpenLiteSpeed images are converted but delivery pauses itself.

## Test matrix

- Servers: Apache 2.4, LiteSpeed Enterprise, OpenLiteSpeed, nginx (expect the warning
  until the configuration is added), Cloudflare in front.
- PHP 8.1 → 8.5; ImageMagick 6 and 7; GD only; ImageMagick without AVIF next to GD with
  AVIF; WordPress 6.5 and latest.
- Checked so far: Apache 2.4 on Windows (PHP 8.3 over FastCGI, MySQL 8, ImageMagick
  7.1.1 Q16) - the main development site; Ubuntu 22.04 (Apache 2.4, PHP 8.1, MariaDB
  10.6, ImageMagick 6.9.11, libheif 1.12, GD without AVIF); OpenLiteSpeed 1.9.3 and
  nginx 1.18 + PHP-FPM on that Ubuntu; nginx 1.22 on Windows; a LiteSpeed Enterprise
  shared host; **WordPress 6.5** (the minimum) on that Ubuntu, with `WP_DEBUG_LOG` on -
  activation, self-test, uploads of every kind, the queue, delivery, Site Health, "View
  details", the GD path and a real uninstall, no notices.
- A fresh WordPress install **updates itself** to the latest major version on its first
  cron run (automatic core updates are on by default) - a "6.5" test site silently
  became 7.1. Define `AUTOMATIC_UPDATER_DISABLED` before installing an old version, and
  check the version before and after a test.
- **WP-CLI on Windows breaks attachment deletion** - it sets ABSPATH with forward slashes
  (`D:/...`), core's `path_is_absolute()` only recognises `D:\`, so
  `wp_delete_attachment_files()` builds bogus size paths and leaves sizes behind (core
  behaviour, plugin on or off). Delete through the Media Library or the REST API
  instead. Application-password auth needs WordPress's root `.htaccess` (pretty
  permalinks), which passes the `Authorization` header to PHP under FastCGI.
- MySQL 8: `GENERATED` is a reserved word - an SQL alias named `generated` broke the
  Library totals query on the first run. Aliases are prefixed (`sum_…`).
- Testing delivery on another server: have it serve the site's uploads folder (e.g. a
  bind mount), so the `.htaccess` the plugin writes is the one it reads, and point the
  self-test there with `lean_images_selftest_url` in a temporary must-use plugin.
- A host without AVIF can be simulated by running WP-CLI with `PHP_INI_SCAN_DIR` pointing
  at a copy of PHP's `conf.d` without the imagick ini (on a PHP whose GD has no AVIF).
- `TESTING.md` - the manual release checklist.

## Still to verify in the real world

- nginx detection through `SERVER_SOFTWARE` when a proxy hides nginx's `Server` header
  (the header path is verified; `SERVER_SOFTWARE` only counts when the test runs from a
  web request, not WP-CLI).
- The first real release: update detection and an actual update from GitHub, on a site
  whose plugin folder is not a link to the source. Deferred until a genuine second
  release - no artificial 1.0.1.

## Settled during the build

- `.htaccess` names only enabled formats, so turning a format off stops its existing
  files being served (the encoder stops making them, but doesn't delete what's there).
- **AVIF on but impossible on the server** (found on a LiteSpeed Enterprise shared host
  whose ImageMagick has no AVIF): the AVIF rule used to be written anyway. Real images
  had no `.avif`, so they were fine, but the self-test's own `test.jpg.avif` reached the
  current browser, which was expected to get WebP → "wrong-format". Reproduced on Ubuntu
  22.04 with WP-CLI run without Imagick (see "Test matrix"). Fixed by
  `Htaccess::formats()`. The wrong-format message now names each mismatch ("a current
  browser got AVIF instead of WebP"), and the Status summary says "Check delivery"
  instead of claiming visitors only get JPEG. After the fix the same host passed
  ("Working", WebP only - later AVIF too, through GD).
- nginx: the offered configuration works on 1.22 and 1.18 (see "Delivery").
- sRGB profile licence: the ICC allows free use and redistribution of
  `sRGB_v4_ICC_preference.icc` "provided that the file is not changed including the ICC
  copyright notice tag" (registry.color.org, sRGB profiles page). Bundled unchanged from
  ImageMagick's Windows install; credited in README.
- WordPress reads width/height of animation-only (libavif) AVIFs correctly: 1284×518 for
  the test file.
- `.avifs` → `image/avif`.
- **Real iPhone HEIC** (iPhone 12 Pro, portrait, Display P3, 11 GPS tags; stored
  4032 × 3024 with *both* an irot transform and EXIF "Rotate 90 CW" - the double-rotation
  trap): ImageMagick 7.1.1 decodes it upright (3024 × 4032) and already reports
  orientation 1, and `wp_getimagesize()` reports the upright size too, so the upload cap
  is right for any box shape. Upload result: main 1125 × 1500, every size portrait and
  upright (checked by eye), `-original.jpg` 3024 × 4032 with the Display P3 profile and no
  EXIF/GPS at all, the HEIC deleted, siblings converted to sRGB (average colour within
  0.3/255 of the colour-managed JPEG). The test files were deleted afterwards.
- **Ubuntu 22.04 end to end** (ImageMagick 6.9.11, Apache 2.4.52, PHP 8.1): self-test
  OK; AVIF probe 1.2 s, so uploads got WebP and the queue (WP-Cron over loopback) made
  the AVIFs within 20 s; animations (GIF, WebP, libavif AVIF, `.avifs`) recorded as
  animated with no sizes; HEIC replaced by `-original.jpg`; only Orientation left in a
  phone JPEG's original; delivery right for current/Safari 15/old browsers with `Vary`;
  deletion through WP-CLI removes every sibling (Linux paths are fine); a real uninstall
  left no siblings, `.htaccess` blocks, self-test folder, options or postmeta.
- **OpenLiteSpeed 1.9.3** can't deliver by `Accept` reliably - details under "Delivery";
  the self-test catches it and pauses delivery (see "Self-test").
- GD's AVIF quality 50 matches ImageMagick's 50 (see "GD fallback").
- `Imagick::autoOrient()` is guarded with `method_exists()` and a manual fallback
  (`Encoder::orient()`).

## Security

Reviewed before the first release (2026-10-15). Nothing reachable by visitors,
subscribers or contributors. Holds, and must keep holding:

- **Who can do what.** Settings, the self-test, the Cloudflare choice, the REST queue
  endpoints, the bulk action and "Convert now" / "Try again" all need `manage_options`
  (links and the bulk action are hidden from everyone else); admin-post actions check a
  nonce; REST uses the cookie nonce, POST only, an enum-checked action. The Media Library
  column shows its numbers to anyone who can see the list.
- **Uploads named like generated files** (`logo.png.avif`, `team.jpg.webp`) are renamed on
  upload (`sanitize_file_name` → `logo-png.avif`). WordPress keeps "png" as an inner
  extension, so before this an Author could put an AVIF where the rules look for another
  image's AVIF - in the same month folder - and AVIF browsers would get it at that
  image's address; conversion would later overwrite or delete it.
- **Other `.htaccess` rules keep working** - see "Delivery" (the rules were moved out of
  the uploads folder for this).
- **Paths from attachment metadata stay inside uploads**: size and original file names
  through `wp_basename()`, and `Encoder::is_in_uploads()` refuses `..` - `Library::files()`
  returns nothing for a main file outside uploads, and uninstall checks the same.
- **The updater** hands WordPress only a manifest in the expected shape
  (`Updater::validated()`): the version as x.y.z, the zip exactly
  `…/releases/download/v<version>/lean-images.zip` of this repository, the requirement
  fields as version numbers (core prints them unescaped in "View details"),
  `last_updated` as a date; HTTPS with certificate checks.
- **Error messages** shown in the Media Library lose server paths (both ImageMagick
  quoting styles, plus ABSPATH, `WP_CONTENT_DIR` and the uploads folder).
- **Everything shown is escaped**; inline data through `wp_json_encode()`. **All SQL is
  prepared**; the queue lock is an atomic `INSERT IGNORE` / conditional `UPDATE`. No
  `eval`, `unserialize`, `extract` or input-driven includes; logging only under
  `WP_DEBUG`.
- **Release workflow**: runs from `main` only; the version input reaches the shell through
  an environment variable and is validated first; actions pinned to commit SHAs; the
  token only in the publishing step.

Accepted: the self-test's loopback requests skip certificate checks (they only fetch the
site's own test image, sending nothing); "Remove generated files" and uninstall delete
`x.jpg.webp` / `x.jpg.avif` next to the files of converted attachments even if another
plugin made them (same naming).

## Deferred / not yet built (deliberately parked, not forgotten)

- Turning off unused image sizes (each costs two extra files now).
- Multisite - activation is refused there for now. Needs one shared rule set for the
  network's main `.htaccess` and per-site cleanup on network deactivation and uninstall.
- Translations (strings still use `__()` with the `lean-images` text domain).
- Animated thumbnails.
- `<picture>` delivery and WebP-as-main-file - both rejected, see above.

## Code conventions

- PSR-12 structure with WordPress carve-outs: tabs, snake_case methods, the ABSPATH
  guard at the top of every file. `final` classes, typed properties and returns, Yoda
  conditions, `\`-prefixed global PHP functions (WordPress functions without),
  static closures.
- Thin bootstrap (`lean-images.php`) and a `Plugin` orchestrator; every class wires its
  own hooks in `register()`. Settings getters in `Config` run through `apply_filters()`.
- Own PSR-4-style autoloader (`src/autoload.php`); no Composer, no third-party code.
- Comments explain *why* - the reasoning, measurements and gotchas - not what the code
  does. Gotchas found during the build go into this file, with versions.
- Repo files: `.editorconfig`, `.gitattributes`, `.gitignore`, `README.md` (users),
  `CLAUDE.md` (maintainers), `TESTING.md` (release checklist), `plugin-info.json` (the
  "View details" modal and the source of `update.json`).

Names: namespace `LeanImages`, constants `LEAN_IMAGES_*`, options/hooks `lean_images_*`,
postmeta `_lean_images`, text domain and slug `lean-images`, CLI `wp lean-images`,
`.htaccess` markers `Lean Images`.

# Testing guide

Step-by-step manual test plan for a release candidate. Work top to bottom.

Conventions used below:

- **"the development site"** = WordPress on Apache with PHP 8.3+ and Imagick on
  ImageMagick 7.
- **"the old host"** = WordPress on an older stack: ImageMagick 6, PHP 8.1, GD without
  AVIF (e.g. Ubuntu 22.04's packages).
- **Deleting attachments on Windows**: use the Media Library or the REST API, not
  `wp post delete` - WP-CLI's forward-slash ABSPATH makes core leave the sizes behind
  (see CLAUDE.md, "Test matrix").
- **Listing an attachment's files**: for each file WordPress records (main, sizes,
  original), check dimensions, bytes and its `.webp`/`.avif` next to it. Settings →
  Media → Large size is 1500 × 1500 unless a test says otherwise.

## 0. Environment setup

- [ ] The development site with `WP_DEBUG` + `WP_DEBUG_LOG` enabled and the plugin
      folder linked or copied into `wp-content/plugins/lean-images`.
- [ ] Its PHP has the Imagick extension loaded (`php -m` lists `imagick`), and
      `Imagick::getVersion()` reports ImageMagick 7.
- [ ] The old host has WordPress installed with the plugin **copied** in (not linked).

## 1. Bootstrap, activation, admin surface

Run sections 1-5 on the newest WordPress; on **WordPress 6.5** (automatic updates off -
a fresh install otherwise updates itself) run at least activation, the self-test, an
upload of each kind and a bulk conversion, with `WP_DEBUG_LOG` on: no notices, a HEIC
upload left alone (6.5 doesn't convert HEIC), a PNG above the cap kept at full size
(WordPress scales PNGs only from 6.8).


- [ ] **Activation**: activates cleanly, nothing in `debug.log`.
- [ ] **Activation without any WebP encoder**: with Imagick disabled and GD built without
      WebP (or GD disabled) in `php.ini`, activating is blocked with the "needs the
      Imagick PHP extension, or GD with WebP support" message and a back link. No fatal,
      plugin absent from the active list. Restore `php.ini` afterwards.
- [ ] **Version requirements**: WordPress refuses activation on PHP below 8.1 or
      WordPress below 6.5 using the header values (core behaviour, nothing in the plugin).
- [ ] **View details**: the Plugins screen row has a "View details" link opening a modal
      with Description (features list), Installation (numbered, not bullets), FAQ and
      Changelog tabs.
- [ ] **No update offered from wordpress.org**: with the `Update URI` header in place,
      Dashboard → Updates never lists Lean Images from wordpress.org.

## 2. Conversion during upload

Upload each through the Media Library (and once via drag & drop into a post), with
Large = 1500 × 1500:

- [ ] **Landscape JPEG above the cap** (e.g. 1920 × 1200): main file `-scaled` at
      1500 × 938, original kept as `original_image`. Sizes: thumbnail, medium,
      medium_large only - **no** `large`, `1536x1536` or `2048x2048` copy. Every JPEG,
      including `-scaled`, has a `.webp` and `.avif` with the same dimensions, smaller
      than the JPEG. The original has none.
- [ ] **JPEG already within the cap** (e.g. 1200 × 750, upright): no `-scaled`; the main
      file itself still gets `.webp` and `.avif` (the main-file gap).
- [ ] **Portrait phone JPEG stored sideways** (EXIF orientation 6, 4032 × 3024): main
      file 1125 × 1500 and upright; all siblings upright, same dimensions as their JPEG.
- [ ] **Non-square Large box**: set Large to 1920 × 1080, upload a 4032 × 3024 landscape
      → main 1440 × 1080. Set Large back.
- [ ] **CMYK JPEG**: siblings look right in a browser (converted to sRGB), not inverted.
- [ ] **PNG logo with transparency and a large flat-colour PNG**: siblings keep the
      transparency; text edges stay crisp.
- [ ] **Siblings carry no metadata**: no EXIF/GPS/XMP/ICC in any `.webp`/`.avif`
      (e.g. `exiftool -a -G1 file.webp`).
- [ ] **JPEG/PNG files WordPress made carry no EXIF/IPTC/XMP** (GPS included) - only a
      colour profile if the upload had one - for a phone photo with GPS: `-scaled` and
      every size (the original is covered in section 5).
- [ ] **Animated GIF larger than the cap, animated WebP, animated AVIF (libavif-made,
      e.g. from colinbendell.github.io/webperf/animated-gif-decode/avif.html)**: no
      `-scaled`, no sizes, no siblings; width/height present in the attachment metadata;
      still animate in the Media Library.
- [ ] **`srcset`** of the landscape image lists only the main image and smaller sizes;
      requesting the `large` size returns the main image.
- [ ] **Deletion**: deleting an image from the Media Library removes every file it had -
      main, sizes, original and all `.webp`/`.avif`.
- [ ] **Both formats off** (filters `lean_images_webp_enabled` / `lean_images_avif_enabled`
      returning false): uploads get no siblings; resizing still uses the sharper filter.
- [ ] `debug.log` stays empty throughout.

## 3. Delivery and the self-test

Requests below use `curl -H "Accept: ..."` against an image that has both siblings.
Accept headers: Chrome `image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8`,
Safari 15 `image/webp,image/png,image/svg+xml,image/*;q=0.8,video/*;q=0.8,*/*;q=0.5`,
old browser `image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5`.

- [ ] **Activation writes the rules**: the main `.htaccess` ends with a `Lean Images`
      block (`AddType` and the rewrite rules), after WordPress's block;
      `wp-content/uploads/.htaccess` has a `Lean Images` block with only the `Vary`
      header - also on a new site whose uploads folder doesn't exist yet. Existing
      content in both files is kept.
- [ ] **Other rules keep working**: put a security plugin's rule above it in the main
      `.htaccess` - `RewriteRule ^wp\-content/uploads/.*\.php$ - [NC,F]` - and a PHP file
      in uploads: requesting it gives 403, not its output. A missing image in uploads gets
      WordPress's own 404 page.
- [ ] **Activation on multisite** is refused with "doesn't support multisite networks
      yet".
- [ ] **First admin page after activation runs the self-test**: Tools → Site Health shows
      "AVIF and WebP images are served to browsers that support them" as passed.
- [ ] **Chrome** gets `image/avif`, **Safari 15** `image/webp`, **old browser**
      `image/jpeg` - for a JPEG and for a PNG; every response has `Vary: Accept`. Byte
      sizes match the `.avif` / `.webp` / original files.
- [ ] **A size whose AVIF wasn't kept** (rename its `.avif` away for the test): Chrome gets
      the WebP.
- [ ] **Uppercase `.JPG`** with a `.JPG.avif` next to it is served as AVIF.
- [ ] **GIF** and other formats are untouched.
- [ ] **In real browsers**: open `/wp-content/uploads/lean-images-selftest/test.jpg` -
      blue in Chrome/Firefox/Safari 16+, green in Safari 14-15, red elsewhere. DevTools →
      Network shows the matching `Content-Type`.
- [ ] **AVIF off** (setting or `lean_images_avif_enabled` filter): the rules no longer
      mention AVIF after the next admin page load; Chrome gets WebP; the self-test still
      passes (it expects WebP).
- [ ] **Deactivation** removes both blocks (and the uploads file, if nothing else was in
      it); Chrome gets the JPEG immediately. Reactivation brings AVIF back.
- [ ] **Failure reports** (each then undone):
      - append `RewriteEngine Off` to the uploads `.htaccess` → error naming that file:
        it "has rewrite rules of its own, which switch off the rules in the site's main
        .htaccess…";
      - the main `.htaccess` made read-only, block removed → error "couldn't write its
        rules to .htaccess and wp-content/uploads/.htaccess…";
      - a must-use plugin returning `['WP_Image_Editor_GD']` from `wp_image_editors` at
        priority 50 → error naming the class; it also shows as an admin notice on the
        Dashboard, Plugins and Media screens;
      - a must-use plugin making `pre_http_request` return a `WP_Error` → warning that the
        test image couldn't be requested.
- [ ] **Unsafe delivery pauses it** (OpenLiteSpeed serving the site's uploads folder, see
      CLAUDE.md "Test matrix"; a must-use plugin points `lean_images_selftest_url` at it):
      - `wp lean-images selftest` → error "In N of 16 test requests, this server sent an
        AVIF or WebP file…", exit 1; both `Lean Images` blocks are gone; Status shows
        "Paused", the Delivery and Web server rows and the server-rules text say why;
      - restart OpenLiteSpeed → every client gets the JPEG;
      - run the test again → "Delivery stays paused…", blocks still out;
      - remove the must-use plugin and run the test on Apache → passes, blocks back, AVIF
        served again.
- [ ] **AVIF on but impossible** (the old host's WP-CLI without Imagick, see CLAUDE.md
      "Test matrix"): `wp lean-images selftest` passes with "WebP is served…", the uploads
      `.htaccess` has no AVIF rule, a current browser gets WebP. Run normally again
      afterwards: the AVIF rule is back.
- [ ] **AVIF through GD** (the development site: after a self-test, set `imagick.avif` to
      false in the `lean_images_environment` option; PHP's GD must have AVIF): the
      Engines row shows GD for AVIF, in the background; the AVIF rule stays in
      `.htaccess`; an upload gets JPEG sizes and WebP at once and every AVIF from the
      queue (the AVIF files contain the string `libavif`); converted AVIFs are upright,
      sRGB, the right size, transparency kept. Run the self-test again afterwards to
      restore the real probe.
- [ ] **On Apache** the self-test's 16 requests (4 with unique query strings, 12 on the
      plain address) all get the expected format.
- [ ] **nginx** (WordPress on nginx + PHP-FPM, ideally an older nginx such as 1.18):
      - without the configuration, `wp lean-images selftest` → error "This server runs
        nginx…", and Status / Site Health show the configuration;
      - with it added (maps in `conf.d`, location in the server block) → passes; AVIF
        comes back as `image/avif`, WebP as `image/webp`, `Vary: Accept` on everything;
      - without the location's `types` block → warning that AVIF is served as
        `application/octet-stream`, with the configuration shown.
- [ ] **Engines row** in Status names the engine for resizing and WebP, AVIF, AVIF
      uploads and HEIC uploads. With the cached probe faked (ImageMagick can't make or open
      AVIF; GD can) it shows GD for AVIF and AVIF uploads, and an AVIF upload is resized by
      `LeanImages\Editor\GdEditor` and saved as AVIF.
- [ ] **Server probe** (`lean_images_environment` option): real WebP/AVIF encoding and
      AVIF/HEIC decoding results, and the AVIF timing, match the host.

## 4. Library, queue and admin screens

Set up an "old library" first: deactivate the plugin, upload a landscape JPEG above the
cap, a sideways phone JPEG (orientation 6, 4032 × 3024) and a PNG, then reactivate.

- [ ] **Settings page** (Media → Lean Images, and the "Settings" link on the Plugins
      screen): Status checklist, Library counts (old-library images under Waiting), the
      settings form, server rules and maintenance - in the Modern and Default admin
      colour schemes, and at phone width.
- [ ] **Convert N waiting images**: progress counts up, Pause stops it ("Paused…"),
      clicking the button again continues. Leaving the page mid-run: conversion finishes
      in the background (WP-Cron) and the page picks the progress up when reopened. Ends
      with "Converted N images." and the savings and disk numbers filled in.
- [ ] **Old-library files**: every existing size - including WordPress's own 2560
      `-scaled` and the 2048 size - has a `.webp`/`.avif` of exactly its dimensions; the
      sideways photo's versions are upright; the existing JPEGs are unchanged (same
      modification time).
- [ ] **Media Library list column**: "N% smaller" per format, "(lossless)" on PNG WebPs
      where chosen, Details with the per-size table (smallest in green) and the
      original's size; "Not converted yet · Convert now" for old images;
      "Animated" with a frame count for new animated uploads.
- [ ] **Convert now** on one image: notice "Image converted.", column updated. Logged in
      as an Author or Editor: no "Convert now" / "Try again" links and no bulk action;
      the admin-post URL itself answers 403.
- [ ] **Upload named like a generated file** (`something.jpg.avif`): stored as
      `something-jpg.avif`.
- [ ] **A broken original** (overwrite the original file with text): three attempts, then
      "Failed" with a readable reason - no server path. "Try again" gives one more
      attempt and the error notice.
- [ ] **Bulk action** "Convert with Lean Images" on a few rows: notice "N images will be
      converted in the background"; non-JPEG/PNG rows are skipped; they're done shortly
      after without the settings page open.
- [ ] **Slow host** (filter `lean_images_avif_inline_seconds` returning 0.01): an upload
      gets WebP during the upload and AVIF shortly after from the queue; the Engines row
      says AVIF is made in the background.
- [ ] **Convert everything again**: confirmation in the page (no browser dialog), then
      progress; files are rewritten one image at a time while the old ones stay served.
- [ ] **Remove generated files**: confirmation with the file count and size; afterwards
      no `.webp`/`.avif` left (the uploaded animations of those types aren't generated
      files and stay), every image back to Waiting, "All generated files are removed." or
      the count.
- [ ] **Settings**: changing a quality and saving shows "Settings saved. New quality
      settings apply to new uploads…"; unticking both formats is refused with a message;
      unticking AVIF removes AVIF from the uploads `.htaccess` on the next admin page.
- [ ] **Cloudflare** (only on a site behind Cloudflare): "Turn off AVIF" and "Keep AVIF"
      each settle the warning, with a notice.
- [ ] **One worker at a time**: while a cron run holds the lock, the page's requests come
      back busy and only poll; nothing is converted twice.
- [ ] **`wp lean-images convert --all` while WP-Cron is converting** (upload a few images,
      then run it at once): it waits, then ends with the totals - it must not keep running
      once the queue is empty.
- [ ] `debug.log` stays empty throughout.

## 5. Originals, HEIC, GD, WP-CLI, updates, uninstall

**Never update or delete the plugin through WordPress on a site whose plugin folder is a
link to the source**: WordPress deletes the folder's contents. Use
`wp plugin uninstall lean-images --skip-delete` there, and test updates only up to
detection.

- [ ] **Originals lose their metadata, losslessly**: upload a phone JPEG with GPS and
      orientation 6. `exiftool` on the kept original shows only Orientation (plus JFIF/ICC
      if present); `magick identify -format %#` gives the same pixel signature as the file
      before upload; `-scaled` and every size show no EXIF at all.
- [ ] **Regenerating sizes** from that original (Regenerate Thumbnails, or the image
      editor's restore) still produces upright images.
- [ ] **Edge-case files** keep decoding with the same pixel signature after stripping:
      progressive JPEG, JPEG with ICC + XMP + comment (ICC kept), CMYK JPEG (Adobe block
      kept), JPEG with data appended after the image (removed), PNG with text + eXIf, WebP
      with EXIF + XMP, animated WebP (untouched).
- [ ] **Images uploaded before the plugin**: after conversion, their stock WordPress
      sizes no longer carry GPS; the original keeps only Orientation.
- [ ] **HEIC** (WordPress 6.7+, Imagick that reads HEIC): upload an iPhone photo in
      portrait. Main image and sizes are JPEG and upright; `<name>-original.jpg` exists at
      quality 92 with the colour profile and nothing else; the `.heic` is gone; the Media
      Library column says "Uploaded as HEIC."
- [ ] **`.avifs`**: an animated AVIF named `.avifs` uploads, shows as "Animated", gets no
      sizes, and is served as `image/avif`.
- [ ] **GD only** (Imagick disabled in `php.ini`, or WP-CLI with `php -c` and an ini
      without it): the Engines row shows "GD … slightly softer" and AVIF made in the
      background;
      uploads get WebP right away and AVIF shortly after; cropped sizes (thumbnail) show
      the same crop in JPEG, WebP and AVIF; PNG transparency kept.
- [ ] **WP-CLI**: `wp lean-images status --run`, `selftest` (exit 0, or 1 on a problem),
      `convert <id>` (a broken image gives a warning and exit 1), `cleanup --yes`, then
      `convert --all` restores everything.
- [ ] **Update detection** with a local manifest (filters
      `lean_images_update_manifest_url` and `lean_images_update_package_prefix` in a
      must-use plugin): Dashboard → Updates lists the higher version; "View details" shows
      its version and changelog; a manifest whose zip isn't exactly
      `<prefix>v<version>/lean-images.zip` is ignored, and a requirement field that isn't
      a version number (e.g. containing HTML) is dropped. Remove the must-use plugin
      afterwards.
- [ ] **After the first GitHub release**, on a normal install (not a link to the source):
      the next release is offered and installs through Dashboard → Updates.
- [ ] **Uninstall** (`--skip-delete` where the plugin folder is a link): every
      `.webp`/`.avif`, the self-test folder, every `lean_images_*` option, the update
      cache and every `_lean_images*` postmeta row are gone; the `.htaccess` blocks went
      at deactivation.

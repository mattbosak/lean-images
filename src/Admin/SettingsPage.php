<?php

namespace LeanImages\Admin;

use LeanImages\Config;
use LeanImages\Environment;
use LeanImages\Htaccess;
use LeanImages\Library;
use LeanImages\SelfTest;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Media → Lean Images: Status, Library, Settings, and server rules and
 * maintenance (CLAUDE.md, "Admin UI").
 *
 * WordPress's own postbox panels, buttons and notices, coloured through
 * --wp-admin-theme-color, so the screen follows every admin colour scheme.
 * The Library panel's body is rendered here for both the page and the REST
 * responses (Api), so the progress updates and the first paint can't disagree.
 */
final class SettingsPage
{

	public const SLUG = 'lean-images';
	private const HOOK_SUFFIX = 'media_page_lean-images';

	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_page']);
		add_action('admin_init', [$this, 'register_setting']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_action('admin_post_lean_images_selftest', [$this, 'handle_selftest']);
		add_action('admin_post_lean_images_cdn', [$this, 'handle_cdn_choice']);
		add_filter('plugin_action_links_' . LEAN_IMAGES_BASENAME, [$this, 'add_settings_link']);
	}

	public static function url(): string
	{
		return admin_url('upload.php?page=' . self::SLUG);
	}

	public function add_page(): void
	{
		add_media_page(
			__('Lean Images', 'lean-images'),
			__('Lean Images', 'lean-images'),
			'manage_options',
			self::SLUG,
			[$this, 'render']
		);
	}

	public function register_setting(): void
	{
		register_setting('lean_images', Config::OPTION, [
			'type' => 'array',
			'sanitize_callback' => [Config::class, 'sanitize'],
			'default' => [],
		]);
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function add_settings_link($links): array
	{
		\array_unshift($links, \sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'lean-images')));

		return $links;
	}

	public function handle_selftest(): void
	{
		$this->check_request('lean_images_selftest');
		SelfTest::run();
		Notices::redirect('selftest');
	}

	public function handle_cdn_choice(): void
	{
		$this->check_request('lean_images_cdn');

		$choice = isset($_POST['choice']) ? sanitize_key(wp_unslash($_POST['choice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_request().

		if ('avif-off' === $choice && !Config::webp_enabled()) {
			Notices::redirect('cdn-needs-webp'); // Config::sanitize() would refuse both formats off.
		}

		if ('avif-off' === $choice) {
			$settings = Config::all();
			$settings['avif'] = false;
			update_option(Config::OPTION, $settings);
			update_option(SelfTest::CDN_CHOICE_OPTION, 'avif-off', false);
			Htaccess::ensure_current();
		} elseif ('kept' === $choice) {
			update_option(SelfTest::CDN_CHOICE_OPTION, 'kept', false);
		} else {
			wp_die(esc_html__('Unknown choice.', 'lean-images'), '', ['response' => 400]);
		}

		SelfTest::run();
		Notices::redirect('avif-off' === $choice ? 'cdn-off' : 'cdn-kept');
	}

	public function enqueue(string $hook_suffix): void
	{
		if (self::HOOK_SUFFIX !== $hook_suffix) {
			return;
		}

		// Inline only: false-src handles, so no extra files ship or are requested.
		wp_register_style('lean-images-admin', false, [], LEAN_IMAGES_VERSION);
		wp_enqueue_style('lean-images-admin');
		wp_add_inline_style('lean-images-admin', self::css());

		wp_register_script('lean-images-admin', false, [], LEAN_IMAGES_VERSION, true);
		wp_enqueue_script('lean-images-admin');
		wp_add_inline_script('lean-images-admin', 'window.leanImagesData = ' . wp_json_encode(self::script_data()) . ';', 'before');
		wp_add_inline_script('lean-images-admin', self::js());
	}

	public function render(): void
	{
		$result = SelfTest::latest();
		$environment = Environment::cached();
		$settings = Config::all();
		$can_avif = Environment::can_encode_avif();
		?>
		<div class="wrap lean-images">
			<h1 class="li-title"><?php esc_html_e('Lean Images', 'lean-images'); ?> <span class="li-version"><?php echo esc_html(\sprintf(/* translators: %s: version number. */ __('Version %s', 'lean-images'), LEAN_IMAGES_VERSION)); ?></span></h1>
			<?php settings_errors(Config::OPTION); ?>

			<div class="li-columns">
				<?php $this->render_status($result, $environment); ?>

				<div class="postbox li-panel">
					<div class="postbox-header">
						<h2><?php esc_html_e('Library', 'lean-images'); ?></h2>
						<a class="li-header-link" href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>"><?php esc_html_e('Open Media Library', 'lean-images'); ?></a>
					</div>
					<div class="inside li-inside">
						<div id="li-library-body"><?php echo self::library_body(Library::stats()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built. ?></div>
						<div class="li-actions" id="li-actions" aria-live="polite">
							<noscript><p class="description"><?php esc_html_e('Converting the library needs JavaScript.', 'lean-images'); ?></p></noscript>
						</div>
					</div>
				</div>
			</div>

			<div class="postbox li-panel">
				<div class="postbox-header"><h2><?php esc_html_e('Settings', 'lean-images'); ?></h2></div>
				<form class="inside li-inside" method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
					<?php settings_fields('lean_images'); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Formats', 'lean-images'); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e('Formats', 'lean-images'); ?></legend>
									<label for="li-avif">
										<input type="checkbox" id="li-avif" name="<?php echo esc_attr(Config::OPTION); ?>[avif]" value="1" <?php checked($settings['avif'] && $can_avif); ?> <?php disabled(!$can_avif); ?>>
										<?php esc_html_e('AVIF', 'lean-images'); ?>
									</label>
									<p class="description">
										<?php
										$can_avif
											? esc_html_e('Smallest files. Every current browser can show them.', 'lean-images')
											: esc_html_e('Not available: this server can\'t make AVIF files.', 'lean-images');
										?>
									</p>
									<br>
									<label for="li-webp">
										<input type="checkbox" id="li-webp" name="<?php echo esc_attr(Config::OPTION); ?>[webp]" value="1" <?php checked($settings['webp']); ?>>
										<?php esc_html_e('WebP', 'lean-images'); ?>
									</label>
									<p class="description"><?php esc_html_e('For browsers that can\'t show AVIF.', 'lean-images'); ?></p>
								</fieldset>
							</td>
						</tr>
						<?php
						$this->render_quality_row('avif', __('AVIF quality', 'lean-images'), $settings['avif_quality'], __('Default 50. Lower numbers make smaller files with more visible compression.', 'lean-images'), !($settings['avif'] && $can_avif));
						$this->render_quality_row('webp', __('WebP quality', 'lean-images'), $settings['webp_quality'], __('Default 75. PNG images also get a lossless WebP try, and the smaller file is kept.', 'lean-images'), !$settings['webp']);
						?>
						<tr>
							<th scope="row"><?php esc_html_e('Largest image size', 'lean-images'); ?></th>
							<td>
								<p class="li-value-line">
									<strong><?php echo esc_html($this->box_label()); ?></strong>
									<a href="<?php echo esc_url(admin_url('options-media.php')); ?>"><?php esc_html_e('Change in Media settings', 'lean-images'); ?></a>
								</p>
								<p class="description"><?php esc_html_e('Taken from the Large size in Settings → Media. Bigger uploads are scaled down to fit. The full-size original is kept, but never shown on pages.', 'lean-images'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Originals', 'lean-images'); ?></th>
							<td><p><?php esc_html_e('Location and camera details are removed from uploaded originals and every image file WordPress makes, without recompressing them. iPhone HEIC photos are replaced with a full-size JPEG.', 'lean-images'); ?></p></td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>

			<?php $this->render_maintenance(); ?>
		</div>
		<?php
	}

	/**
	 * The Library panel's numbers. Shared with the REST responses, which send
	 * it back after every batch.
	 */
	public static function library_body(array $stats): string
	{
		$waiting = $stats['waiting'] + $stats['unconverted'];
		$total = \max(1, $stats['total']);
		$share = static fn (int $count): string => \round(100 * $count / $total, 2) . '%';

		$items = [
			['li-c-done', __('Converted', 'lean-images'), $stats['done']],
			['li-c-wait', __('Waiting', 'lean-images'), $waiting],
			['li-c-fail', __('Failed', 'lean-images'), $stats['failed']],
			['li-c-skip', __('Animated, skipped', 'lean-images'), $stats['animated']],
		];

		$out = '<dl class="li-stats">';
		foreach ($items as [$class, $label, $count]) {
			$out .= \sprintf(
				'<div><dt><span class="li-dot %s" aria-hidden="true"></span>%s</dt><dd>%s</dd></div>',
				esc_attr($class),
				esc_html($label),
				esc_html(number_format_i18n($count))
			);
		}
		$out .= '</dl><div class="li-stackbar" aria-hidden="true">';
		foreach ($items as [$class, , $count]) {
			$out .= \sprintf('<span class="%s" style="width:%s"></span>', esc_attr($class), esc_attr($share($count)));
		}
		$out .= '</div><ul class="li-facts">';

		$out .= '<li>' . \sprintf(
			/* translators: %s: number of images. */
			esc_html(_n('%s image in the Media Library.', '%s images in the Media Library.', $stats['total'], 'lean-images')),
			'<strong>' . esc_html(number_format_i18n($stats['total'])) . '</strong>'
		) . '</li>';

		if ($stats['source'] > 0) {
			$avif = (int) \round(100 * (1 - $stats['avif_view'] / $stats['source']));
			$webp = (int) \round(100 * (1 - $stats['webp_view'] / $stats['source']));
			$out .= '<li>' . (Config::avif_enabled() && Environment::can_encode_avif() && $avif > $webp
				? \sprintf(
					/* translators: 1: percentage for AVIF browsers, 2: percentage for WebP-only browsers. */
					esc_html__('Browsers that show AVIF download %1$s less image data, browsers with only WebP %2$s less.', 'lean-images'),
					'<strong>' . esc_html($avif . '%') . '</strong>',
					'<strong>' . esc_html($webp . '%') . '</strong>'
				)
				: \sprintf(
					/* translators: %s: percentage. */
					esc_html__('Browsers that show WebP download %s less image data.', 'lean-images'),
					'<strong>' . esc_html($webp . '%') . '</strong>'
				)) . '</li>';
		}

		$out .= '<li>' . ($stats['files'] > 0
			? \sprintf(
				/* translators: 1: disk space, 2: number of files. */
				esc_html__('Generated files use %1$s (%2$s files).', 'lean-images'),
				'<strong>' . esc_html(size_format($stats['generated'], 1)) . '</strong>',
				esc_html(number_format_i18n($stats['files']))
			)
			: esc_html__('No generated files yet.', 'lean-images')) . '</li></ul>';

		return $out;
	}

	private function render_quality_row(string $format, string $label, int $value, string $description, bool $disabled): void
	{
		$name = Config::OPTION . "[{$format}_quality]";
		?>
		<tr>
			<th scope="row"><label for="li-<?php echo esc_attr($format); ?>-quality"><?php echo esc_html($label); ?></label></th>
			<td>
				<div class="li-quality">
					<input type="number" id="li-<?php echo esc_attr($format); ?>-quality" name="<?php echo esc_attr($name); ?>" min="1" max="100" value="<?php echo esc_attr((string) $value); ?>" class="small-text" <?php disabled($disabled); ?>>
					<input type="range" id="li-<?php echo esc_attr($format); ?>-range" min="1" max="100" value="<?php echo esc_attr((string) $value); ?>" aria-label="<?php echo esc_attr($label); ?>" <?php disabled($disabled); ?>>
				</div>
				<p class="description"><?php echo esc_html($description); ?></p>
			</td>
		</tr>
		<?php
	}

	private function box_label(): string
	{
		[$width, $height] = \LeanImages\UploadCap::box();

		if ($width <= 0 && $height <= 0) {
			return __('No limit set (WordPress caps uploads at 2560 px)', 'lean-images');
		}

		return \sprintf(
			/* translators: 1: width, 2: height. */
			__('%1$s × %2$s px', 'lean-images'),
			$width > 0 ? number_format_i18n($width) : __('any', 'lean-images'),
			$height > 0 ? number_format_i18n($height) : __('any', 'lean-images')
		);
	}

	private function render_status(?array $result, ?array $environment): void
	{
		$codes = null === $result ? [] : \array_column($result['problems'], 'code');
		[$pill_class, $pill_text, $lead] = $this->summary($result, $codes);
		?>
		<div class="postbox li-panel">
			<div class="postbox-header">
				<h2><?php esc_html_e('Status', 'lean-images'); ?></h2>
				<span class="li-pill <?php echo esc_attr($pill_class); ?>"><?php echo esc_html($pill_text); ?></span>
			</div>
			<div class="inside li-inside">
				<p class="li-lead"><?php echo esc_html($lead); ?></p>

				<?php if (null !== $result) : ?>
					<ul class="li-checks">
						<?php foreach ($this->status_rows($result, (array) $environment, $codes) as [$state, $label, $value]) : ?>
							<li>
								<span class="li-ico li-ico-<?php echo esc_attr($state); ?>" aria-hidden="true"><?php echo esc_html(['ok' => '✓', 'warn' => '!', 'err' => '×', 'info' => 'i'][$state]); ?></span>
								<span class="li-key"><?php echo esc_html($label); ?><span class="screen-reader-text"> (<?php echo esc_html(['ok' => __('OK', 'lean-images'), 'warn' => __('Warning', 'lean-images'), 'err' => __('Problem', 'lean-images'), 'info' => __('Note', 'lean-images')][$state]); ?>)</span></span>
								<?php if (\is_array($value)) : ?>
									<dl class="li-val li-subs">
										<?php foreach ($value as [$job, $text]) : ?>
											<dt><?php echo esc_html($job); ?></dt>
											<dd><?php echo esc_html($text); ?></dd>
										<?php endforeach; ?>
									</dl>
								<?php else : ?>
									<span class="li-val"><?php echo esc_html($value); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php foreach ($result['problems'] as $problem) : ?>
						<?php if ('info' !== $problem['level']) : ?>
							<div class="li-callout li-callout-<?php echo esc_attr($problem['level']); ?>">
								<p><?php echo esc_html($problem['message']); ?></p>
								<?php $this->render_fix($problem['code']); ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div class="li-foot">
				<span>
					<?php
					echo esc_html(null === $result
						? __('Not tested yet.', 'lean-images')
						: \sprintf(
							/* translators: %s: date and time. */
							__('Last checked %s', 'lean-images'),
							wp_date(get_option('date_format') . ', ' . get_option('time_format'), (int) $result['checked'])
						));
					?>
				</span>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="lean_images_selftest">
					<?php wp_nonce_field('lean_images_selftest'); ?>
					<button type="submit" class="button"><?php esc_html_e('Run test again', 'lean-images'); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string[] $codes
	 * @return array{0: string, 1: string, 2: string} Pill class, pill text, lead sentence.
	 */
	private function summary(?array $result, array $codes): array
	{
		$avif = Config::avif_enabled() && Environment::can_encode_avif();

		if (null === $result) {
			return ['li-pill-info', __('Not tested', 'lean-images'), __('The server test hasn\'t run yet. Run it to see whether visitors get AVIF and WebP.', 'lean-images')];
		}

		if (\array_intersect($codes, ['editor-overridden', 'no-imagick', 'no-webp'])) {
			return ['li-pill-err', __('Stopped', 'lean-images'), __('New uploads aren\'t being converted. The problem is described below.', 'lean-images')];
		}

		if (\in_array('unsafe-delivery', $codes, true)) {
			return ['li-pill-warn', __('Paused', 'lean-images'), __('Images are converted, but delivery is paused because this server sent AVIF or WebP to browsers that hadn\'t asked for it. Visitors get JPEG and PNG for now.', 'lean-images')];
		}

		if (\in_array('wrong-format', $codes, true)) {
			return ['li-pill-warn', __('Check delivery', 'lean-images'), __('Images are converted, but some browsers got the wrong format in the test. What went wrong is described below.', 'lean-images')];
		}

		if ('error' === $result['status']) {
			return ['li-pill-warn', __('Not delivering', 'lean-images'), __('Images are converted, but visitors still get JPEG and PNG. The fix is described below.', 'lean-images')];
		}

		if (\in_array('cloudflare', $codes, true)) {
			return ['li-pill-warn', __('Check CDN', 'lean-images'), __('Delivery works. Cloudflare in front of this site may cache an AVIF file and pass it to a browser that can\'t show it.', 'lean-images')];
		}

		if (\in_array('loopback', $codes, true)) {
			return ['li-pill-warn', __('Unconfirmed', 'lean-images'), __('Images are converted, but Lean Images couldn\'t request its test image to confirm delivery.', 'lean-images')];
		}

		$lead = $avif
			? __('Visitors get AVIF or WebP when their browser supports it, and the original JPEG or PNG otherwise.', 'lean-images')
			: __('Visitors get WebP when their browser supports it, and the original JPEG or PNG otherwise.', 'lean-images');

		return ['warning' === $result['status'] ? 'li-pill-warn' : 'li-pill-ok', 'warning' === $result['status'] ? __('Needs attention', 'lean-images') : __('Working', 'lean-images'), $lead];
	}

	/**
	 * Short checklist lines; the full explanation of any problem is in the
	 * callouts underneath.
	 *
	 * @param string[] $codes
	 * @return array<int, array{0: string, 1: string, 2: string|array<int, array{0: string, 1: string}>}>
	 *         State, label, and a value - or, for Engines, lines of job and engine.
	 */
	private function status_rows(array $result, array $environment, array $codes): array
	{
		$rows = [];
		$cases = $result['delivery']['cases'] ?? [];
		$avif_on = Config::avif_enabled() && Environment::can_encode_avif();

		if (\in_array('editor-overridden', $codes, true)) {
			$rows[] = ['err', __('Image processing', 'lean-images'), __('Replaced by another plugin or a code snippet.', 'lean-images')];
		}

		if (\in_array('loopback', $codes, true)) {
			$rows[] = ['warn', __('Delivery', 'lean-images'), __('The test image couldn\'t be requested from this site.', 'lean-images')];
		} elseif (\in_array('unsafe-delivery', $codes, true)) {
			$rows[] = ['err', __('Delivery', 'lean-images'), __('Paused: browsers got formats they hadn\'t asked for in the test.', 'lean-images')];
		} elseif (\array_intersect($codes, ['no-rewrite', 'nginx', 'htaccess-not-writable', 'wrong-format', 'selftest-files'])) {
			$rows[] = ['err', __('Delivery', 'lean-images'), __('Browsers didn\'t get the right format in the test.', 'lean-images')];
		} else {
			$rows[] = ['ok', __('Delivery', 'lean-images'), $avif_on
				? __('AVIF, WebP and the JPEG fallback each reached the right browser in the test.', 'lean-images')
				: __('WebP and the JPEG fallback each reached the right browser in the test.', 'lean-images')];
		}

		$server = '';
		foreach ($cases as $case) {
			if (!empty($case['server'])) {
				$server = (string) $case['server'];
				break;
			}
		}
		$server = \trim((string) \strtok($server, '('));
		$files = self::rule_files();
		if (\in_array('nginx', $codes, true)) {
			$rows[] = ['warn', __('Web server', 'lean-images'), \sprintf(/* translators: %s: server name. */ __('%s doesn\'t read .htaccess files.', 'lean-images'), '' !== $server ? $server : 'nginx')];
		} elseif (Htaccess::is_suspended()) {
			$rows[] = ['warn', __('Web server', 'lean-images'), \trim(('' !== $server ? $server . '. ' : '') . __('Rules taken out of the .htaccess files while delivery is paused.', 'lean-images'))];
		} else {
			$rows[] = [Htaccess::is_written() ? 'ok' : 'err', __('Web server', 'lean-images'), \trim(('' !== $server ? $server . '. ' : '') . (Htaccess::is_written()
				/* translators: %s: file paths, e.g. ".htaccess and wp-content/uploads/.htaccess". */
				? \sprintf(__('Rules are in %s.', 'lean-images'), $files)
				: __('The rules couldn\'t be written to the .htaccess files.', 'lean-images')))];
		}

		$rows[] = $this->engines_row($environment);

		$cloudflare = (bool) \array_filter($cases, static fn ($case) => !empty($case['cloudflare']));
		if (!$cloudflare) {
			$rows[] = ['ok', __('CDN', 'lean-images'), __('None detected.', 'lean-images')];
		} elseif (\in_array('cloudflare', $codes, true)) {
			$rows[] = ['warn', __('CDN', 'lean-images'), __('Cloudflare detected. Its free plan ignores the Vary: Accept header that keeps the formats apart.', 'lean-images')];
		} elseif (!Config::avif_enabled()) {
			$rows[] = ['info', __('CDN', 'lean-images'), __('Cloudflare detected. AVIF is off, so a cached file always suits the browser.', 'lean-images')];
		} else {
			$rows[] = ['info', __('CDN', 'lean-images'), __('Cloudflare detected. You confirmed that "Vary for images" is on.', 'lean-images')];
		}

		$others = \array_filter($result['problems'], static fn ($problem) => 'other-optimizer' === $problem['code']);
		$rows[] = $others
			? ['warn', __('Other image optimizers', 'lean-images'), \sprintf(/* translators: %s: number of plugins. */ _n('%s other plugin also serves WebP or AVIF.', '%s other plugins also serve WebP or AVIF.', \count($others), 'lean-images'), number_format_i18n(\count($others)))]
			: ['ok', __('Other image optimizers', 'lean-images'), __('None active.', 'lean-images')];

		return $rows;
	}

	/**
	 * ".htaccess and wp-content/uploads/.htaccess" - the files the rules go to.
	 */
	private static function rule_files(): string
	{
		return wp_sprintf_l('%l', \array_map([Htaccess::class, 'display_path'], \array_keys(Htaccess::blocks())));
	}

	/**
	 * Which engine does which job, one line each. Per job the order is fixed -
	 * ImageMagick when it can, else GD, else nothing - so this only informs.
	 *
	 * @return array{0: string, 1: string, 2: array<int, array{0: string, 1: string}>}
	 */
	private function engines_row(array $environment): array
	{
		$imagick = $environment['imagick'] ?? null;
		$gd = $environment['gd'] ?? null;
		$label = __('Engines', 'lean-images');

		// PHP's own GD reports itself as "bundled (2.1.0 compatible)".
		$gd_version = (string) ($gd['version'] ?? '');
		if (\preg_match('/^bundled \((\S+) compatible\)$/', $gd_version, $match)) {
			/* translators: %s: GD version. */
			$gd_version = \sprintf(__('%s (bundled with PHP)', 'lean-images'), $match[1]);
		}

		$names = [
			/* translators: %s: ImageMagick version. */
			'imagick' => \sprintf(__('ImageMagick %s', 'lean-images'), \preg_replace('/^ImageMagick (\S+).*$/', '$1', (string) ($imagick['version'] ?? ''))),
			/* translators: %s: GD version. */
			'gd' => \sprintf(__('GD %s', 'lean-images'), $gd_version),
		];

		$engine = Environment::engine();
		if (null === $engine) {
			return ['err', $label, [[__('Resizing and WebP', 'lean-images'), __('neither Imagick nor GD can make WebP files here', 'lean-images')]]];
		}

		$state = 'ok';
		$lines = [];

		if ('imagick' === $engine) {
			$lines[] = [__('Resizing and WebP', 'lean-images'), $names['imagick']];
		} else {
			$state = 'info';
			/* translators: %s: GD and its version. */
			$lines[] = [__('Resizing and WebP', 'lean-images'), \sprintf(__('%s - Imagick isn\'t available, so resized images are slightly softer', 'lean-images'), $names['gd'])];
		}

		$avif_engine = Environment::avif_engine();
		if (!Config::avif_enabled()) {
			$lines[] = [__('AVIF', 'lean-images'), __('turned off in the settings below', 'lean-images')];
		} elseif (null === $avif_engine) {
			$state = 'info';
			$lines[] = [__('AVIF', 'lean-images'), __('no engine here can make AVIF files', 'lean-images')];
		} else {
			$avif = \sprintf(
				Environment::avif_during_upload()
					/* translators: %s: engine and version, e.g. ImageMagick 7.1.1. */
					? __('%s, during upload', 'lean-images')
					/* translators: %s: engine and version, e.g. GD 2.3.3. */
					: __('%s, in the background after upload', 'lean-images'),
				$names[$avif_engine]
			);

			$seconds = 'imagick' === $avif_engine ? ($environment['timing']['avif'] ?? null) : null;
			if (null !== $seconds) {
				/* translators: %s: seconds. */
				$avif .= ' ' . \sprintf(__('(%s s for a 1500 × 1000 test image)', 'lean-images'), number_format_i18n((float) $seconds, 2));
			}

			$lines[] = [__('AVIF', 'lean-images'), $avif];
		}

		$avif_reader = Environment::avif_reader();
		if (null === $avif_reader) {
			$state = 'info';
		}
		$lines[] = [__('AVIF uploads', 'lean-images'), null !== $avif_reader ? $names[$avif_reader] : __('can\'t be opened here, so they get no resized copies', 'lean-images')];

		// WordPress handles HEIC uploads from 6.7, through ImageMagick only.
		if (\version_compare(get_bloginfo('version'), '6.7', '>=')) {
			$reads_heic = 'imagick' === $engine && !empty($imagick['reads_heic']);
			if (!$reads_heic) {
				$state = 'info';
			}
			$lines[] = [__('HEIC uploads', 'lean-images'), $reads_heic
				/* translators: %s: ImageMagick and its version. */
				? \sprintf(__('%s, turned into JPEG', 'lean-images'), $names['imagick'])
				: __('can\'t be opened here', 'lean-images')];
		}

		// WordPress 7.1+ can make a block-editor upload's sizes in the browser; the
		// plugin turns that off (Editors), unless something turns it back on.
		if (\function_exists('wp_is_client_side_media_processing_enabled')) {
			if (wp_is_client_side_media_processing_enabled()) {
				$state = 'info';
				$lines[] = [__('Block editor uploads', 'lean-images'), __('sizes made in the browser (turned on by another plugin or code) - they get no WebP or AVIF until converted again', 'lean-images')];
			} else {
				$lines[] = [__('Block editor uploads', 'lean-images'), __('processed on the server - WordPress\'s in-browser processing is off while Lean Images is active', 'lean-images')];
			}
		}

		return [$state, $label, $lines];
	}

	/**
	 * What to do about a problem, right under its explanation.
	 */
	private function render_fix(string $code): void
	{
		if (\in_array($code, ['nginx', 'wrong-type'], true) && SelfTest::needs_nginx_config(SelfTest::latest())) {
			?>
			<div class="li-code-head">
				<strong><?php esc_html_e('nginx configuration', 'lean-images'); ?></strong>
				<button type="button" class="button" data-li-copy="#li-nginx"><?php esc_html_e('Copy', 'lean-images'); ?></button>
			</div>
			<pre class="li-code" id="li-nginx"><?php echo esc_html(SelfTest::nginx_config()); ?></pre>
			<p class="description"><?php esc_html_e('Reload nginx after adding it, then run the test again.', 'lean-images'); ?></p>
			<?php
		}

		if ('cloudflare' === $code) {
			?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="li-row">
				<input type="hidden" name="action" value="lean_images_cdn">
				<?php wp_nonce_field('lean_images_cdn'); ?>
				<button type="submit" name="choice" value="avif-off" class="button button-primary"><?php esc_html_e('Turn off AVIF', 'lean-images'); ?></button>
				<button type="submit" name="choice" value="kept" class="button"><?php esc_html_e('Keep AVIF', 'lean-images'); ?></button>
			</form>
			<?php
		}
	}

	private function render_maintenance(): void
	{
		?>
		<div class="postbox li-panel">
			<div class="postbox-header"><h2><?php esc_html_e('Server rules and maintenance', 'lean-images'); ?></h2></div>
			<div class="inside li-inside li-tools">
				<div class="li-tool">
					<div>
						<h3><?php esc_html_e('Server rules', 'lean-images'); ?></h3>
						<p class="description">
							<?php
							if (Htaccess::is_suspended()) {
								esc_html_e('Taken out of the .htaccess files while delivery is paused - see Status. Running the test again puts them back if this server handles them correctly.', 'lean-images');
							} elseif (\in_array('nginx', SelfTest::problem_codes(), true)) {
								esc_html_e('Written to the .htaccess files, but this server doesn\'t read them. Use the nginx configuration under Status instead.', 'lean-images');
							} else {
								echo esc_html(\sprintf(
									/* translators: %s: file paths, e.g. ".htaccess and wp-content/uploads/.htaccess". */
									__('Written to %s - the rewrite rules at the end of the site\'s main .htaccess, so rules above them keep applying. They are removed when you deactivate the plugin.', 'lean-images'),
									self::rule_files()
								));
							}
							?>
						</p>
					</div>
					<details class="li-full">
						<summary><?php esc_html_e('Show the rules', 'lean-images'); ?></summary>
						<?php foreach (Htaccess::blocks() as $path => $lines) : ?>
							<p class="li-code-file"><code><?php echo esc_html(Htaccess::display_path($path)); ?></code></p>
							<pre class="li-code"><?php echo esc_html(\implode("\n", $lines)); ?></pre>
						<?php endforeach; ?>
					</details>
				</div>
				<div class="li-tool">
					<div>
						<h3><?php esc_html_e('Convert everything again', 'lean-images'); ?></h3>
						<p class="description"><?php esc_html_e('Recreates every WebP and AVIF file with the current quality settings. Visitors keep getting the current files until each new one is ready.', 'lean-images'); ?></p>
					</div>
					<div><button type="button" class="button" data-li-confirm="reconvert"><?php esc_html_e('Convert everything again', 'lean-images'); ?></button></div>
					<div class="li-full" id="li-confirm-reconvert" hidden></div>
				</div>
				<div class="li-tool">
					<div>
						<h3><?php esc_html_e('Remove generated files', 'lean-images'); ?></h3>
						<p class="description"><?php esc_html_e('Deletes all WebP and AVIF files made by Lean Images. JPEG and PNG files are not touched. Deleting the plugin does this too, but on a large site it\'s more reliable to do it here first.', 'lean-images'); ?></p>
					</div>
					<div><button type="button" class="button li-button-delete" data-li-confirm="remove"><?php esc_html_e('Remove generated files', 'lean-images'); ?></button></div>
					<div class="li-full" id="li-confirm-remove" hidden></div>
				</div>
				<div class="li-tool">
					<div class="li-full">
						<h3><?php esc_html_e('WP-CLI', 'lean-images'); ?></h3>
						<p class="description"><code>wp lean-images status</code> · <code>wp lean-images convert --all</code> · <code>wp lean-images selftest</code> · <code>wp lean-images cleanup</code></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function check_request(string $action): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to do that.', 'lean-images'), '', ['response' => 403]);
		}

		check_admin_referer($action);
	}

	private static function script_data(): array
	{
		return [
			'root' => esc_url_raw(rest_url(Api::NAMESPACE . '/')),
			'nonce' => wp_create_nonce('wp_rest'),
			'state' => Api::payload(),
			'i18n' => [
				/* translators: 1: images done, 2: images in total. */
				'converting' => __('Converting %1$s of %2$s', 'lean-images'),
				/* translators: 1: images done, 2: images in total. */
				'removing' => __('Removing files: %1$s of %2$s images', 'lean-images'),
				'pause' => __('Pause', 'lean-images'),
				'leave' => __('You can leave this page. Conversion continues in the background, just more slowly.', 'lean-images'),
				/* translators: %s: number of images. */
				'convertWaiting' => __('Convert %s waiting images', 'lean-images'),
				/* translators: %s: number of images. */
				'retryFailed' => __('Try %s failed again', 'lean-images'),
				'allDone' => __('All images are converted.', 'lean-images'),
				'blocked' => __('Fix the problem under Status first.', 'lean-images'),
				/* translators: %s: number of images. */
				'converted' => __('Converted %s images.', 'lean-images'),
				/* translators: %s: number of images. */
				'removed' => __('Removed generated files from %s images.', 'lean-images'),
				'removedAll' => __('All generated files are removed.', 'lean-images'),
				/* translators: %s: number of images. */
				'failedSome' => __('%s couldn\'t be converted - the Media Library column shows why.', 'lean-images'),
				'paused' => __('Paused. Images still waiting are converted when you continue.', 'lean-images'),
				'error' => __('The server stopped answering. Reload the page to see where conversion got to.', 'lean-images'),
				/* translators: 1: number of images, 2: number of files. */
				'confirmReconvert' => __('Convert all %1$s converted images again with the current settings? This replaces their %2$s files.', 'lean-images'),
				/* translators: 1: number of files, 2: disk space. */
				'confirmRemove' => __('Delete %1$s WebP and AVIF files (%2$s)? Visitors get the original JPEG and PNG files until images are converted again.', 'lean-images'),
				'reconvert' => __('Convert everything again', 'lean-images'),
				'remove' => __('Delete files', 'lean-images'),
				'cancel' => __('Cancel', 'lean-images'),
				'copied' => __('Copied', 'lean-images'),
				'copy' => __('Copy', 'lean-images'),
				'pressCopy' => __('Press Ctrl+C to copy', 'lean-images'),
			],
		];
	}

	private static function css(): string
	{
		return <<<'CSS'
.lean-images { --li-accent: var(--wp-admin-theme-color, #2271b1); max-width: 1120px; }
.lean-images .li-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }
.lean-images .li-version { font-size: 13px; color: #646970; }
.lean-images .li-columns { display: grid; gap: 0 20px; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1100px) { .lean-images .li-columns { grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); } }
.lean-images .li-panel { margin-top: 16px; min-width: 0; }
.lean-images .postbox-header { padding: 0 12px 0 16px; gap: 12px; }
.lean-images .postbox-header h2 { font-size: 14px; padding: 12px 0; margin: 0; }
.lean-images .li-header-link { font-size: 12px; }
.lean-images .li-inside { display: grid; gap: 14px; margin: 0 !important; padding: 16px !important; }
.lean-images .li-inside > * { margin: 0; }
.lean-images .li-lead { font-size: 14px; max-width: 65ch; }
.lean-images .li-pill { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.lean-images .li-pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.lean-images .li-pill-ok { background: #edfaef; color: #007017; }
.lean-images .li-pill-warn { background: #fcf9e8; color: #8a5a00; }
.lean-images .li-pill-err { background: #fcf0f1; color: #b32d2e; }
.lean-images .li-pill-info { background: #f0f6fc; color: #0a4b78; }
.lean-images .li-checks { margin: 0; border: 1px solid #dcdcde; border-radius: 4px; }
.lean-images .li-checks li { display: grid; grid-template-columns: 18px minmax(0, 11rem) minmax(0, 1fr); gap: 10px; align-items: start; margin: 0; padding: 9px 12px; border-top: 1px solid #dcdcde; }
.lean-images .li-checks li:first-child { border-top: 0; }
.lean-images .li-key { font-weight: 600; }
.lean-images .li-val { color: #50575e; }
.lean-images .li-subs { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 3px 14px; margin: 0; }
.lean-images .li-subs dt { color: #1d2327; }
.lean-images .li-subs dd { margin: 0; }
@media (max-width: 600px) { .lean-images .li-checks li { grid-template-columns: 18px minmax(0, 1fr); gap: 2px 10px; } .lean-images .li-val { grid-column: 2; } }
.lean-images .li-ico { width: 16px; height: 16px; margin-top: 1px; border-radius: 50%; display: inline-grid; place-items: center; font-size: 10px; font-weight: 700; line-height: 1; color: #fff; }
.lean-images .li-ico-ok { background: #00a32a; }
.lean-images .li-ico-warn { background: #dba617; color: #1d2327; }
.lean-images .li-ico-err { background: #d63638; }
.lean-images .li-ico-info { background: #72aee6; color: #1d2327; }
.lean-images .li-callout { display: grid; gap: 10px; padding: 12px 14px; border: 1px solid #dcdcde; border-left-width: 4px; border-radius: 2px; background: #f6f7f7; }
.lean-images .li-callout p { margin: 0; }
.lean-images .li-callout-warning { border-left-color: #dba617; background: #fcf9e8; }
.lean-images .li-callout-error { border-left-color: #d63638; background: #fcf0f1; }
.lean-images .li-code-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 6px; }
.lean-images .li-code-file { margin: 12px 0 4px; }
.lean-images .li-code { margin: 0; padding: 10px 12px; overflow-x: auto; max-width: 100%; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 3px; font-size: 12px; line-height: 1.55; white-space: pre; }
.lean-images .li-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; padding: 9px 16px; border-top: 1px solid #dcdcde; background: #f6f7f7; color: #646970; font-size: 12px; }
.lean-images .li-foot form { margin: 0; }
.lean-images .li-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0; }
@media (max-width: 520px) { .lean-images .li-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.lean-images .li-stats dt { display: flex; align-items: center; gap: 6px; color: #646970; font-size: 12px; }
.lean-images .li-stats dd { margin: 0; font-size: 20px; line-height: 1.3; font-variant-numeric: tabular-nums; }
.lean-images .li-dot { width: 8px; height: 8px; border-radius: 2px; flex: none; }
.lean-images .li-c-done { background: #00a32a; }
.lean-images .li-c-wait { background: var(--li-accent); }
.lean-images .li-c-fail { background: #d63638; }
.lean-images .li-c-skip { background: #c3c4c7; }
.lean-images .li-stackbar { display: flex; height: 8px; margin-top: 12px; border-radius: 4px; overflow: hidden; background: #f0f0f1; }
.lean-images .li-stackbar span { display: block; height: 100%; }
.lean-images .li-facts { margin: 12px 0 0; display: grid; gap: 4px; color: #50575e; }
.lean-images .li-facts li { margin: 0; }
.lean-images .li-facts strong { color: #1d2327; font-variant-numeric: tabular-nums; }
.lean-images .li-actions:empty { display: none; }
.lean-images .li-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; margin: 0; }
.lean-images .li-progress { height: 10px; border-radius: 5px; background: #f0f0f1; border: 1px solid #dcdcde; overflow: hidden; }
.lean-images .li-progress span { display: block; height: 100%; background: var(--li-accent); transition: width .2s linear; }
.lean-images .li-progress-text { margin: 8px 0; font-variant-numeric: tabular-nums; color: #50575e; overflow-wrap: anywhere; }
.lean-images .li-message { margin: 0 0 8px; color: #50575e; }
.lean-images .li-quality { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.lean-images .li-quality input[type="range"] { width: min(260px, 100%); accent-color: var(--li-accent); }
.lean-images .li-value-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; margin: 0; }
.lean-images .li-tools { gap: 0; }
.lean-images .li-tool { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px 16px; align-items: start; padding-block: 14px; border-top: 1px solid #dcdcde; }
.lean-images .li-tool:first-child { border-top: 0; padding-top: 0; }
.lean-images .li-tool:last-child { padding-bottom: 0; }
.lean-images .li-tool h3 { margin: 0 0 2px; font-size: 13px; }
.lean-images .li-tool .description { margin: 0; }
.lean-images .li-full { grid-column: 1 / -1; }
@media (max-width: 600px) { .lean-images .li-tool { grid-template-columns: minmax(0, 1fr); } }
.lean-images details summary { cursor: pointer; color: var(--li-accent); width: fit-content; }
.lean-images details[open] summary { margin-bottom: 8px; }
.lean-images .li-confirm { display: grid; gap: 10px; padding: 12px 14px; border: 1px solid #c3c4c7; border-radius: 4px; background: #f6f7f7; }
.lean-images .li-confirm-danger { border-color: #d63638; background: #fcf0f1; }
.lean-images .li-confirm p { margin: 0; }
.lean-images .button.li-button-delete { color: #b32d2e; border-color: #d63638; }
.lean-images .button.li-button-delete:hover { color: #8a2424; border-color: #b32d2e; background: #fcf0f1; }
.lean-images .button.li-button-delete.button-primary { color: #fff; background: #d63638; }
CSS;
	}

	private static function js(): string
	{
		return <<<'JS'
(function () {
	'use strict';

	const data = window.leanImagesData;
	const t = data.i18n;
	const $ = (selector) => document.querySelector(selector);
	const format = (text, ...values) => {
		let i = 0;
		return text.replace(/%(\d)\$s|%s/g, (match, n) => String(n ? values[n - 1] : values[i++]));
	};
	const number = (n) => Number(n).toLocaleString();
	const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

	let running = false;
	let mode = data.state.queue.mode;
	let total = 0;
	let failedAtStart = data.state.stats.failed;
	let message = '';

	function api(path, body) {
		return fetch(data.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			body: JSON.stringify(body || {}),
		}).then((response) => (response.ok ? response.json() : Promise.reject(response)));
	}

	function update(payload) {
		data.state = payload;
		$('#li-library-body').innerHTML = payload.html;
		renderActions();
	}

	function renderActions() {
		const state = data.state;
		const stats = state.stats;
		const box = $('#li-actions');

		if (running) {
			const done = Math.max(0, Math.min(total, total - state.remaining));
			const percent = total ? Math.round((100 * done) / total) : 100;
			const label = 'remove' === mode ? t.removing : t.converting;
			box.innerHTML =
				'<div class="li-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' + total + '" aria-valuenow="' + done + '"><span style="width:' + percent + '%"></span></div>' +
				'<p class="li-progress-text">' + escape(format(label, number(done), number(total))) + (state.last ? ' · <code>' + escape(state.last) + '</code>' : '') + '</p>' +
				'<p class="li-row"><button type="button" class="button" data-li-action="pause">' + escape(t.pause) + '</button><span class="description">' + escape(t.leave) + '</span></p>';
			return;
		}

		const waiting = stats.waiting + stats.unconverted;
		let html = message ? '<p class="li-message">' + escape(message) + '</p>' : '';
		const buttons = [];

		if (state.blocked) {
			if (waiting > 0) {
				buttons.push('<button type="button" class="button button-primary" disabled>' + escape(format(t.convertWaiting, number(waiting))) + '</button>');
			}
			buttons.push('<span class="description">' + escape(t.blocked) + '</span>');
		} else {
			if (waiting > 0) {
				buttons.push('<button type="button" class="button button-primary" data-li-action="start">' + escape(format(t.convertWaiting, number(waiting))) + '</button>');
			}
			if (stats.failed > 0) {
				buttons.push('<button type="button" class="button" data-li-action="retry">' + escape(format(t.retryFailed, number(stats.failed))) + '</button>');
			}
			if (!waiting && !stats.failed) {
				buttons.push('<span>' + escape(t.allDone) + '</span>');
			}
		}

		box.innerHTML = html + '<p class="li-row">' + buttons.join('') + '</p>';
	}

	async function loop() {
		running = true;
		renderActions();

		while (running) {
			let payload;
			try {
				payload = await api('work');
			} catch (error) {
				running = false;
				message = t.error;
				break;
			}

			update(payload);

			// The queue resets to convert mode when it goes idle, so the kind of run
			// is remembered here rather than read back. The count is what's left
			// compared with the start, so images the cron worker did count too.
			if (!payload.queue.active || 0 === payload.remaining) {
				const handled = Math.max(0, total - payload.remaining);
				const failed = Math.max(0, payload.stats.failed - failedAtStart);
				running = false;
				message = 'remove' === mode
					? format(t.removed, number(handled))
					: format(t.converted, number(handled - failed)) + (failed ? ' ' + format(t.failedSome, number(failed)) : '');
			} else if (payload.busy) {
				await sleep(2000); // The cron worker has the lock; check back.
			}
		}

		renderActions();
	}

	function start(action) {
		message = '';
		api('queue', { action: action }).then((payload) => {
			mode = payload.queue.mode;
			total = payload.remaining;
			failedAtStart = payload.stats.failed;
			if (total > 0) {
				update(payload);
				loop();
			} else {
				// The cron worker, started along with the queue, can finish a small
				// job before this answer arrives.
				message = 'remove' === mode ? t.removedAll : t.allDone;
				update(payload);
			}
		}, () => {
			message = t.error;
			renderActions();
		});
	}

	function confirmBox(kind) {
		const stats = data.state.stats;
		const box = $('#li-confirm-' + kind);
		const text = 'reconvert' === kind
			? format(t.confirmReconvert, number(stats.done), number(stats.files))
			: format(t.confirmRemove, number(stats.files), data.state.generated);
		box.innerHTML =
			'<div class="li-confirm' + ('remove' === kind ? ' li-confirm-danger' : '') + '"><p>' + escape(text) + '</p><p class="li-row">' +
			'<button type="button" class="button button-primary' + ('remove' === kind ? ' li-button-delete' : '') + '" data-li-action="' + kind + '">' + escape('remove' === kind ? t.remove : t.reconvert) + '</button>' +
			'<button type="button" class="button" data-li-cancel="' + kind + '">' + escape(t.cancel) + '</button></p></div>';
		box.hidden = false;
	}

	document.addEventListener('click', (event) => {
		const target = event.target.closest('[data-li-action], [data-li-confirm], [data-li-cancel], [data-li-copy]');
		if (!target) {
			return;
		}

		if (target.dataset.liConfirm) {
			confirmBox(target.dataset.liConfirm);
			return;
		}

		if (target.dataset.liCancel) {
			$('#li-confirm-' + target.dataset.liCancel).hidden = true;
			return;
		}

		if (target.dataset.liCopy) {
			const code = $(target.dataset.liCopy);
			const done = () => { target.textContent = t.copied; setTimeout(() => { target.textContent = t.copy; }, 1600); };
			const fallback = () => {
				const range = document.createRange();
				range.selectNodeContents(code);
				const selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(range);
				target.textContent = t.pressCopy;
			};
			try {
				navigator.clipboard.writeText(code.textContent).then(done, fallback);
			} catch (error) {
				fallback();
			}
			return;
		}

		const action = target.dataset.liAction;
		if ('pause' === action) {
			running = false;
			message = t.paused;
			api('queue', { action: 'pause' }).then(update);
			return;
		}

		['reconvert', 'remove'].forEach((kind) => { $('#li-confirm-' + kind).hidden = true; });
		if ('reconvert' === action || 'remove' === action) {
			$('#li-library-body').scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
		start(action);
	});

	// Number field and slider stay in step; AVIF off greys out its quality.
	['avif', 'webp'].forEach((format) => {
		const field = $('#li-' + format + '-quality');
		const slider = $('#li-' + format + '-range');
		const toggle = $('#li-' + format);
		if (!field || !slider) {
			return;
		}
		field.addEventListener('input', () => { slider.value = Math.min(100, Math.max(1, parseInt(field.value, 10) || 1)); });
		slider.addEventListener('input', () => { field.value = slider.value; });
		if (toggle) {
			toggle.addEventListener('change', () => { field.disabled = slider.disabled = !toggle.checked; });
		}
	});

	renderActions();

	// Work already under way (a bulk run left in the background, or uploads
	// waiting for AVIF): pick it up and show its progress.
	if (data.state.queue.active && data.state.remaining > 0 && !data.state.blocked) {
		total = data.state.remaining;
		loop();
	}
})();
JS;
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Checks that delivery really works on this server, by requesting a test image
 * from the site itself the way four kinds of client would - the .htaccess
 * approach fails silently in plenty of setups (nginx, nginx in front of Apache,
 * OpenLiteSpeed before a restart, mod_rewrite off) and nothing else would ever
 * notice. Also gathers what the server can do and what else could interfere.
 *
 * Failing the other way - AVIF or WebP to a client that didn't ask for it -
 * breaks images for those visitors, so delivery pauses until a re-test passes.
 *
 * The three test files are different colours (JPEG red, WebP green, AVIF
 * blue), so which one came back is identified by its bytes, not by trusting the
 * Content-Type - and a person opening the test URL sees the answer too.
 *
 * Results are cached in an option; Site Health re-runs the test when the cache
 * is older than MAX_AGE.
 */
final class SelfTest
{

	public const OPTION = 'lean_images_selftest';

	/** Under the uploads folder, so the delivery rules apply to it. */
	public const FOLDER = 'lean-images-selftest';

	/**
	 * The site owner's answer to the Cloudflare warning: 'avif-off' (AVIF was
	 * turned off for it) or 'kept' ("Vary for images" is on in Cloudflare).
	 */
	public const CDN_CHOICE_OPTION = 'lean_images_cdn_choice';

	/** Problems that stop conversion itself, not just delivery. */
	private const BLOCKING_CODES = ['editor-overridden', 'no-webp'];

	/**
	 * Delivery results that don't show delivery working. (Finding unsafe delivery
	 * again isn't one: that keeps its own, more specific message.)
	 */
	private const UNCONFIRMED_CODES = ['selftest-files', 'loopback', 'htaccess-not-writable', 'nginx', 'no-rewrite', 'wrong-format'];

	private const MAX_AGE = 12 * HOUR_IN_SECONDS;

	private const TEST_FILES = [
		'test.jpg' => 'jpeg',
		'test.jpg.webp' => 'webp',
		'test.jpg.avif' => 'avif',
	];

	/**
	 * What each simulated client sends: a current browser, one with WebP but no
	 * AVIF (Safari 14-15), one with neither, and a fetcher that accepts anything
	 * without naming a format (some link-preview bots).
	 */
	private const ACCEPT = [
		'avif' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
		'webp' => 'image/webp,image/apng,image/*,*/*;q=0.8',
		'jpeg' => 'image/png,image/*;q=0.8,*/*;q=0.5',
		'any' => '*/*',
	];

	/**
	 * Rounds of all clients on the plain address. OpenLiteSpeed 1.9 sends AVIF
	 * or WebP to clients that didn't ask for them only when the same address is
	 * requested again (a query string hides it), and the first round after a
	 * restart is usually right (CLAUDE.md, "Delivery").
	 */
	private const ROUNDS = 3;

	/** Cloudflare's cf-cache-status values for an answer from its cache. */
	private const CDN_HITS = ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'];

	/** Plugin folders of other plugins that also hand out WebP/AVIF versions. */
	private const OTHER_OPTIMIZERS = [
		'compressx' => 'CompressX',
		'ewww-image-optimizer' => 'EWWW Image Optimizer',
		'ewww-image-optimizer-cloud' => 'EWWW Image Optimizer Cloud',
		'imagify' => 'Imagify',
		'optimole-wp' => 'Optimole',
		'robin-image-optimizer' => 'Robin Image Optimizer',
		'shortpixel-adaptive-images' => 'ShortPixel Adaptive Images',
		'shortpixel-image-optimiser' => 'ShortPixel',
		'webp-converter-for-media' => 'Converter for Media',
		'webp-express' => 'WebP Express',
		'wp-smush-pro' => 'Smush Pro',
		'wp-smushit' => 'Smush',
	];

	public function register(): void
	{
		add_action('admin_init', [$this, 'run_if_never_run']);
		add_filter('site_status_tests', [$this, 'add_site_health_test']);
		add_action('admin_notices', [$this, 'maybe_show_notice']);
	}

	/**
	 * First admin page after activation (which clears the result). Later page
	 * loads only read the cached result.
	 */
	public function run_if_never_run(): void
	{
		if (null === self::latest() && current_user_can('manage_options') && !wp_doing_ajax()) {
			self::run();
		}
	}

	public static function latest(): ?array
	{
		$result = get_option(self::OPTION);

		return \is_array($result) ? $result : null;
	}

	/**
	 * Whether the last test found something that stops conversion - the bulk
	 * buttons are disabled then, since every image would just fail.
	 */
	public static function blocks_conversion(): bool
	{
		$result = self::latest();

		return null !== $result && (bool) \array_intersect(self::BLOCKING_CODES, \array_column($result['problems'], 'code'));
	}

	/**
	 * @return string[]
	 */
	public static function problem_codes(): array
	{
		$result = self::latest();

		return null === $result ? [] : \array_column($result['problems'], 'code');
	}

	/**
	 * Writes the rules (lifting an earlier pause - this is the re-test), tests,
	 * and pauses delivery if the server sent a format to a client that didn't
	 * ask for it: visitors then get JPEG and PNG until it's fixed.
	 *
	 * A pause is only lifted by a test that shows delivery working. One that
	 * can't tell keeps it - on OpenLiteSpeed the rules just written aren't read
	 * before a restart, so it'd look like "rules not applied", and leaving them
	 * in the file would bring the fault back at the next restart.
	 *
	 * @return array{checked: int, status: string, delivery: array, problems: array<int, array{level: string, code: string, message: string}>}
	 */
	public static function run(): array
	{
		$was_paused = Htaccess::is_suspended();
		$environment = Environment::probe(); // First: the rules depend on whether AVIF can be made.
		Htaccess::write();
		$delivery = self::test_delivery();
		$delivery_problems = self::delivery_problems($delivery);

		$unconfirmed = \array_filter($delivery_problems, static fn ($problem) => \in_array($problem['code'], self::UNCONFIRMED_CODES, true));
		if ($was_paused && $unconfirmed) {
			$delivery_problems = \array_merge(
				[self::problem('error', 'unsafe-delivery', __('Delivery stays paused: an earlier test found this server sending AVIF or WebP files to browsers that hadn\'t asked for them, and this test couldn\'t confirm that\'s fixed. Visitors get JPEG and PNG. OpenLiteSpeed doesn\'t apply these rules reliably, so on OpenLiteSpeed it stays paused; on other servers, fix the cause and run the test again.', 'lean-images'))],
				\array_diff_key($delivery_problems, $unconfirmed)
			);
		}

		$problems = \array_merge(
			self::editor_problems($environment),
			$delivery_problems,
			self::environment_problems($environment),
			self::optimizer_problems()
		);

		if (\in_array('unsafe-delivery', \array_column($problems, 'code'), true)) {
			Htaccess::suspend();
		}

		$levels = \array_column($problems, 'level');
		$status = \in_array('error', $levels, true) ? 'error' : (\in_array('warning', $levels, true) ? 'warning' : 'ok');

		$result = [
			'checked' => \time(),
			'status' => $status,
			'delivery' => $delivery,
			'problems' => $problems,
		];
		update_option(self::OPTION, $result, false);

		return $result;
	}

	/**
	 * The configuration nginx needs instead of the .htaccess rules, for the
	 * site's own uploads path and only the enabled formats. A format the browser
	 * doesn't accept maps to ".none", a suffix no file has, so try_files skips it.
	 */
	public static function nginx_config(): string
	{
		$path = \preg_quote(\untrailingslashit((string) wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH)));

		$formats = Htaccess::formats();

		$lines = ['# Inside http { }'];
		foreach ($formats as $format) {
			$lines[] = "map \$http_accept \$lean_images_{$format} {";
			$lines[] = '    default        ".none";';
			$lines[] = "    \"~*image/{$format}\" \".{$format}\";";
			$lines[] = '}';
		}

		$tries = \implode(' ', \array_map(static fn ($format) => "\$uri\$lean_images_{$format}", $formats));

		// The types block: older nginx (1.18, Ubuntu 22.04) has no AVIF in its
		// mime.types and sends it as application/octet-stream. Inside a location
		// it replaces the whole list, so it names all four.
		// Above other image locations: nginx uses the first regex location that
		// matches, so below a "serve static files" one this never runs - seen with
		// nginx serving static files in front of Apache.
		$lines[] = '';
		$lines[] = "# Inside the site's server { }, above any other location that matches images";
		$lines[] = "location ~* ^{$path}/.+\\.(?:jpe?g|png)$ {";
		$lines[] = '    types {';
		$lines[] = '        image/jpeg jpeg jpg;';
		$lines[] = '        image/png  png;';
		$lines[] = '        image/webp webp;';
		$lines[] = '        image/avif avif;';
		$lines[] = '    }';
		$lines[] = '    add_header Vary Accept;';
		$lines[] = '    try_files ' . \trim("{$tries} \$uri") . ' =404;';
		$lines[] = '}';

		return \implode("\n", $lines);
	}

	/**
	 * nginx without the configuration, or nginx labelling AVIF/WebP wrongly
	 * (an older version of it, or a hand-written one).
	 */
	public static function needs_nginx_config(?array $result): bool
	{
		$codes = null === $result ? [] : \array_column($result['problems'], 'code');

		return \in_array('nginx', $codes, true)
			|| (\in_array('wrong-type', $codes, true) && self::is_nginx($result['delivery']['cases'] ?? []));
	}

	public function add_site_health_test($tests)
	{
		$tests['direct']['lean_images'] = [
			'label' => __('Lean Images', 'lean-images'),
			'test' => [$this, 'site_health_result'],
		];

		return $tests;
	}

	public function site_health_result(): array
	{
		$result = self::latest();
		if (null === $result || \time() - (int) $result['checked'] > self::MAX_AGE) {
			$result = self::run();
		}

		$labels = [
			'ok' => \in_array('avif', Htaccess::formats(), true)
				? __('AVIF and WebP images are served to browsers that support them', 'lean-images')
				: __('WebP images are served to browsers that support WebP', 'lean-images'),
			'warning' => __('Lean Images works, but something needs attention', 'lean-images'),
			'error' => __('Lean Images found a problem', 'lean-images'),
		];

		$description = '<p>' . esc_html__('Lean Images requested a test image from this site the way current, older and very old browsers would, and checked what this server can do with images.', 'lean-images') . '</p>';

		if ($result['problems']) {
			$description .= '<ul>';
			foreach ($result['problems'] as $problem) {
				$description .= '<li>' . esc_html($problem['message']) . '</li>';
			}
			$description .= '</ul>';
		}

		if (self::needs_nginx_config($result)) {
			$description .= '<pre>' . esc_html(self::nginx_config()) . '</pre>';
		}

		return [
			'label' => $labels[$result['status']],
			'status' => ['ok' => 'good', 'warning' => 'recommended', 'error' => 'critical'][$result['status']],
			'badge' => ['label' => __('Performance'), 'color' => 'blue'], // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own label.
			'description' => $description,
			'actions' => '',
			'test' => 'lean_images',
		];
	}

	/**
	 * Errors only, on the screens where someone would act on them. Reads the
	 * cached result.
	 */
	public function maybe_show_notice(): void
	{
		$screen = \function_exists('get_current_screen') ? get_current_screen() : null;
		if (!current_user_can('manage_options') || !$screen || !\in_array($screen->id, ['dashboard', 'plugins', 'upload'], true)) {
			return;
		}

		$result = self::latest();
		if (null === $result || 'error' !== $result['status']) {
			return;
		}

		$errors = \array_values(\array_filter($result['problems'], static fn ($problem) => 'error' === $problem['level']));

		\printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__('Lean Images:', 'lean-images'),
			esc_html($errors[0]['message']),
			esc_url(Admin\SettingsPage::url()),
			esc_html__('See Status', 'lean-images')
		);
	}

	private static function test_delivery(): array
	{
		$uploads = wp_upload_dir(null, false);
		if (!empty($uploads['error'])) {
			return ['error' => (string) $uploads['error']];
		}

		$dir = trailingslashit($uploads['basedir']) . self::FOLDER;
		// Filterable for testing the checks against another server.
		$url = (string) apply_filters('lean_images_selftest_url', trailingslashit($uploads['baseurl']) . self::FOLDER . '/test.jpg');

		if (!wp_mkdir_p($dir)) {
			return ['error' => 'mkdir'];
		}

		$formats_by_hash = [];
		foreach (self::TEST_FILES as $file => $format) {
			$source = LEAN_IMAGES_DIR . '/assets/selftest/' . $file;
			if (!@\copy($source, "{$dir}/{$file}")) {
				return ['error' => 'copy'];
			}
			$formats_by_hash[\md5_file($source)] = $format;
		}

		// Each client once, with a unique query string so no cache between here
		// and the server answers: what the server's rules do.
		$cases = [];
		foreach (\array_keys(self::ACCEPT) as $client) {
			$cases[] = self::request(add_query_arg('lean-images-test', wp_generate_password(8, false), $url), $client, $formats_by_hash);
		}

		// Then the plain address, round after round, the way browsers ask for it -
		// only to catch a format sent to a client that never asked for it.
		$repeats = [];
		foreach (\array_merge(...\array_fill(0, self::ROUNDS, \array_keys(self::ACCEPT))) as $client) {
			$repeats[] = self::request($url, $client, $formats_by_hash);
		}

		return ['url' => $url, 'cases' => $cases, 'repeats' => $repeats];
	}

	/**
	 * @param array<string, string> $formats_by_hash
	 */
	private static function request(string $url, string $client, array $formats_by_hash): array
	{
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'redirection' => 2,
				'headers' => ['Accept' => self::ACCEPT[$client], 'Cache-Control' => 'no-cache'],
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters('https_local_ssl_verify', false),
			]
		);

		if (is_wp_error($response)) {
			return ['client' => $client, 'error' => $response->get_error_message()];
		}

		$vary = wp_remote_retrieve_header($response, 'vary');

		return [
			'client' => $client,
			'code' => (int) wp_remote_retrieve_response_code($response),
			'expected' => self::expected_format($client),
			'served' => $formats_by_hash[\md5(wp_remote_retrieve_body($response))] ?? 'other',
			'type' => (string) wp_remote_retrieve_header($response, 'content-type'),
			'vary' => false !== \stripos(\implode(',', (array) $vary), 'accept'),
			'server' => (string) wp_remote_retrieve_header($response, 'server'),
			'cloudflare' => '' !== (string) wp_remote_retrieve_header($response, 'cf-ray'),
			'cdn_hit' => \in_array(\strtoupper((string) wp_remote_retrieve_header($response, 'cf-cache-status')), self::CDN_HITS, true),
		];
	}

	/**
	 * What a browser should get, given which formats are switched on: the best
	 * format it accepts among the enabled ones, else the JPEG.
	 */
	private static function expected_format(string $case): string
	{
		$order = ['avif' => ['avif', 'webp'], 'webp' => ['webp'], 'jpeg' => [], 'any' => []][$case];
		foreach ($order as $format) {
			if (\in_array($format, Htaccess::formats(), true)) {
				return $format;
			}
		}

		return 'jpeg';
	}

	private static function delivery_problems(array $delivery): array
	{
		if (isset($delivery['error'])) {
			return [self::problem('error', 'selftest-files', __('Lean Images couldn\'t create its test files in the uploads folder. Check that the uploads folder is writable.', 'lean-images'))];
		}

		$cases = $delivery['cases'];
		$answered = \array_filter($cases, static fn ($case) => !isset($case['error']));

		if (!$answered) {
			$error = (string) (\reset($cases)['error'] ?? '');

			return [self::problem('warning', 'loopback', \sprintf(
				/* translators: %s: error message from the HTTP request. */
				__('Lean Images couldn\'t request a test image from this site (%s), so it can\'t confirm delivery. Images may still be served correctly - open the test image in a current browser: it shows blue for AVIF, green for WebP and red for JPEG.', 'lean-images'),
				$error
			))];
		}

		$problems = [];

		// Worse than no AVIF: a client got a format it never said it could show.
		// Cloudflare's cache doing that is the Cloudflare warning's business, with
		// the owner's answer to it.
		$all = \array_merge($answered, \array_filter($delivery['repeats'], static fn ($case) => !isset($case['error']) && !$case['cdn_hit']));
		$shows = ['avif' => ['avif', 'webp'], 'webp' => ['webp'], 'jpeg' => [], 'any' => []];
		$unsafe = \array_filter($all, static fn ($case) => \in_array($case['served'], ['avif', 'webp'], true) && !\in_array($case['served'], $shows[$case['client']], true));
		$correct = \array_filter($answered, static fn ($case) => 200 === $case['code'] && $case['served'] === $case['expected']);

		if ($unsafe) {
			$litespeed = false !== \stripos(\implode(' ', \array_column($all, 'server')), 'litespeed');

			$problems[] = self::problem('error', 'unsafe-delivery', \sprintf(
				/* translators: 1: number of wrong answers, 2: number of requests, 3: what to do next. */
				__('In %1$s of %2$s test requests, this server sent an AVIF or WebP file to a browser that hadn\'t asked for one and may not be able to show it, so Lean Images has paused delivery: visitors get JPEG and PNG until a test passes. %3$s', 'lean-images'),
				number_format_i18n(\count($unsafe)),
				number_format_i18n(\count($all)),
				$litespeed
					? __('OpenLiteSpeed does this with .htaccess rules. Restart it now: it keeps using rules that were taken out until it restarts.', 'lean-images')
					: __('A cache that ignores the Vary: Accept header can cause this. Run the test again after changing the server setup.', 'lean-images')
			));
		} elseif (\count($correct) !== \count($cases)) {
			$all_jpeg = !\array_filter($answered, static fn ($case) => 'jpeg' !== $case['served']);

			$files = wp_sprintf_l('%l', \array_map([Htaccess::class, 'display_path'], \array_keys(Htaccess::blocks())));
			$overriding = Htaccess::overriding_files();

			if ($all_jpeg && null === Htaccess::location()) {
				$problems[] = self::problem('error', 'htaccess-not-writable', __('Lean Images couldn\'t work out where the site\'s main .htaccess file is - the uploads folder isn\'t served from the site\'s own address - so visitors only get JPEG and PNG.', 'lean-images'));
			} elseif ($all_jpeg && !Htaccess::is_written()) {
				$problems[] = self::problem('error', 'htaccess-not-writable', \sprintf(
					/* translators: %s: file paths, e.g. ".htaccess and wp-content/uploads/.htaccess". */
					__('Lean Images couldn\'t write its rules to %s, so visitors only get JPEG and PNG. Make those files (or their folders) writable, then run the test again.', 'lean-images'),
					$files
				));
			} elseif ($all_jpeg && self::is_nginx($answered)) {
				$problems[] = self::problem('error', 'nginx', __('This server runs nginx, which doesn\'t read .htaccess files, so visitors only get JPEG and PNG. Add the configuration below to the site\'s nginx configuration, or send it to your host.', 'lean-images'));
			} elseif ($all_jpeg && $overriding) {
				$problems[] = self::problem('error', 'no-rewrite', \sprintf(
					/* translators: %s: file paths, e.g. "wp-content/uploads/.htaccess". */
					__('%s has rewrite rules of its own, which switch off the rules in the site\'s main .htaccess for the uploads folder - Lean Images\' among them - so visitors only get JPEG and PNG. Move those rules into the main .htaccess or remove them, then run the test again.', 'lean-images'),
					wp_sprintf_l('%l', $overriding)
				));
			} elseif ($all_jpeg) {
				$problems[] = self::problem('error', 'no-rewrite', \sprintf(
					/* translators: %s: file paths, e.g. ".htaccess and wp-content/uploads/.htaccess". */
					__('The server didn\'t apply the rules in %s, so visitors only get JPEG and PNG. Usually mod_rewrite is off or the server ignores .htaccess files. On OpenLiteSpeed, restart the server, then run the test again.', 'lean-images'),
					$files
				));
			} else {
				$problems[] = self::problem('error', 'wrong-format', \sprintf(
					/* translators: %s: what went wrong, e.g. "a current browser got AVIF instead of WebP". */
					__('Some browsers got a different image format than they should: %s. Another plugin, a cache or a CDN may be changing image responses.', 'lean-images'),
					self::describe_mismatches($cases)
				));
			}
		} elseif (\array_filter($answered, static fn ($case) => !$case['vary'])) {
			$problems[] = self::problem('warning', 'no-vary', __('Images are served correctly, but without the Vary: Accept header, so a cache could hand an AVIF file to a browser that can\'t show it. Ask your host to enable mod_headers.', 'lean-images'));
		}

		// The right file under the wrong label: pages still show it (browsers sniff
		// images), but opened on its own it downloads, and caches may mishandle it.
		$mislabelled = \array_unique(\array_column(\array_filter(
			$answered,
			static fn ($case) => \in_array($case['served'], ['avif', 'webp'], true) && 0 !== \stripos($case['type'], 'image/' . $case['served'])
		), 'served'));

		if (!$unsafe && $mislabelled) {
			$format = \strtoupper((string) \reset($mislabelled));
			$problems[] = self::problem('warning', 'wrong-type', \sprintf(
				/* translators: 1: AVIF or WebP, 2: lowercase format, 3: the Content-Type the server sent. */
				__('%1$s files are served with the type "%3$s" instead of "image/%2$s". Pages still show them, but opened on their own they download, and some caches handle them badly. Add "image/%2$s %2$s" to the server\'s MIME types - on nginx, use the configuration below - or ask your host.', 'lean-images'),
				'AVIF' === $format ? 'AVIF' : 'WebP',
				\strtolower($format),
				(string) (\array_values(\array_filter($answered, static fn ($case) => \strtolower($format) === $case['served']))[0]['type'] ?? '')
			));
		}

		// Once the owner has answered (AVIF off, or "Vary for images" confirmed),
		// Cloudflare is no longer a problem worth repeating.
		$cdn_settled = 'kept' === get_option(self::CDN_CHOICE_OPTION) || !Config::avif_enabled();

		if (!$cdn_settled && \array_filter($answered, static fn ($case) => $case['cloudflare'])) {
			$problems[] = self::problem('warning', 'cloudflare', __('Cloudflare is in front of this site. Its free plan ignores the Vary: Accept header, so it may cache an AVIF file and pass it to a browser that can\'t show it. Turn AVIF off, or turn on "Vary for images" in Cloudflare (paid plans).', 'lean-images'));
		}

		return $problems;
	}

	/**
	 * "a current browser got AVIF instead of WebP; an older browser got no answer"
	 * - each different mismatch once.
	 */
	private static function describe_mismatches(array $cases): string
	{
		$clients = [
			'avif' => __('a current browser', 'lean-images'),
			'webp' => __('Safari 14-15', 'lean-images'),
			'jpeg' => __('an older browser', 'lean-images'),
			'any' => __('a client that accepts any format', 'lean-images'),
		];
		$formats = ['avif' => 'AVIF', 'webp' => 'WebP', 'jpeg' => 'JPEG', 'other' => __('another file', 'lean-images')];

		$mismatches = [];
		foreach ($cases as $case) {
			if (isset($case['error'])) {
				/* translators: %s: a kind of browser, e.g. "an older browser". */
				$mismatches[] = \sprintf(__('%s got no answer', 'lean-images'), $clients[$case['client']]);
			} elseif (200 !== $case['code']) {
				/* translators: 1: a kind of browser, 2: HTTP status code. */
				$mismatches[] = \sprintf(__('%1$s got HTTP %2$d', 'lean-images'), $clients[$case['client']], $case['code']);
			} elseif ($case['served'] !== $case['expected']) {
				/* translators: 1: a kind of browser, 2: the format it got, 3: the format it should have got. */
				$mismatches[] = \sprintf(__('%1$s got %2$s instead of %3$s', 'lean-images'), $clients[$case['client']], $formats[$case['served']], $formats[$case['expected']]);
			}
		}

		return \implode('; ', \array_unique($mismatches));
	}

	/**
	 * The response's Server header, or this request's own server when run from
	 * a web request (empty under WP-CLI).
	 */
	private static function is_nginx(array $cases): bool
	{
		$servers = \array_column($cases, 'server');
		$servers[] = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';

		return false !== \stripos(\implode(' ', $servers), 'nginx');
	}

	private static function environment_problems(array $environment): array
	{
		$imagick = $environment['imagick'];
		$gd = $environment['gd'];

		if (!$imagick || !$imagick['webp']) {
			if (empty($gd['webp'])) {
				return [self::problem('error', 'no-webp', __('This server can make WebP files with neither Imagick nor GD, so uploads aren\'t converted. Ask your host to enable the Imagick PHP extension.', 'lean-images'))];
			}

			$problems = [self::problem('info', 'gd-only', __('This server has no usable Imagick PHP extension, so GD is used: resized images are slightly softer, colours aren\'t converted to sRGB, and HEIC uploads aren\'t supported. Ask your host to enable Imagick for the best results.', 'lean-images'))];
			$problems[] = empty($gd['avif'])
				? self::problem('info', 'no-avif', __('This server can\'t make AVIF files, so visitors get WebP.', 'lean-images'))
				: self::problem('info', 'avif-slow', __('GD makes AVIF slowly, so AVIF is made in the background after each upload, keeping uploads quick.', 'lean-images'));

			return $problems;
		}

		$problems = [];

		if (!$imagick['avif'] && !empty($gd['avif'])) {
			$problems[] = self::problem('info', 'avif-gd', __('ImageMagick on this server can\'t make AVIF files, but GD can, so GD makes them - in the background after each upload, since it\'s slower.', 'lean-images'));
		} elseif (!$imagick['avif']) {
			$problems[] = self::problem('info', 'no-avif', __('This server can\'t make AVIF files, so visitors get WebP.', 'lean-images'));
		} elseif (!Environment::avif_during_upload()) {
			$problems[] = self::problem('info', 'avif-slow', \sprintf(
				/* translators: %s: seconds, e.g. 1.3 */
				__('AVIF takes %s s for a 1500 × 1000 test image on this server, so it\'s made in the background after each upload, keeping uploads quick.', 'lean-images'),
				number_format_i18n((float) ($environment['timing']['avif'] ?? 0), 1)
			));
		}

		if (!$imagick['reads_avif'] && !empty($gd['reads_avif'])) {
			$problems[] = self::problem('info', 'reads-avif', __('ImageMagick on this server can\'t open AVIF files, so GD makes the resized copies of AVIF uploads.', 'lean-images'));
		} elseif (!$imagick['reads_avif']) {
			$problems[] = self::problem('info', 'reads-avif', __('This server can\'t open AVIF files, so AVIF uploads get no resized copies.', 'lean-images'));
		}

		if (!$imagick['reads_heic'] && \version_compare(get_bloginfo('version'), '6.7', '>=')) {
			$problems[] = self::problem('info', 'reads-heic', __('ImageMagick on this server can\'t open HEIC files, so iPhone photos in HEIC format can\'t be uploaded as images.', 'lean-images'));
		}

		return $problems;
	}

	/**
	 * The editor WordPress would pick for a JPEG. The plugin adds its own at
	 * priority 20; anything running later that replaces the list - like the old
	 * AVIF_GD_Selector_Editor snippet - takes conversion away.
	 */
	private static function editor_problems(array $environment): array
	{
		$engine = Environment::engine();
		if (null === $engine) {
			return []; // Nothing can convert; environment_problems() says so.
		}

		$expected = 'imagick' === $engine ? Editor\ImagickEditor::class : Editor\GdEditor::class;
		$chosen = _wp_image_editor_choose(['mime_type' => 'image/jpeg', 'methods' => ['resize', 'save']]);
		if ($expected === $chosen) {
			return [];
		}

		// The plugin's own GD editor although its Imagick one is still in the
		// list: WordPress turned Imagick down - nobody replaced anything, and
		// images are still converted.
		$editors = (array) apply_filters('wp_image_editors', ['WP_Image_Editor_Imagick', 'WP_Image_Editor_GD']);
		if (Editor\GdEditor::class === $chosen && Editor\ImagickEditor::class === $expected && \in_array(Editor\ImagickEditor::class, $editors, true)) {
			return [self::problem('warning', 'imagick-refused', \sprintf(
				/* translators: %s: the Imagick extension's version as it reports it. */
				__('WordPress\'s check of the Imagick PHP extension fails on this server (it reports version %s), so images are resized and converted with GD instead of ImageMagick - slightly softer. Ask your host to update or reinstall the Imagick extension.', 'lean-images'),
				(string) \phpversion('imagick')
			))];
		}

		return [self::problem('error', 'editor-overridden', \sprintf(
			/* translators: %s: PHP class name. */
			__('Another plugin or code snippet replaces WordPress\'s image editors (%s), so new uploads aren\'t converted. Remove it, then run the test again.', 'lean-images'),
			$chosen ? (string) $chosen : __('none available', 'lean-images')
		))];
	}

	private static function optimizer_problems(): array
	{
		$active = \array_map('dirname', \array_merge(
			(array) get_option('active_plugins', []),
			\array_keys((array) get_site_option('active_sitewide_plugins', []))
		));

		$names = \array_values(\array_intersect_key(self::OTHER_OPTIMIZERS, \array_flip($active)));

		// LiteSpeed Cache only competes when its WebP replacement is switched on.
		if (\in_array('litespeed-cache', $active, true) && get_option('litespeed.conf.img_optm-webp')) {
			$names[] = 'LiteSpeed Cache (WebP replacement)';
		}

		return \array_map(
			static fn ($name) => self::problem('warning', 'other-optimizer', \sprintf(
				/* translators: %s: plugin name. */
				__('%s is also active and can serve WebP or AVIF versions of its own. Running both may hand out the wrong file - turn off its WebP/AVIF option or deactivate it.', 'lean-images'),
				$name
			)),
			$names
		);
	}

	private static function problem(string $level, string $code, string $message): array
	{
		return ['level' => $level, 'code' => $code, 'message' => $message];
	}
}

<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Converts images to WebP and AVIF, and checks that the server delivers them.
 *
 * Bulk work goes through the same queue and lock as the settings page and
 * WP-Cron, so a command-line run and a background run never convert the same
 * image at once.
 */
final class Cli
{

	/**
	 * Shows the last server test and the library numbers.
	 *
	 * ## OPTIONS
	 *
	 * [--run]
	 * : Run the server test first instead of showing the last result.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-images status
	 *     wp lean-images status --run
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function status(array $args, array $assoc_args): void
	{
		$result = !empty($assoc_args['run']) ? SelfTest::run() : SelfTest::latest();

		if (null === $result) {
			\WP_CLI::log('Server test: not run yet (use --run).');
		} else {
			\WP_CLI::log(\sprintf('Server test: %s (%s)', $result['status'], wp_date('Y-m-d H:i', (int) $result['checked'])));
			$this->print_problems($result['problems']);
		}

		$stats = Library::stats();
		\WP_CLI::log(\sprintf(
			'Library: %d images - %d converted, %d waiting, %d failed, %d animated.',
			$stats['total'],
			$stats['done'],
			$stats['waiting'] + $stats['unconverted'],
			$stats['failed'],
			$stats['animated']
		));

		if ($stats['source'] > 0) {
			\WP_CLI::log(\sprintf(
				'Savings: AVIF browsers download %d%% less, WebP-only browsers %d%% less. Generated files: %s in %d files.',
				\round(100 * (1 - $stats['avif_view'] / $stats['source'])),
				\round(100 * (1 - $stats['webp_view'] / $stats['source'])),
				size_format($stats['generated'], 1),
				$stats['files']
			));
		}
	}

	/**
	 * Converts images: the given ones, or every image not converted yet.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to convert now.
	 *
	 * [--all]
	 * : Every image not converted yet, plus waiting and failed ones.
	 *
	 * [--force]
	 * : With --all, convert finished images again too - after changing quality.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-images convert 123 456
	 *     wp lean-images convert --all
	 *     wp lean-images convert --all --force
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function convert(array $args, array $assoc_args): void
	{
		if (SelfTest::blocks_conversion()) {
			\WP_CLI::error('The server test found a problem that stops conversion. Run `wp lean-images status --run` for details.');
		}

		if ($args) {
			$failed = 0;
			foreach ($args as $id) {
				$status = Converter::process((int) $id);
				if ('failed' === $status) {
					$failed++;
					\WP_CLI::warning(\sprintf('#%d failed: %s', $id, Library::error((int) $id)));
				} else {
					\WP_CLI::log(\sprintf('#%d %s', $id, $status));
				}
			}

			$failed ? \WP_CLI::error(\sprintf('%d of %d images failed.', $failed, \count($args))) : \WP_CLI::success(\sprintf('Converted %d images.', \count($args)));

			return;
		}

		if (empty($assoc_args['all'])) {
			\WP_CLI::error('Give attachment IDs, or --all.');
		}

		Library::move_status('failed', 'waiting');
		if (!empty($assoc_args['force'])) {
			Library::move_status('done', 'waiting');
		}

		Queue::start(true, 'convert', false);
		$this->work_queue('Converting');

		$stats = Library::stats();
		\WP_CLI::success(\sprintf('%d images converted, %d failed.', $stats['done'], $stats['failed']));
	}

	/**
	 * Runs the server test: delivery to four kinds of client, what the server
	 * can encode and decode, and anything that could interfere. Exits with an
	 * error when it finds one - and pauses delivery if browsers got formats
	 * they hadn't asked for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-images selftest
	 */
	public function selftest(): void
	{
		$result = SelfTest::run();
		$this->print_problems($result['problems']);

		if ('error' === $result['status']) {
			\WP_CLI::error('The server test found a problem.');
		}

		\WP_CLI::success('warning' === $result['status'] ? 'Working, with warnings.' : (\in_array('avif', Htaccess::formats(), true) ? 'AVIF and WebP are served to browsers that support them.' : 'WebP is served to browsers that support it.'));
	}

	/**
	 * Removes every WebP and AVIF file the plugin made. JPEG and PNG files are
	 * not touched; visitors get them until images are converted again.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-images cleanup --yes
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cleanup(array $args, array $assoc_args): void
	{
		$stats = Library::stats();
		\WP_CLI::confirm(\sprintf('Delete %d WebP and AVIF files (%s)?', $stats['files'], size_format($stats['generated'], 1)), $assoc_args);

		Queue::start(false, 'remove', false);
		$this->work_queue('Removing');

		\WP_CLI::success('Generated files removed.');
	}

	/**
	 * Works the queue in short batches until it's idle, waiting when another
	 * worker holds the lock.
	 */
	private function work_queue(string $label): void
	{
		$remaining = static function (): int {
			$state = Queue::state();
			if ('remove' === $state['mode']) {
				return Library::count_with_files();
			}

			$stats = Library::stats();

			return $stats['waiting'] + ($state['all'] ? $stats['unconverted'] : 0);
		};

		$total = $remaining();
		$progress = \WP_CLI\Utils\make_progress_bar($label, $total);
		$done = 0;

		while (Queue::state()['active']) {
			$result = Queue::work(10.0);
			if ($result['busy']) {
				\sleep(2);
				continue;
			}

			$now = \max(0, $total - $remaining());
			$progress->tick($now - $done);
			$done = $now;

			if (0 === $result['processed'] && !Queue::state()['active']) {
				break;
			}
		}

		$progress->finish();
	}

	/**
	 * @param array<int, array{level: string, code: string, message: string}> $problems
	 */
	private function print_problems(array $problems): void
	{
		foreach ($problems as $problem) {
			\WP_CLI::log(\sprintf('  [%s] %s', $problem['level'], $problem['message']));
		}
	}
}

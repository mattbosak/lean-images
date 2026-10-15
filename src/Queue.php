<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Converts (or removes) in the background, one attachment at a time.
 *
 * Two drivers share one work loop: WP-Cron, so work continues when nobody has
 * the settings screen open, and the settings screen itself, which calls the
 * same loop over REST every few seconds while it's open (WP-Cron only runs when
 * the site gets visits). A lock keeps them from working at the same time.
 *
 * State, in one option: whether the queue is active, whether it includes
 * never-converted images (a bulk conversion) or only ones explicitly waiting
 * (uploads that left AVIF for later, "Convert with Lean Images"), and the mode -
 * convert or remove.
 */
final class Queue
{

	public const HOOK = 'lean_images_work';
	public const STATE_OPTION = 'lean_images_queue';

	/**
	 * An options-table row, not a transient: transients can vanish on an
	 * object-cache flush, and a lock that silently disappears lets two workers
	 * run at once. It holds an expiry time, so a worker that died mid-run doesn't
	 * block the queue for more than a minute.
	 */
	private const LOCK_OPTION = 'lean_images_lock';
	private const LOCK_SECONDS = 60;

	/** Per cron run. Stays well inside common 30-60 s PHP time limits. */
	private const CRON_BUDGET = 20.0;

	public function register(): void
	{
		add_action(self::HOOK, [self::class, 'run_cron']);
	}

	/**
	 * Read from the database every time, not the request's option cache: another
	 * worker (WP-Cron, the settings page, WP-CLI) may have changed it meanwhile,
	 * and update_option() leaves a stale cache in place when the database already
	 * holds the value it writes. A long WP-CLI run kept seeing "active" after the
	 * cron worker had finished the queue - and looped forever.
	 *
	 * @return array{active: bool, all: bool, mode: string}
	 */
	public static function state(): array
	{
		wp_cache_delete(self::STATE_OPTION, 'options');
		$state = get_option(self::STATE_OPTION);

		return [
			'active' => !empty($state['active']),
			'all' => !empty($state['all']),
			'mode' => 'remove' === ($state['mode'] ?? '') ? 'remove' : 'convert',
		];
	}

	/**
	 * Starts or widens the queue. A bulk conversion includes never-converted
	 * images; anything else only works through attachments marked waiting.
	 *
	 * @param bool $kick False when the caller works the queue itself (WP-CLI).
	 */
	public static function start(bool $all = false, string $mode = 'convert', bool $kick = true): void
	{
		$state = self::state();
		$state['active'] = true;
		$state['all'] = $all || ($state['all'] && 'convert' === $mode && 'convert' === $state['mode']);
		$state['mode'] = $mode;
		update_option(self::STATE_OPTION, $state, false);

		if ($kick) {
			self::kick();
		}
	}

	public static function pause(): void
	{
		update_option(self::STATE_OPTION, ['active' => false, 'all' => false, 'mode' => 'convert'], false);
		wp_clear_scheduled_hook(self::HOOK);
	}

	/**
	 * Schedules a cron run now and nudges WP-Cron to run it straight away
	 * rather than on the next visit.
	 */
	public static function kick(): void
	{
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(\time(), self::HOOK);
		}

		if (\function_exists('spawn_cron') && !wp_doing_cron()) {
			spawn_cron();
		}
	}

	public static function run_cron(): void
	{
		if (!self::state()['active']) {
			return;
		}

		self::work(self::CRON_BUDGET);

		// WordPress removes an event before running it, so this can't clash with
		// itself. A few seconds' gap leaves the server room between runs.
		if (self::state()['active'] && !wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(\time() + 5, self::HOOK);
		}
	}

	/**
	 * Works until the budget is used up or nothing is left, finishing at least
	 * one attachment per call. Returns what happened, for the progress display.
	 *
	 * @return array{busy: bool, processed: int, last: string}
	 */
	public static function work(float $budget): array
	{
		$result = ['busy' => false, 'processed' => 0, 'last' => ''];

		if (!self::lock()) {
			$result['busy'] = true;

			return $result;
		}

		try {
			$start = \microtime(true);
			$skip = [];
			$retry_later = false;

			do {
				$state = self::state();
				if (!$state['active']) {
					break;
				}

				$id = 'remove' === $state['mode']
					? Library::next_with_files($skip)
					: Library::next_to_convert($state['all'], $skip);

				if (null === $id) {
					// Nothing left - unless something failed in this run with tries
					// to spare: it's skipped for the rest of this run, so the queue
					// stays active and the next run tries it again.
					if (!$retry_later) {
						self::pause();
					}
					break;
				}

				if ('remove' === $state['mode']) {
					Converter::remove($id);
				} elseif ('failed' === Converter::process($id) && 'waiting' === Library::status($id)) {
					$retry_later = true;
				}

				$skip[] = $id;
				$result['processed']++;
				$result['last'] = wp_basename((string) get_attached_file($id));
				self::lock(true);
			} while (\microtime(true) - $start < $budget);
		} finally {
			self::unlock();
		}

		return $result;
	}

	/**
	 * Claimed in SQL, because add_option() isn't atomic: core writes it as
	 * INSERT ... ON DUPLICATE KEY UPDATE, which also "succeeds" when the row
	 * already exists, and checks the (possibly cached) option first. INSERT
	 * IGNORE affects one row only for the process that created it; taking over
	 * a stale lock is a conditional UPDATE, which only one process can win.
	 */
	private static function lock(bool $refresh = false): bool
	{
		global $wpdb;

		$now = \time();
		$until = $now + self::LOCK_SECONDS;

		if ($refresh) {
			$wpdb->update($wpdb->options, ['option_value' => $until], ['option_name' => self::LOCK_OPTION]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return true;
		}

		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
				self::LOCK_OPTION,
				$until
			)
		);

		if (!$claimed) {
			$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
					$until,
					self::LOCK_OPTION,
					$now
				)
			);
		}

		wp_cache_delete(self::LOCK_OPTION, 'options');

		return (bool) $claimed;
	}

	private static function unlock(): void
	{
		global $wpdb;

		$wpdb->delete($wpdb->options, ['option_name' => self::LOCK_OPTION]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete(self::LOCK_OPTION, 'options');
	}
}

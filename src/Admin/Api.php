<?php

namespace LeanImages\Admin;

use LeanImages\Library;
use LeanImages\Queue;
use LeanImages\SelfTest;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * The REST endpoints behind the Library panel. Each answers with the same
 * payload - counts, queue state, the panel's HTML - so the page can redraw
 * from any of them.
 *
 *  POST /lean-images/v1/queue  {action: start|retry|reconvert|remove|pause}
 *  POST /lean-images/v1/work   - one batch of about WORK_SECONDS
 */
final class Api
{

	public const NAMESPACE = 'lean-images/v1';

	/**
	 * Short batches keep the progress display moving and stay far from PHP's
	 * time limit; at least one image is always finished per batch.
	 */
	private const WORK_SECONDS = 8.0;

	public function register(): void
	{
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes(): void
	{
		$permission = static fn () => current_user_can('manage_options');

		register_rest_route(self::NAMESPACE, '/queue', [
			'methods' => \WP_REST_Server::CREATABLE,
			'permission_callback' => $permission,
			'callback' => [$this, 'queue'],
			'args' => [
				'action' => [
					'required' => true,
					'type' => 'string',
					'enum' => ['start', 'retry', 'reconvert', 'remove', 'pause'],
				],
			],
		]);

		register_rest_route(self::NAMESPACE, '/work', [
			'methods' => \WP_REST_Server::CREATABLE,
			'permission_callback' => $permission,
			'callback' => [$this, 'work'],
		]);
	}

	public function queue(\WP_REST_Request $request): \WP_REST_Response
	{
		switch ($request['action']) {
			case 'start':
				Queue::start(true);
				break;
			case 'retry':
				Library::move_status('failed', 'waiting');
				Queue::start(Queue::state()['all']);
				break;
			case 'reconvert':
				// Old files keep being served until each replacement is written.
				Library::move_status('done', 'waiting');
				Queue::start(true);
				break;
			case 'remove':
				Queue::start(false, 'remove');
				break;
			case 'pause':
				Queue::pause();
				break;
		}

		return new \WP_REST_Response(self::payload());
	}

	public function work(): \WP_REST_Response
	{
		$result = SelfTest::blocks_conversion() && 'remove' !== Queue::state()['mode']
			? ['busy' => false, 'processed' => 0, 'last' => '']
			: Queue::work(self::WORK_SECONDS);

		return new \WP_REST_Response(self::payload() + $result);
	}

	/**
	 * @return array{stats: array, queue: array, remaining: int, blocked: bool, generated: string, html: string}
	 */
	public static function payload(): array
	{
		$stats = Library::stats();
		$queue = Queue::state();

		if ('remove' === $queue['mode']) {
			$remaining = $queue['active'] ? Library::count_with_files() : 0;
		} else {
			$remaining = $stats['waiting'] + ($queue['all'] ? $stats['unconverted'] : 0);
		}

		return [
			'stats' => $stats,
			'queue' => $queue,
			'remaining' => $remaining,
			'blocked' => SelfTest::blocks_conversion(),
			'generated' => size_format($stats['generated'], 1),
			'html' => SettingsPage::library_body($stats),
		];
	}
}

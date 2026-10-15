<?php

namespace LeanImages;

if (!\defined('ABSPATH')) {
	exit;
}

/**
 * Wires the other classes together.
 */
final class Plugin
{

	private static ?Plugin $instance = null;

	private Editors $editors;
	private UploadCap $upload_cap;
	private Attachments $attachments;
	private Cleanup $cleanup;
	private Queue $queue;
	private Admin\Api $api;
	private Htaccess $htaccess;
	private SelfTest $self_test;
	private Admin\SettingsPage $settings_page;
	private Admin\MediaColumn $media_column;
	private Admin\Notices $notices;
	private Updater $updater;
	private UpdateInfo $update_info;

	public static function instance(): Plugin
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
		$this->editors = new Editors();
		$this->upload_cap = new UploadCap();
		$this->attachments = new Attachments();
		$this->cleanup = new Cleanup();
		$this->queue = new Queue();
		$this->api = new Admin\Api();
		$this->htaccess = new Htaccess();
		$this->self_test = new SelfTest();
		$this->settings_page = new Admin\SettingsPage();
		$this->media_column = new Admin\MediaColumn();
		$this->notices = new Admin\Notices();
		$this->updater = new Updater();
		$this->update_info = new UpdateInfo();
	}

	public function init(): void
	{
		// No load_plugin_textdomain(): the plugin ships no translations. Strings
		// still go through __() with the lean-images domain, so adding a
		// languages/ folder later only needs that call (hooked to init - WP 6.7+
		// flags earlier loading as doing-it-wrong).

		// Not admin-only: uploads also arrive through the REST API, admin-ajax,
		// WP-CLI and importers; the queue runs from WP-Cron; the Library panel
		// talks to the REST API; update checks run from WP-Cron and WP-CLI too -
		// none of which count as is_admin().
		$this->editors->register();
		$this->upload_cap->register();
		$this->attachments->register();
		$this->cleanup->register();
		$this->queue->register();
		$this->api->register();
		$this->updater->register();

		if (\defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('lean-images', Cli::class);
		}

		if (is_admin()) {
			$this->htaccess->register();
			$this->self_test->register();
			$this->settings_page->register();
			$this->media_column->register();
			$this->notices->register();
			$this->update_info->register();
		}
	}
}

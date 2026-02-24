<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Archive_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_archive_vote',         [Spolek_Hlasovani_MVP::class, 'handle_archive_vote']);
        add_action('admin_post_spolek_download_archive',     [Spolek_Hlasovani_MVP::class, 'handle_download_archive']);
        add_action('admin_post_spolek_purge_vote',           [Spolek_Hlasovani_MVP::class, 'handle_purge_vote']);
        add_action('admin_post_spolek_run_purge_scan',       [Spolek_Hlasovani_MVP::class, 'handle_run_purge_scan']);
        add_action('admin_post_spolek_run_close_scan',       [Spolek_Hlasovani_MVP::class, 'handle_run_close_scan']);
        add_action('admin_post_spolek_test_archive_storage', [Spolek_Hlasovani_MVP::class, 'handle_test_archive_storage']);
    }
}

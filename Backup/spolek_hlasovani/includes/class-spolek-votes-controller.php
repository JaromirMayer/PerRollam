<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Votes_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_create_vote', [Spolek_Hlasovani_MVP::class, 'handle_create_vote']);
        add_action('admin_post_spolek_cast_vote',   [Spolek_Hlasovani_MVP::class, 'handle_cast_vote']);
        add_action('admin_post_spolek_export_csv',  [Spolek_Hlasovani_MVP::class, 'handle_export_csv']);
    }
}

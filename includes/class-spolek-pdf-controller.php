<?php
if (!defined('ABSPATH')) exit;

final class Spolek_PDF_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_download_pdf',      [Spolek_PDF_Service::class, 'handle_admin_download_pdf']);
        add_action('admin_post_spolek_member_pdf',        [Spolek_PDF_Service::class, 'handle_member_pdf']);
        add_action('admin_post_nopriv_spolek_member_pdf', [Spolek_PDF_Service::class, 'handle_member_pdf']);
    }
}

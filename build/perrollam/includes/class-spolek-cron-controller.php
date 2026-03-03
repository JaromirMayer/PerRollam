<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Cron_Controller
 *
 * Manažerské „Run now“ akce pro cron/self-heal/scan.
 */
final class Spolek_Cron_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_run_self_heal',     [__CLASS__, 'handle_run_self_heal']);
        add_action('admin_post_spolek_run_archive_scan',  [__CLASS__, 'handle_run_archive_scan']);
        add_action('admin_post_spolek_run_reminder_scan', [__CLASS__, 'handle_run_reminder_scan']);
        add_action('admin_post_spolek_spawn_cron',        [__CLASS__, 'handle_spawn_cron']);
    }

    public static function handle_run_self_heal(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_run_self_heal');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Self_Heal')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Self_Heal.');
        }

        try {
            Spolek_Self_Heal::run('manual');
        } catch (Throwable $e) {
            Spolek_Admin::redirect_with_error($return_to, 'Self-heal selhal: ' . $e->getMessage());
        }

        Spolek_Admin::redirect_with_notice($return_to, 'Self-heal spuštěn.');
    }

    public static function handle_run_archive_scan(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_run_archive_scan');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'archive_scan')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Cron::archive_scan.');
        }

        try {
            Spolek_Cron::archive_scan();
        } catch (Throwable $e) {
            Spolek_Admin::redirect_with_error($return_to, 'Archive scan selhal: ' . $e->getMessage());
        }

        Spolek_Admin::redirect_with_notice($return_to, 'Archive scan spuštěn.');
    }

    public static function handle_run_reminder_scan(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_run_reminder_scan');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'reminder_scan')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Cron::reminder_scan.');
        }

        try {
            $stats = (array) Spolek_Cron::reminder_scan();
        } catch (Throwable $e) {
            Spolek_Admin::redirect_with_error($return_to, 'Reminder scan selhal: ' . $e->getMessage());
        }

        $msg = 'Reminder scan: zpracováno hlasování ' . (int)($stats['votes'] ?? 0)
             . ' (celkem reminder pokusů: ' . (int)($stats['total'] ?? 0) . ').';
        Spolek_Admin::redirect_with_notice($return_to, $msg);
    }

    public static function handle_spawn_cron(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_spawn_cron');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (function_exists('spawn_cron')) {
            spawn_cron();
            Spolek_Admin::redirect_with_notice($return_to, 'spawn_cron() zavoláno.');
        }

        Spolek_Admin::redirect_with_error($return_to, 'spawn_cron() není dostupné.');
    }
}

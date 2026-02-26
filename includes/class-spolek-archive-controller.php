<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Archive_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_archive_vote',         [__CLASS__, 'handle_archive_vote']);
        add_action('admin_post_spolek_download_archive',     [__CLASS__, 'handle_download_archive']);
        add_action('admin_post_spolek_purge_vote',           [__CLASS__, 'handle_purge_vote']);
        add_action('admin_post_spolek_run_purge_scan',       [__CLASS__, 'handle_run_purge_scan']);
        add_action('admin_post_spolek_run_close_scan',       [__CLASS__, 'handle_run_close_scan']);
        add_action('admin_post_spolek_test_archive_storage', [__CLASS__, 'handle_test_archive_storage']);
    }

    public static function handle_archive_vote(): void {
        Spolek_Admin::require_manager();

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        Spolek_Admin::verify_nonce_post('spolek_archive_vote_' . $vote_post_id);
        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_MANUAL_START, []);
        }

        if (!class_exists('Spolek_Archive')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Archive.');
        }

        $res = Spolek_Archive::archive_vote($vote_post_id, true);

        if (is_array($res) && !empty($res['ok'])) {
            if (class_exists('Spolek_Audit')) {
                $file = (string) get_post_meta($vote_post_id, Spolek_Config::META_ARCHIVE_FILE, true);
                Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_MANUAL_DONE, [
                    'file' => $file ?: null,
                ]);
            }
            Spolek_Admin::redirect_with_args($return_to, ['archived' => 1]);
        }

        $err = is_array($res) ? (string)($res['error'] ?? 'Archivace selhala') : 'Archivace selhala';
        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_MANUAL_FAIL, [
                'error' => $err,
            ]);
        }
        Spolek_Admin::redirect_with_error($return_to, $err);
    }

    public static function handle_download_archive(): void {
        Spolek_Admin::require_manager();

        // throttling – download je citlivý endpoint
        Spolek_Admin::throttle_or_die('download_archive', 60, 5 * MINUTE_IN_SECONDS);

        $file = isset($_GET['file']) ? (string) wp_unslash($_GET['file']) : '';
        $vote_post_id = isset($_GET['vote_post_id']) ? (int) $_GET['vote_post_id'] : 0;

        if ($file === '' && $vote_post_id) {
            $file = (string) get_post_meta($vote_post_id, Spolek_Config::META_ARCHIVE_FILE, true);
        }
        $file = sanitize_file_name(basename((string)$file));

        if ($file === '') wp_die('Neplatný soubor.');

        Spolek_Admin::verify_nonce_get('spolek_download_archive_' . $file);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id ?: 0, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_DOWNLOAD, [
                'file' => $file,
            ]);
        }

        if (!class_exists('Spolek_Archive')) {
            wp_die('Chybí Spolek_Archive.');
        }

        Spolek_Archive::send_archive($file);
    }

    public static function handle_purge_vote(): void {
        Spolek_Admin::require_manager();

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        Spolek_Admin::verify_nonce_post('spolek_purge_vote_' . $vote_post_id);

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::PURGE_MANUAL_START, []);
        }

        if (!class_exists('Spolek_Archive')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Archive.');
        }

        $res = Spolek_Archive::purge_vote($vote_post_id);

        if (is_array($res) && !empty($res['ok'])) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::PURGE_MANUAL_DONE, []);
            }
            Spolek_Admin::redirect_with_args($return_to, ['purged' => 1]);
        }

        $err = is_array($res) ? (string)($res['error'] ?? 'Purge selhal') : 'Purge selhal';
        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::PURGE_MANUAL_FAIL, [
                'error' => $err,
            ]);
        }
        Spolek_Admin::redirect_with_error($return_to, $err);
    }

    public static function handle_test_archive_storage(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_test_archive_storage');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Archive') || !method_exists('Spolek_Archive', 'test_write')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Archive::test_write.');
        }

        $res = [];
        try {
            $res = Spolek_Archive::test_write();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }

        $args = [
            'storage_test' => '1',
            'storage_test_ok' => !empty($res['ok']) ? '1' : '0',
        ];
        if (!empty($res['storage'])) $args['storage_test_storage'] = (string)$res['storage'];
        if (!empty($res['dir']))     $args['storage_test_dir']     = (string)$res['dir'];
        if (!empty($res['error']))   $args['storage_test_err']     = (string)$res['error'];

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log(0, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_STORAGE_TEST, [
                'ok'      => !empty($res['ok']) ? 1 : 0,
                'storage' => $res['storage'] ?? null,
                'dir'     => $res['dir'] ?? null,
                'error'   => $res['error'] ?? null,
            ]);
        }

        Spolek_Admin::redirect_with_args($return_to, $args);
    }

    public static function handle_run_close_scan(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_run_close_scan');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'close_scan')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Cron::close_scan.');
        }

        try {
            $stats = Spolek_Cron::close_scan();
        } catch (Throwable $e) {
            Spolek_Admin::redirect_with_error($return_to, 'Close scan selhal: ' . $e->getMessage());
        }

        $msg = 'Dohnání uzávěrek: zpracováno ' . (int)($stats['total'] ?? 0)
             . ' (tichý režim: ' . (int)($stats['silent'] ?? 0)
             . ', standard: ' . (int)($stats['normal'] ?? 0)
             . ', chyby: ' . (int)($stats['errors'] ?? 0) . ').';

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log(0, get_current_user_id(), Spolek_Audit_Events::CLOSE_SCAN_MANUAL, [
                'total'  => (int)($stats['total'] ?? 0),
                'silent' => (int)($stats['silent'] ?? 0),
                'normal' => (int)($stats['normal'] ?? 0),
                'errors' => (int)($stats['errors'] ?? 0),
            ]);
        }

        Spolek_Admin::redirect_with_notice($return_to, $msg);
    }

    public static function handle_run_purge_scan(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_run_purge_scan');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'purge_scan')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Cron::purge_scan.');
        }

        if (!class_exists('Spolek_Archive')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Archive.');
        }

        Spolek_Archive::ensure_storage();

        $before = 0;
        try {
            $items = Spolek_Archive::list_archives();
            $before = count(array_filter($items, static function($it){ return !empty($it['purged_at']); }));
        } catch (Throwable $e) {
            $before = 0;
        }

        try {
            Spolek_Cron::purge_scan();
        } catch (Throwable $e) {
            Spolek_Admin::redirect_with_error($return_to, 'Purge scan selhal: ' . $e->getMessage());
        }

        $after = $before;
        try {
            $items = Spolek_Archive::list_archives();
            $after = count(array_filter($items, static function($it){ return !empty($it['purged_at']); }));
        } catch (Throwable $e) {
            $after = $before;
        }

        $delta = max(0, (int)$after - (int)$before);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log(0, get_current_user_id(), Spolek_Audit_Events::PURGE_SCAN_MANUAL, [
                'purged' => (int)$delta,
            ]);
        }

        Spolek_Admin::redirect_with_args($return_to, [
            'purge_scan' => '1',
            'purge_scan_purged' => (string)$delta,
        ]);
    }
}

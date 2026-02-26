<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-post handlery pro "Nástroje".
 */
final class Spolek_Tools_Controller {

    public function register(): void {
        add_action('admin_post_spolek_tools_test_mail', [$this, 'handle_test_mail']);
        add_action('admin_post_spolek_tools_test_pdf',  [$this, 'handle_test_pdf']);
        add_action('admin_post_spolek_tools_cleanup_index', [$this, 'handle_cleanup_index']);
    }

    public function handle_test_mail(): void {
        if (!class_exists('Spolek_Admin')) { wp_die('Chybí Spolek_Admin.'); }
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_tools_test_mail');
        Spolek_Admin::throttle_or_die('tools_test_mail', 20, HOUR_IN_SECONDS);

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        $u = wp_get_current_user();
        $email = (string)($u->user_email ?? '');
        if ($email === '') {
            Spolek_Admin::redirect_with_error($return_to, 'Aktuální uživatel nemá e-mail.');
        }

        $ok = class_exists('Spolek_Tools') ? Spolek_Tools::send_test_mail($email) : false;

        if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
            Spolek_Audit::log(null, (int)get_current_user_id(), $ok ? Spolek_Audit_Events::TOOLS_TEST_MAIL_OK : Spolek_Audit_Events::TOOLS_TEST_MAIL_FAIL, [
                'to' => $email,
            ]);
        }

        if ($ok) {
            Spolek_Admin::redirect_with_notice($return_to, 'Test e-mail odeslán na: ' . $email);
        }

        Spolek_Admin::redirect_with_error($return_to, 'Test e-mail se nepodařilo odeslat (wp_mail vrátil false).');
    }

    public function handle_test_pdf(): void {
        if (!class_exists('Spolek_Admin')) { wp_die('Chybí Spolek_Admin.'); }
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_tools_test_pdf');
        Spolek_Admin::throttle_or_die('tools_test_pdf', 10, HOUR_IN_SECONDS);

        $res = class_exists('Spolek_Tools') ? Spolek_Tools::generate_test_pdf_bytes() : ['ok'=>false,'pdf'=>null,'error'=>'missing_tools'];

        $ok = !empty($res['ok']) && !empty($res['pdf']);

        if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
            Spolek_Audit::log(null, (int)get_current_user_id(), $ok ? Spolek_Audit_Events::TOOLS_TEST_PDF_OK : Spolek_Audit_Events::TOOLS_TEST_PDF_FAIL, [
                'error' => $ok ? null : (string)($res['error'] ?? 'unknown'),
            ]);
        }

        if (!$ok) {
            $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());
            Spolek_Admin::redirect_with_error($return_to, 'Test PDF se nepodařilo vygenerovat: ' . (string)($res['error'] ?? 'unknown'));
        }

        // Send PDF
        $pdf = (string)$res['pdf'];
        $fname = 'spolek-test-' . wp_date('Ymd-His', time(), wp_timezone()) . '.pdf';

        while (ob_get_level()) { @ob_end_clean(); }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function handle_cleanup_index(): void {
        if (!class_exists('Spolek_Admin')) { wp_die('Chybí Spolek_Admin.'); }
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_tools_cleanup_index');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        if (!class_exists('Spolek_Archive') || !method_exists('Spolek_Archive', 'cleanup_index')) {
            Spolek_Admin::redirect_with_error($return_to, 'Chybí Spolek_Archive::cleanup_index.');
        }

        $st = Spolek_Archive::cleanup_index(true);
        $msg = 'Čištění indexu hotovo. Skryto: ' . (int)($st['hidden_new'] ?? 0) . ', obnoveno: ' . (int)($st['unhidden'] ?? 0) . '.';
        Spolek_Admin::redirect_with_notice($return_to, $msg);
    }
}

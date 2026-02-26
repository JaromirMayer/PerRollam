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
        add_action('admin_post_spolek_tools_integration_tests', [$this, 'handle_integration_tests']);
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

    /** 6.5.1 – Minimální integrační testy. Výstup je jednoduchá HTML stránka s reportem. */
    public function handle_integration_tests(): void {
        if (!class_exists('Spolek_Admin')) { wp_die('Chybí Spolek_Admin.'); }
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_tools_integration_tests');
        Spolek_Admin::throttle_or_die('tools_it', 5, HOUR_IN_SECONDS);

        $u = wp_get_current_user();
        $uid = (int) get_current_user_id();
        $email = (string)($u->user_email ?? '');

        $res = class_exists('Spolek_Tools')
            ? (array) Spolek_Tools::run_integration_tests($uid, $email)
            : ['ok' => false, 'results' => [['key'=>'tools','ok'=>false,'message'=>'missing_tools']]];

        $ok = !empty($res['ok']);
        $rows = (array)($res['results'] ?? []);

        $back = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        $title = $ok ? 'Integrační testy: OK' : 'Integrační testy: FAIL';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title>'
              . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;}table{border-collapse:collapse;width:100%;max-width:900px;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background:#f6f7f7;} .ok{color:#0a7;} .fail{color:#b00;} .muted{opacity:.8}</style>'
              . '</head><body>';

        $html .= '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p class="muted">Plugin verze: ' . esc_html(defined('SPOLEK_HLASOVANI_VERSION') ? SPOLEK_HLASOVANI_VERSION : 'n/a') . '</p>';
        $html .= '<table><thead><tr><th>Test</th><th>Stav</th><th>Zpráva</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $k = (string)($r['key'] ?? '');
            $rok = !empty($r['ok']);
            $msg = (string)($r['message'] ?? '');
            $html .= '<tr>'
                  . '<td>' . esc_html($k) . '</td>'
                  . '<td class="' . ($rok ? 'ok' : 'fail') . '">' . ($rok ? '✅ OK' : '❌ FAIL') . '</td>'
                  . '<td>' . esc_html($msg) . '</td>'
                  . '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p style="margin-top:16px;"><a href="' . esc_url($back) . '">← zpět do portálu</a></p>';
        $html .= '</body></html>';

        while (ob_get_level()) { @ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}

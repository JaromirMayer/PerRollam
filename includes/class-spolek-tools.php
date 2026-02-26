<?php
if (!defined('ABSPATH')) exit;

/**
 * 6.4 – Tools + healthcheck helpers.
 *
 * Pozn.: UI je v portálu (Spolek_Portal_Renderer). Tady je jen logika.
 */
final class Spolek_Tools {

    /**
     * Spustí cleanup indexu archivů jen občas (best-effort), aby se index dlouhodobě "nezanáší".
     */
    public static function maybe_cleanup_archive_index(): void {
        if (!class_exists('Spolek_Archive') || !method_exists('Spolek_Archive', 'cleanup_index')) return;

        $key = 'spolek_arch_index_cleanup_daily';
        if (get_transient($key)) return;

        // 1× denně stačí
        set_transient($key, 1, DAY_IN_SECONDS);
        Spolek_Archive::cleanup_index(false);
    }

    /**
     * Jednoduchý healthcheck pro admin UX.
     * @return array<int,array{key:string,label:string,status:string,message:string}>
     */
    public static function healthcheck(): array {
        global $wpdb;

        $checks = [];

        // DB tabulky
        $tables = [
            'votes'    => Spolek_Config::table_votes(),
            'mail_log' => Spolek_Config::table_mail_log(),
            'audit'    => Spolek_Config::table_audit(),
        ];
        foreach ($tables as $k => $t) {
            $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            $checks[] = [
                'key' => 'db.' . $k,
                'label' => 'DB tabulka ' . $k,
                'status' => $exists ? 'ok' : 'error',
                'message' => $exists ? 'OK' : 'CHYBÍ (plugin activation / dbDelta)',
            ];
        }

        // WP-Cron
        $disable = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        $checks[] = [
            'key' => 'cron.disable_wp_cron',
            'label' => 'WP-Cron (DISABLE_WP_CRON)',
            'status' => $disable ? 'warn' : 'ok',
            'message' => $disable ? 'DISABLE_WP_CRON = true (doporučený server cron)' : 'WP-Cron povolen',
        ];

        // Cron schedule existence
        $hooks = [
            Spolek_Config::HOOK_CLOSE_SCAN,
            Spolek_Config::HOOK_REMINDER_SCAN,
            Spolek_Config::HOOK_ARCHIVE_SCAN,
            Spolek_Config::HOOK_PURGE_SCAN,
            Spolek_Config::HOOK_SELF_HEAL,
        ];
        foreach ($hooks as $h) {
            $next = (int) wp_next_scheduled($h);
            $checks[] = [
                'key' => 'cron.next.' . $h,
                'label' => 'Cron next: ' . $h,
                'status' => $next ? 'ok' : 'warn',
                'message' => $next ? wp_date('j.n.Y H:i', $next, wp_timezone()) : 'nenaplánováno (spusť Self-heal nebo aktivaci)',
            ];
        }

        // Archiv storage
        if (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'storage_status')) {
            $st = Spolek_Archive::storage_status();
            $primary = (string)($st['primary'] ?? '');
            $label = (string)($st['primary_label'] ?? $primary);

            $primary_ok = true;
            $checks_rows = (array)($st['checks'] ?? []);
            foreach ($checks_rows as $row) {
                if (!is_array($row)) continue;
                if ((string)($row['key'] ?? '') === $primary || (string)($row['storage'] ?? '') === $primary) {
                    $primary_ok = !empty($row['exists']) && !empty($row['writable']);
                }
            }

            $checks[] = [
                'key' => 'archive.storage',
                'label' => 'Úložiště archivů (primary)',
                'status' => $primary_ok ? 'ok' : 'warn',
                'message' => $label . ($primary_ok ? ' – OK' : ' – problém s přístupem/zápisem'),
            ];
        }

        // DOMPDF
        $dompdf_ok = self::ensure_dompdf();
        $checks[] = [
            'key' => 'pdf.dompdf',
            'label' => 'PDF engine (DOMPDF)',
            'status' => $dompdf_ok ? 'ok' : 'error',
            'message' => $dompdf_ok ? 'OK' : 'Nenačteno (vendor/autoload.php / composer)',
        ];

        return $checks;
    }

    /**
     * Test mailu (vrací true/false).
     */
    public static function send_test_mail(string $to_email): bool {
        $to_email = sanitize_email($to_email);
        if (!$to_email) return false;

        // Preferujeme mailer – zapisuje do mail_log tabulky (6.5.1).
        if (class_exists('Spolek_Mailer') && method_exists('Spolek_Mailer', 'send_test_logged')) {
            $res = (array) Spolek_Mailer::send_test_logged((int)get_current_user_id(), $to_email);
            return !empty($res['ok']);
        }

        // fallback
        $subject = '[Spolek] Test e-mailu (' . (defined('SPOLEK_HLASOVANI_VERSION') ? SPOLEK_HLASOVANI_VERSION : 'n/a') . ')';
        $body = "Toto je testovací e-mail z pluginu Spolek – Hlasování per rollam.\n\n"
              . 'Web: ' . home_url('/') . "\n"
              . 'Čas: ' . wp_date('j.n.Y H:i:s', time(), wp_timezone()) . "\n";

        return (bool) wp_mail($to_email, $subject, $body);
    }

    /**
     * 6.5.1 – Minimální integrační testy (PDF, mail_log, cron close).
     * Pozn.: test uzávěrky běží v silent režimu a vytvoří dočasné hlasování, které se pak uklidí.
     * @return array{ok:bool,results:array<int,array{key:string,ok:bool,message:string}>}
     */
    public static function run_integration_tests(int $user_id, string $email): array {
        global $wpdb;

        $user_id = (int)$user_id;
        $email = sanitize_email((string)$email);
        $results = [];

        if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
            Spolek_Audit::log(null, $user_id ?: null, Spolek_Audit_Events::TOOLS_IT_START, null);
        }

        // 1) PDF
        $pdf = self::generate_test_pdf_bytes();
        $pdf_ok = !empty($pdf['ok']) && !empty($pdf['pdf']);
        $results[] = [
            'key' => 'pdf',
            'ok' => $pdf_ok,
            'message' => $pdf_ok ? 'OK (DOMPDF generuje)' : ('FAIL: ' . (string)($pdf['error'] ?? 'unknown')),
        ];

        // 2) Mail + mail_log
        $mail_ok = false;
        $mail_msg = 'missing_mailer';
        if ($email && class_exists('Spolek_Mailer') && method_exists('Spolek_Mailer', 'send_test_logged')) {
            $r = (array) Spolek_Mailer::send_test_logged($user_id, $email);
            $mail_ok = !empty($r['ok']);
            $mail_msg = $mail_ok ? 'OK (odesláno + zapsáno do mail_log)' : ('FAIL (' . (string)($r['status'] ?? 'fail') . ')');

            // ověření, že řádek je v DB
            $t = Spolek_Config::table_mail_log();
            $st = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $t WHERE vote_post_id=%d AND user_id=%d AND mail_type=%s LIMIT 1",
                0, $user_id, 'test'
            ));
            if ($st === '') {
                $mail_ok = false;
                $mail_msg = 'FAIL: mail_log row not found';
            }
        } elseif (!$email) {
            $mail_msg = 'FAIL: user has no email';
        }
        $results[] = [
            'key' => 'mail_log',
            'ok' => $mail_ok,
            'message' => $mail_msg,
        ];

        // 3) Cron close (silent)
        $close_ok = false;
        $close_msg = 'missing_vote_processor';
        $tmp_post_id = 0;
        $tmp_pdf = '';
        $tmp_zip = '';

        if (class_exists('Spolek_Vote_Processor') && class_exists('Spolek_Config')) {
            $now = time();
            $tmp_post_id = wp_insert_post([
                'post_type'   => Spolek_Config::CPT,
                'post_status' => 'publish',
                'post_title'  => 'TEST – integrační uzávěrka ' . wp_date('Y-m-d H:i:s', $now, wp_timezone()),
            ], true);

            if (is_wp_error($tmp_post_id) || !$tmp_post_id) {
                $close_ok = false;
                $close_msg = 'FAIL: wp_insert_post';
            } else {
                $tmp_post_id = (int)$tmp_post_id;
                update_post_meta($tmp_post_id, Spolek_Config::META_TEXT, 'Testovací návrh – lze smazat.');
                update_post_meta($tmp_post_id, Spolek_Config::META_START_TS, (string)($now - 7200));
                update_post_meta($tmp_post_id, Spolek_Config::META_END_TS,   (string)($now - 3600));

                // zavřít v silent režimu (nesmí posílat maily)
                Spolek_Vote_Processor::close($tmp_post_id, true);

                $processed = (string) get_post_meta($tmp_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
                $label = (string) get_post_meta($tmp_post_id, Spolek_Config::META_RESULT_LABEL, true);
                $close_ok = ($processed !== '' && $label !== '');
                $close_msg = $close_ok ? 'OK (uzavřeno + vyhodnoceno)' : 'FAIL: close meta missing';

                $tmp_pdf = (string) get_post_meta($tmp_post_id, Spolek_Config::META_PDF_PATH, true);
                $tmp_zip_file = (string) get_post_meta($tmp_post_id, Spolek_Config::META_ARCHIVE_FILE, true);
                if ($tmp_zip_file !== '' && class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'locate')) {
                    $loc = (array) Spolek_Archive::locate($tmp_zip_file);
                    $tmp_zip = (string)($loc['path'] ?? '');
                }
            }
        }

        $results[] = [
            'key' => 'cron_close',
            'ok' => $close_ok,
            'message' => $close_msg,
        ];

        // Cleanup best-effort (aby se nezanáší web)
        if ($tmp_pdf && file_exists($tmp_pdf)) {
            @unlink($tmp_pdf);
        }
        if ($tmp_zip && file_exists($tmp_zip)) {
            @unlink($tmp_zip);
        }
        if ($tmp_post_id > 0) {
            wp_delete_post($tmp_post_id, true);
        }

        // smazat test mail log řádek (vote_post_id=0) – aby to nedělalo šum
        if ($user_id > 0) {
            $t = Spolek_Config::table_mail_log();
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $t WHERE vote_post_id=%d AND user_id=%d AND mail_type=%s",
                0, $user_id, 'test'
            ));
        }

        // schovat případný záznam v indexu (post už je smazán) – best-effort
        if (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'cleanup_index')) {
            Spolek_Archive::cleanup_index(false);
        }

        $ok = true;
        foreach ($results as $r) {
            if (empty($r['ok'])) { $ok = false; break; }
        }

        if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
            Spolek_Audit::log(null, $user_id ?: null, $ok ? Spolek_Audit_Events::TOOLS_IT_DONE : Spolek_Audit_Events::TOOLS_IT_FAIL, [
                'ok' => $ok,
                'results' => $results,
            ]);
        }

        return ['ok' => $ok, 'results' => $results];
    }

    /**
     * Vygeneruje testovací PDF (vrací bytes) nebo null.
     * @return array{ok:bool,pdf:?string,error:?string}
     */
    public static function generate_test_pdf_bytes(): array {
        if (!self::ensure_dompdf()) {
            return ['ok' => false, 'pdf' => null, 'error' => 'dompdf_missing'];
        }

        try {
            $tz = wp_timezone();
            $html = '<!doctype html><html><head><meta charset="utf-8">'
                . '<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;}h1{font-size:18px;margin:0 0 8px 0;}.muted{color:#555;}</style>'
                . '</head><body>'
                . '<h1>Test PDF – Spolek</h1>'
                . '<div class="muted">Web: ' . esc_html(home_url('/')) . '</div>'
                . '<div class="muted">Čas: ' . esc_html(wp_date('j.n.Y H:i:s', time(), $tz)) . '</div>'
                . '<p>Pokud vidíš tento soubor, generování PDF funguje.</p>'
                . '</body></html>';

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdf = $dompdf->output();
            if (!$pdf) {
                return ['ok' => false, 'pdf' => null, 'error' => 'dompdf_output_empty'];
            }

            return ['ok' => true, 'pdf' => $pdf, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'pdf' => null, 'error' => 'exception: ' . $e->getMessage()];
        }
    }

    // ===== internals =====

    private static function ensure_dompdf(): bool {
        if (class_exists('\\Dompdf\\Dompdf')) return true;

        if (defined('SPOLEK_HLASOVANI_PATH')) {
            $autoload = rtrim((string)SPOLEK_HLASOVANI_PATH, '/\\') . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }
        }

        return class_exists('\\Dompdf\\Dompdf');
    }
}

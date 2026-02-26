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

        $subject = '[Spolek] Test e-mailu (' . (defined('SPOLEK_HLASOVANI_VERSION') ? SPOLEK_HLASOVANI_VERSION : 'n/a') . ')';
        $body = "Toto je testovací e-mail z pluginu Spolek – Hlasování per rollam.\n\n"
              . 'Web: ' . home_url('/') . "\n"
              . 'Čas: ' . wp_date('j.n.Y H:i:s', time(), wp_timezone()) . "\n";

        return (bool) wp_mail($to_email, $subject, $body);
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

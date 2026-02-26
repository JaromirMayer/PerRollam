<?php
if (!defined('ABSPATH')) exit;

/**
 * 5.3 – Portál (UI render) vytažený z legacy.
 *
 * Cíl: class-spolek-legacy.php držet jen kompatibilní obálku a business logiku,
 * UI rendering (shortcode portálu) je tady.
 */
final class Spolek_Portal_Renderer {

    // Udržujeme stejné hodnoty jako legacy (backward-compat)
    private const CPT = Spolek_Config::CPT;
    private const TABLE = Spolek_Config::TABLE_VOTES;

    /** Vykreslí celý portál (shortcode). */
    public static function render_portal(): string {

        $assets = self::portal_assets();

        if (!is_user_logged_in()) {

            // návrat po přihlášení zpět na portál
            $redirect = self::portal_url();

            // login stránka /login (i když není "WP stránka", URL funguje)
            $login_base = home_url('/login/');
            $login_url  = add_query_arg('redirect_to', $redirect, $login_base);

            $out  = '<div class="spolek-login-box">';
            $out .= '<p>Sekce určená pro členy Spolku.</p>';
            $out .= '<p><a class="spolek-login-button" href="' . esc_url($login_url) . '">Přihlásit se</a></p>';
            $out .= '</div>';

            return '<div class="spolek-portal">' . $assets . $out . '</div>';
        }

        $out = '';

        // Flash zprávy (success/error) – jednotně nahoře
        $out .= self::render_flash_messages();

        // Detail? (preferujeme veřejný token v=...)
        $vote_id = 0;
        $public = isset($_GET['v']) ? sanitize_text_field(wp_unslash((string)$_GET['v'])) : '';
        if ($public !== '' && class_exists('Spolek_Vote_Service')) {
            $vote_id = (int) Spolek_Vote_Service::resolve_public_id($public);
        }

        if (!$vote_id) {
            $vote_id = (int) get_query_var('spolek_vote');
            if (!$vote_id && isset($_GET['spolek_vote'])) {
                $vote_id = (int) $_GET['spolek_vote'];
            }
        }
        if ($vote_id) {
            $out .= self::render_detail($vote_id);
            $out .= '<p><a href="' . esc_url(self::portal_url()) . '">← Zpět na seznam</a></p>';
            return '<div class="spolek-portal">' . $assets . $out . '</div>';
        }

        // Správce: formulář pro nové hlasování
        if (self::is_manager()) {
            $out .= self::render_create_form();
            $out .= self::render_section_sep();
        }

        // 1) Aktuální hlasování
        $out .= self::render_list();

        if (self::is_manager()) {
            // 2) Archiv uzavřených hlasování
            $out .= self::render_section_sep();
            $out .= self::render_archive_panel();

            // 3) Archivní ZIP soubory po smazání z DB
            $out .= self::render_section_sep();
            $out .= self::render_purged_archives_panel();

            // 6.4 – Nástroje + healthcheck
            $out .= self::render_section_sep();
            $out .= self::render_tools_panel();
        } else {
            // Uzavřená hlasování (pro členy – jen pro čtení)
            $out .= self::render_section_sep();
            $out .= self::render_closed_list();
        }

        return '<div class="spolek-portal">' . $assets . $out . '</div>';
    }

    /** Vizuální helpery portálu. */
    private static function portal_assets(): string {
        return '<style>
            .spolek-portal .spolek-section-sep{border:0;border-top:1px solid var(--spolek-accent,#2271b1);margin:14px 0;}
            .spolek-portal .spolek-login-box{max-width:720px;margin:12px 0;padding:16px;border:1px solid #ddd;background:#fff;}
            .spolek-portal .spolek-login-button{display:inline-block;padding:10px 16px;border-radius:6px;background:var(--spolek-accent,#2271b1);color:#fff;text-decoration:none;}
            .spolek-portal .spolek-login-button:hover{filter:brightness(.95);color:#fff;text-decoration:none;}
        </style>
        <script>
        document.addEventListener("DOMContentLoaded", function(){
            try {
                var root = document.documentElement;
                var btn = document.querySelector(".spolek-portal button, .spolek-portal .button");
                if (!btn) return;
                var c = window.getComputedStyle(btn).backgroundColor;
                if (c) root.style.setProperty("--spolek-accent", c);
            } catch(e) {}
        });
        </script>';
    }

    private static function render_section_sep(): string {
        return '<hr class="spolek-section-sep">';
    }

    /** Jednotné flash zprávy pro portál (notice/error). */
    private static function render_flash_messages(): string {
        $msgs = [];

        if (!empty($_GET['created'])) {
            $msgs[] = ['ok', 'Hlasování bylo vytvořeno.'];
        }
        if (!empty($_GET['voted'])) {
            $msgs[] = ['ok', 'Hlas byl uložen.'];
        }
        if (!empty($_GET['archived'])) {
            $msgs[] = ['ok', 'Archiv byl vytvořen.'];
        }
        if (!empty($_GET['purged'])) {
            $msgs[] = ['ok', 'Hlasování bylo smazáno z databáze (archivní ZIP zůstal uložen).'];
        }
        if (!empty($_GET['notice'])) {
            $msgs[] = ['ok', (string) sanitize_text_field(wp_unslash((string)$_GET['notice']))];
        }
        if (!empty($_GET['err'])) {
            $raw = (string) sanitize_text_field(wp_unslash((string)$_GET['err']));
            $msgs[] = ['err', self::humanize_error($raw)];
        }

        if (!$msgs) return '';

        $out = '';
        foreach ($msgs as $m) {
            $type = (string)($m[0] ?? 'ok');
            $text = (string)($m[1] ?? '');
            if ($text === '') continue;

            $bg = ($type === 'err') ? '#fff5f5' : '#f0f6ff';
            $bd = ($type === 'err') ? '#d63638' : '#2271b1';

            $out .= '<div style="margin:10px 0;padding:10px 12px;border:1px solid ' . esc_attr($bd) . ';background:' . esc_attr($bg) . ';border-radius:8px;">'
                 . '<strong>' . esc_html($text) . '</strong>'
                 . '</div>';
        }

        return $out;
    }

    /** Zlidštění technických chyb (6.4.3). */
    private static function humanize_error(string $raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') return 'Došlo k chybě.';

        // Typické technické chyby → lidské hlášky
        if (stripos($raw, 'Chybí Spolek_') !== false || stripos($raw, 'Chybí třída') !== false) {
            return 'Funkce není v této instalaci dostupná (chybí modul / soubor).';
        }
        if (stripos($raw, 'nonce') !== false) {
            return 'Neplatný bezpečnostní token (nonce). Obnov stránku a zkus to znovu.';
        }
        if (stripos($raw, 'Rate limit') !== false || stripos($raw, 'Příliš mnoho') !== false) {
            return 'Příliš mnoho požadavků v krátkém čase. Zkus to prosím za chvíli.';
        }

        // jinak necháme tak, jak je (už je většinou human-readable)
        return $raw;
    }

    private static function portal_url(): string {
        // aktuální URL stránky bez query
        return remove_query_arg([
            'v','spolek_vote','created','voted','err','export','archived','purged','purge_scan','purge_scan_purged',
            'notice','storage_test','storage_test_ok','storage_test_err','storage_test_storage','storage_test_dir','storage_test_err',
            // filtry seznamu archivů
            'arch_q','arch_state','arch_from','arch_to','arch_verify'
        ], home_url(add_query_arg([])));
    }

    /** Detail URL hlasování (preferuje v=... token). */
    private static function detail_url(int $vote_post_id): string {
        if (class_exists('Spolek_Vote_Service')) {
            return (string) Spolek_Vote_Service::vote_detail_url($vote_post_id);
        }
        return add_query_arg('spolek_vote', (int)$vote_post_id, self::portal_url());
    }

    /** 6.1.4 – Cron status (jen pro správce). */
    private static function render_cron_status_panel(string $heading_tag = 'h2'): string {
        if (!self::is_manager()) return '';

        $now = time();
        $tz = wp_timezone();

        $hooks = [
            Spolek_Config::HOOK_CLOSE_SCAN,
            Spolek_Config::HOOK_REMINDER_SCAN,
            Spolek_Config::HOOK_ARCHIVE_SCAN,
            Spolek_Config::HOOK_PURGE_SCAN,
            Spolek_Config::HOOK_SELF_HEAL,
        ];

        $disable_wp_cron = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);

        // poslední známý běh (jakýkoli hook)
        $last_any = 0;
        if (class_exists('Spolek_Cron_Status')) {
            foreach ($hooks as $h) {
                $st = Spolek_Cron_Status::get($h);
                $last_any = max($last_any, (int)($st['last'] ?? 0));
            }
        }

        // počty „co čeká“
        $overdue_closures = self::count_overdue_closures();
        $missing_archives = self::count_missing_archives();
        $purge_candidates = self::count_purge_candidates();
        $reminder_candidates = self::count_reminder_candidates();

        $action = esc_url(admin_url('admin-post.php'));

        $heading_tag = in_array($heading_tag, ['h2','h3','h4'], true) ? $heading_tag : 'h2';
        $html  = '<' . $heading_tag . '>Cron status</' . $heading_tag . '>';
        $html .= '<div style="opacity:.85;">Serverový čas: <strong>'
              . esc_html(wp_date('j.n.Y H:i:s', $now, $tz))
              . '</strong></div>';

        if ($disable_wp_cron) {
            $html .= '<p style="margin:10px 0;padding:10px;border:1px solid #d63638;background:#fff5f5;">'
                  . '<strong>DISABLE_WP_CRON = true</strong> → WordPress nebude spouštět cron přes návštěvy. '
                  . 'Doporučené je nastavit <em>server cron</em> na volání wp-cron.php.'
                  . '</p>';
        }

        if ($last_any > 0 && ($now - $last_any) > (2 * HOUR_IN_SECONDS)) {
            $html .= '<p style="margin:10px 0;padding:10px;border:1px solid #dba617;background:#fff8e5;">'
                  . 'Poslední zaznamenaný běh cron hooků je starší než 2 hodiny: <strong>'
                  . esc_html(wp_date('j.n.Y H:i', $last_any, $tz))
                  . '</strong>. Pokud to není očekávané, nastavte server cron (viz níže) nebo ověřte, že hosting WP-Cron neblokuje.'
                  . '</p>';
        }

        $html .= '<ul style="margin:10px 0 14px 18px;">'
              . '<li><strong>Čeká na uzávěrku:</strong> ' . (int)$overdue_closures . '</li>'
              . '<li><strong>Chybí archiv ZIP:</strong> ' . (int)$missing_archives . '</li>'
              . '<li><strong>Kandidáti na purge (' . (int)Spolek_Config::PURGE_RETENTION_DAYS . ' dní):</strong> ' . (int)$purge_candidates . '</li>'
              . '<li><strong>Kandidáti na reminder (do 48h):</strong> ' . (int)$reminder_candidates . '</li>'
              . '</ul>';

        // Tabulka hooků
        $html .= '<table style="width:100%;border-collapse:collapse;">'
              . '<thead><tr>'
              . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hook</th>'
              . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Schedule</th>'
              . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Next</th>'
              . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Last</th>'
              . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">State</th>'
              . '</tr></thead><tbody>';

        foreach ($hooks as $h) {
            $sched = function_exists('wp_get_schedule') ? (string) wp_get_schedule($h) : '';
            $next  = (int) wp_next_scheduled($h);
            $st = class_exists('Spolek_Cron_Status') ? Spolek_Cron_Status::get($h) : ['last'=>0,'last_ok'=>0,'last_error'=>0,'error_msg'=>''];

            $next_s = $next ? wp_date('j.n.Y H:i', $next, $tz) : '–';
            $last_s = !empty($st['last']) ? wp_date('j.n.Y H:i', (int)$st['last'], $tz) : '–';
            $state  = 'OK';
            if (!empty($st['last_error']) && (int)$st['last_error'] >= (int)($st['last_ok'] ?? 0)) {
                $state = 'ERROR';
                if (!empty($st['error_msg'])) {
                    $state .= ': ' . esc_html((string)$st['error_msg']);
                }
            }

            $html .= '<tr>'
                  . '<td style="border-bottom:1px solid #eee;padding:6px;"><code>' . esc_html($h) . '</code></td>'
                  . '<td style="border-bottom:1px solid #eee;padding:6px;">' . esc_html($sched ?: '–') . '</td>'
                  . '<td style="border-bottom:1px solid #eee;padding:6px;">' . esc_html($next_s) . '</td>'
                  . '<td style="border-bottom:1px solid #eee;padding:6px;">' . esc_html($last_s) . '</td>'
                  . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $state . '</td>'
                  . '</tr>';
        }

        $html .= '</tbody></table>';

        // Run now tlačítka
        $html .= '<h3 style="margin-top:14px;">Run now</h3>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_run_self_heal">'
            . wp_nonce_field('spolek_run_self_heal', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Self-heal</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_run_archive_scan">'
            . wp_nonce_field('spolek_run_archive_scan', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Archive scan</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_run_reminder_scan">'
            . wp_nonce_field('spolek_run_reminder_scan', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Reminder scan</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_spawn_cron">'
            . wp_nonce_field('spolek_spawn_cron', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">spawn_cron()</button>'
            . '</form>';

        $html .= '</div>';

        // Doporučený server cron
        $wp_cron_url = esc_url(site_url('/wp-cron.php?doing_wp_cron'));
        $html .= '<h3 style="margin-top:14px;">Doporučené nastavení server cron</h3>'
              . '<div style="opacity:.85;margin-bottom:6px;">Spusťte každých 5 minut (příklad):</div>'
              . '<pre style="background:#f6f7f7;border:1px solid #ddd;padding:10px;border-radius:8px;overflow:auto;">'
              . '*/5 * * * * curl -fsS "' . $wp_cron_url . '" > /dev/null 2>&1'
              . '</pre>';

        return $html;
    }

    /** 6.4.2 – Jedno místo pro Nástroje (test mailu/PDF, healthcheck, cron). */
    private static function render_tools_panel(): string {
        if (!self::is_manager()) return '';

        $action = esc_url(admin_url('admin-post.php'));

        $html  = '<h2>Nástroje</h2>';
        $html .= '<div style="opacity:.8;margin:6px 0 10px 0;">Testy a provozní diagnostika na jednom místě.</div>';

        // Healthcheck
        $checks = class_exists('Spolek_Tools') ? (array) Spolek_Tools::healthcheck() : [];
        if ($checks) {
            $html .= '<h3>Healthcheck</h3>';
            $html .= '<table style="width:100%;border-collapse:collapse;">'
                  . '<thead><tr>'
                  . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Kontrola</th>'
                  . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Stav</th>'
                  . '</tr></thead><tbody>';

            foreach ($checks as $c) {
                $label = (string)($c['label'] ?? ($c['key'] ?? ''));
                $status = (string)($c['status'] ?? 'ok');
                $msg = (string)($c['message'] ?? '');

                $icon = ($status === 'error') ? '❌' : (($status === 'warn') ? '⚠️' : '✅');
                $html .= '<tr>'
                      . '<td style="border-bottom:1px solid #eee;padding:6px;">' . esc_html($label) . '</td>'
                      . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $icon . ' ' . esc_html($msg) . '</td>'
                      . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        // Test tlačítka
        $html .= '<h3 style="margin-top:14px;">Testy</h3>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_tools_test_mail">'
            . wp_nonce_field('spolek_tools_test_mail', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Test e-mailu</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_tools_test_pdf">'
            . wp_nonce_field('spolek_tools_test_pdf', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Test PDF (stáhnout)</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_test_archive_storage">'
            . wp_nonce_field('spolek_test_archive_storage', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Test úložiště archivů</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_tools_cleanup_index">'
            . wp_nonce_field('spolek_tools_cleanup_index', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Vyčistit index archivů</button>'
            . '</form>';

        $html .= '<form method="post" action="'.$action.'" style="margin:0;">'
            . '<input type="hidden" name="action" value="spolek_tools_integration_tests">'
            . wp_nonce_field('spolek_tools_integration_tests', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Integrační testy (PDF + mail log + uzávěrka)</button>'
            . '</form>';

        $html .= '</div>';

        // Cron status (detail)
        $html .= '<details style="margin-top:14px;">'
              . '<summary style="cursor:pointer;font-weight:600;">Cron status (detail + Run now)</summary>'
              . '<div style="margin-top:10px;">' . self::render_cron_status_panel('h3') . '</div>'
              . '</details>';

        return $html;
    }

    private static function count_overdue_closures(): int {
        $now = time();
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Config::META_CLOSE_PROCESSED_AT,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
        return (int)($q->found_posts ?? 0);
    }

    private static function count_missing_archives(): int {
        $now = time();
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Config::META_CLOSE_PROCESSED_AT,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => Spolek_Config::META_ARCHIVE_FILE,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
        return (int)($q->found_posts ?? 0);
    }

    private static function count_purge_candidates(): int {
        $threshold = time() - ((int) Spolek_Config::PURGE_RETENTION_DAYS * DAY_IN_SECONDS);
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $threshold,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Config::META_CLOSE_PROCESSED_AT,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => Spolek_Config::META_ARCHIVE_FILE,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);
        return (int)($q->found_posts ?? 0);
    }

    private static function count_reminder_candidates(): int {
        $now = time();
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_START_TS,
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now + (48 * HOUR_IN_SECONDS),
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);
        return (int)($q->found_posts ?? 0);
    }

    private static function is_manager(): bool {
        return class_exists('Spolek_Admin') ? Spolek_Admin::is_manager() : (is_user_logged_in() && current_user_can('manage_options'));
    }

    /** @return array{0:int,1:int,2:string} */
    private static function vote_meta(int $post_id): array {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_vote_meta($post_id)
            : [(int) get_post_meta($post_id, Spolek_Config::META_START_TS, true), (int) get_post_meta($post_id, Spolek_Config::META_END_TS, true), (string) get_post_meta($post_id, Spolek_Config::META_TEXT, true)];
    }

    private static function vote_status(int $start_ts, int $end_ts): string {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_status($start_ts, $end_ts)
            : 'closed';
    }

    private static function user_has_voted(int $vote_post_id, int $user_id): bool {
        if (class_exists('Spolek_Votes')) {
            return Spolek_Votes::has_user_voted($vote_post_id, $user_id);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE vote_post_id=%d AND user_id=%d LIMIT 1",
            $vote_post_id, $user_id
        ));
        return !empty($exists);
    }

    private static function render_create_form(): string {
        $action = esc_url(admin_url('admin-post.php'));
        $now = time();
        $default_end = $now + (7 * DAY_IN_SECONDS);

        $html  = '<h2>Nové hlasování</h2>';
        // flash zprávy se zobrazují jednotně nahoře

        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_create_vote">';
        $html .= wp_nonce_field('spolek_create_vote', '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';

        $html .= '<p><label>Název (interní):<br><input required type="text" name="title" style="width:100%"></label></p>';
        $html .= '<p><label>Plné znění návrhu usnesení:<br><textarea required name="text" rows="8" style="width:100%"></textarea></label></p>';

        $html .= '<p><label>Start (YYYY-MM-DD HH:MM) – serverový čas:<br><input required type="text" name="start" value="'.esc_attr(wp_date('Y-m-d H:i', $now)).'"></label></p>';
        $html .= '<p><label>Deadline (YYYY-MM-DD HH:MM):<br><input required type="text" name="end" value="'.esc_attr(wp_date('Y-m-d H:i', $default_end)).'"></label></p>';

        // 3.8 – vyhodnocení (ruleset/quorum/pass/base)
        $html .= '<h3>Vyhodnocení (3.8)</h3>';

        $html .= '<p><label>Typ hlasování:<br>'
        . '<select name="ruleset" id="spolek_ruleset">'
        . '<option value="standard" selected>Standard (většina ANO &gt; NE)</option>'
        . '<option value="two_thirds">2/3 většina</option>'
        . '</select>'
        . '</label></p>';

        $html .= '<p><label>Kvórum účasti (% všech členů, 0 = bez kvóra):<br>'
        . '<input type="number" name="quorum_ratio" id="spolek_quorum_ratio" min="0" max="100" step="0.01" value="0" style="width:120px;">'
        . '</label></p>';

        $html .= '<p><label>Poměr pro přijetí – ANO (%):<br>'
        . '<input type="number" name="pass_ratio" id="spolek_pass_ratio" min="0" max="100" step="0.01" value="50" style="width:120px;"> '
        . '<span style="opacity:.8;">(50 = většina, 66.67 = dvě třetiny)</span>'
        . '</label></p>';

        $html .= '<p><label>Základ pro výpočet poměru:<br>'
        . '<select name="pass_base" id="spolek_pass_base">'
        . '<option value="valid" selected>Platné hlasy (ANO + NE)</option>'
        . '<option value="all">Všichni členové</option>'
        . '</select>'
        . '</label></p>';

        $html .= '<script>
        (function(){
        var r = document.getElementById("spolek_ruleset");
        var q = document.getElementById("spolek_quorum_ratio");
        var p = document.getElementById("spolek_pass_ratio");
        var b = document.getElementById("spolek_pass_base");
        if(!r||!q||!p||!b) return;

        r.addEventListener("change", function(){
        if (r.value === "standard") { q.value = 0; p.value = 50; b.value = "valid"; }
        if (r.value === "two_thirds") { q.value = 50; p.value = 66.67; b.value = "valid"; }
        });
        })();
        </script>';

        $html .= '<p><button type="submit">Vyhlásit hlasování</button></p>';
        $html .= '<p style="opacity:.8;">Volby jsou pevně: <strong>ANO / NE / ZDRŽEL SE</strong>. Po odeslání už nelze hlas změnit.</p>';
        $html .= '</form>';

        return $html;
    }

    private static function render_list(): string {
        $now = time();
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'meta_value_num',
            'meta_key' => Spolek_Config::META_END_TS,
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        $html = '<h2>Aktuální hlasování</h2>';
        if (!$q->have_posts()) {
            return $html . '<p>Zatím není vyhlášeno žádné hlasování.</p>';
        }

        $posts = (array)($q->posts ?? []);
        $ids = [];
        foreach ($posts as $p) {
            if (is_object($p) && !empty($p->ID)) $ids[] = (int)$p->ID;
        }

        $counts_bulk = class_exists('Spolek_Votes') ? Spolek_Votes::get_counts_bulk($ids) : [];




        $members_total = class_exists('Spolek_Vote_Service') ? count(Spolek_Vote_Service::get_members()) : 0;
        $tz = wp_timezone();

        // ČLEN – jednoduchý seznam
        if (!self::is_manager()) {
            $html .= '<ul>';
            foreach ($ids as $id) {
                [$start_ts, $end_ts] = self::vote_meta($id);
                $status = self::vote_status($start_ts, $end_ts);
                $label = $status === 'open' ? 'Otevřené' : ($status === 'closed' ? 'Ukončené' : 'Připravované');
                $link = self::detail_url($id);

                $html .= '<li>';
                $html .= '<a href="'.esc_url($link).'">' . esc_html(get_the_title($id)) . '</a>';
                $html .= ' — <em>' . esc_html($label) . '</em>';
                if ($start_ts && $end_ts) {
                    if ($status === 'open') {
                        $left = human_time_diff($now, $end_ts);
                        $html .= ' (končí ' . esc_html(wp_date('j.n.Y H:i', $end_ts, $tz)) . ', zbývá ' . esc_html($left) . ')';
                    } else {
                        $html .= ' (od ' . esc_html(wp_date('j.n.Y H:i', $start_ts, $tz)) . ' do ' . esc_html(wp_date('j.n.Y H:i', $end_ts, $tz)) . ')';
                    }
                }
                $html .= '</li>';
            }
            $html .= '</ul>';

            wp_reset_postdata();
            return $html;
        }

        // SPRÁVCE – přehledová tabulka (6.4.1)
        $html .= '<table style="width:100%;border-collapse:collapse;">'
            . '<thead><tr>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hlasování</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Stav</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Deadline</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hlasy</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Kvórum</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Pass</th>'
            . '</tr></thead><tbody>';

        foreach ($ids as $id) {
            [$start_ts, $end_ts] = self::vote_meta($id);
            $status = self::vote_status($start_ts, $end_ts);
            $status_label = ($status === 'open') ? 'Otevřené' : (($status === 'upcoming') ? 'Připravované' : 'Ukončené');

            $counts = $counts_bulk[$id] ?? ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];
            $eval = class_exists('Spolek_Vote_Service')
                ? Spolek_Vote_Service::evaluate_vote($id, $counts)
                : ['participated'=>((int)$counts['ANO']+(int)$counts['NE']+(int)$counts['ZDRZEL']), 'members_total'=>$members_total, 'quorum_required'=>0, 'quorum_met'=>true, 'yes'=>(int)$counts['ANO'], 'yes_needed'=>PHP_INT_MAX];

            $deadline = $end_ts ? wp_date('j.n.Y H:i', $end_ts, $tz) : '–';
            if ($status === 'open' && $end_ts) {
                $deadline .= ' <span style="opacity:.75;">(zbývá ' . esc_html(human_time_diff($now, $end_ts)) . ')</span>';
            } elseif ($status === 'upcoming' && $start_ts) {
                $deadline = wp_date('j.n.Y H:i', $start_ts, $tz) . ' <span style="opacity:.75;">(za ' . esc_html(human_time_diff($now, $start_ts)) . ')</span>';
            }

            $yes = (int)($counts['ANO'] ?? 0);
            $no  = (int)($counts['NE'] ?? 0);
            $ab  = (int)($counts['ZDRZEL'] ?? 0);
            $part = (int)($eval['participated'] ?? ($yes+$no+$ab));

            $votes_cell = 'ANO ' . $yes . ' / NE ' . $no . ' / ZDRŽEL ' . $ab . ' <span style="opacity:.75;">(celkem ' . $part . ')</span>';

            $qr = (int)($eval['quorum_required'] ?? 0);
            $qm = !empty($eval['quorum_met']);
            $quorum_cell = ($qr > 0)
                ? ($part . '/' . $qr . ' ' . ($qm ? '✅' : '❌'))
                : ('bez kvóra <span style="opacity:.75;">(' . $part . '/' . (int)($eval['members_total'] ?? $members_total) . ')</span>');

            $yn = (int)($eval['yes_needed'] ?? PHP_INT_MAX);
            $pass_ok = ($qm && $yes >= $yn);
            $pass_cell = ($yn === PHP_INT_MAX)
                ? '–'
                : ($yes . '/' . $yn . ' ' . ($pass_ok ? '✅' : '❌'));

            $link = self::detail_url($id);
            $html .= '<tr>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'
                . '<a href="'.esc_url($link).'">' . esc_html(get_the_title($id)) . '</a>'
                . '</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">' . esc_html($status_label) . '</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $deadline . '</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $votes_cell . '</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $quorum_cell . '</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">' . $pass_cell . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        wp_reset_postdata();

        return $html;
    }

    /** 4.2 – Archiv uzavřených hlasování (jen pro správce). */
    private static function render_archive_panel(): string {
        if (!self::is_manager()) return '';

        $html = '<h2>Archiv uzavřených hlasování</h2>';

        // flash zprávy se zobrazují jednotně nahoře

        $run_action = esc_url(admin_url('admin-post.php'));
        $html .= '<form method="post" action="'.$run_action.'" style="margin:8px 0 12px 0;">'
            . '<input type="hidden" name="action" value="spolek_run_close_scan">'
            . wp_nonce_field('spolek_run_close_scan', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Dohnat uzávěrky (starší hlasování)</button>'
            . '<div style="margin-top:6px;opacity:.75;font-size:12px;">Zpracuje max ' . (int)Spolek_Config::CLOSE_SCAN_LIMIT . ' ukončených hlasování, která stále čekají na cron. Hlasování uzavřená před více než ' . (int)Spolek_Config::SILENT_AFTER_DAYS . ' dny dožene v tichém režimu (bez rozesílky e-mailů), ale vytvoří výsledek, PDF a archiv ZIP.</div>'
            . '</form>';

        if (!class_exists('Spolek_Archive')) {
            return $html . '<p style="color:#b00;">Chybí třída Spolek_Archive (soubor include). Archivace není dostupná.</p>';
        }

        Spolek_Archive::ensure_storage();

        $now = time();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            $html .= '<p>Zatím nejsou žádná ukončená hlasování.</p>';
        } else {
            $posts = (array)($q->posts ?? []);
            $ids = [];
            foreach ($posts as $p) {
                if (is_object($p) && !empty($p->ID)) $ids[] = (int)$p->ID;
            }
            $counts_bulk = class_exists('Spolek_Votes') ? Spolek_Votes::get_counts_bulk($ids) : [];



// Bulk purge (UX): checkboxy + hromadné smazání z DB
$bulk_form_id = 'spolek-archive-bulk-purge-form';
$bulk_action = esc_url(admin_url('admin-post.php'));
$html .= '<form id="'.esc_attr($bulk_form_id).'" method="post" action="'.$bulk_action.'" style="margin:8px 0 10px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm(\'Opravdu smazat vybraná hlasování z databáze? Archivní ZIPy zůstanou uloženy.\');">';
$html .= '<input type="hidden" name="action" value="spolek_purge_votes">';
$html .= wp_nonce_field('spolek_purge_votes', '_nonce', true, false);
$html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';
$html .= '<button type="submit" class="button button-secondary" disabled data-spolek-bulk-btn="1">Smazat vybrané z DB</button>';
$html .= '<span style="opacity:.8;font-size:12px;" data-spolek-bulk-count="1">Vybráno: 0</span>';
$html .= '<span style="opacity:.75;font-size:12px;">(lze jen když je uložen ZIP a sedí SHA)</span>';
$html .= '</form>';

$html .= '<script>(function(){'
    . 'function qsa(sel){return Array.prototype.slice.call(document.querySelectorAll(sel));}'
    . 'function init(){'
        . 'var form=document.getElementById("spolek-archive-bulk-purge-form"); if(!form) return;'
        . 'var btn=form.querySelector("[data-spolek-bulk-btn]");'
        . 'var cnt=form.querySelector("[data-spolek-bulk-count]");'
        . 'var all=document.getElementById("spolek-archive-select-all");'
        . 'function update(){'
            . 'var cbs=qsa("input.spolek-archive-bulk");'
            . 'var n=0; cbs.forEach(function(cb){ if(cb.checked) n++; });'
            . 'if(btn) btn.disabled = (n===0);'
            . 'if(cnt) cnt.textContent = "Vybráno: " + n;'
            . 'if(all){'
                . 'var enabled=cbs.filter(function(cb){return !cb.disabled;});'
                . 'var checked=enabled.filter(function(cb){return cb.checked;});'
                . 'all.indeterminate = (checked.length>0 && checked.length<enabled.length);'
                . 'all.checked = (enabled.length>0 && checked.length===enabled.length);'
            . '}'
        . '}'
        . 'qsa("input.spolek-archive-bulk").forEach(function(cb){ cb.addEventListener("change",update); });'
        . 'if(all){'
            . 'all.addEventListener("change",function(){'
                . 'qsa("input.spolek-archive-bulk").forEach(function(cb){ if(!cb.disabled) cb.checked = all.checked; });'
                . 'update();'
            . '});'
        . '}'
        . 'update();'
    . '}'
    . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);} else {init();}'
. '})();</script>';

            $html .= '<table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;width:1%;"><input type="checkbox" id="spolek-archive-select-all" title="Vybrat vše"></th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hlasování</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Ukončeno</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Zpracováno</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Výsledek</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hlasy</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Archiv</th>'
                . '</tr></thead><tbody>';

            while ($q->have_posts()) {
                $q->the_post();
                $id = get_the_ID();
                [$start_ts, $end_ts] = self::vote_meta($id);

                $processed_at = (string) get_post_meta($id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
                $file = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_FILE, true);
                $sha  = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_SHA256, true);
                $err  = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_ERROR, true);

$processed_ok = ($processed_at !== '');
$file_base = basename((string)$file);
$has_file = false;
if ($processed_ok && $file_base !== '') {
    $path = Spolek_Archive::locate_path($file_base);
    if ($path && is_file($path)) $has_file = true;
}


                $end_label = $end_ts ? wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) : '–';
                $proc_label = $processed_at ? wp_date('j.n.Y H:i', (int)$processed_at, wp_timezone()) : '–';

                $counts = $counts_bulk[$id] ?? ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];
                $yes = (int)($counts['ANO'] ?? 0);
                $no  = (int)($counts['NE'] ?? 0);
                $ab  = (int)($counts['ZDRZEL'] ?? 0);

                $res_label = (string) get_post_meta($id, Spolek_Config::META_RESULT_LABEL, true);
                if ($res_label === '' && class_exists('Spolek_Vote_Service')) {
                    $ev = Spolek_Vote_Service::evaluate_vote($id, $counts);
                    $res_label = (string)($ev['label'] ?? '');
                }

                $detail_link = self::detail_url($id);



$bulk_cb = '';
if (!$processed_ok) {
    $bulk_cb = '<input type="checkbox" disabled title="Čeká na uzávěrku (cron).">';
} elseif (!$has_file) {
    $bulk_cb = '<input type="checkbox" disabled title="Chybí archiv ZIP.">';
} else {
    $bulk_cb = '<input type="checkbox" class="spolek-archive-bulk" name="vote_post_ids[]" value="'.(int)$id.'" form="spolek-archive-bulk-purge-form">';
}
                $html .= '<tr>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">' . $bulk_cb . '</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'
                    . '<a href="'.esc_url($detail_link).'">'.esc_html(get_the_title()).'</a>'
                    . '</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($end_label).'</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($proc_label).'</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($res_label ?: '–').'</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">ANO '.(int)$yes.' / NE '.(int)$no.' / ZDRŽEL '.(int)$ab.'</td>';

                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">';

                
// pokud ještě není uzávěrka hotová, nearchivujeme
if (!$processed_ok) {
    $html .= '<span style="opacity:.8;">Čeká na uzávěrku (cron).</span>';
} else {
    if ($has_file) {
        $file = $file_base;

        $dl = admin_url('admin-post.php');
        $dl = add_query_arg([
            'action' => 'spolek_download_archive',
            'file'   => $file,
            '_nonce' => wp_create_nonce('spolek_download_archive_' . $file),
        ], $dl);

        $purge_action = esc_url(admin_url('admin-post.php'));

        $html .= '<a class="button" href="'.esc_url($dl).'">Stáhnout archiv ZIP</a> ';

        $html .= '<form method="post" action="'.$purge_action.'" style="display:inline-block;margin-left:8px;" onsubmit="return confirm(\'Opravdu smazat hlasování #' . (int)$id . ' z databáze? Archivní ZIP zůstane uložen.\');">';
        $html .= '<input type="hidden" name="action" value="spolek_purge_vote">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$id.'">';
        $html .= wp_nonce_field('spolek_purge_vote_'.$id, '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';
        $html .= '<button type="submit">Smazat z DB</button>';
        $html .= '</form>';

        if ($sha) {
            $html .= '<div style="margin-top:4px;opacity:.75;font-size:12px;">SHA256: '.esc_html($sha).'</div>';
        }
    } else {
        // vytvořit archiv
        $archive_action = esc_url(admin_url('admin-post.php'));
        $html .= '<form method="post" action="'.$archive_action.'" style="display:inline-block;">';
        $html .= '<input type="hidden" name="action" value="spolek_archive_vote">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$id.'">';
        $html .= wp_nonce_field('spolek_archive_vote_'.$id, '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';
        $html .= '<button type="submit">Zálohovat nyní</button>';
        $html .= '</form>';

        if ($err) {
            $html .= '<div style="margin-top:4px;color:#b00;font-size:12px;">Chyba archivace: '.esc_html($err).'</div>';
        } else {
            $html .= '<div style="margin-top:4px;opacity:.75;font-size:12px;">Automaticky se pokusí vytvořit i cron po uzávěrce.</div>';
        }
    }
}

$html .= '</td>';
                $html .= '</tr>';
            }
            wp_reset_postdata();

            $html .= '</tbody></table>';
        }

        return $html;
    }

    /**
     * 6.3.2 – Archivní ZIP soubory (index)
     * Cíl: seznam archivů (včetně těch po purge), filtry, stažení a validace existence.
     */
    private static function render_purged_archives_panel(): string {
        if (!self::is_manager()) return '';

        // 6.4.4 – auto-clean index (best-effort)
        if (class_exists('Spolek_Tools')) {
            Spolek_Tools::maybe_cleanup_archive_index();
        }

        $html = '<h2>Archivní ZIP soubory (index)</h2>';
        $html .= '<div style="opacity:.8;margin:6px 0 10px 0;">'
            . 'Pravidla: <strong>Silent režim</strong> při dohánění uzávěrek po ' . (int)Spolek_Config::SILENT_AFTER_DAYS . ' dnech, '
            . '<strong>retence DB</strong> ' . (int)Spolek_Config::PURGE_RETENTION_DAYS . ' dní (pak lze bezpečně smazat z DB, ZIP zůstává uložen).'
            . '</div>';

        // Diagnostika úložiště archivů
        if (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'storage_status')) {
            $st = Spolek_Archive::storage_status();

            // Výsledek testu zápisu
            if (!empty($_GET['storage_test'])) {
                $ok = !empty($_GET['storage_test_ok']);
                $msg = $ok ? 'Test zápisu: OK ✅' : 'Test zápisu: CHYBA ❌';
                if (!$ok && !empty($_GET['storage_test_err'])) {
                    $msg .= ' – ' . esc_html((string)$_GET['storage_test_err']);
                }
                $html .= '<p><strong>' . $msg . '</strong></p>';
            }

            $html .= '<div style="padding:10px 12px;border:1px solid rgba(0,0,0,.12);border-radius:8px;margin:10px 0 12px 0;">';
            $html .= '<div style="font-weight:600;margin-bottom:6px;">Úložiště archivních ZIPů</div>';
            $html .= '<div style="opacity:.85;margin-bottom:8px;">Primární režim: <strong>' . esc_html((string)($st['primary_label'] ?? ($st['primary'] ?? ''))) . '</strong></div>';

            $root_dir = (string)($st['root_dir'] ?? '');
            if ($root_dir !== '') {
                $html .= '<div style="opacity:.75;font-size:12px;margin-bottom:8px;">Cesta: <code>' . esc_html($root_dir) . '</code></div>';
            }

            $checks = (array)($st['checks'] ?? []);
            if ($checks) {
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                $html .= '<tr><th style="text-align:left;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Varianta</th>'
                    . '<th style="text-align:left;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Adresář</th>'
                    . '<th style="text-align:center;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Existuje</th>'
                    . '<th style="text-align:center;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Zápis</th></tr>';

                foreach ($checks as $k => $row) {
                    $dir = (string)($row['dir'] ?? '');
                    $exists = !empty($row['exists']);
                    $writable = !empty($row['writable']);
                    $label = (string)($row['label'] ?? $k);

                    $html .= '<tr>'
                        . '<td style="padding:6px 4px;vertical-align:top;"><strong>' . esc_html($label) . '</strong></td>'
                        . '<td style="padding:6px 4px;vertical-align:top;"><code style="font-size:12px;">' . esc_html($dir) . '</code></td>'
                        . '<td style="padding:6px 4px;text-align:center;">' . ($exists ? '✅' : '❌') . '</td>'
                        . '<td style="padding:6px 4px;text-align:center;">' . ($writable ? '✅' : '❌') . '</td>'
                        . '</tr>';
                }

                $html .= '</table>';
            }

            $test_action = esc_url(admin_url('admin-post.php'));
            $html .= '<form method="post" action="'.$test_action.'" style="margin-top:10px;">'
                . '<input type="hidden" name="action" value="spolek_test_archive_storage">'
                . wp_nonce_field('spolek_test_archive_storage', '_nonce', true, false)
                . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
                . '<button type="submit">Otestovat zápis do úložiště</button>'
                . '<span style="margin-left:10px;opacity:.75;font-size:12px;">(zapíše a smaže malý testovací soubor v primární lokaci)</span>'
                . '</form>';

            $html .= '</div>';
        }

        // Ruční spuštění purge scanu
        $purged_n = isset($_GET['purge_scan_purged']) ? (int)$_GET['purge_scan_purged'] : null;
        if (!empty($_GET['purge_scan'])) {
            if ($purged_n !== null) {
                $html .= '<p><strong>Purge scan dokončen.</strong> Smazáno z DB: ' . (int)$purged_n . '.</p>';
            } else {
                $html .= '<p><strong>Purge scan dokončen.</strong></p>';
            }
        }

        $run_action = esc_url(admin_url('admin-post.php'));
        $html .= '<form method="post" action="'.$run_action.'" style="margin:8px 0 12px 0;">'
            . '<input type="hidden" name="action" value="spolek_run_purge_scan">'
            . wp_nonce_field('spolek_run_purge_scan', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Spustit automatické mazání (' . (int)Spolek_Config::PURGE_RETENTION_DAYS . ' dní)</button>'
            . '<div style="margin-top:6px;opacity:.75;font-size:12px;">Smaže max ' . (int)Spolek_Config::PURGE_SCAN_LIMIT . ' hlasování, která jsou uzavřená déle než ' . (int)Spolek_Config::PURGE_RETENTION_DAYS . ' dní a mají archivní ZIP (ověří SHA256). Maže i audit.</div>'
            . '</form>';

        if (!class_exists('Spolek_Archive')) {
            return $html . '<p style="color:#b00;">Chybí třída Spolek_Archive (soubor include). Archivace není dostupná.</p>';
        }

        Spolek_Archive::ensure_storage();

        // ===== filtry =====
        $arch_q = isset($_GET['arch_q']) ? sanitize_text_field(wp_unslash((string)$_GET['arch_q'])) : '';
        $arch_state = isset($_GET['arch_state']) ? sanitize_key(wp_unslash((string)$_GET['arch_state'])) : 'all';
        if (!in_array($arch_state, ['all','active','purged','missing','hidden'], true)) $arch_state = 'all';

        $arch_from = isset($_GET['arch_from']) ? sanitize_text_field(wp_unslash((string)$_GET['arch_from'])) : '';
        $arch_to   = isset($_GET['arch_to'])   ? sanitize_text_field(wp_unslash((string)$_GET['arch_to']))   : '';
        $arch_verify = !empty($_GET['arch_verify']);

        $from_ts = 0;
        $to_ts = 0;
        if ($arch_from !== '') {
            $t = strtotime($arch_from . ' 00:00:00');
            if ($t !== false) $from_ts = (int)$t;
        }
        if ($arch_to !== '') {
            $t = strtotime($arch_to . ' 23:59:59');
            if ($t !== false) $to_ts = (int)$t;
        }

        // Filter form (GET)
        $html .= '<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:12px 0 10px 0;">'
            . '<div><div style="font-size:12px;opacity:.75;">Hledat</div><input type="text" name="arch_q" value="'.esc_attr($arch_q).'" placeholder="název / soubor" style="min-width:220px;"></div>'
            . '<div><div style="font-size:12px;opacity:.75;">Stav</div><select name="arch_state">'
                . '<option value="all"'.selected($arch_state,'all',false).'>Vše</option>'
                . '<option value="active"'.selected($arch_state,'active',false).'>V DB</option>'
                . '<option value="purged"'.selected($arch_state,'purged',false).'>Po purge</option>'
                . '<option value="missing"'.selected($arch_state,'missing',false).'>Chybí soubor</option>'
                . '<option value="hidden"'.selected($arch_state,'hidden',false).'>Skryté</option>'
              . '</select></div>'
            . '<div><div style="font-size:12px;opacity:.75;">Od (YYYY-MM-DD)</div><input type="text" name="arch_from" value="'.esc_attr($arch_from).'" placeholder="2026-01-01" style="width:120px;"></div>'
            . '<div><div style="font-size:12px;opacity:.75;">Do (YYYY-MM-DD)</div><input type="text" name="arch_to" value="'.esc_attr($arch_to).'" placeholder="2026-12-31" style="width:120px;"></div>'
            . '<label style="display:flex;gap:6px;align-items:center;margin-bottom:2px;"><input type="checkbox" name="arch_verify" value="1"'.checked($arch_verify,true,false).'> ověřit SHA</label>'
            . '<div><button type="submit">Filtrovat</button> '
            . '<a href="'.esc_url(self::portal_url()).'" style="margin-left:6px;">Reset</a></div>'
            . '</form>';

        // ===== data =====
        $items = Spolek_Archive::list_archives();
        if (!$items) {
            $html .= '<p style="opacity:.8;">Zatím žádné archivy.</p>';
            return $html;
        }

        // Předpočty (pro souhrn)
        $cnt_all = 0; $cnt_active = 0; $cnt_purged = 0; $cnt_missing = 0; $cnt_hidden = 0;
        foreach ($items as $it) {
            $cnt_all++;
            $is_hidden = (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'is_hidden_item'))
                ? Spolek_Archive::is_hidden_item((array)$it)
                : (!empty($it['hidden']) || !empty($it['hidden_at']));
            if ($is_hidden) {
                $cnt_hidden++;
            }
            $is_purged = !empty($it['purged_at']);
            if ($is_purged) $cnt_purged++; else $cnt_active++;
            $file = basename((string)($it['file'] ?? ''));
            if ($file === '') continue;
            $loc = Spolek_Archive::locate($file, !empty($it['storage']) ? (string)$it['storage'] : null);
            if (!$loc || empty($loc['path']) || !is_file((string)$loc['path'])) $cnt_missing++;
        }

        $html .= '<div style="opacity:.85;margin:6px 0 10px 0;">'
            . 'Celkem: <strong>'.(int)$cnt_all.'</strong> | V DB: <strong>'.(int)$cnt_active.'</strong> | Po purge: <strong>'.(int)$cnt_purged.'</strong> | Chybí soubor: <strong>'.(int)$cnt_missing.'</strong> | Skryté: <strong>'.(int)$cnt_hidden.'</strong>'
            . '</div>';

        $html .= '<table style="width:100%;border-collapse:collapse;">'
            . '<thead><tr>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Archiv</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Archivováno</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">DB</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Soubor</th>'
            . ($arch_verify ? '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">SHA</th>' : '')
            . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Akce</th>'
            . '</tr></thead><tbody>';

        $shown = 0;
        foreach ($items as $it) {
            $file = basename((string)($it['file'] ?? ''));
            if ($file === '') continue;

            $title = (string)($it['title'] ?? $file);
            $vote_post_id = (int)($it['vote_post_id'] ?? 0);
            $archived_at  = (int)($it['archived_at'] ?? 0);
            $purged_at    = (int)($it['purged_at'] ?? 0);
            $is_purged    = $purged_at > 0;
            $is_hidden    = (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'is_hidden_item'))
                ? Spolek_Archive::is_hidden_item((array)$it)
                : (!empty($it['hidden']) || !empty($it['hidden_at']));

            // default: skryté nezobrazujeme, pokud si je explicitně nevyfiltruješ
            if ($arch_state === 'hidden' && !$is_hidden) continue;
            if ($arch_state !== 'hidden' && $is_hidden) continue;

            if ($arch_q !== '') {
                $hay = $title . ' ' . $file;
                if (stripos($hay, $arch_q) === false) continue;
            }
            if ($from_ts > 0 && $archived_at > 0 && $archived_at < $from_ts) continue;
            if ($to_ts > 0 && $archived_at > 0 && $archived_at > $to_ts) continue;

            $loc = Spolek_Archive::locate($file, !empty($it['storage']) ? (string)$it['storage'] : null);
            $path = ($loc && !empty($loc['path'])) ? (string)$loc['path'] : '';
            $exists = ($path !== '' && is_file($path));

            if ($arch_state === 'active' && $is_purged) continue;
            if ($arch_state === 'purged' && !$is_purged) continue;
            if ($arch_state === 'missing' && $exists) continue;

            $sha_expected = (string)($it['sha256'] ?? '');
            $sha_ok = null;
            if ($arch_verify) {
                if ($exists && $sha_expected !== '') {
                    $sha_ok = hash_equals($sha_expected, hash_file('sha256', $path));
                } elseif ($sha_expected !== '') {
                    $sha_ok = false;
                }
            }

            $db_label = $is_purged
                ? ('smazáno ' . esc_html(wp_date('j.n.Y H:i', $purged_at, wp_timezone())))
                : 'v DB';

            $file_label = $exists ? '✅ ' : '❌ ';
            $bytes = $exists ? (int)filesize($path) : (int)($it['bytes'] ?? 0);
            $file_label .= esc_html($file) . ($bytes ? ' <span style="opacity:.75;">(' . esc_html(size_format($bytes)) . ')</span>' : '');

            $dl = '';
            if ($exists && $sha_ok !== false) {
                $dl_url = add_query_arg([
                    'action' => 'spolek_download_archive',
                    'file'   => $file,
                    '_nonce' => wp_create_nonce('spolek_download_archive_' . $file),
                ], admin_url('admin-post.php'));
                $dl = '<a class="button" href="'.esc_url($dl_url).'">Stáhnout</a>';
            }

            // Tlačítka pro aktivní záznamy (v DB)
            $purge_btn = '';
            $rebuild_btn = '';
            if (!$is_purged && $vote_post_id > 0) {
                $post = get_post($vote_post_id);
                if ($post) {
                    if ($exists) {
                        $purge_btn .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-left:6px;" onsubmit="return confirm(\'Opravdu smazat hlasování #' . (int)$vote_post_id . ' z databáze? Archivní ZIP zůstane uložen.\');">'
                            . '<input type="hidden" name="action" value="spolek_purge_vote">'
                            . '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">'
                            . wp_nonce_field('spolek_purge_vote_'.$vote_post_id, '_nonce', true, false)
                            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
                            . '<button type="submit">Smazat z DB</button>'
                            . '</form>';
                    } else {
                        $rebuild_btn .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-left:6px;">'
                            . '<input type="hidden" name="action" value="spolek_archive_vote">'
                            . '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">'
                            . wp_nonce_field('spolek_archive_vote_'.$vote_post_id, '_nonce', true, false)
                            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
                            . '<button type="submit">Znovu vytvořit ZIP</button>'
                            . '</form>';
                    }
                } else {
                    $db_label = '<span style="color:#b00;">záznam hlasování chybí</span>';
                }
            }

            $title_html = esc_html($title);
            if (!$is_purged && $vote_post_id > 0) {
                $title_html = '<a href="'.esc_url(self::detail_url($vote_post_id)).'">'.esc_html($title).'</a>';
            }

            $html .= '<tr>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'.$title_html.'</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($archived_at ? wp_date('j.n.Y H:i', $archived_at, wp_timezone()) : '–').'</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'.$db_label.'</td>'
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'.$file_label.'</td>'
                . ($arch_verify ? '<td style="border-bottom:1px solid #eee;padding:6px;">' . (
                    $sha_ok === null ? '–' : ($sha_ok ? '✅ OK' : '<span style="color:#b00;">❌ MISMATCH</span>')
                  ) . '</td>' : '')
                . '<td style="border-bottom:1px solid #eee;padding:6px;">'.$dl.$purge_btn.$rebuild_btn.'</td>'
                . '</tr>';

            $shown++;
        }

        if ($shown === 0) {
            $html .= '<tr><td colspan="'.($arch_verify ? 6 : 5).'" style="padding:10px;opacity:.8;">Nic nenalezeno pro zadané filtry.</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** Uzavřená hlasování – jen seznam pro členy. */
    private static function render_closed_list(): string {
        $now = time();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!$q->have_posts()) return '';

        $html  = '<details style="margin-top:18px;">';
        $html .= '<summary><strong>Ukončená hlasování (archiv, jen pro čtení)</strong></summary>';
        $html .= '<ul style="margin-top:10px;">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            [$start_ts, $end_ts] = self::vote_meta($id);

            $link = self::detail_url($id);

            $end_label = $end_ts ? wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) : '–';
            $html .= '<li><a href="'.esc_url($link).'">' . esc_html(get_the_title()) . '</a>'
                . ' <span style="opacity:.75;">(ukončeno '.$end_label.')</span></li>';
        }

        wp_reset_postdata();
        $html .= '</ul></details>';

        return $html;
    }

    private static function render_detail(int $vote_post_id): string {
        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== self::CPT) {
            return '<p>Hlasování nenalezeno.</p>';
        }

        [$start_ts, $end_ts, $text] = self::vote_meta($vote_post_id);
        $status = self::vote_status($start_ts, $end_ts);

        $html = '<h2>' . esc_html($post->post_title) . '</h2>';
        if ($start_ts && $end_ts) {
            $html .= '<p><strong>Termín:</strong> ' . esc_html(wp_date('j.n.Y H:i', $start_ts)) . ' – ' . esc_html(wp_date('j.n.Y H:i', $end_ts)) . '</p>';
        }

        $html .= '<div style="white-space:pre-wrap; padding:12px; border:1px solid #ddd;">' . esc_html($text) . '</div>';

        $user_id = get_current_user_id();

        if (!empty($_GET['voted'])) {
            $html .= '<p><strong>Děkujeme, hlas byl uložen.</strong></p>';
        }
        if (!empty($_GET['err'])) {
            $err = sanitize_text_field(wp_unslash((string)$_GET['err']));
            $html .= '<p><strong style="color:#b00;">Chyba: ' . esc_html($err) . '</strong></p>';
        }

        if ($status !== 'open') {
            $html .= '<p><em>Hlasování není otevřené.</em></p>';
        } else {
            if (self::user_has_voted($vote_post_id, $user_id)) {
                $html .= '<p><strong>Už jste hlasoval(a). Hlas nelze změnit.</strong></p>';
            } else {
                $html .= self::render_vote_form($vote_post_id);
            }
        }

        // Správce: export + jednoduchý souhrn
        if (self::is_manager()) {
            $html .= '<hr>';
            $html .= self::render_manager_tools($vote_post_id);
        }

        return $html;
    }

    private static function render_vote_form(int $vote_post_id): string {
        $action = esc_url(admin_url('admin-post.php'));

        $html  = '<h3>Odevzdat hlas</h3>';
        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_cast_vote">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">';
        $html .= wp_nonce_field('spolek_cast_vote_'.$vote_post_id, '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';

        $html .= '<p>';
        foreach (['ANO','NE','ZDRZEL'] as $val) {
            $label = $val === 'ZDRZEL' ? 'ZDRŽEL SE' : $val;
            $html .= '<label style="margin-right:16px;"><input required type="radio" name="choice" value="'.esc_attr($val).'"> '.esc_html($label).'</label>';
        }
        $html .= '</p>';

        $html .= '<p><button type="submit">Odeslat hlas</button></p>';
        $html .= '<p style="opacity:.8;">Po odeslání už nelze hlas změnit.</p>';
        $html .= '</form>';

        return $html;
    }

    private static function render_manager_tools(int $vote_post_id): string {
        $map = class_exists('Spolek_Votes')
            ? Spolek_Votes::get_counts($vote_post_id)
            : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

        $action = esc_url(admin_url('admin-post.php'));
        $html  = '<h3>Správa (jen pro správce)</h3>';
        $html .= '<p><strong>Souhrn:</strong> ANO: '.$map['ANO'].' | NE: '.$map['NE'].' | ZDRŽEL: '.$map['ZDRZEL'].'</p>';

        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_export_csv">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">';
        $html .= wp_nonce_field('spolek_export_csv_'.$vote_post_id, '_nonce', true, false);
        $html .= '<button type="submit">Stáhnout CSV (hlasy)</button>';
        $html .= '</form>';

        $pdf_path = (string) get_post_meta($vote_post_id, Spolek_Config::META_PDF_PATH, true);
        if ($pdf_path && file_exists($pdf_path)) {
            $dl = admin_url('admin-post.php');
            $dl = add_query_arg([
                'action'        => 'spolek_download_pdf',
                'vote_post_id'  => (int)$vote_post_id,
                '_nonce'        => wp_create_nonce('spolek_download_pdf_'.$vote_post_id),
            ], $dl);

            $html .= '<p><a class="button" href="'.esc_url($dl).'">Stáhnout zápis PDF</a></p>';
        } else {
            $html .= '<p style="opacity:.8;">Zápis PDF zatím není vygenerován (vygeneruje se po ukončení hlasování).</p>';
        }

        return $html;
    }
}

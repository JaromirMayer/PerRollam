<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Mailer
 *
 * Centrální mailer s idempotencí přes DB log (spolek_vote_mail_log).
 * - šablony (subject/body) na jednom místě
 * - přílohy na jednom místě
 * - jednotný log (pro archiv mail_log.csv)
 *
 * Statusy v logu:
 * - sent | fail | skip | no_email | silent
 *
 * Pozn.: idempotence se aplikuje jen na status='sent' -> fail/skip/no_email/silent umožní pozdější retry.
 */
final class Spolek_Mailer {

    public const TABLE = 'spolek_vote_mail_log';

    /** Vrátí plný název DB tabulky včetně prefixu. */
    public static function table_name(): string {
        if (class_exists('Spolek_Config') && method_exists('Spolek_Config', 'table_mail_log')) {
            return (string) Spolek_Config::table_mail_log();
        }
        global $wpdb;
        return $wpdb->prefix . self::TABLE; // fallback
    }

    /** Instalace tabulky pro mail log (dbDelta). */
    public static function install_table(): void {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            mail_type VARCHAR(20) NOT NULL,
            sent_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL,
            error_text TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mail (vote_post_id, user_id, mail_type),
            KEY idx_vote (vote_post_id),
            KEY idx_user (user_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /** Vrátí true pokud už byl mail úspěšně odeslán. */
    public static function mail_already_sent(int $vote_post_id, int $user_id, string $type): bool {
        global $wpdb;
        $table = self::table_name();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table
             WHERE vote_post_id=%d AND user_id=%d AND mail_type=%s AND status='sent'
             LIMIT 1",
            $vote_post_id, $user_id, $type
        ));

        return !empty($exists);
    }

    /** Zapíše stav do log tabulky (UPSERT přes unique key). */
    public static function log_mail(int $vote_post_id, int $user_id, string $type, string $status, ?string $error = null): void {
        global $wpdb;
        $t = self::table_name();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (vote_post_id, user_id, mail_type, sent_at, status, error_text)
             VALUES (%d, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                sent_at = VALUES(sent_at),
                status = VALUES(status),
                error_text = VALUES(error_text)",
            $vote_post_id,
            $user_id,
            $type,
            current_time('mysql'),
            $status,
            $error
        ));
    }

    // ======================================================================
    // Public API – všechny mail akce přes Spolek_Mailer
    // ======================================================================

    /** Oznámení o vyhlášení hlasování (announce) – všem členům. */
    public static function send_announce(int $vote_post_id): array {
        $vote_post_id = (int)$vote_post_id;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Config::CPT) {
            return self::empty_stats();
        }

        [$start_ts, $end_ts, $text] = Spolek_Vote_Service::get_vote_meta($vote_post_id);

        $link = Spolek_Vote_Service::vote_detail_url($vote_post_id);
        $subject = 'Vyhlášeno hlasování: ' . $post->post_title;

        $body = "Bylo vyhlášeno hlasování per rollam.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: {$link}\n"
              . "Hlasování je otevřené od: " . wp_date('j.n.Y H:i', (int)$start_ts, wp_timezone()) . "\n"
              . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        $members = (array) Spolek_Vote_Service::get_members();

        self::audit_batch($vote_post_id, null, Spolek_Audit_Events::MAIL_BATCH_START, [
            'type'    => 'announce',
            'members' => count($members),
        ]);

        $stats = self::send_to_members($vote_post_id, $members, 'announce', $subject, $body, [], false);

        self::audit_batch($vote_post_id, null, Spolek_Audit_Events::MAIL_BATCH_DONE, array_merge(['type' => 'announce'], $stats));

        return $stats;
    }

    /** Reminder 48/24 – posílá jen členům, kteří ještě nehlasovali. */
    public static function send_reminder(int $vote_post_id, string $type): array {
        $vote_post_id = (int)$vote_post_id;
        $type = (string)$type;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Config::CPT) {
            return self::empty_stats();
        }

        [$start_ts, $end_ts, $text] = Spolek_Vote_Service::get_vote_meta($vote_post_id);

        if (Spolek_Vote_Service::get_status((int)$start_ts, (int)$end_ts) !== 'open') {
            return self::empty_stats();
        }

        $link = Spolek_Vote_Service::vote_detail_url($vote_post_id);
        $subject = ($type === 'reminder48')
            ? 'Připomínka: 48 hodin do konce hlasování – ' . $post->post_title
            : 'Připomínka: 24 hodin do konce hlasování – ' . $post->post_title;

        $body = "Připomínka hlasování per rollam.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: {$link}\n"
              . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        $members = (array) Spolek_Vote_Service::get_members();

        self::audit_batch($vote_post_id, null, Spolek_Audit_Events::MAIL_BATCH_START, [
            'type'    => $type,
            'members' => count($members),
        ]);

        $total = 0; $sent = 0; $skip = 0; $failed = 0; $no_email = 0; $silent = 0;

        foreach ($members as $u) {
            $uid = (int)($u->ID ?? 0);
            if ($uid <= 0) continue;

            // posílat jen těm, kteří ještě nehlasovali
            $already = class_exists('Spolek_Votes')
                ? Spolek_Votes::has_user_voted($vote_post_id, $uid)
                : Spolek_Hlasovani_MVP::user_has_voted($vote_post_id, $uid);
            if ($already) continue;

            $total++;
            $status = self::send_member_mail($vote_post_id, $u, $type, $subject, $body, [], false);
            if ($status === 'sent') $sent++;
            elseif ($status === 'skip') $skip++;
            elseif ($status === 'no_email') $no_email++;
            elseif ($status === 'silent') $silent++;
            else $failed++;
        }

        $stats = ['total'=>$total,'sent'=>$sent,'skip'=>$skip,'no_email'=>$no_email,'failed'=>$failed,'silent'=>$silent];

        self::audit_batch($vote_post_id, null, Spolek_Audit_Events::MAIL_BATCH_DONE, array_merge(['type' => $type], $stats));

        return $stats;
    }

    /** Výsledek hlasování (result) – normal/silent. */
    public static function send_result(
        int $vote_post_id,
        array $map,
        array $eval,
        string $text,
        int $start_ts,
        int $end_ts,
        ?string $pdf_path,
        bool $silent_mode
    ): array {
        $vote_post_id = (int)$vote_post_id;
        $silent_mode = (bool)$silent_mode;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Config::CPT) {
            return self::empty_stats();
        }

        $link = Spolek_Vote_Service::vote_detail_url($vote_post_id);
        $subject = 'Výsledek hlasování: ' . $post->post_title;

        $body_base = "Hlasování per rollam bylo ukončeno.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: {$link}\n"
              . "Ukončeno: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Výsledek (počty hlasů):\n"
              . "ANO: " . (int)($map['ANO'] ?? 0) . "\n"
              . "NE: " . (int)($map['NE'] ?? 0) . "\n"
              . "ZDRŽEL SE: " . (int)($map['ZDRZEL'] ?? 0) . "\n\n"
              . "Vyhodnocení: " . (string)($eval['label'] ?? '') . "\n"
              . (string)($eval['explain'] ?? '') . "\n\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        // přílohy – centrálně
        $attachments = [];
        if (!$silent_mode && $pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
        }

        $members = (array) Spolek_Vote_Service::get_members();

        self::audit_batch(
            $vote_post_id,
            null,
            $silent_mode ? Spolek_Audit_Events::MAIL_RESULT_SILENT_START : Spolek_Audit_Events::MAIL_RESULT_BATCH_START,
            [
            'members' => count($members),
            'has_pdf' => !empty($attachments) ? 1 : 0,
        ]);

        $total = 0; $sent = 0; $skip = 0; $failed = 0; $no_email = 0; $silent = 0;

        foreach ($members as $u) {
            $uid = (int)($u->ID ?? 0);
            if ($uid <= 0) continue;
            $total++;

            $body = $body_base;

            // link pro člena (PDF landing) – jen pokud máme PDF servis
            if (class_exists('Spolek_PDF_Service')) {
                $exp = time() + (30 * DAY_IN_SECONDS);
                $sig = Spolek_PDF_Service::member_sig($uid, $vote_post_id, $exp);
                $pdf_link = Spolek_PDF_Service::member_landing_url($vote_post_id, $uid, $exp, $sig);
                $body .= "\n\nZápis PDF ke stažení (vyžaduje přihlášení):\n<{$pdf_link}>\n";
            }

            $status = self::send_member_mail($vote_post_id, $u, 'result', $subject, $body, $attachments, $silent_mode);
            if ($status === 'sent') $sent++;
            elseif ($status === 'skip') $skip++;
            elseif ($status === 'no_email') $no_email++;
            elseif ($status === 'silent') $silent++;
            else $failed++;
        }

        $stats = ['total'=>$total,'sent'=>$sent,'skip'=>$skip,'no_email'=>$no_email,'failed'=>$failed,'silent'=>$silent];

        self::audit_batch(
            $vote_post_id,
            null,
            $silent_mode ? Spolek_Audit_Events::MAIL_RESULT_SILENT_DONE : Spolek_Audit_Events::MAIL_RESULT_BATCH_DONE,
            array_merge($stats, [
            'has_pdf' => !empty($attachments) ? 1 : 0,
        ]));

        return $stats;
    }

    // ======================================================================
    // Low-level send
    // ======================================================================

    /**
     * Odeslání mailu konkrétnímu členovi.
     * Vrací: sent | skip | no_email | fail | silent
     */
    public static function send_member_mail(
        int $vote_post_id,
        $user,
        string $type,
        string $subject,
        string $body,
        array $attachments = [],
        bool $silent_mode = false
    ): string {
        $vote_post_id = (int) $vote_post_id;
        $type = (string) $type;
        $silent_mode = (bool) $silent_mode;

        $uid = (int) ($user->ID ?? 0);
        $email = (string) ($user->user_email ?? '');

        if (!$uid) return 'fail';

        // Filtry pro úpravu šablony
        $subject = (string) apply_filters('spolek_mail_subject', $subject, $type, $vote_post_id, $user);
        $body    = (string) apply_filters('spolek_mail_body', $body, $type, $vote_post_id, $user);

        if (self::mail_already_sent($vote_post_id, $uid, $type)) {
            // už odesláno -> nepišeme do DB logu, ať nepřebijeme status 'sent' (idempotence)
            self::audit_batch($vote_post_id, $uid, Spolek_Audit_Events::MAIL_MEMBER_SKIP, ['type' => $type, 'reason' => 'already_sent']);
            return 'skip';
        }

        // Silent mód = nic neodesílat, jen log do auditu (+ do mail logu kvůli archivu)
        if ($silent_mode) {
            self::log_mail($vote_post_id, $uid, $type, 'silent', 'silent_mode');
            self::audit_batch($vote_post_id, $uid, Spolek_Audit_Events::MAIL_MEMBER_SILENT, ['type' => $type]);
            return 'silent';
        }

        if ($email === '') {
            self::log_mail($vote_post_id, $uid, $type, 'no_email', 'empty_email');
            self::audit_batch($vote_post_id, $uid, Spolek_Audit_Events::MAIL_MEMBER_NO_EMAIL, ['type' => $type]);
            return 'no_email';
        }

        // jen existující soubory
        $atts = [];
        foreach ((array)$attachments as $p) {
            $p = (string) $p;
            if ($p !== '' && file_exists($p)) $atts[] = $p;
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (self::use_html($type, $vote_post_id, $user)) {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $body = self::render_html($subject, $body, $type, $vote_post_id, $user);
        }

        $ok = wp_mail($email, $subject, $body, $headers, $atts);

        if ($ok) {
            self::log_mail($vote_post_id, $uid, $type, 'sent', null);
            return 'sent';
        }

        self::log_mail($vote_post_id, $uid, $type, 'fail', 'wp_mail returned false');
        self::audit_batch($vote_post_id, $uid, Spolek_Audit_Events::MAIL_MEMBER_FAIL, ['type' => $type, 'error' => 'wp_mail returned false']);
        return 'fail';
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function empty_stats(): array {
        return ['total'=>0,'sent'=>0,'skip'=>0,'no_email'=>0,'failed'=>0,'silent'=>0];
    }

    private static function audit_batch(?int $vote_post_id, ?int $user_id, string $event, $meta = null): void {
        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, $user_id, $event, $meta);
        }
    }

    /**
     * Přepínač HTML vs. TEXT.
     *
     * - default: text
     * - zapnutí: add_filter('spolek_mail_use_html', fn()=>true);
     * - nebo:    add_filter('spolek_mail_format', fn($fmt)=>'html', 10, 4);
     */
    private static function use_html(string $type, int $vote_post_id, $user): bool {
        $use = (bool) apply_filters('spolek_mail_use_html', false, $type, $vote_post_id, $user);
        $fmt = (string) apply_filters('spolek_mail_format', $use ? 'html' : 'text', $type, $vote_post_id, $user);
        return strtolower($fmt) === 'html';
    }

    /**
     * Default HTML obálka – tělo e-mailu zůstává primárně textové (v <pre>).
     */
    private static function render_html(string $subject, string $body, string $type, int $vote_post_id, $user): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $safe_body = esc_html($body);

        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f5f5f5;">'
              . '<div style="max-width:680px;margin:0 auto;padding:18px;">'
              . '<div style="background:#ffffff;border:1px solid #e5e5e5;border-radius:12px;padding:18px;">'
              . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:18px;font-weight:600;margin:0 0 12px 0;">'
              . esc_html($site)
              . '</div>'
              . '<pre style="white-space:pre-wrap;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.45;">'
              . $safe_body
              . '</pre>'
              . '<hr style="border:none;border-top:1px solid #eee;margin:18px 0;">'
              . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:12px;color:#666;">'
              . 'Tento e-mail byl odeslán automaticky.'
              . '</div>'
              . '</div></div></body></html>';

        return (string) apply_filters('spolek_mail_html_template', $html, $subject, $body, $type, $vote_post_id, $user);
    }

    /** Posílání jedním průchodem všem členům (announce apod.). */
    private static function send_to_members(int $vote_post_id, array $members, string $type, string $subject, string $body, array $attachments, bool $silent_mode): array {
        $total = 0; $sent = 0; $skip = 0; $failed = 0; $no_email = 0; $silent = 0;

        foreach ((array)$members as $u) {
            $uid = (int)($u->ID ?? 0);
            if ($uid <= 0) continue;

            $total++;
            $status = self::send_member_mail($vote_post_id, $u, $type, $subject, $body, $attachments, $silent_mode);
            if ($status === 'sent') $sent++;
            elseif ($status === 'skip') $skip++;
            elseif ($status === 'no_email') $no_email++;
            elseif ($status === 'silent') $silent++;
            else $failed++;
        }

        return ['total'=>$total,'sent'=>$sent,'skip'=>$skip,'no_email'=>$no_email,'failed'=>$failed,'silent'=>$silent];
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Mailer
 *
 * Jednoduchý mailer s idempotencí přes DB log (spolek_vote_mail_log).
 * - pokud už je pro (vote_post_id, user_id, mail_type) status='sent', další pokusy se skipnou
 * - fail se loguje a další pokus je povolen (protože kontrolujeme jen sent)
 */
final class Spolek_Mailer {

    public const TABLE = 'spolek_vote_mail_log';

    /** Vrátí plný název DB tabulky včetně prefixu. */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
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

    /**
     * Vrátí true pokud už byl mail úspěšně odeslán.
     * Pozn.: kontrolujeme jen status='sent' -> fail umožní retry.
     */
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

    /**
     * Odeslání mailu konkrétnímu členovi.
     * Vrací: sent | skip | no_email | fail
     */
    public static function send_member_mail(int $vote_post_id, $user, string $type, string $subject, string $body, array $attachments = []): string {
        $vote_post_id = (int) $vote_post_id;
        $type = (string) $type;

        $uid = (int) ($user->ID ?? 0);
        $email = (string) ($user->user_email ?? '');

        if (!$uid) return 'fail';
        if ($email === '') return 'no_email';

        if (self::mail_already_sent($vote_post_id, $uid, $type)) {
            return 'skip';
        }

        // jen existující soubory
        $atts = [];
        foreach ((array)$attachments as $p) {
            $p = (string) $p;
            if ($p !== '' && file_exists($p)) $atts[] = $p;
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $ok = wp_mail($email, $subject, $body, $headers, $atts);

        if ($ok) {
            self::log_mail($vote_post_id, $uid, $type, 'sent', null);
            return 'sent';
        }

        self::log_mail($vote_post_id, $uid, $type, 'fail', 'wp_mail returned false');
        return 'fail';
    }
}

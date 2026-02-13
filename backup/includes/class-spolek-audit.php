<?php
if (!defined('ABSPATH')) exit;

class Spolek_Audit {
    const TABLE_AUDIT = 'spolek_vote_audit';

    public static function install_table() : void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . self::TABLE_AUDIT;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_at DATETIME NOT NULL,
            vote_post_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            event VARCHAR(50) NOT NULL,
            meta LONGTEXT NULL,
            ip_hash CHAR(64) NULL,
            ua_hash CHAR(64) NULL,
            PRIMARY KEY (id),
            KEY idx_vote (vote_post_id),
            KEY idx_user (user_id),
            KEY idx_event_at (event_at),
            KEY idx_event (event)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function req_ip() : string {
        // REMOTE_ADDR je nejspolehlivější; XFF může být spoof
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Pokud jsi za Cloudflare, můžeš preferovat:
        // if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];

        return (string)$ip;
    }

    private static function req_ua() : string {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public static function log(?int $vote_post_id, ?int $user_id, string $event, $meta = null) : void {
        global $wpdb;

        $ip = self::req_ip();
        $ua = self::req_ua();

        $ip_hash = $ip !== '' ? hash('sha256', $ip . '|' . wp_salt('spolek_audit_ip')) : null;
        $ua_hash = $ua !== '' ? hash('sha256', $ua . '|' . wp_salt('spolek_audit_ua')) : null;

        $table = $wpdb->prefix . self::TABLE_AUDIT;

        $wpdb->insert($table, [
            'event_at'     => current_time('mysql'), // WP timezone
            'vote_post_id' => $vote_post_id ?: null,
            'user_id'      => $user_id ?: null,
            'event'        => $event,
            'meta'         => $meta === null ? null : wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip_hash'      => $ip_hash,
            'ua_hash'      => $ua_hash,
        ]);
    }
}

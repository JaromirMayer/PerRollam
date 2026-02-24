<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Votes {

    public const TABLE = 'spolek_votes';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_table(): void {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            choice VARCHAR(10) NOT NULL,
            cast_at DATETIME NOT NULL,
            ip_hash CHAR(64) NULL,
            ua_hash CHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vote_user (vote_post_id, user_id),
            KEY idx_vote (vote_post_id),
            KEY idx_user (user_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    public static function has_user_voted(int $vote_post_id, int $user_id): bool {
        global $wpdb;
        $table = self::table_name();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE vote_post_id=%d AND user_id=%d LIMIT 1",
            $vote_post_id, $user_id
        ));

        return !empty($exists);
    }

    /** Vrací true pokud insert proběhl, false pokud selhal (duplicitní / DB error). */
    public static function insert_vote(int $vote_post_id, int $user_id, string $choice): bool {
        global $wpdb;
        $table = self::table_name();

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Zachovávám kompatibilní hash (bez salt) – ať to nemění chování. Kdykoli později můžeme přepnout na salted.
        $ip_hash = $ip !== '' ? hash('sha256', $ip) : null;
        $ua_hash = $ua !== '' ? hash('sha256', $ua) : null;

        $ok = $wpdb->insert($table, [
            'vote_post_id' => $vote_post_id,
            'user_id'      => $user_id,
            'choice'       => $choice,
            'cast_at'      => current_time('mysql'),
            'ip_hash'      => $ip_hash,
            'ua_hash'      => $ua_hash,
        ], ['%d','%d','%s','%s','%s','%s']);

        return (bool)$ok;
    }

    /** Vrátí mapu: ['ANO'=>int,'NE'=>int,'ZDRZEL'=>int] */
    public static function get_counts(int $vote_post_id): array {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT choice, COUNT(*) as c FROM $table WHERE vote_post_id=%d GROUP BY choice",
            $vote_post_id
        ), ARRAY_A);

        $map = ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];
        foreach ((array)$rows as $row) {
            $ch = (string)($row['choice'] ?? '');
            if (isset($map[$ch])) $map[$ch] = (int)($row['c'] ?? 0);
        }
        return $map;
    }

    /** Pro CSV export */
    public static function export_rows(int $vote_post_id): array {
        global $wpdb;
        $table = self::table_name();

        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT user_id, choice, cast_at FROM $table WHERE vote_post_id=%d ORDER BY cast_at ASC",
            $vote_post_id
        ), ARRAY_A);
    }
}

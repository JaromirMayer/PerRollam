<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Config {

    // CPT + capability
    public const CPT        = 'spolek_hlasovani';
    public const CAP_MANAGE = 'manage_spolek_hlasovani';

    // DB tabulky (bez prefixu)
    public const TABLE_VOTES    = 'spolek_votes';
    public const TABLE_AUDIT    = 'spolek_vote_audit';
    public const TABLE_MAIL_LOG = 'spolek_vote_mail_log';

    // Cron hooky
    public const HOOK_CLOSE        = 'spolek_vote_close';
    public const HOOK_REMINDER     = 'spolek_vote_reminder';
    public const HOOK_ARCHIVE_SCAN = 'spolek_archive_scan';
    public const HOOK_PURGE_SCAN   = 'spolek_purge_scan';
    public const HOOK_CLOSE_SCAN   = 'spolek_close_scan';
    public const HOOK_SELF_HEAL    = 'spolek_self_heal';

    // Cron interval slug (registered via cron_schedules)
    public const CRON_10MIN        = 'spolek_10min';

    // Self-heal transient (rate-limit per-request runner)
    public const SELF_HEAL_TRANSIENT = 'spolek_self_heal_last_run';

    // Meta keys (výsledek/uzávěrka)
    public const META_RULESET            = '_spolek_ruleset';
    public const META_QUORUM_RATIO       = '_spolek_quorum_ratio';
    public const META_PASS_RATIO         = '_spolek_pass_ratio';
    public const META_BASE               = '_spolek_pass_base';
    public const META_RESULT_LABEL       = '_spolek_result_label';
    public const META_RESULT_EXPLAIN     = '_spolek_result_explain';
    public const META_RESULT_ADOPTED     = '_spolek_result_adopted';

    public const META_CLOSE_PROCESSED_AT = '_spolek_close_processed_at';
    public const META_CLOSE_ATTEMPTS     = '_spolek_close_attempts';
    public const META_CLOSE_LAST_ERROR   = '_spolek_close_last_error';
    public const META_CLOSE_STARTED_AT   = '_spolek_close_started_at';
    public const META_CLOSE_NEXT_RETRY   = '_spolek_close_next_retry_at';
    public const META_CLOSE_GAVE_UP_AT   = '_spolek_close_gave_up_at';

    public const CLOSE_MAX_ATTEMPTS      = 5;

    // Archiv meta + storage
    public const META_ARCHIVE_FILE       = '_spolek_archive_file';
    public const META_ARCHIVE_SHA256     = '_spolek_archive_sha256';
    public const META_ARCHIVED_AT        = '_spolek_archived_at';
    public const META_ARCHIVE_ERROR      = '_spolek_archive_error';
    public const META_ARCHIVE_STORAGE    = '_spolek_archive_storage';

    public const ARCHIVE_DIR_SLUG        = 'spolek-hlasovani/archive';
    public const ARCHIVE_INDEX_FILE      = 'archives.json';

    // Helper: prefixované tabulky
    public static function table(string $base): string {
        global $wpdb;
        return $wpdb->prefix . $base;
    }

    public static function table_votes(): string    { return self::table(self::TABLE_VOTES); }
    public static function table_audit(): string    { return self::table(self::TABLE_AUDIT); }
    public static function table_mail_log(): string { return self::table(self::TABLE_MAIL_LOG); }
}

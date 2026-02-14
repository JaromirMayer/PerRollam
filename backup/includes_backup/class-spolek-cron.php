<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Cron {

    public const HOOK_CLOSE    = 'spolek_vote_close';
    public const HOOK_REMINDER = 'spolek_vote_reminder';

    public function register(): void {
        // Cron hooky – delegujeme do legacy
        add_action(self::HOOK_REMINDER, [$this, 'handle_reminder'], 10, 2);
        add_action(self::HOOK_CLOSE, [$this, 'handle_close'], 10, 1);
    }

    public function handle_reminder($vote_post_id, $type): void {
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'handle_cron_reminder')) {
            Spolek_Hlasovani_MVP::handle_cron_reminder((int)$vote_post_id, (string)$type);
        }
    }

    public function handle_close($vote_post_id): void {
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'handle_cron_close')) {
            Spolek_Hlasovani_MVP::handle_cron_close((int)$vote_post_id);
        }
    }

    /**
     * Naplánuje události (close + reminder 48h/24h) pro konkrétní hlasování.
     */
    public static function schedule_vote_events(int $post_id, int $start_ts, int $end_ts): void {

        // 1) Uzavření
        wp_clear_scheduled_hook(self::HOOK_CLOSE, [$post_id]);
        wp_schedule_single_event($end_ts, self::HOOK_CLOSE, [$post_id]);

        // 2) Reminder 48h
        $t48 = $end_ts - 48 * HOUR_IN_SECONDS;
        wp_clear_scheduled_hook(self::HOOK_REMINDER, [$post_id, 'reminder48']);
        if ($t48 > time()) {
            wp_schedule_single_event($t48, self::HOOK_REMINDER, [$post_id, 'reminder48']);
        }

        // 3) Reminder 24h
        $t24 = $end_ts - 24 * HOUR_IN_SECONDS;
        wp_clear_scheduled_hook(self::HOOK_REMINDER, [$post_id, 'reminder24']);
        if ($t24 > time()) {
            wp_schedule_single_event($t24, self::HOOK_REMINDER, [$post_id, 'reminder24']);
        }
    }

    public static function clear_vote_events(int $post_id): void {
        wp_clear_scheduled_hook(self::HOOK_CLOSE, [$post_id]);
        wp_clear_scheduled_hook(self::HOOK_REMINDER, [$post_id, 'reminder48']);
        wp_clear_scheduled_hook(self::HOOK_REMINDER, [$post_id, 'reminder24']);
    }
}

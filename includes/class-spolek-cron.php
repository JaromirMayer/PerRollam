<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Cron {
    
    public const HOOK_CLOSE        = Spolek_Config::HOOK_CLOSE;
    public const HOOK_REMINDER     = Spolek_Config::HOOK_REMINDER;
    public const HOOK_ARCHIVE_SCAN = Spolek_Config::HOOK_ARCHIVE_SCAN;
    public const HOOK_PURGE_SCAN   = Spolek_Config::HOOK_PURGE_SCAN;
    public const HOOK_CLOSE_SCAN   = Spolek_Config::HOOK_CLOSE_SCAN;
    public const HOOK_SELF_HEAL    = Spolek_Config::HOOK_SELF_HEAL;
    public const HOOK_REMINDER_SCAN = Spolek_Config::HOOK_REMINDER_SCAN;

    /** Přidá vlastní intervaly pro WP-Cron. */
    public static function add_schedules(array $schedules): array {
        if (!isset($schedules[Spolek_Config::CRON_10MIN])) {
            $schedules[Spolek_Config::CRON_10MIN] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => 'Spolek – každých 10 minut',
            ];
        }
        return $schedules;
    }

    public function register(): void {
        add_filter('cron_schedules', [__CLASS__, 'add_schedules']);

        // Cron hooky – delegujeme do legacy
        add_action(self::HOOK_REMINDER, [$this, 'handle_reminder'], 10, 2);
        add_action(self::HOOK_CLOSE, [$this, 'handle_close'], 10, 1);

        // 4.2 – dohánění archivace (1× za hodinu)
        add_action(self::HOOK_ARCHIVE_SCAN, [__CLASS__, 'archive_scan']);
        if (!wp_next_scheduled(self::HOOK_ARCHIVE_SCAN)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK_ARCHIVE_SCAN);
        }

        // 4.3 – pročištění databáze (1× denně) – maže jen pokud existuje archiv ZIP + sedí SHA256
        add_action(self::HOOK_PURGE_SCAN, [__CLASS__, 'purge_scan']);
        if (!wp_next_scheduled(self::HOOK_PURGE_SCAN)) {
            wp_schedule_event(time() + 600, 'daily', self::HOOK_PURGE_SCAN);
        }

        // 4.4.2 – dohánění uzávěrek (1× za hodinu)
        add_action(self::HOOK_CLOSE_SCAN, [__CLASS__, 'close_scan']);
        $next_close_scan = wp_next_scheduled(self::HOOK_CLOSE_SCAN);
        if (!$next_close_scan) {
            wp_schedule_event(time() + 450, Spolek_Config::CRON_10MIN, self::HOOK_CLOSE_SCAN);
        } else {
            $sched = function_exists('wp_get_schedule') ? (string) wp_get_schedule(self::HOOK_CLOSE_SCAN) : '';
            if ($sched !== '' && $sched !== Spolek_Config::CRON_10MIN) {
                wp_clear_scheduled_hook(self::HOOK_CLOSE_SCAN);
                wp_schedule_event(time() + 450, Spolek_Config::CRON_10MIN, self::HOOK_CLOSE_SCAN);
            }
        }

        // 6.1 – dohánění reminderů (každých ~10 min)
        add_action(self::HOOK_REMINDER_SCAN, [__CLASS__, 'reminder_scan']);
        $next_rem_scan = wp_next_scheduled(self::HOOK_REMINDER_SCAN);
        if (!$next_rem_scan) {
            wp_schedule_event(time() + 510, Spolek_Config::CRON_10MIN, self::HOOK_REMINDER_SCAN);
        } else {
            $sched = function_exists('wp_get_schedule') ? (string) wp_get_schedule(self::HOOK_REMINDER_SCAN) : '';
            if ($sched !== '' && $sched !== Spolek_Config::CRON_10MIN) {
                wp_clear_scheduled_hook(self::HOOK_REMINDER_SCAN);
                wp_schedule_event(time() + 510, Spolek_Config::CRON_10MIN, self::HOOK_REMINDER_SCAN);
            }
        }

        // 5.1 – self-heal (redundance vedle request-driven watchdogu)
        if (class_exists('Spolek_Self_Heal')) {
            $next_heal = wp_next_scheduled(self::HOOK_SELF_HEAL);
            if (!$next_heal) {
                wp_schedule_event(time() + 480, Spolek_Config::CRON_10MIN, self::HOOK_SELF_HEAL);
            } else {
                $sched = function_exists('wp_get_schedule') ? (string) wp_get_schedule(self::HOOK_SELF_HEAL) : '';
                if ($sched !== '' && $sched !== Spolek_Config::CRON_10MIN) {
                    wp_clear_scheduled_hook(self::HOOK_SELF_HEAL);
                    wp_schedule_event(time() + 480, Spolek_Config::CRON_10MIN, self::HOOK_SELF_HEAL);
                }
            }
        }

    }

    public function handle_reminder($vote_post_id, $type): void {
    Spolek_Vote_Processor::reminder((int)$vote_post_id, (string)$type);
}

    public function handle_close($vote_post_id): void {
    Spolek_Vote_Processor::close((int)$vote_post_id, false);
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


    /**
     * 4.2 – Cron scan: archivuje uzavřená a zpracovaná hlasování, která ještě nemají ZIP.
     * Běží 1× za hodinu, ale zpracuje max 10 položek (aby to nebylo těžké).
     */
    public static function archive_scan(): void {
        if (class_exists('Spolek_Cron_Status')) {
            Spolek_Cron_Status::touch(self::HOOK_ARCHIVE_SCAN, true);
        }
        if (!class_exists('Spolek_Archive') || !class_exists('Spolek_Hlasovani_MVP')) return;

        $now = time();
        $max = (int) Spolek_Config::CLOSE_MAX_ATTEMPTS;
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_CLOSE_PROCESSED_AT,
            'order'          => 'ASC',
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
                    'key'     => Spolek_Archive::META_ARCHIVE_FILE,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (!$q->have_posts()) return;

        Spolek_Archive::ensure_storage();

        while ($q->have_posts()) {
            $q->the_post();
            $id = (int) get_the_ID();

            $res = Spolek_Archive::archive_vote($id, false);
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($id, null, Spolek_Audit_Events::ARCHIVE_SCAN_ATTEMPT, [
                    'ok'    => (bool)($res['ok'] ?? false),
                    'error' => (string)($res['error'] ?? ''),
                ]);
            }
        }
        wp_reset_postdata();
    }
    

    /**
     * 4.3 – Cron scan: smaže z DB uzavřená hlasování starší než 30 dní,
     * jen pokud existuje archivní ZIP a sedí SHA256 (ověřuje Spolek_Archive::purge_vote()).
     * Běží 1× denně, max 10 položek na běh.
     *
     * @return int Kolik hlasování bylo smazáno z DB (OK).
     */
    public static function purge_scan(): int {
        if (class_exists('Spolek_Cron_Status')) {
            Spolek_Cron_Status::touch(self::HOOK_PURGE_SCAN, true);
        }
        if (!class_exists('Spolek_Archive') || !class_exists('Spolek_Hlasovani_MVP')) return 0;

        $threshold = time() - (30 * DAY_IN_SECONDS);

        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'ASC',
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
                    'key'     => Spolek_Archive::META_ARCHIVE_FILE,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (!$q->have_posts()) return 0;

        $ids = wp_list_pluck($q->posts, 'ID');
        wp_reset_postdata();

        Spolek_Archive::ensure_storage();

        $purged = 0;
        foreach ($ids as $id) {
            $id = (int) $id;

            $res = Spolek_Archive::purge_vote($id);

            if (is_array($res) && !empty($res['ok'])) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * 4.4.2 – Dohánění uzávěrek pro starší hlasování, která už jsou ukončená,
     * ale pořád nemají META_CLOSE_PROCESSED_AT (např. kvůli neproběhlému cron jobu).
     *
     * - max $limit položek na běh (kvůli zátěži)
     * - hlasování uzavřená před více než $silent_after_days dny se doženou v tichém režimu (bez rozesílky e-mailů),
     *   ale výsledek + PDF + archiv ZIP se vytvoří.
     *
     * @return array{total:int,silent:int,normal:int,errors:int,ids:array<int>}
     */
    public static function close_scan(int $limit = 10, int $silent_after_days = 7): array {
        if (class_exists('Spolek_Cron_Status')) {
            Spolek_Cron_Status::touch(self::HOOK_CLOSE_SCAN, true);
        }
        if (!class_exists('Spolek_Hlasovani_MVP')) {
            return ['total'=>0,'silent'=>0,'normal'=>0,'errors'=>0,'ids'=>[]];
        }

        $now = time();
        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => max(1, (int)$limit),
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'ASC',
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
                // attempts: NOT EXISTS OR < max
                [
                    'relation' => 'OR',
                    [
                        'key'     => Spolek_Config::META_CLOSE_ATTEMPTS,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => Spolek_Config::META_CLOSE_ATTEMPTS,
                        'value'   => $max,
                        'compare' => '<',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            return ['total'=>0,'silent'=>0,'normal'=>0,'errors'=>0,'ids'=>[]];
        }

        $ids = wp_list_pluck($q->posts, 'ID');
        wp_reset_postdata();

        $stats = ['total'=>0,'silent'=>0,'normal'=>0,'errors'=>0,'ids'=>[]];

        foreach ($ids as $id) {
            $id = (int)$id;

            $processed_at = get_post_meta($id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
            if (!empty($processed_at)) continue;

            $end_ts = (int) get_post_meta($id, Spolek_Config::META_END_TS, true);
            $age = ($end_ts > 0) ? max(0, $now - $end_ts) : 0;

            $stats['total']++;
            $stats['ids'][] = $id;

            try {
                if ($end_ts > 0 && $age > ((int)$silent_after_days * DAY_IN_SECONDS)) {
                    self::cron_close_silent($id);
                    $stats['silent']++;
                } else {
                    self::cron_close($id);
                    $stats['normal']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($id, null, Spolek_Audit_Events::CLOSE_SCAN_ERROR, [
                        'msg' => substr((string)$e->getMessage(), 0, 300),
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * Uzávěrka bez rozesílky e-mailů (tichý režim).
     * Vytvoří výsledek, PDF a best-effort ZIP archiv, nastaví META_CLOSE_PROCESSED_AT.
     */
 
    public static function cron_close_silent(int $vote_post_id): void {
    Spolek_Vote_Processor::close($vote_post_id, true);
}

    // ===== Lock + retry (přesun z legacy) =====

    private static function close_lock_key(int $vote_post_id): string {
    return 'spolek_vote_close_lock_' . $vote_post_id;
}

    private static function acquire_close_lock(int $vote_post_id, int $ttl = 600): ?string {
    $key = self::close_lock_key($vote_post_id);

    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : (string) wp_rand(100000, 999999) . '-' . microtime(true);

    $exp = time() + max(30, $ttl);
    $value = $exp . '|' . $token;

    if (add_option($key, $value, '', 'no')) {
        return $token;
    }

    $existing = (string) get_option($key, '');
    if ($existing !== '') {
        $parts = explode('|', $existing, 2);
        $existing_exp = (int)($parts[0] ?? 0);

        if ($existing_exp > 0 && $existing_exp < time()) {
            delete_option($key);
            if (add_option($key, $value, '', 'no')) {
                return $token;
            }
        }
    }

    return null;
}

    private static function release_close_lock(int $vote_post_id, string $token): void {
    $key = self::close_lock_key($vote_post_id);

    $existing = (string) get_option($key, '');
    if ($existing === '') return;

    $parts = explode('|', $existing, 2);
    $existing_token = (string)($parts[1] ?? '');

    if ($existing_token !== '' && hash_equals($existing_token, $token)) {
        delete_option($key);
    }
}

    private static function close_retry_delay_seconds(int $attempt): int {
    $delays = [300, 900, 1800, 3600, 7200]; // 5m,15m,30m,60m,2h
    $idx = max(1, $attempt) - 1;
    if ($idx >= count($delays)) $idx = count($delays) - 1;
    return (int) $delays[$idx];
}

    private static function schedule_close_retry(int $vote_post_id, int $attempt, string $reason): void {
    $processed_at = get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
    if (!empty($processed_at)) return;

    if ($attempt >= Spolek_Config::CLOSE_MAX_ATTEMPTS) {
        Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_RETRY_GIVE_UP, [
            'attempt' => $attempt,
            'max'     => Spolek_Config::CLOSE_MAX_ATTEMPTS,
            'reason'  => $reason,
        ]);
        return;
    }

    $delay  = self::close_retry_delay_seconds($attempt);
    $jitter = (int) wp_rand(0, 30);
    $when   = time() + $delay + $jitter;

    $next = wp_next_scheduled(self::HOOK_CLOSE, [$vote_post_id]);
    if ($next && $next > (time() + 10)) {
        update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY, (string)$next);
        return;
    }

    wp_schedule_single_event($when, self::HOOK_CLOSE, [$vote_post_id]);
    update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY, (string)$when);

    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_RETRY_SCHEDULED, [
        'attempt' => $attempt,
        'when'    => $when,
        'delay'   => $delay,
        'reason'  => $reason,
    ]);
}

// ===== Přesunuté handlery z legacy =====

    public static function cron_close(int $vote_post_id): void {
    Spolek_Vote_Processor::close($vote_post_id, false);
}

    public static function cron_reminder(int $vote_post_id, string $type): void {
    Spolek_Vote_Processor::reminder($vote_post_id, $type);
}

    /**
     * 6.1 – Cron scan: dohání reminder48/reminder24 pro otevřená hlasování,
     * pokud byl WP-Cron pozadu nebo událost neproběhla.
     *
     * Běží každých ~10 min, zpracuje max 10 hlasování.
     *
     * @return array{votes:int,total:int,ids:array<int>}
     */
    public static function reminder_scan(int $limit = 10): array {
        if (class_exists('Spolek_Cron_Status')) {
            Spolek_Cron_Status::touch(self::HOOK_REMINDER_SCAN, true);
        }

        if (!class_exists('Spolek_Vote_Processor') || !class_exists('Spolek_Hlasovani_MVP')) {
            return ['votes' => 0, 'total' => 0, 'ids' => []];
        }

        $now = time();
        $limit = max(1, (int)$limit);

        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'ASC',
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
                // končí do 48 hodin
                [
                    'key'     => Spolek_Config::META_END_TS,
                    'value'   => $now + (48 * HOUR_IN_SECONDS),
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            return ['votes' => 0, 'total' => 0, 'ids' => []];
        }

        $ids = wp_list_pluck($q->posts, 'ID');
        wp_reset_postdata();

        $stats = ['votes' => 0, 'total' => 0, 'ids' => []];

        foreach ($ids as $id) {
            $id = (int)$id;
            $end_ts = (int) get_post_meta($id, Spolek_Config::META_END_TS, true);
            if ($end_ts <= 0) continue;

            $stats['votes']++;
            $stats['ids'][] = $id;

            // 24h okno má prioritu; 48h posíláme jen pokud ještě nejsme v posledních 24h.
            $type = ($now >= ($end_ts - 24 * HOUR_IN_SECONDS)) ? 'reminder24' : 'reminder48';

            Spolek_Vote_Processor::reminder($id, $type);
            $stats['total']++;
        }

        return $stats;
    }

}

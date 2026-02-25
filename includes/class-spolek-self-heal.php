<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Self_Heal
 *
 * Watchdog, který "dohoní" nejčastější selhání WP-Cronu:
 * - uzávěrky, které měly být dávno provedené, ale nemají META_CLOSE_PROCESSED_AT
 * - chybějící naplánované recurring eventy (close_scan, archive_scan, purge_scan)
 *
 * Běží ve dvou režimech:
 * 1) request-driven: wp_loaded + admin_init, rate-limited transientem (default 5 min)
 * 2) cron-driven: HOOK_SELF_HEAL (každých ~10 min), pokud WP-Cron běží
 */
final class Spolek_Self_Heal {

    /** Jak často smí běžet na běžných requestech (sekundy). */
    private const REQUEST_TTL = 300; // 5 min

    /** Kolik uzávěrek max na 1 běh. */
    private const CLOSE_LIMIT = 3;

    /** Po kolika dnech doženeme uzávěrku v tichém režimu. */
    private const SILENT_AFTER_DAYS = 7;

    public function register(): void {
        // request-driven watchdog
        add_action('wp_loaded', [__CLASS__, 'maybe_run_request'], 20);
        add_action('admin_init', [__CLASS__, 'maybe_run_request'], 20);

        // cron-driven watchdog (redundance)
        add_action(Spolek_Config::HOOK_SELF_HEAL, [__CLASS__, 'run']);
    }

    /**
     * Spustí watchdog na normálních requestech, ale jen občas.
     */
    public static function maybe_run_request(): void {
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (defined('WP_CLI') && WP_CLI) return;

        // Bezpečnost / výkon: self-heal spouštíme jen pokud uživatel je přihlášený.
        // (Admin stránky jsou vždy přihlášené; v portálu je taky obvyklé.)
        if (!is_user_logged_in()) return;

        $key = Spolek_Config::SELF_HEAL_TRANSIENT;
        $last = (int) get_transient($key);
        if ($last > 0 && (time() - $last) < self::REQUEST_TTL) return;
        set_transient($key, time(), self::REQUEST_TTL);

        self::run('request');
    }

    /**
     * Hlavní entry-point.
     *
     * @param string $source request|cron
     */
    public static function run(string $source = 'cron'): void {
        // 1) zajistit recurring eventy
        self::ensure_recurring_events();

        // 2) dohánění uzávěrek (největší pain)
        $inline = ($source === 'cron') || current_user_can(Spolek_Config::CAP_MANAGE);
        $stats = self::heal_overdue_closures(self::CLOSE_LIMIT, self::SILENT_AFTER_DAYS, $inline);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log(null, get_current_user_id() ?: null, Spolek_Audit_Events::SELF_HEAL_TICK, [
                'source' => $source,
                'now'    => time(),
                'stats'  => $stats,
            ]);
        }
    }

    private static function ensure_recurring_events(): void {
        // close_scan (10 min)
        if (!wp_next_scheduled(Spolek_Config::HOOK_CLOSE_SCAN)) {
            wp_schedule_event(time() + 120, Spolek_Config::CRON_10MIN, Spolek_Config::HOOK_CLOSE_SCAN);
        }

        // archive_scan (hourly)
        if (!wp_next_scheduled(Spolek_Config::HOOK_ARCHIVE_SCAN)) {
            wp_schedule_event(time() + 300, 'hourly', Spolek_Config::HOOK_ARCHIVE_SCAN);
        }

        // purge_scan (daily)
        if (!wp_next_scheduled(Spolek_Config::HOOK_PURGE_SCAN)) {
            wp_schedule_event(time() + 600, 'daily', Spolek_Config::HOOK_PURGE_SCAN);
        }

        // self-heal cron (10 min)
        if (!wp_next_scheduled(Spolek_Config::HOOK_SELF_HEAL)) {
            wp_schedule_event(time() + 180, Spolek_Config::CRON_10MIN, Spolek_Config::HOOK_SELF_HEAL);
        }
    }

    /**
     * Najde ukončená hlasování bez META_CLOSE_PROCESSED_AT a pokusí se je zavřít.
     */
    private static function heal_overdue_closures(int $limit, int $silent_after_days, bool $inline): array {
        if (!class_exists('Spolek_Hlasovani_MVP') || !class_exists('Spolek_Vote_Processor')) {
            return ['total' => 0, 'done' => 0, 'skip' => 0, 'ids' => []];
        }

        $now = time();
        $limit = max(1, (int)$limit);

        // Vyloučíme případy, kdy už je dosažen max počet pokusů.
        $max = (int) Spolek_Hlasovani_MVP::CLOSE_MAX_ATTEMPTS;

        $q = new WP_Query([
            'post_type'      => Spolek_Hlasovani_MVP::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_spolek_end_ts',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => '_spolek_end_ts',
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT,
                    'compare' => 'NOT EXISTS',
                ],
                // attempts: NOT EXISTS OR < max
                [
                    'relation' => 'OR',
                    [
                        'key'     => Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS,
                        'value'   => $max,
                        'compare' => '<',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            return ['total' => 0, 'done' => 0, 'skip' => 0, 'ids' => []];
        }

        $ids = wp_list_pluck($q->posts, 'ID');
        wp_reset_postdata();

        $stats = ['total' => 0, 'done' => 0, 'skip' => 0, 'ids' => []];

        foreach ($ids as $id) {
            $id = (int)$id;
            $stats['total']++;
            $stats['ids'][] = $id;

            // Pokud už je naplánovaná uzávěrka brzy, nespouštíme ji na requestu (duplicitní práce).
            $next = wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$id]);
            if ($next && $next <= (time() + 5 * MINUTE_IN_SECONDS)) {
                $stats['skip']++;
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($id, get_current_user_id() ?: null, Spolek_Audit_Events::SELF_HEAL_CLOSE_SKIP, [
                        'reason' => 'close_already_scheduled',
                        'next'   => (int)$next,
                    ]);
                }
                continue;
            }

            $end_ts = (int) get_post_meta($id, '_spolek_end_ts', true);
            $age = ($end_ts > 0) ? max(0, $now - $end_ts) : 0;
            $silent = ($end_ts > 0 && $age > ((int)$silent_after_days * DAY_IN_SECONDS));

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($id, get_current_user_id() ?: null, Spolek_Audit_Events::SELF_HEAL_CLOSE_RUN, [
                    'silent' => $silent ? 1 : 0,
                    'age_s'  => (int)$age,
                    'inline' => $inline ? 1 : 0,
                ]);
            }

            if ($inline) {
                // admin/cron: provedeme uzávěrku hned (self-heal = okamžité vyřešení čekání)
                Spolek_Vote_Processor::close($id, $silent);
                $stats['done']++;
            } else {
                // člen/uživatel: jen "nakopneme" WP-Cron (ať se request nezdržuje rozesílkou)
                $when = time() + 30;
                $next = wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$id]);
                if (!$next) {
                    wp_schedule_single_event($when, Spolek_Config::HOOK_CLOSE, [$id]);
                }
                if (function_exists('spawn_cron')) {
                    spawn_cron();
                }
                $stats['done']++;
            }
        }

        return $stats;
    }
}

<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Cron {

    public const HOOK_CLOSE        = 'spolek_vote_close';
    public const HOOK_REMINDER     = 'spolek_vote_reminder';
    public const HOOK_ARCHIVE_SCAN = 'spolek_archive_scan';
    public const HOOK_PURGE_SCAN   = 'spolek_purge_scan';


    public function register(): void {
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

    }

    public function handle_reminder($vote_post_id, $type): void {
    self::cron_reminder((int)$vote_post_id, (string)$type);
}

    public function handle_close($vote_post_id): void {
    self::cron_close((int)$vote_post_id);
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
        if (!class_exists('Spolek_Archive') || !class_exists('Spolek_Hlasovani_MVP')) return;

        $now = time();
        $q = new WP_Query([
            'post_type'      => Spolek_Hlasovani_MVP::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT,
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
                Spolek_Audit::log($id, null, 'archive_scan_attempt', [
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
        if (!class_exists('Spolek_Archive') || !class_exists('Spolek_Hlasovani_MVP')) return 0;

        $threshold = time() - (30 * DAY_IN_SECONDS);

        $q = new WP_Query([
            'post_type'      => Spolek_Hlasovani_MVP::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_spolek_end_ts',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => '_spolek_end_ts',
                    'value'   => $threshold,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT,
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
    $processed_at = get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, true);
    if (!empty($processed_at)) return;

    if ($attempt >= Spolek_Hlasovani_MVP::CLOSE_MAX_ATTEMPTS) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_give_up', [
            'attempt' => $attempt,
            'max'     => Spolek_Hlasovani_MVP::CLOSE_MAX_ATTEMPTS,
            'reason'  => $reason,
        ]);
        return;
    }

    $delay  = self::close_retry_delay_seconds($attempt);
    $jitter = (int) wp_rand(0, 30);
    $when   = time() + $delay + $jitter;

    $next = wp_next_scheduled(self::HOOK_CLOSE, [$vote_post_id]);
    if ($next && $next > (time() + 10)) {
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY, (string)$next);
        return;
    }

    wp_schedule_single_event($when, self::HOOK_CLOSE, [$vote_post_id]);
    update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY, (string)$when);

    Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_scheduled', [
        'attempt' => $attempt,
        'when'    => $when,
        'delay'   => $delay,
        'reason'  => $reason,
    ]);
}

// ===== Přesunuté handlery z legacy =====

    public static function cron_close(int $vote_post_id): void {
    $vote_post_id = (int) $vote_post_id;

    $post = get_post($vote_post_id);
    if (!$post || $post->post_type !== Spolek_Hlasovani_MVP::CPT) return;

    $processed_at = get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, true);
    if (!empty($processed_at)) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_processed', [
            'processed_at' => $processed_at,
        ]);
        return;
    }

    $lock_token = self::acquire_close_lock($vote_post_id, 600);
    if (!$lock_token) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_lock_busy', ['now' => time()]);

        if (!wp_next_scheduled(self::HOOK_CLOSE, [$vote_post_id])) {
            wp_schedule_single_event(time() + 120, self::HOOK_CLOSE, [$vote_post_id]);
        }
        return;
    }

    Spolek_Audit::log($vote_post_id, null, 'cron_close_start', ['now' => time()]);

    $attempt = 0;

    try {
        [$start_ts, $end_ts, $text] = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);

        Spolek_Audit::log($vote_post_id, null, 'cron_close_called', [
            'start_ts' => (int)$start_ts,
            'end_ts'   => (int)$end_ts,
            'now'      => time(),
        ]);

        $now = time();
        if ($now < (int)$end_ts) {
            wp_clear_scheduled_hook(self::HOOK_CLOSE, [$vote_post_id]);
            wp_schedule_single_event(((int)$end_ts) + 60, self::HOOK_CLOSE, [$vote_post_id]);

            Spolek_Audit::log($vote_post_id, null, 'cron_close_rescheduled', [
                'now'    => $now,
                'end_ts' => (int)$end_ts,
            ]);
            return;
        }

        $status_now = Spolek_Hlasovani_MVP::get_status((int)$start_ts, (int)$end_ts);
        if ($status_now !== 'closed') {
            Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_not_closed', [
                'status' => $status_now,
                'now'    => time(),
                'end_ts' => (int)$end_ts,
            ]);
            return;
        }

        $attempt = (int) get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, true);
        $attempt++;
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, (string)$attempt);
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT, (string) time());

        global $wpdb;
        $table = $wpdb->prefix . Spolek_Hlasovani_MVP::TABLE;

        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT choice, COUNT(*) as c FROM $table WHERE vote_post_id=%d GROUP BY choice",
            $vote_post_id
        ), ARRAY_A);

        $map = ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];
        foreach ($counts as $row) {
            $ch = $row['choice'];
            if (isset($map[$ch])) $map[$ch] = (int)$row['c'];
        }

        $eval = Spolek_Hlasovani_MVP::evaluate_vote($vote_post_id, $map);

        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_LABEL, $eval['label']);
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_EXPLAIN, $eval['explain']);
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_ADOPTED, $eval['adopted'] ? '1' : '0');

        $link = Spolek_Hlasovani_MVP::vote_detail_url($vote_post_id);
        $subject = 'Výsledek hlasování: ' . $post->post_title;

        $body = "Hlasování per rollam bylo ukončeno.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: $link\n"
              . "Ukončeno: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Výsledek (počty hlasů):\n"
              . "ANO: {$map['ANO']}\n"
              . "NE: {$map['NE']}\n"
              . "ZDRŽEL SE: {$map['ZDRZEL']}\n\n"
              . "\nVyhodnocení: " . $eval['label'] . "\n"
              . $eval['explain'] . "\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        $pdf_path = Spolek_Hlasovani_MVP::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

        $attachments = [];
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
            Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                'pdf'   => basename($pdf_path),
                'bytes' => (int) filesize($pdf_path),
            ]);
        } else {
            Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                'pdf'   => null,
                'error' => 'pdf not generated',
            ]);
        }

        $sent = 0; $skipped = 0; $failed = 0; $no_email = 0; $total = 0;
        $members = Spolek_Hlasovani_MVP::get_members();

        Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_start', [
            'members' => is_array($members) ? count($members) : 0,
            'has_pdf' => !empty($attachments) ? 1 : 0,
        ]);

        foreach ($members as $u) {
            $total++;

            $exp = time() + (30 * DAY_IN_SECONDS);
            $uid = (int) $u->ID;
            $sig = Spolek_Hlasovani_MVP::member_pdf_sig($uid, $vote_post_id, $exp);

            $landing = trailingslashit(home_url('/clenove/stazeni-zapisu/'));

            $pdf_link = add_query_arg([
                'vote_post_id' => $vote_post_id,
                'uid'          => $uid,
                'exp'          => $exp,
                'sig'          => $sig,
            ], $landing);

            $body_with_link = $body
                . "\n\nZápis PDF ke stažení (vyžaduje přihlášení):\n<"
                . $pdf_link
                . ">\n";

            $mail_status = Spolek_Mailer::send_member_mail($vote_post_id, $u, 'result', $subject, $body_with_link, $attachments);

            if ($mail_status === 'sent') $sent++;
            elseif ($mail_status === 'skip') $skipped++;
            elseif ($mail_status === 'no_email') $no_email++;
            else $failed++;
        }

        Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_done', [
            'total'    => (int)$total,
            'sent'     => (int)$sent,
            'skip'     => (int)$skipped,
            'no_email' => (int)$no_email,
            'failed'   => (int)$failed,
            'has_pdf'  => !empty($attachments) ? 1 : 0,
        ]);

        if ($failed > 0) {
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR, "result mails failed: $failed");
            throw new \RuntimeException("result mails failed: $failed");
        }

        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, (string) time());

        // 4.2 – Archiv (best-effort, bez blokování uzávěrky)
        if (class_exists('Spolek_Archive')) {
            $res = Spolek_Archive::archive_vote($vote_post_id, false);
            if (!(is_array($res) && !empty($res['ok']))) {
                $err = is_array($res) ? (string)($res['error'] ?? 'archive_failed') : 'archive_failed';
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'archive_auto_failed', [
                        'error' => $err,
                    ]);
                }
            }
        }

        delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT);
        delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR);
        delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY);

    } catch (\Throwable $e) {
        $msg = substr((string) $e->getMessage(), 0, 500);

        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR, $msg);

        Spolek_Audit::log($vote_post_id, null, 'cron_close_exception', [
            'attempt' => (int)$attempt,
            'message' => $msg,
            'code'    => (int)$e->getCode(),
            'file'    => basename((string)$e->getFile()),
            'line'    => (int)$e->getLine(),
        ]);

        self::schedule_close_retry($vote_post_id, (int)$attempt, 'exception');

    } finally {
        self::release_close_lock($vote_post_id, $lock_token);
    }
}

    public static function cron_reminder(int $vote_post_id, string $type): void {
    $vote_post_id = (int) $vote_post_id;
    $type = (string) $type;

    $post = get_post($vote_post_id);
    if (!$post || $post->post_type !== Spolek_Hlasovani_MVP::CPT) return;

    [$start_ts, $end_ts, $text] = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);

    if (Spolek_Hlasovani_MVP::get_status((int)$start_ts, (int)$end_ts) !== 'open') return;

    $link = Spolek_Hlasovani_MVP::vote_detail_url($vote_post_id);
    $subject = ($type === 'reminder48')
        ? 'Připomínka: 48 hodin do konce hlasování – ' . $post->post_title
        : 'Připomínka: 24 hodin do konce hlasování – ' . $post->post_title;

    $members = Spolek_Hlasovani_MVP::get_members();
    Spolek_Audit::log($vote_post_id, null, 'cron_reminder_sending', [
        'members' => is_array($members) ? count($members) : 0,
        'type' => $type,
    ]);

    foreach ($members as $u) {
        if (Spolek_Hlasovani_MVP::user_has_voted($vote_post_id, (int)$u->ID)) continue;

        $body = "Připomínka hlasování per rollam.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: $link\n"
              . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        Spolek_Mailer::send_member_mail($vote_post_id, $u, $type, $subject, $body);
    }
}

}

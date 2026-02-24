<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Vote_Processor {

    /** Uzávěrka (normal/silent). */
    public static function close(int $vote_post_id, bool $silent = false): void {
        $silent ? self::close_silent($vote_post_id) : self::close_normal($vote_post_id);
    }

    /** Reminder 48/24. */
    public static function reminder(int $vote_post_id, string $type): void {
        $vote_post_id = (int)$vote_post_id;
        $type = (string)$type;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Hlasovani_MVP::CPT) return;

        [$start_ts, $end_ts, $text] = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);

        if (Spolek_Hlasovani_MVP::get_status((int)$start_ts, (int)$end_ts) !== 'open') return;

        $link = Spolek_Hlasovani_MVP::vote_detail_url($vote_post_id);
        $subject = ($type === 'reminder48')
            ? 'Připomínka: 48 hodin do konce hlasování – ' . $post->post_title
            : 'Připomínka: 24 hodin do konce hlasování – ' . $post->post_title;

        $members = Spolek_Hlasovani_MVP::get_members();
        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, 'cron_reminder_sending', [
                'members' => is_array($members) ? count($members) : 0,
                'type'    => $type,
            ]);
        }

        foreach ((array)$members as $u) {
            $uid = (int)($u->ID ?? 0);
            if ($uid <= 0) continue;

            // používáme centrální tabulku hlasů
            $already = class_exists('Spolek_Votes')
                ? Spolek_Votes::has_user_voted($vote_post_id, $uid)
                : Spolek_Hlasovani_MVP::user_has_voted($vote_post_id, $uid);

            if ($already) continue;

            $body = "Připomínka hlasování per rollam.\n\n"
                  . "Název: {$post->post_title}\n"
                  . "Odkaz: $link\n"
                  . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
                  . "Plné znění návrhu:\n"
                  . $text . "\n";

            Spolek_Mailer::send_member_mail($vote_post_id, $u, $type, $subject, $body);
        }
    }

    // ======================================================================
    // Uzávěrka – SILENT
    // ======================================================================

    private static function close_silent(int $vote_post_id): void {
        $vote_post_id = (int)$vote_post_id;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Hlasovani_MVP::CPT) return;

        $processed_at = get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) return;

        $lock_token = self::acquire_close_lock($vote_post_id, 600);
        if (!$lock_token) {
            if (!wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$vote_post_id])) {
                wp_schedule_single_event(time() + 120, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
            }
            return;
        }

        $attempt = 0;

        try {
            [$start_ts, $end_ts, $text] = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);

            $now = time();
            if ($now < (int)$end_ts) {
                wp_clear_scheduled_hook(Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
                wp_schedule_single_event(((int)$end_ts) + 60, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
                return;
            }

            $status_now = Spolek_Hlasovani_MVP::get_status((int)$start_ts, (int)$end_ts);
            if ($status_now !== 'closed') return;

            $attempt = (int) get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, true);
            $attempt++;
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, (string)$attempt);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT, (string) time());

            $map = class_exists('Spolek_Votes')
                ? Spolek_Votes::get_counts($vote_post_id)
                : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

            $eval = Spolek_Hlasovani_MVP::evaluate_vote($vote_post_id, $map);

            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_LABEL, $eval['label']);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_EXPLAIN, $eval['explain']);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_RESULT_ADOPTED, $eval['adopted'] ? '1' : '0');

            $pdf_path = class_exists('Spolek_PDF_Service')
                ? Spolek_PDF_Service::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts)
                : Spolek_Hlasovani_MVP::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

            if ($pdf_path && file_exists($pdf_path)) {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                        'pdf'   => basename($pdf_path),
                        'bytes' => (int) filesize($pdf_path),
                    ]);
                }
            } else {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'pdf_generation_failed', []);
                }
            }

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_silent_done', [
                    'attempt' => (int)$attempt,
                ]);
            }

            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, (string) time());

            if (class_exists('Spolek_Archive')) {
                $res = Spolek_Archive::archive_vote($vote_post_id, false);
                if (!(is_array($res) && !empty($res['ok']))) {
                    $err = is_array($res) ? (string)($res['error'] ?? 'archive_failed') : 'archive_failed';
                    if (class_exists('Spolek_Audit')) {
                        Spolek_Audit::log($vote_post_id, null, 'archive_auto_failed', ['error' => $err]);
                    }
                }
            }

            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT);
            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR);
            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY);

        } catch (\Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 500);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR, $msg);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_silent_exception', [
                    'msg'  => $msg,
                    'file' => basename((string)$e->getFile()),
                    'line' => (int)$e->getLine(),
                ]);
            }

            self::schedule_close_retry($vote_post_id, (int)$attempt, 'silent_exception');

        } finally {
            self::release_close_lock($vote_post_id, $lock_token);
        }
    }

    // ======================================================================
    // Uzávěrka – NORMAL (s rozesílkou)
    // ======================================================================

    private static function close_normal(int $vote_post_id): void {
        $vote_post_id = (int)$vote_post_id;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Hlasovani_MVP::CPT) return;

        $processed_at = get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_processed', [
                    'processed_at' => $processed_at,
                ]);
            }
            return;
        }

        $lock_token = self::acquire_close_lock($vote_post_id, 600);
        if (!$lock_token) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_lock_busy', ['now' => time()]);
            }
            if (!wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$vote_post_id])) {
                wp_schedule_single_event(time() + 120, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
            }
            return;
        }

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, 'cron_close_start', ['now' => time()]);
        }

        $attempt = 0;

        try {
            [$start_ts, $end_ts, $text] = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_called', [
                    'start_ts' => (int)$start_ts,
                    'end_ts'   => (int)$end_ts,
                    'now'      => time(),
                ]);
            }

            $now = time();
            if ($now < (int)$end_ts) {
                wp_clear_scheduled_hook(Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
                wp_schedule_single_event(((int)$end_ts) + 60, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);

                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'cron_close_rescheduled', [
                        'now'    => $now,
                        'end_ts' => (int)$end_ts,
                    ]);
                }
                return;
            }

            $status_now = Spolek_Hlasovani_MVP::get_status((int)$start_ts, (int)$end_ts);
            if ($status_now !== 'closed') {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_not_closed', [
                        'status' => $status_now,
                        'now'    => time(),
                        'end_ts' => (int)$end_ts,
                    ]);
                }
                return;
            }

            $attempt = (int) get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, true);
            $attempt++;
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_ATTEMPTS, (string)$attempt);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT, (string) time());

            $map = class_exists('Spolek_Votes')
                ? Spolek_Votes::get_counts($vote_post_id)
                : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

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

            $pdf_path = class_exists('Spolek_PDF_Service')
                ? Spolek_PDF_Service::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts)
                : Spolek_Hlasovani_MVP::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);
            $attachments = [];

            if ($pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                        'pdf'   => basename($pdf_path),
                        'bytes' => (int) filesize($pdf_path),
                    ]);
                }
            } else {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                        'pdf'   => null,
                        'error' => 'pdf not generated',
                    ]);
                }
            }

            $sent = 0; $skipped = 0; $failed = 0; $no_email = 0; $total = 0;
            $members = Spolek_Hlasovani_MVP::get_members();

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_start', [
                    'members' => is_array($members) ? count($members) : 0,
                    'has_pdf' => !empty($attachments) ? 1 : 0,
                ]);
            }

            foreach ((array)$members as $u) {
                $total++;

                $exp = time() + (30 * DAY_IN_SECONDS);
                $uid = (int) $u->ID;
                if (class_exists('Spolek_PDF_Service')) {
                    $sig = Spolek_PDF_Service::member_sig($uid, $vote_post_id, $exp);
                    $pdf_link = Spolek_PDF_Service::member_landing_url($vote_post_id, $uid, $exp, $sig);
                } else {
                    // fallback
                    $sig = Spolek_Hlasovani_MVP::member_pdf_sig($uid, $vote_post_id, $exp);
                    $landing = trailingslashit(home_url('/clenove/stazeni-zapisu/'));
                    $pdf_link = add_query_arg([
                        'vote_post_id' => $vote_post_id,
                        'uid'          => $uid,
                        'exp'          => $exp,
                        'sig'          => $sig,
                    ], $landing);
                }

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

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_done', [
                    'total'    => (int)$total,
                    'sent'     => (int)$sent,
                    'skip'     => (int)$skipped,
                    'no_email' => (int)$no_email,
                    'failed'   => (int)$failed,
                    'has_pdf'  => !empty($attachments) ? 1 : 0,
                ]);
            }

            if ($failed > 0) {
                update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR, "result mails failed: $failed");
                throw new \RuntimeException("result mails failed: $failed");
            }

            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, (string) time());

            // best-effort archiv
            if (class_exists('Spolek_Archive')) {
                $res = Spolek_Archive::archive_vote($vote_post_id, false);
                if (!(is_array($res) && !empty($res['ok']))) {
                    $err = is_array($res) ? (string)($res['error'] ?? 'archive_failed') : 'archive_failed';
                    if (class_exists('Spolek_Audit')) {
                        Spolek_Audit::log($vote_post_id, null, 'archive_auto_failed', ['error' => $err]);
                    }
                }
            }

            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_STARTED_AT);
            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR);
            delete_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY);

        } catch (\Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 500);
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_LAST_ERROR, $msg);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_exception', [
                    'attempt' => (int)$attempt,
                    'message' => $msg,
                    'code'    => (int)$e->getCode(),
                    'file'    => basename((string)$e->getFile()),
                    'line'    => (int)$e->getLine(),
                ]);
            }

            self::schedule_close_retry($vote_post_id, (int)$attempt, 'exception');

        } finally {
            self::release_close_lock($vote_post_id, $lock_token);
        }
    }

    // ======================================================================
    // Lock + retry
    // ======================================================================

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
        return (int)$delays[$idx];
    }

    private static function schedule_close_retry(int $vote_post_id, int $attempt, string $reason): void {
        $processed_at = get_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) return;

        if ($attempt >= Spolek_Hlasovani_MVP::CLOSE_MAX_ATTEMPTS) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_give_up', [
                    'attempt' => $attempt,
                    'max'     => Spolek_Hlasovani_MVP::CLOSE_MAX_ATTEMPTS,
                    'reason'  => $reason,
                ]);
            }
            return;
        }

        $delay  = self::close_retry_delay_seconds($attempt);
        $jitter = (int) wp_rand(0, 30);
        $when   = time() + $delay + $jitter;

        $next = wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
        if ($next && $next > (time() + 10)) {
            update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY, (string)$next);
            return;
        }

        wp_schedule_single_event($when, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
        update_post_meta($vote_post_id, Spolek_Hlasovani_MVP::META_CLOSE_NEXT_RETRY, (string)$when);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_scheduled', [
                'attempt' => $attempt,
                'when'    => $when,
                'delay'   => $delay,
                'reason'  => $reason,
            ]);
        }
    }
}

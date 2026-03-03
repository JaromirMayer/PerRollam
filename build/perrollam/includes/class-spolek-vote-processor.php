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

        if ($type !== 'reminder48' && $type !== 'reminder24') return;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Config::CPT) return;

        if (!class_exists('Spolek_Mailer')) return;

        // Vote-level idempotence: pokud jsme už batch úspěšně dokončili, nic nedělej.
        $done_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_DONE_AT : Spolek_Config::META_REMINDER24_DONE_AT;
        $done_at  = (string) get_post_meta($vote_post_id, $done_key, true);
        if ($done_at !== '') {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_REMINDER_SKIP_DONE, ['type' => $type, 'done_at' => $done_at]);
            }
            return;
        }

        $lock_token = self::acquire_reminder_lock($vote_post_id, $type, 300);
        if (!$lock_token) {
            // lock busy -> krátký retry
            self::schedule_reminder_retry($vote_post_id, $type, 1, 'lock_busy');
            return;
        }

        try {
            if (class_exists('Spolek_Cron_Status')) {
                Spolek_Cron_Status::touch(Spolek_Config::HOOK_REMINDER, true);
            }

            [$start_ts, $end_ts] = [
                (int) get_post_meta($vote_post_id, Spolek_Config::META_START_TS, true),
                (int) get_post_meta($vote_post_id, Spolek_Config::META_END_TS, true),
            ];
            if ($start_ts && $end_ts) {
                $status = Spolek_Vote_Service::get_status($start_ts, $end_ts);
                if ($status !== 'open') {
                    // mimo okno -> už nemá smysl retry
                    update_post_meta($vote_post_id, $done_key, (string) time());
                    return;
                }
            }

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_REMINDER_START, ['type' => $type]);
            }

            $stats = (array) Spolek_Mailer::send_reminder($vote_post_id, $type);
            $failed = (int)($stats['failed'] ?? 0);

            if ($failed <= 0) {
                update_post_meta($vote_post_id, $done_key, (string) time());
                // cleanup
                delete_post_meta($vote_post_id, ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_ATTEMPTS : Spolek_Config::META_REMINDER24_ATTEMPTS);
                delete_post_meta($vote_post_id, ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_LAST_ERROR : Spolek_Config::META_REMINDER24_LAST_ERROR);
                delete_post_meta($vote_post_id, ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_NEXT_RETRY : Spolek_Config::META_REMINDER24_NEXT_RETRY);

                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_REMINDER_DONE, array_merge(['type' => $type], $stats));
                }
                return;
            }

            // failures -> retry (idempotence na úrovni člena drží mail log)
            $attempt_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_ATTEMPTS : Spolek_Config::META_REMINDER24_ATTEMPTS;
            $attempt = (int) get_post_meta($vote_post_id, $attempt_key, true);
            $attempt++;
            update_post_meta($vote_post_id, $attempt_key, (string)$attempt);

            $err_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_LAST_ERROR : Spolek_Config::META_REMINDER24_LAST_ERROR;
            update_post_meta($vote_post_id, $err_key, 'reminder failed: ' . $failed);

            self::schedule_reminder_retry($vote_post_id, $type, $attempt, 'failed_' . $failed);

        } catch (Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 300);
            if (class_exists('Spolek_Cron_Status')) {
                Spolek_Cron_Status::touch(Spolek_Config::HOOK_REMINDER, false, $msg);
            }
            $err_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_LAST_ERROR : Spolek_Config::META_REMINDER24_LAST_ERROR;
            update_post_meta($vote_post_id, $err_key, $msg);
            self::schedule_reminder_retry($vote_post_id, $type, 1, 'exception');
        } finally {
            self::release_reminder_lock($vote_post_id, $type, $lock_token);
        }
    }

    // ======================================================================
    // Reminder lock + retry
    // ======================================================================

    private static function reminder_lock_key(int $vote_post_id, string $type): string {
        return 'spolek_vote_rem_lock_' . $vote_post_id . '_' . $type;
    }

    private static function acquire_reminder_lock(int $vote_post_id, string $type, int $ttl = 300): ?string {
        $key = self::reminder_lock_key($vote_post_id, $type);

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

    private static function release_reminder_lock(int $vote_post_id, string $type, string $token): void {
        $key = self::reminder_lock_key($vote_post_id, $type);
        $existing = (string) get_option($key, '');
        if ($existing === '') return;

        $parts = explode('|', $existing, 2);
        $existing_token = (string)($parts[1] ?? '');
        if ($existing_token !== '' && hash_equals($existing_token, $token)) {
            delete_option($key);
        }
    }

    private static function reminder_retry_delay_seconds(int $attempt): int {
        $delays = [300, 900, 1800, 3600]; // 5m,15m,30m,60m
        $idx = max(1, $attempt) - 1;
        if ($idx >= count($delays)) $idx = count($delays) - 1;
        return (int)$delays[$idx];
    }

    private static function schedule_reminder_retry(int $vote_post_id, string $type, int $attempt, string $reason): void {
        $vote_post_id = (int)$vote_post_id;
        if ($attempt >= (int)Spolek_Config::REMINDER_MAX_ATTEMPTS) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_REMINDER_RETRY_GIVE_UP, ['type' => $type, 'attempt' => $attempt, 'reason' => $reason]);
            }
            // už dál nespouštíme – aby se to netočilo
            $done_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_DONE_AT : Spolek_Config::META_REMINDER24_DONE_AT;
            update_post_meta($vote_post_id, $done_key, (string) time());
            return;
        }

        $delay  = self::reminder_retry_delay_seconds($attempt);
        $jitter = (int) wp_rand(0, 30);
        $when   = time() + $delay + $jitter;

        // pokud už je naplánováno dřív, neplánuj druhé
        $next = wp_next_scheduled(Spolek_Config::HOOK_REMINDER, [$vote_post_id, $type]);
        if ($next && $next <= ($when + 10)) {
            return;
        }

        wp_schedule_single_event($when, Spolek_Config::HOOK_REMINDER, [$vote_post_id, $type]);
        $next_key = ($type === 'reminder48') ? Spolek_Config::META_REMINDER48_NEXT_RETRY : Spolek_Config::META_REMINDER24_NEXT_RETRY;
        update_post_meta($vote_post_id, $next_key, (string)$when);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_REMINDER_RETRY_SCHEDULED, [
                'type'   => $type,
                'attempt'=> $attempt,
                'when'   => $when,
                'delay'  => $delay,
                'reason' => $reason,
            ]);
        }
    }

    // ======================================================================
    // Uzávěrka – SILENT
    // ======================================================================

    private static function close_silent(int $vote_post_id): void {
        $vote_post_id = (int)$vote_post_id;

        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== Spolek_Config::CPT) return;

        $processed_at = get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) {
            // hotovo -> pro jistotu vyčistíme plánované hooky (duplicitní běhy)
            if (class_exists('Spolek_Cron')) {
                Spolek_Cron::clear_vote_events($vote_post_id);
            }
            return;
        }

        // Stop endless loops: max pokusů = give up
        $max = (int) Spolek_Config::CLOSE_MAX_ATTEMPTS;
        $attempts_so_far = (int) get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, true);
        if ($attempts_so_far >= $max) {
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_GAVE_UP_AT, (string) time());
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SKIP_MAX, [
                    'attempts' => $attempts_so_far,
                    'max'      => $max,
                    'mode'     => 'silent',
                ]);
            }
            return;
        }

        $lock_token = self::acquire_close_lock($vote_post_id, 600);
        if (!$lock_token) {
            if (!wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$vote_post_id])) {
                wp_schedule_single_event(time() + 120, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
            }
            return;
        }

        $attempt = 0;

        try {
            [$start_ts, $end_ts, $text] = Spolek_Vote_Service::get_vote_meta($vote_post_id);

            $now = time();
            if ($now < (int)$end_ts) {
                wp_clear_scheduled_hook(Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
                wp_schedule_single_event(((int)$end_ts) + 60, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
                return;
            }

            $status_now = Spolek_Vote_Service::get_status((int)$start_ts, (int)$end_ts);
            if ($status_now !== 'closed') return;

            $attempt = (int) get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, true);
            $attempt++;
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, (string)$attempt);
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_STARTED_AT, (string) time());

            $map = class_exists('Spolek_Votes')
                ? Spolek_Votes::get_counts($vote_post_id)
                : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

            $eval = Spolek_Vote_Service::evaluate_vote($vote_post_id, $map);

            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_LABEL, $eval['label']);
            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_EXPLAIN, $eval['explain']);
            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_ADOPTED, $eval['adopted'] ? '1' : '0');

            $pdf_path = class_exists('Spolek_PDF_Service')
                ? Spolek_PDF_Service::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts)
                : Spolek_Hlasovani_MVP::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

            if ($pdf_path && file_exists($pdf_path)) {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PDF_GENERATED, [
                        'pdf'   => basename($pdf_path),
                        'bytes' => (int) filesize($pdf_path),
                    ]);
                }
            } else {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PDF_GENERATION_FAILED, []);
                }
            }
            if (class_exists("Spolek_Mailer")) {
                // tichý režim: NEodesílá e-maily, jen loguje do auditu (+ do mail logu pro archiv mail_log.csv)
                Spolek_Mailer::send_result($vote_post_id, (array)$map, (array)$eval, (string)$text, (int)$start_ts, (int)$end_ts, $pdf_path ?: null, true);
            }


            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SILENT_DONE, [
                    'attempt' => (int)$attempt,
                ]);
            }

            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, (string) time());

            // po uzávěrce už nechceme žádné další close/reminder eventy
            if (class_exists('Spolek_Cron')) {
                Spolek_Cron::clear_vote_events($vote_post_id);
            }

            if (class_exists('Spolek_Archive')) {
                $res = Spolek_Archive::archive_vote($vote_post_id, false);
                if (!(is_array($res) && !empty($res['ok']))) {
                    $err = is_array($res) ? (string)($res['error'] ?? 'archive_failed') : 'archive_failed';
                    if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_AUTO_FAILED, ['error' => $err]);
                    }
                }
            }

            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_STARTED_AT);
            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_LAST_ERROR);
            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY);

        } catch (\Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 500);
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_LAST_ERROR, $msg);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SILENT_EXCEPTION, [
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
        if (!$post || $post->post_type !== Spolek_Config::CPT) return;

        $processed_at = get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SKIP_PROCESSED, [
                    'processed_at' => $processed_at,
                ]);
            }
            if (class_exists('Spolek_Cron')) {
                Spolek_Cron::clear_vote_events($vote_post_id);
            }
            return;
        }

        // Stop endless loops: max pokusů = give up
        $max = (int) Spolek_Config::CLOSE_MAX_ATTEMPTS;
        $attempts_so_far = (int) get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, true);
        if ($attempts_so_far >= $max) {
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_GAVE_UP_AT, (string) time());
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SKIP_MAX, [
                    'attempts' => $attempts_so_far,
                    'max'      => $max,
                    'mode'     => 'normal',
                ]);
            }
            return;
        }

        $lock_token = self::acquire_close_lock($vote_post_id, 600);
        if (!$lock_token) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_LOCK_BUSY, ['now' => time()]);
            }
            if (!wp_next_scheduled(Spolek_Config::HOOK_CLOSE, [$vote_post_id])) {
                wp_schedule_single_event(time() + 120, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
            }
            return;
        }

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_START, ['now' => time()]);
        }

        $attempt = 0;

        try {
            [$start_ts, $end_ts, $text] = Spolek_Vote_Service::get_vote_meta($vote_post_id);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_CALLED, [
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
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_RESCHEDULED, [
                        'now'    => $now,
                        'end_ts' => (int)$end_ts,
                    ]);
                }
                return;
            }

            $status_now = Spolek_Vote_Service::get_status((int)$start_ts, (int)$end_ts);
            if ($status_now !== 'closed') {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_SKIP_NOT_CLOSED, [
                        'status' => $status_now,
                        'now'    => time(),
                        'end_ts' => (int)$end_ts,
                    ]);
                }
                return;
            }

            $attempt = (int) get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, true);
            $attempt++;
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_ATTEMPTS, (string)$attempt);
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_STARTED_AT, (string) time());

            $map = class_exists('Spolek_Votes')
                ? Spolek_Votes::get_counts($vote_post_id)
                : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

            $eval = Spolek_Vote_Service::evaluate_vote($vote_post_id, $map);

            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_LABEL, $eval['label']);
            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_EXPLAIN, $eval['explain']);
            update_post_meta($vote_post_id, Spolek_Config::META_RESULT_ADOPTED, $eval['adopted'] ? '1' : '0');

            $pdf_path = class_exists('Spolek_PDF_Service')
                ? Spolek_PDF_Service::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts)
                : Spolek_Hlasovani_MVP::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

            if ($pdf_path && file_exists($pdf_path)) {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PDF_GENERATED, [
                        'pdf'   => basename($pdf_path),
                        'bytes' => (int) filesize($pdf_path),
                    ]);
                }
            } else {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PDF_GENERATION_FAILED, [
                        'error' => 'pdf_not_generated',
                    ]);
                }
            }

            $stats_mail = class_exists('Spolek_Mailer')
                ? Spolek_Mailer::send_result($vote_post_id, (array)$map, (array)$eval, (string)$text, (int)$start_ts, (int)$end_ts, $pdf_path ?: null, false)
                : ['failed' => 0];

            if ((int)($stats_mail['failed'] ?? 0) > 0) {
                $failed = (int)($stats_mail['failed'] ?? 0);
                update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_LAST_ERROR, "result mails failed: $failed");
                throw new \RuntimeException("result mails failed: $failed");
            }


            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, (string) time());

            // po uzávěrce už nechceme žádné další close/reminder eventy
            if (class_exists('Spolek_Cron')) {
                Spolek_Cron::clear_vote_events($vote_post_id);
            }

            // best-effort archiv
            if (class_exists('Spolek_Archive')) {
                $res = Spolek_Archive::archive_vote($vote_post_id, false);
                if (!(is_array($res) && !empty($res['ok']))) {
                    $err = is_array($res) ? (string)($res['error'] ?? 'archive_failed') : 'archive_failed';
                    if (class_exists('Spolek_Audit')) {
                        Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_AUTO_FAILED, ['error' => $err]);
                    }
                }
            }

            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_STARTED_AT);
            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_LAST_ERROR);
            delete_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY);

        } catch (\Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 500);
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_LAST_ERROR, $msg);

            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_EXCEPTION, [
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
        $processed_at = get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
        if (!empty($processed_at)) return;

        if ($attempt >= Spolek_Config::CLOSE_MAX_ATTEMPTS) {
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_GAVE_UP_AT, (string) time());
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_RETRY_GIVE_UP, [
                    'attempt' => $attempt,
                    'max'     => Spolek_Config::CLOSE_MAX_ATTEMPTS,
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
            update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY, (string)$next);
            return;
        }

        wp_schedule_single_event($when, Spolek_Config::HOOK_CLOSE, [$vote_post_id]);
        update_post_meta($vote_post_id, Spolek_Config::META_CLOSE_NEXT_RETRY, (string)$when);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::CRON_CLOSE_RETRY_SCHEDULED, [
                'attempt' => $attempt,
                'when'    => $when,
                'delay'   => $delay,
                'reason'  => $reason,
            ]);
        }
    }
}

<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Votes_Controller {

    private static bool $registered = false;

    public function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        add_action('admin_post_spolek_create_vote', [__CLASS__, 'handle_create_vote']);
        add_action('admin_post_spolek_cast_vote',   [__CLASS__, 'handle_cast_vote']);
        add_action('admin_post_spolek_export_csv',  [__CLASS__, 'handle_export_csv']);
    }

    public static function handle_create_vote(): void {
        Spolek_Admin::require_manager();
        Spolek_Admin::verify_nonce_post('spolek_create_vote');

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        $title = sanitize_text_field($_POST['title'] ?? '');
        $text  = sanitize_textarea_field($_POST['text'] ?? '');
        $start = sanitize_text_field($_POST['start'] ?? '');
        $end   = sanitize_text_field($_POST['end'] ?? '');

        $tz = wp_timezone();
        $start_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $start, $tz);
        $end_dt   = DateTimeImmutable::createFromFormat('Y-m-d H:i', $end, $tz);
        $start_ts = $start_dt ? $start_dt->getTimestamp() : 0;
        $end_ts   = $end_dt ? $end_dt->getTimestamp() : 0;

        if (!$title || !$text || !$start_ts || !$end_ts || $end_ts <= $start_ts) {
            Spolek_Admin::redirect_with_error($return_to, 'Neplatné údaje (zkontrolujte datum/čas).');
        }

        $post_id = wp_insert_post([
            'post_type'   => Spolek_Config::CPT,
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($post_id)) {
            Spolek_Admin::redirect_with_error($return_to, 'Nelze vytvořit hlasování.');
        }
        $post_id = (int) $post_id;

        update_post_meta($post_id, Spolek_Config::META_TEXT, $text);
        update_post_meta($post_id, Spolek_Config::META_START_TS, (int)$start_ts);
        update_post_meta($post_id, Spolek_Config::META_END_TS, (int)$end_ts);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($post_id, get_current_user_id(), Spolek_Audit_Events::VOTE_CREATED, [
                'title' => $title,
            ]);
        }

        $ruleset = sanitize_text_field($_POST['ruleset'] ?? 'standard');
        if (!in_array($ruleset, ['standard', 'two_thirds'], true)) $ruleset = 'standard';

        $pass_base = sanitize_text_field($_POST['pass_base'] ?? 'valid');
        if (!in_array($pass_base, ['valid', 'all'], true)) $pass_base = 'valid';

        $to_ratio = static function($v) {
            $v = trim((string)$v);
            if ($v === '') return null;
            $v = str_replace(',', '.', $v);
            $r = ((float)$v) / 100.0;
            if ($r < 0) $r = 0;
            if ($r > 1) $r = 1;
            return $r;
        };

        $quorum_ratio = $to_ratio($_POST['quorum_ratio'] ?? '');
        $pass_ratio   = $to_ratio($_POST['pass_ratio'] ?? '');

        update_post_meta($post_id, Spolek_Config::META_RULESET, $ruleset);
        update_post_meta($post_id, Spolek_Config::META_BASE, $pass_base);

        if ($quorum_ratio === null) delete_post_meta($post_id, Spolek_Config::META_QUORUM_RATIO);
        else update_post_meta($post_id, Spolek_Config::META_QUORUM_RATIO, (string)$quorum_ratio);

        if ($pass_ratio === null) delete_post_meta($post_id, Spolek_Config::META_PASS_RATIO);
        else update_post_meta($post_id, Spolek_Config::META_PASS_RATIO, (string)$pass_ratio);

        // naplánovat připomínky + výsledek
        if (class_exists('Spolek_Cron')) {
            Spolek_Cron::schedule_vote_events($post_id, (int)$start_ts, (int)$end_ts);
        }

        // odeslat oznámení všem členům
        if (class_exists('Spolek_Mailer')) {
            Spolek_Mailer::send_announce($post_id);
        }

        wp_safe_redirect(add_query_arg('created', '1', $return_to));
        exit;
    }

    public static function handle_cast_vote(): void {
        Spolek_Admin::require_login();

        $return_to = Spolek_Admin::get_return_to(Spolek_Admin::default_return_to());

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        $choice = sanitize_text_field($_POST['choice'] ?? '');
        $user_id = get_current_user_id();

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id ?: 0, $user_id, Spolek_Audit_Events::VOTE_CAST_ATTEMPT, [
                'choice' => $choice ?: null,
            ]);
        }

        if (!$vote_post_id || !in_array($choice, ['ANO','NE','ZDRZEL'], true)) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id ?: 0, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
                    'reason' => 'invalid_choice_or_vote_id',
                    'choice' => $choice ?: null,
                ]);
            }
            Spolek_Admin::redirect_detail_error($return_to, $vote_post_id, 'Neplatná volba.');
        }

        Spolek_Admin::verify_nonce_post('spolek_cast_vote_' . $vote_post_id);

        [$start_ts, $end_ts] = Spolek_Admin::vote_times($vote_post_id);
        $status = Spolek_Admin::vote_status((int)$start_ts, (int)$end_ts);
        if ($status !== 'open') {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
                    'reason' => 'not_open',
                    'status' => $status,
                ]);
            }
            Spolek_Admin::redirect_detail_error($return_to, $vote_post_id, 'Hlasování není otevřené.');
        }

        if (class_exists('Spolek_Votes') && Spolek_Votes::has_user_voted($vote_post_id, $user_id)) {
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
                    'reason' => 'already_voted',
                ]);
            }
            Spolek_Admin::redirect_detail_error($return_to, $vote_post_id, 'Už jste hlasoval(a).');
        }

        $ok = class_exists('Spolek_Votes') ? Spolek_Votes::insert_vote($vote_post_id, $user_id, $choice) : false;

        if (!$ok) {
            global $wpdb;
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_FAILED, [
                    'reason'   => 'db_insert_failed',
                    'db_error' => $wpdb->last_error ?: null,
                ]);
            }
            Spolek_Admin::redirect_detail_error($return_to, $vote_post_id, 'Nelze uložit hlas (možná duplicitní).');
        }

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_SAVED, [
                'choice' => $choice,
            ]);
        }

        wp_safe_redirect(add_query_arg(['spolek_vote' => $vote_post_id, 'voted' => '1'], $return_to));
        exit;
    }

    public static function handle_export_csv(): void {
        Spolek_Admin::require_manager();

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        Spolek_Admin::verify_nonce_post('spolek_export_csv_' . $vote_post_id);

        $rows = class_exists('Spolek_Votes') ? Spolek_Votes::export_rows($vote_post_id) : [];

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, get_current_user_id(), Spolek_Audit_Events::CSV_EXPORTED, [
                'rows' => is_array($rows) ? count($rows) : 0,
            ]);
        }

        $filename = 'hlasovani-' . $vote_post_id . '-hlasy.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['user_id','user_email','choice','cast_at']);

        foreach ($rows as $r) {
            $u = get_user_by('id', (int)($r['user_id'] ?? 0));
            fputcsv($out, [
                $r['user_id'] ?? '',
                $u ? $u->user_email : '',
                $r['choice'] ?? '',
                $r['cast_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}

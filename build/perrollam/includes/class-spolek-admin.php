<?php
if (!defined('ABSPATH')) exit;

/**
 * Společné utility pro admin_post handlery (auth, nonce, redirecty, helpery).
 *
 * Cíl: aby controllery nemusely sahat do Spolek_Hlasovani_MVP (legacy).
 */
final class Spolek_Admin {

    /** Bezpečný IP string (pro rate-limit). */
    public static function client_ip(): string {
        $ip = '';
        // Preferuj REMOTE_ADDR. HTTP_X_FORWARDED_FOR je snadno spoofovatelný.
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        $ip = trim($ip);
        if ($ip === '') $ip = '0.0.0.0';
        // normalizace
        return substr(preg_replace('/[^0-9a-fA-F:\.]/', '', $ip), 0, 64);
    }

    /** Jednoduchý throttle (transient). */
    public static function throttle_or_die(string $bucket, int $limit, int $window_seconds): void {
        $bucket = trim((string)$bucket);
        if ($bucket === '' || $limit <= 0 || $window_seconds <= 0) return;

        $uid = is_user_logged_in() ? (int)get_current_user_id() : 0;
        $ip  = self::client_ip();

        $key = 'spolek_rl_' . substr(sha1($bucket . '|' . $uid . '|' . $ip), 0, 24);
        $now = time();

        $st = get_transient($key);
        $count = 0;
        $start = $now;
        if (is_array($st)) {
            $count = (int)($st['c'] ?? 0);
            $start = (int)($st['s'] ?? $now);
        }

        if (($now - $start) >= $window_seconds) {
            $count = 0;
            $start = $now;
        }

        $count++;
        set_transient($key, ['c' => $count, 's' => $start], $window_seconds + 5);

        if ($count > $limit) {
            wp_die('Příliš mnoho požadavků. Zkuste to prosím za chvíli.', 'Rate limit', ['response' => 429]);
        }
    }

    public static function is_manager(): bool {
        if (!is_user_logged_in()) return false;
        if (current_user_can(Spolek_Config::CAP_MANAGE)) return true;

        // fallback na roli podle názvu (kdyby capability nebyla přiřazena)
        $user = wp_get_current_user();
        return in_array('spravce_hlasovani', (array)$user->roles, true);
    }

    public static function require_manager(): void {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }
    }

    public static function require_login(): void {
        if (!is_user_logged_in()) {
            wp_die('Musíte být přihlášeni.');
        }
    }

    public static function verify_nonce_post(string $action, string $field = '_nonce'): void {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string)$_POST[$field])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
            wp_die('Neplatný nonce.');
        }
    }

    public static function verify_nonce_get(string $action, string $field = '_nonce'): void {
        $nonce = isset($_GET[$field]) ? sanitize_text_field(wp_unslash((string)$_GET[$field])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
            wp_die('Neplatný nonce.');
        }
    }

    public static function get_return_to(string $fallback): string {
        $rt = isset($_POST['return_to']) ? esc_url_raw(wp_unslash((string)$_POST['return_to'])) : '';
        if ($rt) return $rt;

        $ref = wp_get_referer();
        if ($ref) return $ref;

        return $fallback;
    }

    /** Default návratová URL (portál). */
    public static function default_return_to(): string {
        if (class_exists('Spolek_Vote_Service')) {
            $url = (string) Spolek_Vote_Service::portal_base_url();
            if ($url) return $url;
        }
        return home_url('/clenove/hlasovani/');
    }

    public static function redirect_with_error(string $return_to, string $msg): void {
        wp_safe_redirect(add_query_arg('err', rawurlencode($msg), $return_to));
        exit;
    }

    public static function redirect_with_notice(string $return_to, string $msg): void {
        wp_safe_redirect(add_query_arg('notice', rawurlencode($msg), $return_to));
        exit;
    }

    /**
     * Bezpečný redirect s více query parametry (string=>string/int).
     * @param array<string,string|int> $args
     */
    public static function redirect_with_args(string $return_to, array $args): void {
        $safe = [];
        foreach ($args as $k => $v) {
            $safe[(string)$k] = is_int($v) ? (string)$v : (string)$v;
        }

        wp_safe_redirect(add_query_arg($safe, $return_to));
        exit;
    }


    /**
     * Redirect na detail hlasování (přidá parametr spolek_vote).
     * @param array<string,string|int> $args
     */
    public static function redirect_detail_args(string $return_to, int $vote_post_id, array $args): void {
        // Preferujeme veřejný token v URL (parametr v=...) – proti enumeration.
        if (class_exists('Spolek_Vote_Service')) {
            $base = (string) Spolek_Vote_Service::vote_detail_url($vote_post_id);
            self::redirect_with_args($base, $args);
        }

        $args = array_merge(['spolek_vote' => (int)$vote_post_id], $args);
        self::redirect_with_args($return_to, $args);
    }

    public static function redirect_detail_error(string $return_to, int $vote_post_id, string $msg): void {
        if (class_exists('Spolek_Vote_Service')) {
            $base = (string) Spolek_Vote_Service::vote_detail_url($vote_post_id);
            wp_safe_redirect(add_query_arg(['err' => rawurlencode($msg)], $base));
            exit;
        }

        wp_safe_redirect(add_query_arg(['spolek_vote' => $vote_post_id, 'err' => rawurlencode($msg)], $return_to));
        exit;
    }

    /** upcoming | open | closed */
    public static function vote_status(int $start_ts, int $end_ts): string {
        $now = time();
        if ($now < $start_ts) return 'upcoming';
        if ($now < $end_ts) return 'open';
        return 'closed';
    }

    /** @return array{0:int,1:int} [start_ts,end_ts] */
    public static function vote_times(int $post_id): array {
        $start_ts = (int) get_post_meta($post_id, Spolek_Config::META_START_TS, true);
        $end_ts   = (int) get_post_meta($post_id, Spolek_Config::META_END_TS, true);
        return [$start_ts, $end_ts];
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Společné utility pro admin_post handlery (auth, nonce, redirecty, helpery).
 *
 * Cíl: aby controllery nemusely sahat do Spolek_Hlasovani_MVP (legacy).
 */
final class Spolek_Admin {

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
        $nonce = isset($_POST[$field]) ? (string)$_POST[$field] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
            wp_die('Neplatný nonce.');
        }
    }

    public static function verify_nonce_get(string $action, string $field = '_nonce'): void {
        $nonce = isset($_GET[$field]) ? (string)$_GET[$field] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $action)) {
            wp_die('Neplatný nonce.');
        }
    }

    public static function get_return_to(string $fallback): string {
        $rt = isset($_POST['return_to']) ? esc_url_raw((string)$_POST['return_to']) : '';
        if ($rt) return $rt;

        $ref = wp_get_referer();
        if ($ref) return $ref;

        return $fallback;
    }

    public static function redirect_with_error(string $return_to, string $msg): void {
        wp_safe_redirect(add_query_arg('err', rawurlencode($msg), $return_to));
        exit;
    }

    public static function redirect_detail_error(string $return_to, int $vote_post_id, string $msg): void {
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

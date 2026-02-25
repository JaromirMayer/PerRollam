<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Spolek_Portal {

    public function register(): void {
        add_shortcode('spolek_hlasovani_portal', [$this, 'shortcode']);
        add_shortcode('spolek_userbar', [$this, 'shortcode_userbar']);
    }

    public function shortcode($atts = [], $content = null): string {
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'render_portal')) {
            return (string) Spolek_Hlasovani_MVP::render_portal();
        }
        return '';
    }

    public function shortcode_userbar($atts = [], $content = null): string {
        if (!is_user_logged_in()) {
            return '';
        }

        $atts = shortcode_atts([
            // kam se vrátit po odhlášení (můžeš dát třeba / nebo /login/)
            'redirect' => home_url('/login/'),
            'label'    => 'Odhlásit se',
            'email'    => '0', // '1' = zobrazit e-mail
        ], $atts, 'spolek_userbar');

        $u = wp_get_current_user();
        $name = $u->display_name ?: $u->user_login;

        // DŮLEŽITÉ: používej wp_logout_url() → Theme My Login si to přefiltruje na /logout + nonce
        $logout_url = wp_logout_url($atts['redirect']);

        $html  = '<div class="spolek-userbar" style="max-width:720px;margin:0 0 14px 0;padding:12px 14px;border:1px solid rgba(0,0,0,.12);border-radius:10px;background:#fff;">';
        $html .= '<div style="margin-bottom:10px;"><strong>Přihlášený uživatel:</strong> ' . esc_html($name);

        if ($atts['email'] === '1' && !empty($u->user_email)) {
            $html .= ' <span style="opacity:.75">(' . esc_html($u->user_email) . ')</span>';
        }

        $html .= '</div>';
        $html .= '<a href="' . esc_url($logout_url) . '" style="display:inline-block;padding:10px 14px;border-radius:8px;background:#2271b1;color:#fff;text-decoration:none;">' . esc_html($atts['label']) . '</a>';
        $html .= '</div>';

        return $html;
    }
}

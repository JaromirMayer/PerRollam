<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Spolek_Portal {

    public function register(): void {
        add_shortcode('spolek_hlasovani_portal', [$this, 'shortcode']);
    }

    public function shortcode($atts = [], $content = null): string {
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'render_portal')) {
            return (string) Spolek_Hlasovani_MVP::render_portal();
        }
        return '';
    }
}

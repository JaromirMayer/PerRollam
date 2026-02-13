<?php
if (!defined('ABSPATH')) exit;

final class Spolek_PDF {

    public function register(): void {
        add_shortcode('spolek_pdf_landing', [$this, 'landing_shortcode']);
    }

    public function landing_shortcode($atts = [], $content = null): string {
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'shortcode_pdf_landing')) {
            return (string) Spolek_Hlasovani_MVP::shortcode_pdf_landing();
        }
        return '';
    }
}

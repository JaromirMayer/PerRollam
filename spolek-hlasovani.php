<?php
/**
 * Plugin Name: Spolek – Hlasování per rollam (MVP)
 * Description: Front-end hlasování pro členy spolku (ANO/NE/ZDRŽEL), 1 hlas na člena, uzávěrka a export CSV.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

// Jednotné konstanty pro celý plugin
if (!defined('SPOLEK_HLASOVANI_PATH')) {
    define('SPOLEK_HLASOVANI_PATH', plugin_dir_path(__FILE__));
}
if (!defined('SPOLEK_HLASOVANI_FILE')) {
    define('SPOLEK_HLASOVANI_FILE', __FILE__);
}

// Načti jen kabeláž (ne audit/legacy přímo)
require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-plugin.php';

// Spusť plugin (registruje shortcode na init atd.)
add_action('plugins_loaded', function () {
    Spolek_Plugin::instance()->run();
});

// Aktivace
register_activation_hook(SPOLEK_HLASOVANI_FILE, ['Spolek_Plugin', 'activate']);

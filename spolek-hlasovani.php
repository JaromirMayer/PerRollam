<?php
/**
 * Plugin Name: Spolek – Hlasování per rollam (MVP)
 * Description: Front-end hlasování pro členy spolku (ANO/NE/ZDRŽEL), 1 hlas na člena, uzávěrka a export CSV.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;

if (!defined('SPOLEK_HLASOVANI_DIR')) {
    define('SPOLEK_HLASOVANI_DIR', __DIR__);
}

require_once __DIR__ . '/includes/class-spolek-audit.php';
require_once __DIR__ . '/includes/class-spolek-legacy.php';
require_once __DIR__ . '/includes/class-spolek-plugin.php';

Spolek_Plugin::init();
register_activation_hook(__FILE__, ['Spolek_Plugin', 'activate']);

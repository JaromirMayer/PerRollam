<?php
/**
 * Plugin Name: Spolek – Hlasování per rollam (MVP)
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;

if (!defined('SPOLEK_HLASOVANI_DIR')) {
    define('SPOLEK_HLASOVANI_DIR', __DIR__);
}

$trace = WP_CONTENT_DIR . '/uploads/spolek-trace.txt';
@file_put_contents($trace, "\n=== BOOT " . date('c') . " ===\n", FILE_APPEND);

// 0) rychlá kontrola: jestli už třídy existují (kolize / 2 kopie pluginu)
foreach (['Spolek_Audit','Spolek_Hlasovani_MVP','Spolek_Plugin'] as $c) {
    if (class_exists($c, false)) {
        @file_put_contents($trace, "KOLIZE: {$c} už existuje před require\n", FILE_APPEND);
        return;
    }
}

@file_put_contents($trace, "require audit\n", FILE_APPEND);
require_once __DIR__ . '/includes/class-spolek-audit.php';
@file_put_contents($trace, "require legacy\n", FILE_APPEND);
require_once __DIR__ . '/includes/class-spolek-legacy.php';
@file_put_contents($trace, "require plugin\n", FILE_APPEND);
require_once __DIR__ . '/includes/class-spolek-plugin.php';
@file_put_contents($trace, "after requires\n", FILE_APPEND);

// Aktivace a init
register_activation_hook(__FILE__, ['Spolek_Plugin', 'activate']);
add_action('plugins_loaded', ['Spolek_Plugin', 'init']);
@file_put_contents($trace, "hooks registered\n", FILE_APPEND);

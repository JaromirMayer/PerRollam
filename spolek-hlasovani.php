<?php
/**
 * Plugin Name:       PerRollam – Hlasování per rollam
 * Plugin URI:        https://solitare.eu/perrollam
 * Description:       Front-end hlasování pro členy spolku (ANO/NE/ZDRŽEL), uzávěrka, exporty, archivace.
 * Version:           5.7.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Jaromír
 * Author URI:        https://solitare.eu
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perrollam
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// === Konstanty pluginu (používají je include třídy) ===
define('SPOLEK_HLASOVANI_VERSION', '5.7.1');
define('SPOLEK_HLASOVANI_FILE', __FILE__);
define('SPOLEK_HLASOVANI_PATH', plugin_dir_path(__FILE__));
define('SPOLEK_HLASOVANI_URL', plugin_dir_url(__FILE__));
define('SPOLEK_HLASOVANI_BASENAME', plugin_basename(__FILE__));

// === Composer autoload (DOMPDF apod.) ===
$autoload = SPOLEK_HLASOVANI_PATH . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// === Bootstrap pluginu ===
require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-plugin.php';

// Aktivace (tvorba tabulek, capability, …)
register_activation_hook(__FILE__, ['Spolek_Plugin', 'activate']);

// Běh pluginu
add_action('plugins_loaded', function () {
    Spolek_Plugin::instance()->run();
}, 0);

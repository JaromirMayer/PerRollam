<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Plugin {

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

    // === Auto-updates (Update URI: https://updates.solitare.eu/perrollam/) ===
    private const UPDATE_INFO_URL     = 'https://updates.solitare.eu/perrollam/info.json';
    private const UPDATE_FALLBACK_URI = 'https://updates.solitare.eu/perrollam/';
    private const UPDATE_CACHE_KEY    = 'spolek_perrollam_update_info';

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function load_dependencies(): void {
        // Audit musí být dřív než legacy (legacy ho používá)
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-audit.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-mailer.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-cron.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-archive.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-legacy.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-portal.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-pdf.php';
    }

    public function run(): void {
        if ($this->booted) return;
        $this->booted = true;

        $this->load_dependencies();
        // WP auto-updates (Update URI host: updates.solitare.eu)
        $this->register_updates();

        
        if (class_exists('Spolek_Cron')) {
            (new Spolek_Cron())->register();
        }

        if (class_exists('Spolek_Hlasovani_MVP')) {
        Spolek_Hlasovani_MVP::init();
        }
    
        if (class_exists('Spolek_Portal')) {
            (new Spolek_Portal())->register();
        }
        
        if (class_exists('Spolek_PDF')) {
            (new Spolek_PDF())->register();
        }
    }


    /**
     * Registrace update hooků pro Update URI host: updates.solitare.eu
     */
    private function register_updates(): void {
        add_filter('update_plugins_updates.solitare.eu', [$this, 'filter_update'], 10, 4);

        // Po úspěšném updatu vymažeme cache, ať WP hned vidí nový stav
        add_action('upgrader_process_complete', function ($upgrader, $hook_extra) {
            if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
                return;
            }
            $plugins = $hook_extra['plugins'] ?? [];
            if (is_array($plugins) && defined('SPOLEK_HLASOVANI_BASENAME') && in_array(SPOLEK_HLASOVANI_BASENAME, $plugins, true)) {
                delete_site_transient(self::UPDATE_CACHE_KEY);
            }
        }, 10, 2);
    }

    /**
     * Update payload pro WP (Update URI mechanismus).
     *
     * @param array|false $update
     * @param mixed       $plugin_data
     * @param mixed       $plugin_file
     * @param mixed       $locales
     * @return array|false
     */
    public function filter_update($update, $plugin_data, $plugin_file, $locales) {
        if (!defined('SPOLEK_HLASOVANI_BASENAME')) {
            return $update;
        }

        if (!is_array($plugin_data) || !is_string($plugin_file)) {
            return $update;
        }

        // Jen náš plugin
        if ($plugin_file !== SPOLEK_HLASOVANI_BASENAME) {
            return $update;
        }

        $remote = $this->get_remote_update_info();
        if (!$remote) {
            return $update;
        }

        $remote_version = (string)($remote['version'] ?? '');
        $package        = (string)($remote['download_url'] ?? '');
        if ($remote_version === '' || $package === '') {
            return $update;
        }

        $local_version = (string)($plugin_data['Version'] ?? (defined('SPOLEK_HLASOVANI_VERSION') ? SPOLEK_HLASOVANI_VERSION : '0.0.0'));
        if (!version_compare($remote_version, $local_version, '>')) {
            return $update; // není novější
        }

        $slug = dirname($plugin_file); // perrollam

        return [
            'id'           => (string)($plugin_data['UpdateURI'] ?? self::UPDATE_FALLBACK_URI),
            'slug'         => $slug,
            'version'      => $remote_version,
            'url'          => (string)($remote['homepage'] ?? self::UPDATE_FALLBACK_URI),
            'package'      => $package,
            'tested'       => (string)($remote['tested'] ?? ''),
            'requires'     => (string)($remote['requires'] ?? ''),
            'requires_php' => (string)($remote['requires_php'] ?? ''),
        ];
    }

    private function get_remote_update_info(): ?array {
        $cached = get_site_transient(self::UPDATE_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $res = wp_remote_get(self::UPDATE_INFO_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($res) || (int)wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($res);
        // BOM-safe (kdyby se někdy objevil)
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        set_site_transient(self::UPDATE_CACHE_KEY, $data, HOUR_IN_SECONDS);
        return $data;
    }
    public static function activate(): void {
        // Aktivace se může spustit v jiném kontextu – dependency načíst tady taky
        if (defined('SPOLEK_HLASOVANI_PATH')) {
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-audit.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-mailer.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-cron.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-archive.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-legacy.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-portal.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-pdf.php';
        }

        if (class_exists('Spolek_Archive')) {
            Spolek_Archive::ensure_storage();
        }

        if (class_exists('Spolek_Hlasovani_MVP')) {
            Spolek_Hlasovani_MVP::activate();
        }
    }
}

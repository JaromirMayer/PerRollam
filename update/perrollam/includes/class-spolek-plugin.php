<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Plugin {

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

    /**
     * Seznam závislostí (pořadí je důležité):
     * Audit -> Mailer -> Votes -> Cron -> Archive -> Legacy -> Portal -> PDF
     */
    private const DEPENDENCIES = [
        'includes/class-spolek-config.php',
        'includes/class-spolek-upgrade.php',
        'includes/class-spolek-cron-status.php',
        'includes/class-spolek-vote-service.php',
        'includes/class-spolek-admin.php',
        'includes/class-spolek-tools.php',
        'includes/class-spolek-audit-events.php',
        'includes/class-spolek-audit.php',
        'includes/class-spolek-mailer.php',
        'includes/class-spolek-votes.php',
        'includes/class-spolek-vote-processor.php',
        'includes/class-spolek-cron.php',
        'includes/class-spolek-self-heal.php',
        'includes/class-spolek-archive.php',
        'includes/class-spolek-pdf-service.php',
        'includes/class-spolek-portal-renderer.php',
        'includes/class-spolek-legacy.php',
        'includes/class-spolek-votes-controller.php',
        'includes/class-spolek-archive-controller.php',
        'includes/class-spolek-cron-controller.php',
        'includes/class-spolek-pdf-controller.php',
        'includes/class-spolek-tools-controller.php',
        'includes/class-spolek-portal.php',
        'includes/class-spolek-pdf.php',
    ];
    // === Auto-updates (Update URI host: updates.solitare.eu) ===
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

    private static function require_dependencies(): void {

    // Pokud funguje Composer autoload (classmap includes/), class_exists() si třídy načte samo.
    // Když vendor/autoload.php chybí, vrátí false a spadneme do ručního require_once.
    if (
        class_exists('Spolek_Cron') &&
        class_exists('Spolek_Archive') &&
        class_exists('Spolek_Hlasovani_MVP') &&
        class_exists('Spolek_PDF_Service') &&
        class_exists('Spolek_Admin') &&
        class_exists('Spolek_Portal_Renderer') &&
        class_exists('Spolek_Vote_Service')
    ) {
        return;
    }

    foreach (self::DEPENDENCIES as $rel) {
        require_once SPOLEK_HLASOVANI_PATH . $rel;
    }
}

    public function run(): void {
        if ($this->booted) return;
        $this->booted = true;

        self::require_dependencies();

        // Auto-updates (Update URI)
        $this->register_updates();

        // 6.5.2 – DB upgrade rutina (best-effort; dbDelta jen při změně verze)
        if (class_exists('Spolek_Upgrade')) {
            Spolek_Upgrade::maybe_upgrade(false);
        }
        
        if (class_exists('Spolek_Votes_Controller')) {
        (new Spolek_Votes_Controller())->register();
        }
        if (class_exists('Spolek_Archive_Controller')) {
        (new Spolek_Archive_Controller())->register();
        }
        if (class_exists('Spolek_Cron_Controller')) {
        (new Spolek_Cron_Controller())->register();
        }
        if (class_exists('Spolek_PDF_Controller')) {
        (new Spolek_PDF_Controller())->register();
        }

        if (class_exists('Spolek_Tools_Controller')) {
            (new Spolek_Tools_Controller())->register();
        }

        if (class_exists('Spolek_Cron')) {
            (new Spolek_Cron())->register();
        }

        if (class_exists('Spolek_Self_Heal')) {
            (new Spolek_Self_Heal())->register();
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
     * Pozn.: update kontrola poběží, když je plugin aktivní (protože filtr je registrován za běhu pluginu).
     */
    private function register_updates(): void {
        add_filter('update_plugins_updates.solitare.eu', [$this, 'filter_update'], 10, 4);

        // Po úspěšné aktualizaci smažeme cache, ať WP hned vidí nový stav.
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
     * @param array       $plugin_data
     * @param string      $plugin_file
     * @param string[]    $locales
     * @return array|false
     */
    public function filter_update($update, $plugin_data, $plugin_file, $locales) {
        if (!defined('SPOLEK_HLASOVANI_BASENAME') || $plugin_file !== SPOLEK_HLASOVANI_BASENAME) {
            return $update;
        }

        $remote = $this->get_remote_update_info();
        if (!$remote) {
            return $update;
        }

        $remote_version = isset($remote['version']) ? (string) $remote['version'] : '';
        $package        = isset($remote['download_url']) ? (string) $remote['download_url'] : '';
        if ($remote_version === '' || $package === '') {
            return $update;
        }

        $local_version = isset($plugin_data['Version'])
            ? (string) $plugin_data['Version']
            : (defined('SPOLEK_HLASOVANI_VERSION') ? (string) SPOLEK_HLASOVANI_VERSION : '0.0.0');

        if (!version_compare($remote_version, $local_version, '>')) {
            return $update;
        }

        $slug = dirname((string) $plugin_file); // typicky "perrollam"

        return [
            'id'           => isset($plugin_data['UpdateURI']) ? (string) $plugin_data['UpdateURI'] : self::UPDATE_FALLBACK_URI,
            'slug'         => $slug,
            'version'      => $remote_version,
            'url'          => isset($remote['homepage']) ? (string) $remote['homepage'] : self::UPDATE_FALLBACK_URI,
            'package'      => $package,
            'tested'       => isset($remote['tested']) ? (string) $remote['tested'] : '',
            'requires'     => isset($remote['requires']) ? (string) $remote['requires'] : '',
            'requires_php' => isset($remote['requires_php']) ? (string) $remote['requires_php'] : '',
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

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($res);
        // BOM-safe (kdyby se někdy na serveru objevil)
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        set_site_transient(self::UPDATE_CACHE_KEY, $data, HOUR_IN_SECONDS);
        return $data;
    }


    public static function activate(): void {
        if (!defined('SPOLEK_HLASOVANI_PATH')) {
            return;
        }

        self::require_dependencies();

        // Auto-updates (Update URI)
        $this->register_updates();

        if (class_exists('Spolek_Upgrade')) {
            Spolek_Upgrade::maybe_upgrade(true);
        }

        if (class_exists('Spolek_Archive')) {
            Spolek_Archive::ensure_storage();
        }

        if (class_exists('Spolek_Hlasovani_MVP')) {
            Spolek_Hlasovani_MVP::activate();
        }
    }
}

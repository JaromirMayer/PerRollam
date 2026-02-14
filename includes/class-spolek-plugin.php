<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Plugin {

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

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
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-cron.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-legacy.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-portal.php';
        require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-pdf.php';
    }

    public function run(): void {
        if ($this->booted) return;
        $this->booted = true;

        $this->load_dependencies();
        
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

    public static function activate(): void {
        // Aktivace se může spustit v jiném kontextu – dependency načíst tady taky
        if (defined('SPOLEK_HLASOVANI_PATH')) {
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-audit.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-cron.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-legacy.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-portal.php';
            require_once SPOLEK_HLASOVANI_PATH . 'includes/class-spolek-pdf.php';
        }

        if (class_exists('Spolek_Hlasovani_MVP')) {
            Spolek_Hlasovani_MVP::activate();
        }
    }
}

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
        'includes/class-spolek-admin.php',
        'includes/class-spolek-audit-events.php',
        'includes/class-spolek-audit.php',
        'includes/class-spolek-mailer.php',
        'includes/class-spolek-votes.php',
        'includes/class-spolek-vote-processor.php',
        'includes/class-spolek-cron.php',
        'includes/class-spolek-self-heal.php',
        'includes/class-spolek-archive.php',
        'includes/class-spolek-pdf-service.php',
        'includes/class-spolek-legacy.php',
        'includes/class-spolek-votes-controller.php',
        'includes/class-spolek-archive-controller.php',
        'includes/class-spolek-pdf-controller.php',
        'includes/class-spolek-portal.php',
        'includes/class-spolek-pdf.php',
    ];

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
        class_exists('Spolek_Admin')
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
        
        if (class_exists('Spolek_Votes_Controller')) {
        (new Spolek_Votes_Controller())->register();
        }
        if (class_exists('Spolek_Archive_Controller')) {
        (new Spolek_Archive_Controller())->register();
        }
        if (class_exists('Spolek_PDF_Controller')) {
        (new Spolek_PDF_Controller())->register();
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

    public static function activate(): void {
        if (!defined('SPOLEK_HLASOVANI_PATH')) {
            return;
        }

        self::require_dependencies();

        if (class_exists('Spolek_Archive')) {
            Spolek_Archive::ensure_storage();
        }

        if (class_exists('Spolek_Hlasovani_MVP')) {
            Spolek_Hlasovani_MVP::activate();
        }
    }
}

<?php

if (!defined('ABSPATH')) exit;

/**
 * LEGACY (kompatibilní obálka)
 *
 * 5.3: UI render + admin_post handlery jsou vytažené do samostatných tříd
 * (Spolek_Portal_Renderer + controllery). Tahle třída drží:
 * - konstanty (kvůli zpětné kompatibilitě)
 * - business helpery, které používají jiné části systému
 * - tenké wrappery na nové implementace
 */
class Spolek_Hlasovani_MVP {

    // Backward compat (původní názvy)
    const CPT = 'spolek_hlasovani';
    const TABLE = 'spolek_votes';
    const CAP_MANAGE = 'manage_spolek_hlasovani';

    const META_RULESET      = '_spolek_ruleset';        // 'standard' | 'two_thirds'
    const META_QUORUM_RATIO = '_spolek_quorum_ratio';   // např. 0.5
    const META_PASS_RATIO   = '_spolek_pass_ratio';     // např. 0.5 nebo 0.6666667
    const META_BASE         = '_spolek_pass_base';      // 'valid' (ANO+NE) | 'all' (všichni členové)
    const META_RESULT_LABEL   = '_spolek_result_label';
    const META_RESULT_EXPLAIN = '_spolek_result_explain';
    const META_RESULT_ADOPTED = '_spolek_result_adopted';

    const META_CLOSE_PROCESSED_AT = '_spolek_close_processed_at';
    const META_CLOSE_ATTEMPTS    = '_spolek_close_attempts';
    const META_CLOSE_LAST_ERROR  = '_spolek_close_last_error';
    const META_CLOSE_STARTED_AT  = '_spolek_close_started_at';
    const META_CLOSE_NEXT_RETRY  = '_spolek_close_next_retry_at';
    const CLOSE_MAX_ATTEMPTS     = 5;

    // Archiv
    const META_ARCHIVE_FILE   = '_spolek_archive_file';
    const META_ARCHIVE_SHA256 = '_spolek_archive_sha256';
    const META_ARCHIVED_AT    = '_spolek_archived_at';
    const META_ARCHIVE_ERROR  = '_spolek_archive_error';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('init', [__CLASS__, 'register_shortcodes']);

        // query var pro detail v portálu
        add_filter('query_vars', function($vars){
            $vars[] = 'spolek_vote';
            return $vars;
        });
    }

    // -----------------
    // Wrappery (PDF/cron)
    // -----------------

    public static function handle_download_pdf() {
        if (class_exists('Spolek_PDF_Service')) {
            Spolek_PDF_Service::handle_admin_download_pdf();
            return;
        }
        wp_die('PDF servis není dostupný.');
    }

    public static function handle_member_pdf() {
        if (class_exists('Spolek_PDF_Service')) {
            Spolek_PDF_Service::handle_member_pdf();
            return;
        }
        wp_die('PDF servis není dostupný.');
    }

    public static function handle_cron_close($vote_post_id) {
        if (class_exists('Spolek_Cron')) {
            Spolek_Cron::cron_close((int)$vote_post_id);
            return;
        }
        wp_die('Cron není dostupný.');
    }

    public static function handle_cron_reminder($vote_post_id, $type) {
        if (class_exists('Spolek_Cron')) {
            Spolek_Cron::cron_reminder((int)$vote_post_id, (string)$type);
            return;
        }
        wp_die('Cron není dostupný.');
    }

    // -----------------
    // Wrappery (UI/handlers)
    // -----------------

    public static function render_portal() {
        if (class_exists('Spolek_Portal_Renderer')) {
            return (string) Spolek_Portal_Renderer::render_portal();
        }
        return '';
    }

    /** Backward-compat: původní admin_post handlery (dnes je řeší controllery). */
    public static function handle_create_vote() {
        if (class_exists('Spolek_Votes_Controller')) {
            Spolek_Votes_Controller::handle_create_vote();
            return;
        }
        wp_die('Votes controller není dostupný.');
    }

    public static function handle_cast_vote() {
        if (class_exists('Spolek_Votes_Controller')) {
            Spolek_Votes_Controller::handle_cast_vote();
            return;
        }
        wp_die('Votes controller není dostupný.');
    }

    public static function handle_export_csv() {
        if (class_exists('Spolek_Votes_Controller')) {
            Spolek_Votes_Controller::handle_export_csv();
            return;
        }
        wp_die('Votes controller není dostupný.');
    }

    public static function handle_archive_vote() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_archive_vote();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    public static function handle_download_archive() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_download_archive();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    public static function handle_purge_vote() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_purge_vote();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    public static function handle_test_archive_storage() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_test_archive_storage();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    public static function handle_run_close_scan() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_run_close_scan();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    public static function handle_run_purge_scan() : void {
        if (class_exists('Spolek_Archive_Controller')) {
            Spolek_Archive_Controller::handle_run_purge_scan();
            return;
        }
        wp_die('Archive controller není dostupný.');
    }

    // -----------------
    // Aktivace + CPT
    // -----------------

    public static function activate() {
        // instalace tabulky hlasů
        if (class_exists('Spolek_Votes')) {
            Spolek_Votes::install_table();
        } else {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                vote_post_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                choice VARCHAR(10) NOT NULL,
                cast_at DATETIME NOT NULL,
                ip_hash CHAR(64) NULL,
                ua_hash CHAR(64) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_vote_user (vote_post_id, user_id),
                KEY idx_vote (vote_post_id),
                KEY idx_user (user_id)
            ) $charset_collate;";
            dbDelta($sql);
        }

        // mail log tabulka (idempotence pro wp_mail)
        if (class_exists('Spolek_Mailer')) {
            Spolek_Mailer::install_table();
        }

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::install_table();
        }

        // Přidat capability adminovi a roli spravce_hlasovani (pokud existuje)
        if ($admin = get_role('administrator')) {
            $admin->add_cap(self::CAP_MANAGE);
        }
        if ($mgr = get_role('spravce_hlasovani')) {
            $mgr->add_cap(self::CAP_MANAGE);
        }
    }

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Hlasování (Spolek)',
                'singular_name' => 'Hlasování (Spolek)',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title'],
        ]);
    }

    public static function register_shortcodes() {
        // v 5.x je shortcode registrace v Spolek_Portal
    }

    // -----------------
    // Business helpery používané jinde
    // -----------------

    public static function shortcode_pdf_landing() : string {
        if (class_exists('Spolek_PDF_Service')) {
            return (string) Spolek_PDF_Service::shortcode_pdf_landing();
        }
        return '<p>PDF servis není dostupný.</p>';
    }

    public static function evaluate_vote(int $vote_post_id, array $counts) : array {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::evaluate_vote($vote_post_id, $counts)
            : [];
    }

    public static function member_pdf_sig(int $user_id, int $vote_post_id, int $exp) : string {
        if (class_exists('Spolek_PDF_Service')) {
            return Spolek_PDF_Service::member_sig($user_id, $vote_post_id, $exp);
        }
        $data = $user_id . '|' . $vote_post_id . '|' . $exp;
        return hash_hmac('sha256', $data, wp_salt('spolek_member_pdf'));
    }

    public static function generate_pdf_minutes(int $vote_post_id, array $map, string $text, int $start_ts, int $end_ts) : ?string {
        if (class_exists('Spolek_PDF_Service')) {
            return Spolek_PDF_Service::generate_pdf_minutes($vote_post_id, $map, $text, $start_ts, $end_ts);
        }
        return null;
    }

    public static function vote_detail_url(int $vote_post_id) : string {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::vote_detail_url($vote_post_id)
            : add_query_arg('spolek_vote', $vote_post_id, home_url('/clenove/hlasovani/'));
    }

    public static function get_members() {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_members()
            : [];
    }

    public static function send_member_mail($vote_post_id, $u, $type, $subject, $body, $attachments = []) {
        return Spolek_Mailer::send_member_mail((int)$vote_post_id, $u, (string)$type, (string)$subject, (string)$body, (array)$attachments);
    }

    public static function schedule_vote_events(int $post_id, int $start_ts, int $end_ts) : void {
        if (class_exists('Spolek_Cron')) {
            Spolek_Cron::schedule_vote_events($post_id, $start_ts, $end_ts);
        }
    }

    /** upcoming | open | closed */
    public static function get_status(int $start_ts, int $end_ts) : string {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_status($start_ts, $end_ts)
            : 'closed';
    }

    /** @return array{0:int,1:int,2:string} [start_ts,end_ts,text] */
    public static function get_vote_meta(int $post_id) : array {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_vote_meta($post_id)
            : [0,0,''];
    }

    /** Fallback helper (používá se jen když chybí Spolek_Votes). */
    public static function user_has_voted(int $vote_post_id, int $user_id) : bool {
        if (class_exists('Spolek_Votes')) {
            return Spolek_Votes::has_user_voted($vote_post_id, $user_id);
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE vote_post_id=%d AND user_id=%d LIMIT 1",
            $vote_post_id, $user_id
        ));
        return !empty($exists);
    }
}

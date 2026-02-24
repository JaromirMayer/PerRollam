<?php

if (!defined('ABSPATH')) exit;

class Spolek_Hlasovani_MVP {
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
    const META_CLOSE_PROCESSED_AT = '_spolek_close_processed_at'; // unix timestamp kdy se uzávěrka kompletně dokončila
    const META_CLOSE_ATTEMPTS    = '_spolek_close_attempts';
    const META_CLOSE_LAST_ERROR  = '_spolek_close_last_error';
    const META_CLOSE_STARTED_AT  = '_spolek_close_started_at';
    const META_CLOSE_NEXT_RETRY  = '_spolek_close_next_retry_at';
    const CLOSE_MAX_ATTEMPTS     = 5;

    // 4.2 – Archiv
    const META_ARCHIVE_FILE   = '_spolek_archive_file';
    const META_ARCHIVE_SHA256 = '_spolek_archive_sha256';
    const META_ARCHIVED_AT    = '_spolek_archived_at';
    const META_ARCHIVE_ERROR  = '_spolek_archive_error';

    public static function init() {
    add_action('init', [__CLASS__, 'register_cpt']);
    add_action('init', [__CLASS__, 'register_shortcodes']);

    // admin_post handlery jsou od 4.1.6 registrované v controllerech:
    // Spolek_Votes_Controller, Spolek_Archive_Controller, Spolek_PDF_Controller

    add_filter('query_vars', function($vars){
        $vars[] = 'spolek_vote';
        return $vars;
    });
}
    
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
    Spolek_Cron::cron_close((int)$vote_post_id);
}

public static function handle_cron_reminder($vote_post_id, $type) {
    Spolek_Cron::cron_reminder((int)$vote_post_id, (string)$type);
}

public static function activate() {
        // instalace tabulky hlasů
    if (class_exists('Spolek_Votes')) {
    Spolek_Votes::install_table();
    } else {
    // fallback (kdyby z nějakého důvodu Spolek_Votes nebyl dostupný)
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
        
        Spolek_Audit::install_table();

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
            'show_ui' => true,          // admin only jako záloha/servis
            'show_in_menu' => false,    // neschovávat menu; admin si najde přes URL nebo hledání
            'supports' => ['title'],
        ]);
    }

    public static function register_shortcodes() {
        // Jeden shortcode, který umí list + detail + (pro správce) create form
        // add_shortcode('spolek_hlasovani_portal', [__CLASS__, 'render_portal']);
        // add_shortcode('spolek_pdf_landing', [__CLASS__, 'shortcode_pdf_landing']);
    }
    
    public static function shortcode_pdf_landing() : string {
        if (class_exists('Spolek_PDF_Service')) {
            return (string) Spolek_PDF_Service::shortcode_pdf_landing();
        }
        return '<p>PDF servis není dostupný.</p>';
}

public static function evaluate_vote(int $vote_post_id, array $counts) : array {
    $members_total = count(self::get_members());

    $yes = (int)($counts['ANO'] ?? 0);
    $no  = (int)($counts['NE'] ?? 0);
    $abs = (int)($counts['ZDRZEL'] ?? 0);

    $participated = $yes + $no + $abs;
    $valid_votes  = $yes + $no;

    $ruleset = (string) get_post_meta($vote_post_id, self::META_RULESET, true);
    if (!$ruleset) $ruleset = 'standard';

    $quorum_ratio = (float) get_post_meta($vote_post_id, self::META_QUORUM_RATIO, true);
    $pass_ratio   = (float) get_post_meta($vote_post_id, self::META_PASS_RATIO, true);
    $base         = (string) get_post_meta($vote_post_id, self::META_BASE, true);

    if ($ruleset === 'standard') {
        if ($quorum_ratio <= 0) $quorum_ratio = 0.0;
        if ($pass_ratio <= 0)   $pass_ratio   = 0.5;
        if (!$base)             $base         = 'valid';
    } elseif ($ruleset === 'two_thirds') {
        if ($quorum_ratio <= 0) $quorum_ratio = 0.5;
        if ($pass_ratio <= 0)   $pass_ratio   = 2/3;
        if (!$base)             $base         = 'valid';
    } else {
        // fallback
        if ($pass_ratio <= 0) $pass_ratio = 0.5;
        if (!$base) $base = 'valid';
    }

    $quorum_required = ($quorum_ratio > 0)
        ? (int) ceil($members_total * $quorum_ratio)
        : 0;

    $quorum_met = ($quorum_required === 0) ? true : ($participated >= $quorum_required);

    // základ pro výpočet potřebných ANO
    $denom = ($base === 'all') ? $members_total : $valid_votes;

    if ($denom <= 0) {
    $yes_needed = PHP_INT_MAX;
} else {
    if ($pass_ratio <= 0.5 + 1e-9) {
        $yes_needed = (int) floor($denom * $pass_ratio) + 1; // přísná většina
    } else {
        $yes_needed = (int) ceil($denom * $pass_ratio);      // kvalifikovaná většina
    }
}

    $adopted = $quorum_met && ($yes >= $yes_needed);

    $label = !$quorum_met
        ? 'NEPLATNÉ (nesplněno kvórum)'
        : ($adopted ? 'PŘIJATO' : 'NEPŘIJATO');

    $explain = !$quorum_met
        ? "Kvórum: $participated / $quorum_required (účast / minimum)."
        : "ANO: $yes, NE: $no, ZDRŽEL: $abs. Potřebné ANO: $yes_needed (základ: ".($base==='all'?'všichni členové':'platné hlasy').").";

    return [
        'members_total'    => $members_total,
        'yes'              => $yes,
        'no'               => $no,
        'abstain'          => $abs,
        'participated'     => $participated,
        'valid_votes'      => $valid_votes,
        'quorum_required'  => $quorum_required,
        'quorum_met'       => $quorum_met,
        'yes_needed'       => $yes_needed,
        'adopted'          => $adopted,
        'label'            => $label,
        'explain'          => $explain,
        'ruleset'          => $ruleset,
    ];
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
    private static function portal_base_url() : string {
    // Sem dej přesnou URL stránky, kde máš shortcode [spolek_hlasovani_portal]
    // U tebe typicky /clenove/hlasovani/
    return home_url('/clenove/hlasovani/');
}

public static function vote_detail_url(int $vote_post_id) : string {
    return add_query_arg('spolek_vote', $vote_post_id, self::portal_base_url());
}

public static function get_members() {
    return get_users([
        'role__in' => ['clen', 'spravce_hlasovani'],
        'number'   => 200,
    ]);
}

public static function send_member_mail($vote_post_id, $u, $type, $subject, $body, $attachments = []) {
    // kompatibilní wrapper – logika je ve Spolek_Mailer
    return Spolek_Mailer::send_member_mail((int)$vote_post_id, $u, (string)$type, (string)$subject, (string)$body, (array)$attachments);
}

private static function schedule_vote_events(int $post_id, int $start_ts, int $end_ts) : void {
    Spolek_Cron::schedule_vote_events($post_id, $start_ts, $end_ts);
}

    private static function is_manager() : bool {
        if (!is_user_logged_in()) return false;
        if (current_user_can(self::CAP_MANAGE)) return true;

        // fallback na roli podle názvu (kdyby capability nebyla přiřazena)
        $user = wp_get_current_user();
        return in_array('spravce_hlasovani', (array)$user->roles, true);
    }
    
    private static function get_return_to(string $fallback) : string {
    $rt = isset($_POST['return_to']) ? esc_url_raw($_POST['return_to']) : '';
    if ($rt) return $rt;

    $ref = wp_get_referer();
    if ($ref) return $ref;

    return $fallback;
    }

    public static function get_status(int $start_ts, int $end_ts) : string {
        $now = time();
        if ($now < $start_ts) return 'upcoming';
        if ($now < $end_ts) return 'open';
        return 'closed';
    }

    public static function get_vote_meta(int $post_id) : array {
        $start_ts = (int) get_post_meta($post_id, '_spolek_start_ts', true);
        $end_ts   = (int) get_post_meta($post_id, '_spolek_end_ts', true);
        $text     = (string) get_post_meta($post_id, '_spolek_text', true);
        return [$start_ts, $end_ts, $text];
    }

    private static function user_has_voted(int $vote_post_id, int $user_id) : bool {
    if (class_exists('Spolek_Votes')) {
        return Spolek_Votes::has_user_voted($vote_post_id, $user_id);
    }

    // fallback
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE vote_post_id=%d AND user_id=%d LIMIT 1",
        $vote_post_id, $user_id
    ));
    return !empty($exists);
}

    public static function render_portal() {

    $assets = self::portal_assets();

    if (!is_user_logged_in()) {

        // návrat po přihlášení zpět na portál
        $redirect = self::portal_url();

        // login stránka /login (i když není "WP stránka", URL funguje)
        $login_base = home_url('/login/');
        $login_url  = add_query_arg('redirect_to', $redirect, $login_base);

        // ponech si text jaký chceš (teď máš "Sekce určená pro členy Spolku.")
        $out  = '<div class="spolek-login-box">';
        $out .= '<p>Sekce určená pro členy Spolku.</p>';
        $out .= '<p><a class="spolek-login-button" href="' . esc_url($login_url) . '">Přihlásit se</a></p>';
        $out .= '</div>';

        return '<div class="spolek-portal">' . $assets . $out . '</div>';
    }

        $out = '';

        // Detail?
        $vote_id = (int) get_query_var('spolek_vote');
        if (!$vote_id && isset($_GET['spolek_vote'])) {
            $vote_id = (int) $_GET['spolek_vote'];
        }
        if ($vote_id) {
            $out .= self::render_detail($vote_id);
            $out .= '<p><a href="' . esc_url(self::portal_url()) . '">← Zpět na seznam</a></p>';
            return '<div class="spolek-portal">' . $assets . $out . '</div>';
        }

        // Správce: formulář pro nové hlasování (ponecháme nahoře)
        if (self::is_manager()) {
            $out .= self::render_create_form();
            $out .= self::render_section_sep();
        }

        // 1) Aktuální hlasování
        $out .= self::render_list();

        if (self::is_manager()) {
            // 2) Archiv uzavřených hlasování
            $out .= self::render_section_sep();
            $out .= self::render_archive_panel();

            // 3) Archivní ZIP soubory po smazání z DB
            $out .= self::render_section_sep();
            $out .= self::render_purged_archives_panel();
        } else {
            // Uzavřená hlasování (pro členy – jen pro čtení)
            $out .= self::render_section_sep();
            $out .= self::render_closed_list();
        }

        return '<div class="spolek-portal">' . $assets . $out . '</div>';
    }

    /** Vizuální helpery portálu (oddělovače sekcí). */
    private static function portal_assets(): string {
        return '<style>
            .spolek-portal .spolek-section-sep{
            border:0;
            border-top:1px solid var(--spolek-accent,#2271b1);margin:14px 0;
            }
            .spolek-portal .spolek-login-box{
            max-width:720px;
            margin:12px 0;
            padding:16px;
            border:1px solid #ddd;
            background:#fff;
            }
            .spolek-portal .spolek-login-button{
            display:inline-block;
            padding:10px 16px;
            border-radius:6px;
            background:var(--spolek-accent,#2271b1);
            color:#fff;
            text-decoration:none;
            }
            .spolek-portal .spolek-login-button:hover{
            filter:brightness(.95);
            color:#fff;
            text-decoration:none;
            }
        </style>
        <script>
        document.addEventListener("DOMContentLoaded", function(){
            try {
                var root = document.documentElement;
                var btn = document.querySelector(".spolek-portal button, .spolek-portal .button");
                if (!btn) return;
                var c = window.getComputedStyle(btn).backgroundColor;
                if (c) root.style.setProperty("--spolek-accent", c);
            } catch(e) {}
        });
        </script>';
    }

    private static function render_section_sep(): string {
        return '<hr class="spolek-section-sep">';
    }

    private static function portal_url() : string {
        // aktuální URL stránky bez query
        return remove_query_arg(['spolek_vote','created','voted','err','export','archived','purged','purge_scan','purge_scan_purged'], home_url(add_query_arg([])));
    }

    private static function render_create_form() : string {
        $action = esc_url(admin_url('admin-post.php'));
        $now = time();
        $default_end = $now + (7 * DAY_IN_SECONDS);

        $html  = '<h2>Nové hlasování</h2>';
        if (!empty($_GET['created'])) {
            $html .= '<p><strong>Hlasování bylo vytvořeno.</strong></p>';
        }
        if (!empty($_GET['err'])) {
            $html .= '<p><strong style="color:#b00;">Chyba: ' . esc_html($_GET['err']) . '</strong></p>';
        }

        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_create_vote">';
        $html .= wp_nonce_field('spolek_create_vote', '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';

        $html .= '<p><label>Název (interní):<br><input required type="text" name="title" style="width:100%"></label></p>';
        $html .= '<p><label>Plné znění návrhu usnesení:<br><textarea required name="text" rows="8" style="width:100%"></textarea></label></p>';

        $html .= '<p><label>Start (YYYY-MM-DD HH:MM) – serverový čas:<br><input required type="text" name="start" value="'.esc_attr(wp_date('Y-m-d H:i', $now)).'"></label></p>';
        $html .= '<p><label>Deadline (YYYY-MM-DD HH:MM):<br><input required type="text" name="end" value="'.esc_attr(wp_date('Y-m-d H:i', $default_end)).'"></label></p>';

        // 3.8 – vyhodnocení (ruleset/quorum/pass/base)
        $html .= '<h3>Vyhodnocení (3.8)</h3>';

        $html .= '<p><label>Typ hlasování:<br>'
        . '<select name="ruleset" id="spolek_ruleset">'
        . '<option value="standard" selected>Standard (většina ANO &gt; NE)</option>'
        . '<option value="two_thirds">2/3 většina</option>'
        . '</select>'
        . '</label></p>';

        $html .= '<p><label>Kvórum účasti (% všech členů, 0 = bez kvóra):<br>'
        . '<input type="number" name="quorum_ratio" id="spolek_quorum_ratio" min="0" max="100" step="0.01" value="0" style="width:120px;">'
        . '</label></p>';

        $html .= '<p><label>Poměr pro přijetí – ANO (%):<br>'
        . '<input type="number" name="pass_ratio" id="spolek_pass_ratio" min="0" max="100" step="0.01" value="50" style="width:120px;"> '
        . '<span style="opacity:.8;">(50 = většina, 66.67 = dvě třetiny)</span>'
        . '</label></p>';

        $html .= '<p><label>Základ pro výpočet poměru:<br>'
        . '<select name="pass_base" id="spolek_pass_base">'
        . '<option value="valid" selected>Platné hlasy (ANO + NE)</option>'
        . '<option value="all">Všichni členové</option>'
        . '</select>'
        . '</label></p>';

        $html .= '<script>
        (function(){
        var r = document.getElementById("spolek_ruleset");
        var q = document.getElementById("spolek_quorum_ratio");
        var p = document.getElementById("spolek_pass_ratio");
        var b = document.getElementById("spolek_pass_base");
        if(!r||!q||!p||!b) return;

        r.addEventListener("change", function(){
        if (r.value === "standard") { q.value = 0; p.value = 50; b.value = "valid"; }
        if (r.value === "two_thirds") { q.value = 50; p.value = 66.67; b.value = "valid"; }
        });
        })();
            </script>';

        $html .= '<p><button type="submit">Vyhlásit hlasování</button></p>';
        $html .= '<p style="opacity:.8;">Volby jsou pevně: <strong>ANO / NE / ZDRŽEL SE</strong>. Po odeslání už nelze hlas změnit.</p>';
        $html .= '</form>';

        return $html;
    }

    private static function render_list() : string {
        $now = time();
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'meta_value_num',
            'meta_key' => '_spolek_end_ts',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key'     => '_spolek_end_ts',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        $html = '<h2>Aktuální hlasování</h2>';
        if (!$q->have_posts()) {
            return $html . '<p>Zatím není vyhlášeno žádné hlasování.</p>';
        }

        $html .= '<ul>';
        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            [$start_ts, $end_ts] = self::get_vote_meta($id);

            $status = self::get_status($start_ts, $end_ts);
            $label = $status === 'open' ? 'Otevřené' : ($status === 'closed' ? 'Ukončené' : 'Připravované');

            $link = add_query_arg('spolek_vote', $id, self::portal_url());
            $html .= '<li>';
            $html .= '<a href="'.esc_url($link).'">' . esc_html(get_the_title()) . '</a>';
            $html .= ' — <em>' . esc_html($label) . '</em>';
            if ($start_ts && $end_ts) {
                $html .= ' (od ' . esc_html(wp_date('j.n.Y H:i', $start_ts)) . ' do ' . esc_html(wp_date('j.n.Y H:i', $end_ts)) . ')';}
            $html .= '</li>';
        }
        wp_reset_postdata();
        $html .= '</ul>';

        return $html;
    }

    /**
     * 4.2 – Archiv uzavřených hlasování (jen pro správce).
     * - "Zálohovat" vytvoří ZIP do uploads/spolek-hlasovani-archiv/
     * - "Stáhnout archiv" stáhne ZIP
     * - "Smazat z DB" smaže CPT + hlasy + mail log + audit (ZIP zůstává)
     */
    private static function render_archive_panel() : string {
        if (!self::is_manager()) return '';

        $html = '<h2>Archiv uzavřených hlasování</h2>';

        if (!empty($_GET['archived'])) {
            $html .= '<p><strong>Archiv byl vytvořen.</strong></p>';
        }
        if (!empty($_GET['purged'])) {
            $html .= '<p><strong>Hlasování bylo smazáno z databáze (archivní ZIP zůstal uložen).</strong></p>';
        }

        if (!empty($_GET['notice'])) {
            $html .= '<p><strong>' . esc_html((string)$_GET['notice']) . '</strong></p>';
        }

        $run_action = esc_url(admin_url('admin-post.php'));
        $html .= '<form method="post" action="'.$run_action.'" style="margin:8px 0 12px 0;">'
            . '<input type="hidden" name="action" value="spolek_run_close_scan">'
            . wp_nonce_field('spolek_run_close_scan', '_nonce', true, false)
            . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
            . '<button type="submit">Dohnat uzávěrky (starší hlasování)</button>'
            . '<div style="margin-top:6px;opacity:.75;font-size:12px;">Zpracuje max 10 ukončených hlasování, která stále čekají na cron. Hlasování uzavřená před více než 7 dny dožene v tichém režimu (bez rozesílky e-mailů), ale vytvoří výsledek, PDF a archiv ZIP.</div>'
            . '</form>';

        if (!class_exists('Spolek_Archive')) {
            return $html . '<p style="color:#b00;">Chybí třída Spolek_Archive (soubor include). Archivace není dostupná.</p>';
        }

        Spolek_Archive::ensure_storage();

        $now = time();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_spolek_end_ts',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_spolek_end_ts',
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            $html .= '<p>Zatím nejsou žádná ukončená hlasování.</p>';
        } else {
            $html .= '<table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Hlasování</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Ukončeno</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Zpracováno</th>'
                . '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Archiv</th>'
                . '</tr></thead><tbody>';

            while ($q->have_posts()) {
                $q->the_post();
                $id = get_the_ID();
                [$start_ts, $end_ts] = self::get_vote_meta($id);

                $processed_at = (string) get_post_meta($id, self::META_CLOSE_PROCESSED_AT, true);
                $file = (string) get_post_meta($id, self::META_ARCHIVE_FILE, true);
                $sha  = (string) get_post_meta($id, self::META_ARCHIVE_SHA256, true);
                $err  = (string) get_post_meta($id, self::META_ARCHIVE_ERROR, true);

                $end_label = $end_ts ? wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) : '–';
                $proc_label = $processed_at ? wp_date('j.n.Y H:i', (int)$processed_at, wp_timezone()) : '–';

                $detail_link = add_query_arg('spolek_vote', $id, self::portal_url());

                $html .= '<tr>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'
                    . '<a href="'.esc_url($detail_link).'">'.esc_html(get_the_title()).'</a>'
                    . '</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($end_label).'</td>';
                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">'.esc_html($proc_label).'</td>';

                $html .= '<td style="border-bottom:1px solid #eee;padding:6px;">';

                // pokud ještě není uzávěrka hotová, nearchivujeme
                if (!$processed_at) {
                    $html .= '<span style="opacity:.8;">Čeká na uzávěrku (cron).</span>';
                } else {
                    $has_file = false;
                    $file = basename($file);
                    if ($file !== '') {
                        $path = Spolek_Archive::locate_path($file);
                        if ($path) $has_file = true;
                    }

                    if ($has_file) {
                        $dl = admin_url('admin-post.php');
                        $dl = add_query_arg([
                            'action' => 'spolek_download_archive',
                            'file'   => $file,
                            '_nonce' => wp_create_nonce('spolek_download_archive_' . $file),
                        ], $dl);

                        $purge_action = esc_url(admin_url('admin-post.php'));

                        $html .= '<a class="button" href="'.esc_url($dl).'">Stáhnout archiv ZIP</a> ';

                        $html .= '<form method="post" action="'.$purge_action.'" style="display:inline-block;margin-left:8px;" onsubmit="return confirm(\'Opravdu smazat hlasování #' . (int)$id . ' z databáze? Archivní ZIP zůstane uložen.\');">';
                        $html .= '<input type="hidden" name="action" value="spolek_purge_vote">';
                        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$id.'">';
                        $html .= wp_nonce_field('spolek_purge_vote_'.$id, '_nonce', true, false);
                        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';
                        $html .= '<button type="submit">Smazat z DB</button>';
                        $html .= '</form>';

                        if ($sha) {
                            $html .= '<div style="margin-top:4px;opacity:.75;font-size:12px;">SHA256: '.esc_html($sha).'</div>';
                        }
                    } else {
                        // vytvořit archiv
                        $archive_action = esc_url(admin_url('admin-post.php'));
                        $html .= '<form method="post" action="'.$archive_action.'" style="display:inline-block;">';
                        $html .= '<input type="hidden" name="action" value="spolek_archive_vote">';
                        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$id.'">';
                        $html .= wp_nonce_field('spolek_archive_vote_'.$id, '_nonce', true, false);
                        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';
                        $html .= '<button type="submit">Zálohovat nyní</button>';
                        $html .= '</form>';

                        if ($err) {
                            $html .= '<div style="margin-top:4px;color:#b00;font-size:12px;">Chyba archivace: '.esc_html($err).'</div>';
                        } else {
                            $html .= '<div style="margin-top:4px;opacity:.75;font-size:12px;">Automaticky se pokusí vytvořit i cron po uzávěrce.</div>';
                        }
                    }
                }

                $html .= '</td>';
                $html .= '</tr>';
            }
            wp_reset_postdata();

            $html .= '</tbody></table>';
        }

        return $html;
    }

    /** 4.2 – Sekce 3: Archivní ZIP soubory, po smazání z DB (jen pro správce). */
    private static function render_purged_archives_panel(): string {
        if (!self::is_manager()) return '';

        $html = '<h2>Archivní ZIP soubory, po smazání z DB</h2>';
        // Diagnostika úložiště archivů (4.4.1)
        if (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'storage_status')) {
            $st = Spolek_Archive::storage_status();

            // Výsledek testu zápisu
            if (!empty($_GET['storage_test'])) {
                $ok = !empty($_GET['storage_test_ok']);
                $msg = $ok ? 'Test zápisu: OK ✅' : 'Test zápisu: CHYBA ❌';
                if (!$ok && !empty($_GET['storage_test_err'])) {
                    $msg .= ' – ' . esc_html((string)$_GET['storage_test_err']);
                }
                $html .= '<p><strong>' . $msg . '</strong></p>';
            }

            $html .= '<div style="padding:10px 12px;border:1px solid rgba(0,0,0,.12);border-radius:8px;margin:10px 0 12px 0;">';
            $html .= '<div style="font-weight:600;margin-bottom:6px;">Úložiště archivních ZIPů</div>';
            $html .= '<div style="opacity:.85;margin-bottom:8px;">Primární režim: <strong>' . esc_html((string)($st['primary_label'] ?? ($st['primary'] ?? ''))) . '</strong></div>';

            $root_dir = (string)($st['root_dir'] ?? '');
            if ($root_dir !== '') {
                $html .= '<div style="opacity:.75;font-size:12px;margin-bottom:8px;">Cesta: <code>' . esc_html($root_dir) . '</code></div>';
            }

            $checks = (array)($st['checks'] ?? []);
            if ($checks) {
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                $html .= '<tr><th style="text-align:left;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Varianta</th>'
                    . '<th style="text-align:left;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Adresář</th>'
                    . '<th style="text-align:center;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Existuje</th>'
                    . '<th style="text-align:center;padding:6px 4px;border-bottom:1px solid rgba(0,0,0,.08);">Zápis</th></tr>';

                foreach ($checks as $k => $row) {
                    $dir = (string)($row['dir'] ?? '');
                    $exists = !empty($row['exists']);
                    $writable = !empty($row['writable']);
                    $label = (string)($row['label'] ?? $k);

                    $html .= '<tr>'
                        . '<td style="padding:6px 4px;vertical-align:top;"><strong>' . esc_html($label) . '</strong></td>'
                        . '<td style="padding:6px 4px;vertical-align:top;"><code style="font-size:12px;">' . esc_html($dir) . '</code></td>'
                        . '<td style="padding:6px 4px;text-align:center;">' . ($exists ? '✅' : '❌') . '</td>'
                        . '<td style="padding:6px 4px;text-align:center;">' . ($writable ? '✅' : '❌') . '</td>'
                        . '</tr>';
                }

                $html .= '</table>';
            }

            $test_action = esc_url(admin_url('admin-post.php'));
            $html .= '<form method="post" action="'.$test_action.'" style="margin-top:10px;">'
                . '<input type="hidden" name="action" value="spolek_test_archive_storage">'
                . wp_nonce_field('spolek_test_archive_storage', '_nonce', true, false)
                . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
                . '<button type="submit">Otestovat zápis do úložiště</button>'
                . '<span style="margin-left:10px;opacity:.75;font-size:12px;">(zapíše a smaže malý testovací soubor v primární lokaci)</span>'
                . '</form>';

            $html .= '</div>';
        }

// Ruční spuštění purge scanu (4.3) – pro test / okamžité pročištění (maže i audit)
$purged_n = isset($_GET['purge_scan_purged']) ? (int)$_GET['purge_scan_purged'] : null;
if (!empty($_GET['purge_scan'])) {
    if ($purged_n !== null) {
        $html .= '<p><strong>Purge scan dokončen.</strong> Smazáno z DB: ' . (int)$purged_n . '.</p>';
    } else {
        $html .= '<p><strong>Purge scan dokončen.</strong></p>';
    }
}

$run_action = esc_url(admin_url('admin-post.php'));
$html .= '<form method="post" action="'.$run_action.'" style="margin:8px 0 12px 0;">'
    . '<input type="hidden" name="action" value="spolek_run_purge_scan">'
    . wp_nonce_field('spolek_run_purge_scan', '_nonce', true, false)
    . '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">'
    . '<button type="submit">Spustit automatické mazání (30 dní)</button>'
    . '<div style="margin-top:6px;opacity:.75;font-size:12px;">Smaže max 10 hlasování, která jsou uzavřená déle než 30 dní a mají archivní ZIP (ověří SHA256). Maže i audit.</div>'
    . '</form>';

        if (!class_exists('Spolek_Archive')) {
            return $html . '<p style="color:#b00;">Chybí třída Spolek_Archive (soubor include). Archivace není dostupná.</p>';
        }

        Spolek_Archive::ensure_storage();

        $items = Spolek_Archive::list_archives();
        $purged = array_filter($items, function($it){ return !empty($it['purged_at']); });

        if (!$purged) {
            $html .= '<p style="opacity:.8;">Zatím žádné.</p>';
            return $html;
        }

        $html .= '<ul>';
        foreach ($purged as $it) {
            $file = basename((string)($it['file'] ?? ''));
            if ($file === '') continue;

            $dl = admin_url('admin-post.php');
            $dl = add_query_arg([
                'action' => 'spolek_download_archive',
                'file'   => $file,
                '_nonce' => wp_create_nonce('spolek_download_archive_' . $file),
            ], $dl);

            $title = (string)($it['title'] ?? $file);
            $archived_at = (int)($it['archived_at'] ?? 0);
            $purged_at   = (int)($it['purged_at'] ?? 0);

            $html .= '<li>'
                . '<a href="'.esc_url($dl).'">'.esc_html($title).'</a>'
                . ' <span style="opacity:.75;">(archiv: '.esc_html($archived_at ? wp_date('j.n.Y H:i', $archived_at, wp_timezone()) : '–')
                . ', smazáno z DB: '.esc_html($purged_at ? wp_date('j.n.Y H:i', $purged_at, wp_timezone()) : '–')
                . ')</span>'
                . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /** Uzavřená hlasování – jen seznam pro členy (bez správcovských akcí). */
    private static function render_closed_list() : string {
        $now = time();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_spolek_end_ts',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_spolek_end_ts',
                    'value'   => $now,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!$q->have_posts()) return '';

        $html  = '<details style="margin-top:18px;">';
        $html .= '<summary><strong>Ukončená hlasování (archiv, jen pro čtení)</strong></summary>';
        $html .= '<ul style="margin-top:10px;">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            [$start_ts, $end_ts] = self::get_vote_meta($id);

            $link = add_query_arg('spolek_vote', $id, self::portal_url());

            $end_label = $end_ts ? wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) : '–';
            $html .= '<li><a href="'.esc_url($link).'">' . esc_html(get_the_title()) . '</a>'
                . ' <span style="opacity:.75;">(ukončeno '.$end_label.')</span></li>';
        }

        wp_reset_postdata();
        $html .= '</ul></details>';

        return $html;
    }

    private static function render_detail(int $vote_post_id) : string {
        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== self::CPT) {
            return '<p>Hlasování nenalezeno.</p>';
        }

        [$start_ts, $end_ts, $text] = self::get_vote_meta($vote_post_id);
        $status = self::get_status($start_ts, $end_ts);

        $html = '<h2>' . esc_html($post->post_title) . '</h2>';
        if ($start_ts && $end_ts) {
            $html .= '<p><strong>Termín:</strong> ' . esc_html(wp_date('j.n.Y H:i', $start_ts)) . ' – ' . esc_html(wp_date('j.n.Y H:i', $end_ts)) . '</p>';}

        $html .= '<div style="white-space:pre-wrap; padding:12px; border:1px solid #ddd;">' . esc_html($text) . '</div>';

        $user_id = get_current_user_id();

        if (!empty($_GET['voted'])) {
            $html .= '<p><strong>Děkujeme, hlas byl uložen.</strong></p>';
        }
        if (!empty($_GET['err'])) {
            $html .= '<p><strong style="color:#b00;">Chyba: ' . esc_html($_GET['err']) . '</strong></p>';
        }

        if ($status !== 'open') {
            $html .= '<p><em>Hlasování není otevřené.</em></p>';
        } else {
            if (self::user_has_voted($vote_post_id, $user_id)) {
                $html .= '<p><strong>Už jste hlasoval(a). Hlas nelze změnit.</strong></p>';
            } else {
                $html .= self::render_vote_form($vote_post_id);
            }
        }

        // Správce: export + jednoduchý souhrn
        if (self::is_manager()) {
            $html .= '<hr>';
            $html .= self::render_manager_tools($vote_post_id);
        }

        return $html;
    }

    private static function render_vote_form(int $vote_post_id) : string {
        $action = esc_url(admin_url('admin-post.php'));

        $html  = '<h3>Odevzdat hlas</h3>';
        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_cast_vote">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">';
        $html .= wp_nonce_field('spolek_cast_vote_'.$vote_post_id, '_nonce', true, false);
        $html .= '<input type="hidden" name="return_to" value="'.esc_attr(self::portal_url()).'">';

        $html .= '<p>';
        foreach (['ANO','NE','ZDRZEL'] as $val) {
            $label = $val === 'ZDRZEL' ? 'ZDRŽEL SE' : $val;
            $html .= '<label style="margin-right:16px;"><input required type="radio" name="choice" value="'.esc_attr($val).'"> '.esc_html($label).'</label>';
        }
        $html .= '</p>';

        $html .= '<p><button type="submit">Odeslat hlas</button></p>';
        $html .= '<p style="opacity:.8;">Po odeslání už nelze hlas změnit.</p>';
        $html .= '</form>';

        return $html;
    }

    private static function render_manager_tools(int $vote_post_id) : string {
        
        $map = class_exists('Spolek_Votes')
    ? Spolek_Votes::get_counts($vote_post_id)
    : ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];

        $action = esc_url(admin_url('admin-post.php'));
        $html  = '<h3>Správa (jen pro správce)</h3>';
        $html .= '<p><strong>Souhrn:</strong> ANO: '.$map['ANO'].' | NE: '.$map['NE'].' | ZDRŽEL: '.$map['ZDRZEL'].'</p>';

        $html .= '<form method="post" action="'.$action.'">';
        $html .= '<input type="hidden" name="action" value="spolek_export_csv">';
        $html .= '<input type="hidden" name="vote_post_id" value="'.(int)$vote_post_id.'">';
        $html .= wp_nonce_field('spolek_export_csv_'.$vote_post_id, '_nonce', true, false);
        $html .= '<button type="submit">Stáhnout CSV (hlasy)</button>';
        $html .= '</form>';

        $pdf_path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
            if ($pdf_path && file_exists($pdf_path)) {
        $dl = admin_url('admin-post.php');
        $dl = add_query_arg([
        'action'        => 'spolek_download_pdf',
        'vote_post_id'  => (int)$vote_post_id,
        '_nonce'        => wp_create_nonce('spolek_download_pdf_'.$vote_post_id),
    ], $dl);

        $html .= '<p><a class="button" href="'.esc_url($dl).'">Stáhnout zápis PDF</a></p>';
    } else {
        $html .= '<p style="opacity:.8;">Zápis PDF zatím není vygenerován (vygeneruje se po ukončení hlasování).</p>';
    }

        return $html;

 }

    public static function handle_create_vote() {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }
        if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_create_vote')) {
            wp_die('Neplatný nonce.');
        }
        
        $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));
        $title = sanitize_text_field($_POST['title'] ?? '');
        $text  = sanitize_textarea_field($_POST['text'] ?? '');
        $start = sanitize_text_field($_POST['start'] ?? '');
        $end   = sanitize_text_field($_POST['end'] ?? '');  

        $tz = wp_timezone();

        $start_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $start, $tz);
        $end_dt   = DateTimeImmutable::createFromFormat('Y-m-d H:i', $end, $tz);

        $start_ts = $start_dt ? $start_dt->getTimestamp() : 0;
        $end_ts   = $end_dt ? $end_dt->getTimestamp() : 0;


        if (!$title || !$text || !$start_ts || !$end_ts || $end_ts <= $start_ts) {
            self::redirect_with_error('Neplatné údaje (zkontrolujte datum/čas).');
        }

        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);

        if (is_wp_error($post_id)) {
            self::redirect_with_error('Nelze vytvořit hlasování.');
        }

        update_post_meta($post_id, '_spolek_text', $text);
        update_post_meta($post_id, '_spolek_start_ts', (int)$start_ts);
        update_post_meta($post_id, '_spolek_end_ts', (int)$end_ts);
        
        Spolek_Audit::log((int)$post_id, get_current_user_id(), Spolek_Audit_Events::VOTE_CREATED, [
        'title' => $title,
        ]);
        
        $ruleset = sanitize_text_field($_POST['ruleset'] ?? 'standard');
        if (!in_array($ruleset, ['standard', 'two_thirds'], true)) $ruleset = 'standard';

        $pass_base = sanitize_text_field($_POST['pass_base'] ?? 'valid');
        if (!in_array($pass_base, ['valid', 'all'], true)) $pass_base = 'valid';

        $to_ratio = function($v) {
            $v = trim((string)$v);
            if ($v === '') return null;
            $v = str_replace(',', '.', $v);
            $r = ((float)$v) / 100.0;
            if ($r < 0) $r = 0;
            if ($r > 1) $r = 1;
            return $r;
};

$quorum_ratio = $to_ratio($_POST['quorum_ratio'] ?? '');
$pass_ratio   = $to_ratio($_POST['pass_ratio'] ?? '');

update_post_meta($post_id, self::META_RULESET, $ruleset);
update_post_meta($post_id, self::META_BASE, $pass_base);

if ($quorum_ratio === null) delete_post_meta($post_id, self::META_QUORUM_RATIO);
else update_post_meta($post_id, self::META_QUORUM_RATIO, (string)$quorum_ratio);

if ($pass_ratio === null) delete_post_meta($post_id, self::META_PASS_RATIO);
else update_post_meta($post_id, self::META_PASS_RATIO, (string)$pass_ratio);
        
        // naplánovat připomínky + výsledek
Spolek_Cron::schedule_vote_events((int)$post_id, (int)$start_ts, (int)$end_ts);

// odeslat oznámení všem členům (centrálně přes Spolek_Mailer)
if (class_exists("Spolek_Mailer")) {
    Spolek_Mailer::send_announce((int)$post_id);
}

        // MVP: email notifikace zatím neřešíme automaticky (doplníme v dalším kroku)
        wp_safe_redirect(add_query_arg('created', '1', $return_to));
        exit;
    }

public static function handle_cast_vote() {
    if (!is_user_logged_in()) {
        wp_die('Musíte být přihlášeni.');
    }

    $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
    $choice = sanitize_text_field($_POST['choice'] ?? '');
    $user_id = get_current_user_id();

    // audit: pokus o hlasování
    Spolek_Audit::log($vote_post_id ?: 0, $user_id, Spolek_Audit_Events::VOTE_CAST_ATTEMPT, [
        'choice' => $choice ?: null,
    ]);

    // validace vstupu
    if (!$vote_post_id || !in_array($choice, ['ANO','NE','ZDRZEL'], true)) {
        Spolek_Audit::log($vote_post_id ?: 0, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
            'reason' => 'invalid_choice_or_vote_id',
            'choice' => $choice ?: null,
        ]);
        self::redirect_detail_error($vote_post_id, 'Neplatná volba.');
    }

    // nonce
    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_cast_vote_'.$vote_post_id)) {
        Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
            'reason' => 'nonce_invalid',
        ]);
        self::redirect_detail_error($vote_post_id, 'Neplatný nonce.');
    }

    $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

    // status
    [$start_ts, $end_ts] = self::get_vote_meta($vote_post_id);
    $status = self::get_status((int)$start_ts, (int)$end_ts);
    if ($status !== 'open') {
        Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
            'reason' => 'not_open',
            'status' => $status,
        ]);
        self::redirect_detail_error($vote_post_id, 'Hlasování není otevřené.');
    }

    // duplicita
    if (self::user_has_voted($vote_post_id, $user_id)) {
        Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_REJECTED, [
            'reason' => 'already_voted',
        ]);
        self::redirect_detail_error($vote_post_id, 'Už jste hlasoval(a).');
    }

    // uložení hlasu
    // uložení hlasu
    $ok = (class_exists('Spolek_Votes'))
    ? Spolek_Votes::insert_vote($vote_post_id, $user_id, $choice)
    : false;

    if (!$ok) {
    global $wpdb;
    Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_FAILED, [
        'reason'   => 'db_insert_failed',
        'db_error' => $wpdb->last_error ?: null,
    ]);
    self::redirect_detail_error($vote_post_id, 'Nelze uložit hlas (možná duplicitní).');
}

    // audit: hlas uložen
    Spolek_Audit::log($vote_post_id, $user_id, Spolek_Audit_Events::VOTE_CAST_SAVED, [
        'choice' => $choice,
    ]);

    wp_safe_redirect(add_query_arg(['spolek_vote' => $vote_post_id, 'voted' => '1'], $return_to));
    exit;
}

    public static function handle_export_csv() {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_export_csv_'.$vote_post_id)) {
            wp_die('Neplatný nonce.');
        }

        $rows = class_exists('Spolek_Votes')
    ? Spolek_Votes::export_rows($vote_post_id)
        : [];

        $filename = 'hlasovani-' . $vote_post_id . '-hlasy.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['user_id','user_email','choice','cast_at']);

        foreach ($rows as $r) {
            $u = get_user_by('id', (int)$r['user_id']);
            fputcsv($out, [
                $r['user_id'],
                $u ? $u->user_email : '',
                $r['choice'],
                $r['cast_at'],
            ]);
        }
        fclose($out);
        exit;
    }
    
    // ===== 4.0.2 Lock pro uzávěrku (cron_close) =====
/**
 * Atomický lock přes add_option(). Vrací token locku nebo null.
 */
/**
 * Uvolní lock jen pokud token sedí (bezpečné při expiraci/novém locku).
 */


    // ===== 4.2 Archiv – handlery =====

    public static function handle_archive_vote() : void {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_archive_vote_'.$vote_post_id)) {
            wp_die('Neplatný nonce.');
        }

        $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

        if (!class_exists('Spolek_Archive')) {
            wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Archive.'), $return_to));
            exit;
        }

        $res = Spolek_Archive::archive_vote($vote_post_id, true);

        if (is_array($res) && !empty($res['ok'])) {
            wp_safe_redirect(add_query_arg('archived', '1', $return_to));
            exit;
        }

        $err = is_array($res) ? (string)($res['error'] ?? 'Archivace selhala') : 'Archivace selhala';
        wp_safe_redirect(add_query_arg('err', rawurlencode($err), $return_to));
        exit;
    }

    public static function handle_download_archive() : void {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        $file = (string)($_GET['file'] ?? '');
        $vote_post_id = (int)($_GET['vote_post_id'] ?? 0);

        if ($file === '' && $vote_post_id) {
            $file = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_FILE, true);
        }
        $file = basename((string)$file);

        if ($file === '') wp_die('Neplatný soubor.');

        $nonce = (string)($_GET['_nonce'] ?? '');
        if (!$nonce || !wp_verify_nonce($nonce, 'spolek_download_archive_' . $file)) {
            wp_die('Neplatný nonce.');
        }

        if (!class_exists('Spolek_Archive')) {
            wp_die('Chybí Spolek_Archive.');
        }

        Spolek_Archive::send_archive($file);
    }

    public static function handle_purge_vote() : void {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_purge_vote_'.$vote_post_id)) {
            wp_die('Neplatný nonce.');
        }

        $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

        if (!class_exists('Spolek_Archive')) {
            wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Archive.'), $return_to));
            exit;
        }

        $res = Spolek_Archive::purge_vote($vote_post_id);

        if (is_array($res) && !empty($res['ok'])) {
            wp_safe_redirect(add_query_arg('purged', '1', $return_to));
            exit;
        }

        $err = is_array($res) ? (string)($res['error'] ?? 'Purge selhal') : 'Purge selhal';
        wp_safe_redirect(add_query_arg('err', rawurlencode($err), $return_to));
        exit;
    }

public static function handle_test_archive_storage() : void {
    if (!is_user_logged_in() || !self::is_manager()) {
        wp_die('Nemáte oprávnění.');
    }

    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_test_archive_storage')) {
        wp_die('Neplatný nonce.');
    }

    $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

    if (!class_exists('Spolek_Archive') || !method_exists('Spolek_Archive', 'test_write')) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Archive::test_write.'), $return_to));
        exit;
    }

    $res = [];
    try {
        $res = Spolek_Archive::test_write();
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage()];
    }

    $args = [
        'storage_test' => '1',
        'storage_test_ok' => !empty($res['ok']) ? '1' : '0',
    ];
    if (!empty($res['storage'])) $args['storage_test_storage'] = (string)$res['storage'];
    if (!empty($res['dir']))     $args['storage_test_dir']     = (string)$res['dir'];
    if (!empty($res['error']))   $args['storage_test_err']     = (string)$res['error'];

    wp_safe_redirect(add_query_arg($args, $return_to));
    exit;
}

public static function handle_run_close_scan() : void {
    if (!is_user_logged_in() || !self::is_manager()) {
        wp_die('Nemáte oprávnění.');
    }

    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_run_close_scan')) {
        wp_die('Neplatný nonce.');
    }

    $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

    if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'close_scan')) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Cron::close_scan.'), $return_to));
        exit;
    }

    try {
        $stats = Spolek_Cron::close_scan();
    } catch (Throwable $e) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Close scan selhal: ' . $e->getMessage()), $return_to));
        exit;
    }

    $msg = 'Dohnání uzávěrek: zpracováno ' . (int)($stats['total'] ?? 0)
         . ' (tichý režim: ' . (int)($stats['silent'] ?? 0)
         . ', standard: ' . (int)($stats['normal'] ?? 0)
         . ', chyby: ' . (int)($stats['errors'] ?? 0) . ').';

    wp_safe_redirect(add_query_arg('notice', rawurlencode($msg), $return_to));
    exit;
}


public static function handle_run_purge_scan() : void {
    if (!is_user_logged_in() || !self::is_manager()) {
        wp_die('Nemáte oprávnění.');
    }

    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_run_purge_scan')) {
        wp_die('Neplatný nonce.');
    }

    $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

    if (!class_exists('Spolek_Cron') || !method_exists('Spolek_Cron', 'purge_scan')) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Cron::purge_scan.'), $return_to));
        exit;
    }

    if (!class_exists('Spolek_Archive')) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Chybí Spolek_Archive.'), $return_to));
        exit;
    }

    Spolek_Archive::ensure_storage();

    $before = 0;
    try {
        $items = Spolek_Archive::list_archives();
        $before = count(array_filter($items, function($it){ return !empty($it['purged_at']); }));
    } catch (Throwable $e) {
        $before = 0;
    }

    try {
        Spolek_Cron::purge_scan();
    } catch (Throwable $e) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Purge scan selhal: ' . $e->getMessage()), $return_to));
        exit;
    }

    $after = $before;
    try {
        $items = Spolek_Archive::list_archives();
        $after = count(array_filter($items, function($it){ return !empty($it['purged_at']); }));
    } catch (Throwable $e) {
        $after = $before;
    }

    $delta = max(0, (int)$after - (int)$before);

    wp_safe_redirect(add_query_arg([
        'purge_scan' => '1',
        'purge_scan_purged' => (string)$delta,
    ], $return_to));
    exit;
}



    private static function redirect_with_error(string $msg) {
        wp_safe_redirect(add_query_arg('err', rawurlencode($msg), self::portal_url()));
        exit;
    }

    private static function redirect_detail_error(int $vote_post_id, string $msg) {
        wp_safe_redirect(add_query_arg(['spolek_vote'=>$vote_post_id, 'err'=>rawurlencode($msg)], self::portal_url()));
        exit;
    }
}

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

        // Form handlers (front-end post → admin-post.php)
        add_action('admin_post_spolek_create_vote', [__CLASS__, 'handle_create_vote']);
        add_action('admin_post_spolek_cast_vote', [__CLASS__, 'handle_cast_vote']);
        add_action('admin_post_spolek_export_csv', [__CLASS__, 'handle_export_csv']);
        // 4.2 – Archiv
        add_action('admin_post_spolek_archive_vote', [__CLASS__, 'handle_archive_vote']);
        add_action('admin_post_spolek_download_archive', [__CLASS__, 'handle_download_archive']);
        add_action('admin_post_spolek_purge_vote', [__CLASS__, 'handle_purge_vote']);
        add_action('admin_post_spolek_download_pdf', [__CLASS__, 'handle_download_pdf']);
        add_action('admin_post_spolek_member_pdf', [__CLASS__, 'handle_member_pdf']);
        add_action('admin_post_nopriv_spolek_member_pdf', [__CLASS__, 'handle_member_pdf']);
        // add_action('spolek_vote_reminder', [__CLASS__, 'handle_cron_reminder'], 10, 2);
        // add_action('spolek_vote_close', [__CLASS__, 'handle_cron_close'], 10, 1);

        // Query var pro detail
        add_filter('query_vars', function($vars){
            $vars[] = 'spolek_vote';
            return $vars;
        });
    }
    
    public static function handle_download_pdf() {
    if (!is_user_logged_in() || !self::is_manager()) {
        wp_die('Nemáte oprávnění.');
    }

    $vote_post_id = (int)($_GET['vote_post_id'] ?? 0);
    if (!$vote_post_id) wp_die('Neplatné hlasování.');

    $nonce = $_GET['_nonce'] ?? '';
    if (!$nonce || !wp_verify_nonce($nonce, 'spolek_download_pdf_'.$vote_post_id)) {
        wp_die('Neplatný nonce.');
    }

    $path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
    if (!$path || !file_exists($path)) {
        wp_die('Soubor nenalezen.');
    }

    $filename = basename($path);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

public static function handle_member_pdf() {

    $vote_post_id = (int)($_GET['vote_post_id'] ?? 0);
    $uid = (int)($_GET['uid'] ?? 0);
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');

    if (!$vote_post_id || !$uid || !$exp || !$sig) {
        wp_die('Neplatný odkaz (chybí parametr).');
    }

    if ($exp < time()) {
        wp_die('Odkaz vypršel.');
    }

    // Pokud není přihlášený, přesměruj na login a vrať se zpět na tento odkaz
    if (!is_user_logged_in()) {
        $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        wp_safe_redirect(wp_login_url($current_url));
        exit;
    }

    $current_uid = get_current_user_id();

    // Pokud je přihlášený jiný uživatel než uid v odkazu, povol jen správci
    if ($current_uid !== $uid && !current_user_can(self::CAP_MANAGE)) {
        wp_die('Odkaz je určen jinému uživateli.');
    }

    // Ověření podpisu
    $expected = self::member_pdf_sig($uid, $vote_post_id, $exp);
    if (!hash_equals($expected, $sig)) {
        wp_die('Neplatný podpis odkazu.');
    }

    // Role / oprávnění (člen/správce/admin)
    $user = wp_get_current_user();
    $roles = (array)$user->roles;

    if (!in_array('clen', $roles, true) && !in_array('spravce_hlasovani', $roles, true) && !current_user_can(self::CAP_MANAGE)) {
        wp_die('Nemáte oprávnění.');
    }

    $path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
    if (!$path || !file_exists($path)) {
        wp_die('Soubor nenalezen.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

public static function handle_cron_close($vote_post_id) {
    Spolek_Cron::cron_close((int)$vote_post_id);
}

public static function handle_cron_reminder($vote_post_id, $type) {
    Spolek_Cron::cron_reminder((int)$vote_post_id, (string)$type);
}

    public static function activate() {
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

    $vote_post_id = (int)($_GET['vote_post_id'] ?? 0);
    $uid = (int)($_GET['uid'] ?? 0);
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');

    if (!$vote_post_id || !$uid || !$exp || !$sig) {
        return '<p>Neplatný odkaz.</p>';
    }

    // když není přihlášený, vrať ho na login a po loginu zpět sem
    if (!is_user_logged_in()) {
        $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        wp_safe_redirect(wp_login_url($current_url));
        exit;
    }

    $download_url = add_query_arg([
        'action'       => 'spolek_member_pdf',
        'vote_post_id' => $vote_post_id,
        'uid'          => $uid,
        'exp'          => $exp,
        'sig'          => $sig,
    ], admin_url('admin-post.php'));

    // kam po stažení (uprav si podle reality)
    $after_url = home_url('/clenove/profil/');

    $download_url = esc_url($download_url);
    $after_url = esc_url($after_url);

    return '
<div style="max-width:720px;margin:20px auto;padding:16px;border:1px solid #ddd;">
  <h2>Stahuji zápis PDF…</h2>
  <p>Pokud se stažení nespustí automaticky, klikni zde: <a href="'.$download_url.'">Stáhnout PDF</a></p>
  <p>Po stažení budeš přesměrován na profil.</p>

  <iframe src="'.$download_url.'" style="display:none;width:0;height:0;border:0;"></iframe>

  <script>
    setTimeout(function(){
      window.location.href = "'.$after_url.'";
    }, 1500);
  </script>
</div>';
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
    $data = $user_id . '|' . $vote_post_id . '|' . $exp;
    return hash_hmac('sha256', $data, wp_salt('spolek_member_pdf'));
}

    private static function pdf_upload_dir() : array {
    $up = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'spolek-hlasovani';
    $url = trailingslashit($up['baseurl']) . 'spolek-hlasovani';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    // Volitelné: pokus o zamezení přímého přístupu (Apache)
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Deny from all\n");
    }

    return ['dir' => $dir, 'url' => $url];
}

private static function load_dompdf() : bool {
    if (class_exists('\\Dompdf\\Dompdf')) return true;

    $autoload = rtrim(SPOLEK_HLASOVANI_PATH, '/\\') . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    return class_exists('\\Dompdf\\Dompdf');
}

public static function generate_pdf_minutes(int $vote_post_id, array $map, string $text, int $start_ts, int $end_ts) : ?string {
    if (!self::load_dompdf()) {
        return null;
    }

    $post = get_post($vote_post_id);
    if (!$post) return null;

    $tz = wp_timezone();

    $title = $post->post_title;
    $generated = wp_date('j.n.Y H:i', time(), $tz);
    $start_s   = wp_date('j.n.Y H:i', $start_ts, $tz);
    $end_s     = wp_date('j.n.Y H:i', $end_ts, $tz);

    $ano    = (int)($map['ANO'] ?? 0);
    $ne     = (int)($map['NE'] ?? 0);
    $zdrzel = (int)($map['ZDRZEL'] ?? 0);
    $total  = $ano + $ne + $zdrzel;

    // HTML pro PDF
    $html = '<!doctype html><html><head><meta charset="utf-8">
    <style>
      body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.35; }
      h1 { font-size: 18px; margin: 0 0 10px 0; }
      h2 { font-size: 14px; margin: 16px 0 8px 0; }
      .box { border: 1px solid #333; padding: 10px; margin: 10px 0; }
      table { width: 100%; border-collapse: collapse; margin-top: 8px; }
      th, td { border: 1px solid #333; padding: 6px; text-align: left; }
      .muted { color: #555; }
      pre { white-space: pre-wrap; font-family: DejaVu Sans, sans-serif; }
    </style>
    </head><body>';

    $html .= '<h1>Zápis o hlasování per rollam</h1>';
    $html .= '<div class="muted">Vygenerováno: ' . esc_html($generated) . '</div>';

    $html .= '<div class="box">';
    $html .= '<strong>Název hlasování:</strong> ' . esc_html($title) . '<br>';
    $html .= '<strong>ID hlasování:</strong> ' . (int)$vote_post_id . '<br>';
    $html .= '<strong>Otevřené od:</strong> ' . esc_html($start_s) . '<br>';
    $html .= '<strong>Deadline:</strong> ' . esc_html($end_s) . '<br>';
    $html .= '</div>';

    $html .= '<h2>Výsledek</h2>';
    $html .= '<table>
        <tr><th>Volba</th><th>Počet</th></tr>
        <tr><td>ANO</td><td>' . $ano . '</td></tr>
        <tr><td>NE</td><td>' . $ne . '</td></tr>
        <tr><td>ZDRŽEL SE</td><td>' . $zdrzel . '</td></tr>
        <tr><td><strong>Celkem</strong></td><td><strong>' . $total . '</strong></td></tr>
    </table>';

    $html .= '<h2>Plné znění návrhu</h2>';
    $html .= '<div class="box"><pre>' . esc_html($text) . '</pre></div>';

    $html .= '</body></html>';

    // Dompdf render
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $out = self::pdf_upload_dir();
    $fname = 'zapis-' . $vote_post_id . '-' . wp_date('Ymd-His', time(), $tz) . '.pdf';
    $path = trailingslashit($out['dir']) . $fname;

    $pdf = $dompdf->output();
    if (!$pdf) return null;

    $ok = @file_put_contents($path, $pdf);
    if (!$ok) return null;

    // Ulož poslední PDF do meta (pro stažení správcem)
    update_post_meta($vote_post_id, '_spolek_pdf_path', $path);
    update_post_meta($vote_post_id, '_spolek_pdf_generated_at', current_time('mysql'));

    return $path;
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

    public static function user_has_voted(int $vote_post_id, int $user_id) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE vote_post_id=%d AND user_id=%d LIMIT 1",
            $vote_post_id, $user_id
        ));
        return !empty($exists);
    }

    public static function render_portal() {
        if (!is_user_logged_in()) {
            return '<p>Musíte být přihlášeni.</p>';
        }

        $out = '';
        $assets = self::portal_assets();

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
            .spolek-portal .spolek-section-sep{border:0;border-top:1px solid var(--spolek-accent,#2271b1);margin:14px 0;}
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
        return remove_query_arg(['spolek_vote','created','voted','err','export'], home_url(add_query_arg([])));
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
                        $item = Spolek_Archive::find_by_file($file);
                        $path = (wp_upload_dir()['basedir'] ?? '') . '/' . Spolek_Archive::DIR_SLUG . '/' . $file;
                        if ($item || ($path && file_exists($path))) $has_file = true;
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
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT choice, COUNT(*) as c FROM $table WHERE vote_post_id=%d GROUP BY choice",
            $vote_post_id
        ), ARRAY_A);

        $map = ['ANO'=>0,'NE'=>0,'ZDRZEL'=>0];
        foreach ($counts as $row) {
            $ch = $row['choice'];
            if (isset($map[$ch])) $map[$ch] = (int)$row['c'];
        }

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
        
        Spolek_Audit::log((int)$post_id, get_current_user_id(), 'vote_created', [
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

// odeslat oznámení všem členům
$link = self::vote_detail_url((int)$post_id);
$subject = 'Vyhlášeno hlasování: ' . $title;

$body = "Bylo vyhlášeno hlasování per rollam.\n\n"
      . "Název: $title\n"
      . "Odkaz: $link\n"
      . "Hlasování je otevřené od: " . wp_date('j.n.Y H:i', (int)$start_ts, wp_timezone()) . "\n"
      . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
      . "Plné znění návrhu:\n"
      . $text . "\n";

foreach (self::get_members() as $u) {
    self::send_member_mail((int)$post_id, $u, 'announce', $subject, $body);
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
    Spolek_Audit::log($vote_post_id ?: 0, $user_id, 'vote_cast_attempt', [
        'choice' => $choice ?: null,
    ]);

    // validace vstupu
    if (!$vote_post_id || !in_array($choice, ['ANO','NE','ZDRZEL'], true)) {
        Spolek_Audit::log($vote_post_id ?: 0, $user_id, 'vote_cast_rejected', [
            'reason' => 'invalid_choice_or_vote_id',
            'choice' => $choice ?: null,
        ]);
        self::redirect_detail_error($vote_post_id, 'Neplatná volba.');
    }

    // nonce
    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_cast_vote_'.$vote_post_id)) {
        Spolek_Audit::log($vote_post_id, $user_id, 'vote_cast_rejected', [
            'reason' => 'nonce_invalid',
        ]);
        self::redirect_detail_error($vote_post_id, 'Neplatný nonce.');
    }

    $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

    // status
    [$start_ts, $end_ts] = self::get_vote_meta($vote_post_id);
    $status = self::get_status((int)$start_ts, (int)$end_ts);
    if ($status !== 'open') {
        Spolek_Audit::log($vote_post_id, $user_id, 'vote_cast_rejected', [
            'reason' => 'not_open',
            'status' => $status,
        ]);
        self::redirect_detail_error($vote_post_id, 'Hlasování není otevřené.');
    }

    // duplicita
    if (self::user_has_voted($vote_post_id, $user_id)) {
        Spolek_Audit::log($vote_post_id, $user_id, 'vote_cast_rejected', [
            'reason' => 'already_voted',
        ]);
        self::redirect_detail_error($vote_post_id, 'Už jste hlasoval(a).');
    }

    // uložení hlasu
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $ok = $wpdb->insert($table, [
        'vote_post_id' => $vote_post_id,
        'user_id'      => $user_id,
        'choice'       => $choice,
        'cast_at'      => current_time('mysql'),
        'ip_hash'      => $ip ? hash('sha256', $ip) : null,
        'ua_hash'      => $ua ? hash('sha256', $ua) : null,
    ], ['%d','%d','%s','%s','%s','%s']);

    if (!$ok) {
        Spolek_Audit::log($vote_post_id, $user_id, 'vote_cast_failed', [
            'reason'   => 'db_insert_failed',
            'db_error' => $wpdb->last_error ?: null,
        ]);
        self::redirect_detail_error($vote_post_id, 'Nelze uložit hlas (možná duplicitní).');
    }

    // audit: hlas uložen
    Spolek_Audit::log($vote_post_id, $user_id, 'vote_cast_saved', [
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

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, choice, cast_at FROM $table WHERE vote_post_id=%d ORDER BY cast_at ASC",
            $vote_post_id
        ), ARRAY_A);

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

    private static function redirect_with_error(string $msg) {
        wp_safe_redirect(add_query_arg('err', rawurlencode($msg), self::portal_url()));
        exit;
    }

    private static function redirect_detail_error(int $vote_post_id, string $msg) {
        wp_safe_redirect(add_query_arg(['spolek_vote'=>$vote_post_id, 'err'=>rawurlencode($msg)], self::portal_url()));
        exit;
    }
}
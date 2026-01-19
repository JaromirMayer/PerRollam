<?php
/**
 * Plugin Name: Spolek – Hlasování per rollam (MVP)
 * Description: Front-end hlasování pro členy spolku (ANO/NE/ZDRŽEL), 1 hlas na člena, uzávěrka a export CSV.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

class Spolek_Hlasovani_MVP {
    const CPT = 'spolek_hlasovani';
    const TABLE = 'spolek_votes';
    const CAP_MANAGE = 'manage_spolek_hlasovani';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('init', [__CLASS__, 'register_shortcodes']);

        // Form handlers (front-end post → admin-post.php)
        add_action('admin_post_spolek_create_vote', [__CLASS__, 'handle_create_vote']);
        add_action('admin_post_spolek_cast_vote', [__CLASS__, 'handle_cast_vote']);
        add_action('admin_post_spolek_export_csv', [__CLASS__, 'handle_export_csv']);
        add_action('admin_post_spolek_download_pdf', [__CLASS__, 'handle_download_pdf']);
        add_action('admin_post_spolek_member_pdf', [__CLASS__, 'handle_member_pdf']);
        add_action('admin_post_nopriv_spolek_member_pdf', [__CLASS__, 'handle_member_pdf']);
        add_action('spolek_vote_reminder', [__CLASS__, 'handle_cron_reminder'], 10, 2);
        add_action('spolek_vote_close', [__CLASS__, 'handle_cron_close'], 10, 1);

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

    public static function handle_cron_reminder($vote_post_id, $type) {
    // type: reminder48 | reminder24
    $vote_post_id = (int) $vote_post_id;
    $type = (string) $type;
    $post = get_post($vote_post_id);
    if (!$post || $post->post_type !== self::CPT) return;

    [$start_ts, $end_ts, $text] = self::get_vote_meta($vote_post_id);

    // připomínka jen pokud ještě neskončilo
    if (self::get_status((int)$start_ts, (int)$end_ts) !== 'open') return;

    $link = self::vote_detail_url($vote_post_id);
    $subject = ($type === 'reminder48')
        ? 'Připomínka: 48 hodin do konce hlasování – ' . $post->post_title
        : 'Připomínka: 24 hodin do konce hlasování – ' . $post->post_title;

    foreach (self::get_members() as $u) {
        // posílat jen těm, kdo ještě nehlasovali
        if (self::user_has_voted($vote_post_id, (int)$u->ID)) continue;

        $body = "Připomínka hlasování per rollam.\n\n"
              . "Název: {$post->post_title}\n"
              . "Odkaz: $link\n"
              . "Deadline: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        self::send_member_mail($vote_post_id, $u, $type, $subject, $body);
    }
}

public static function handle_cron_close($vote_post_id) {
    $vote_post_id = (int) $vote_post_id;
    $type = (string) $type;
    $post = get_post($vote_post_id);
    if (!$post || $post->post_type !== self::CPT) return;

    [$start_ts, $end_ts, $text] = self::get_vote_meta($vote_post_id);

    // poslat výsledek jen po skončení
    if (self::get_status((int)$start_ts, (int)$end_ts) !== 'closed') return;

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

    $link = self::vote_detail_url($vote_post_id);
    $subject = 'Výsledek hlasování: ' . $post->post_title;

    $body = "Hlasování per rollam bylo ukončeno.\n\n"
          . "Název: {$post->post_title}\n"
          . "Odkaz: $link\n"
          . "Ukončeno: " . wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) . "\n\n"
          . "Výsledek (počty hlasů):\n"
          . "ANO: {$map['ANO']}\n"
          . "NE: {$map['NE']}\n"
          . "ZDRŽEL SE: {$map['ZDRZEL']}\n\n"
          . "Plné znění návrhu:\n"
          . $text . "\n";
    
    $pdf_path = self::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

$attachments = [];
if ($pdf_path && file_exists($pdf_path)) {
    $attachments[] = $pdf_path;
}

    foreach (self::get_members() as $u) {

    $exp = time() + (30 * DAY_IN_SECONDS);
$uid = (int) $u->ID;
$sig = self::member_pdf_sig($uid, (int)$vote_post_id, $exp);

// landing page (tvoje stránka se shortcode)
$landing = home_url('/clenove/stazeni-zapisu/');
$landing = trailingslashit($landing);

$pdf_link = add_query_arg([
    'vote_post_id' => (int) $vote_post_id,
    'uid'          => $uid,
    'exp'          => $exp,
    'sig'          => $sig,
], $landing);

// DŮLEŽITÉ: dej link na vlastní řádek a obal do < > (Gmail pak méně často „ořízne“ URL)
$body_with_link = $body
    . "\n\nZápis PDF ke stažení (vyžaduje přihlášení):\n<"
    . $pdf_link
    . ">\n";

self::send_member_mail($vote_post_id, $u, 'result', $subject, $body_with_link, $attachments);
}

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
        
        $table_mail = $wpdb->prefix . 'spolek_vote_mail_log';

        $sql2 = "CREATE TABLE $table_mail (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            mail_type VARCHAR(20) NOT NULL,
            sent_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL,
            error_text TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mail (vote_post_id, user_id, mail_type),
            KEY idx_vote (vote_post_id),
            KEY idx_user (user_id)
        ) $charset_collate;";
        dbDelta($sql2);

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
        add_shortcode('spolek_hlasovani_portal', [__CLASS__, 'render_portal']);
        add_shortcode('spolek_pdf_landing', [__CLASS__, 'shortcode_pdf_landing']);
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
    
    private static function member_pdf_sig(int $user_id, int $vote_post_id, int $exp) : string {
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

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    return class_exists('\\Dompdf\\Dompdf');
}

private static function generate_pdf_minutes(int $vote_post_id, array $map, string $text, int $start_ts, int $end_ts) : ?string {
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

private static function vote_detail_url(int $vote_post_id) : string {
    return add_query_arg('spolek_vote', $vote_post_id, self::portal_base_url());
}

private static function get_members() {
    return get_users([
        'role__in' => ['clen', 'spravce_hlasovani'],
        'number'   => 200,
    ]);
}

private static function mail_already_sent(int $vote_post_id, int $user_id, string $type) : bool {
    global $wpdb;
    $t = $wpdb->prefix . 'spolek_vote_mail_log';
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t WHERE vote_post_id=%d AND user_id=%d AND mail_type=%s LIMIT 1",
        $vote_post_id, $user_id, $type
    ));
    return !empty($id);
}

private static function log_mail(int $vote_post_id, int $user_id, string $type, string $status, string $error = null) : void {
    global $wpdb;
    $t = $wpdb->prefix . 'spolek_vote_mail_log';

    // INSERT IGNORE loguje jen jednou (uniq_mail)
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $t (vote_post_id, user_id, mail_type, sent_at, status, error_text)
         VALUES (%d, %d, %s, %s, %s, %s)",
        $vote_post_id,
        $user_id,
        $type,
        current_time('mysql'),
        $status,
        $error
    ));
}

private static function send_member_mail($vote_post_id, $u, $type, $subject, $body, $attachments = []) {
    $vote_post_id = (int) $vote_post_id;
    $type = (string) $type;

    if (empty($u->user_email)) return;

    if (self::mail_already_sent($vote_post_id, (int)$u->ID, $type)) {
        return; // už jednou odesláno
    }

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    // 5. parametr = přílohy (pole cest k souborům)
    $ok = wp_mail($u->user_email, $subject, $body, $headers, (array)$attachments);

    if ($ok) {
        self::log_mail($vote_post_id, (int)$u->ID, $type, 'sent', null);
    } else {
        self::log_mail($vote_post_id, (int)$u->ID, $type, 'fail', 'wp_mail returned false');
    }
}

private static function schedule_vote_events(int $vote_post_id, int $start_ts, int $end_ts) : void {
    $now = time();

    // close event (výsledek) - vždy
    if ($end_ts > $now) {
        if (!wp_next_scheduled('spolek_vote_close', [$vote_post_id])) {
            wp_schedule_single_event($end_ts + 5, 'spolek_vote_close', [$vote_post_id]);
        }
    }

    // reminder 48h a 24h – jen když to dává smysl
    $t48 = $end_ts - (48 * HOUR_IN_SECONDS);
    if ($t48 > $now + 60) {
        if (!wp_next_scheduled('spolek_vote_reminder', [$vote_post_id, 'reminder48'])) {
            wp_schedule_single_event($t48, 'spolek_vote_reminder', [$vote_post_id, 'reminder48']);
        }
    }

    $t24 = $end_ts - (24 * HOUR_IN_SECONDS);
    if ($t24 > $now + 60) {
        if (!wp_next_scheduled('spolek_vote_reminder', [$vote_post_id, 'reminder24'])) {
            wp_schedule_single_event($t24, 'spolek_vote_reminder', [$vote_post_id, 'reminder24']);
        }
    }
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

    private static function get_status(int $start_ts, int $end_ts) : string {
        $now = time();
        if ($now < $start_ts) return 'upcoming';
        if ($now > $end_ts) return 'closed';
        return 'open';
    }

    private static function get_vote_meta(int $post_id) : array {
        $start_ts = (int) get_post_meta($post_id, '_spolek_start_ts', true);
        $end_ts   = (int) get_post_meta($post_id, '_spolek_end_ts', true);
        $text     = (string) get_post_meta($post_id, '_spolek_text', true);
        return [$start_ts, $end_ts, $text];
    }

    private static function user_has_voted(int $vote_post_id, int $user_id) : bool {
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

        // Detail?
        $vote_id = (int) get_query_var('spolek_vote');
        if (!$vote_id && isset($_GET['spolek_vote'])) {
            $vote_id = (int) $_GET['spolek_vote'];
        }
        if ($vote_id) {
            $out .= self::render_detail($vote_id);
            $out .= '<p><a href="' . esc_url(self::portal_url()) . '">← Zpět na seznam</a></p>';
            return $out;
        }

        // Správce: formulář pro nové hlasování
        if (self::is_manager()) {
            $out .= self::render_create_form();
            $out .= '<hr>';
        }

        // Seznam hlasování
        $out .= self::render_list();
        return $out;
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

        $html .= '<p><button type="submit">Vyhlásit hlasování</button></p>';
        $html .= '<p style="opacity:.8;">Volby jsou pevně: <strong>ANO / NE / ZDRŽEL SE</strong>. Po odeslání už nelze hlas změnit.</p>';
        $html .= '</form>';

        return $html;
    }

    private static function render_list() : string {
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $html = '<h2>Hlasování</h2>';
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

        return $html;
        
        $pdf_path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
if ($pdf_path && file_exists($pdf_path)) {
    $dl = admin_url('admin-post.php');
    $dl = add_query_arg([
        'action' => 'spolek_download_pdf',
        'vote_post_id' => $vote_post_id,
        '_nonce' => wp_create_nonce('spolek_download_pdf_'.$vote_post_id),
    ], $dl);

    $html .= '<p><a class="button" href="'.esc_url($dl).'">Stáhnout zápis PDF</a></p>';
} else {
    $html .= '<p style="opacity:.8;">Zápis PDF zatím není vygenerován (vygeneruje se po ukončení hlasování).</p>';
}
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
        
        // naplánovat připomínky + výsledek
self::schedule_vote_events((int)$post_id, (int)$start_ts, (int)$end_ts);

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
        if (!is_user_logged_in()) wp_die('Musíte být přihlášeni.');

        $vote_post_id = (int)($_POST['vote_post_id'] ?? 0);
        $choice = sanitize_text_field($_POST['choice'] ?? '');

        if (!$vote_post_id || !in_array($choice, ['ANO','NE','ZDRZEL'], true)) {
            self::redirect_detail_error($vote_post_id, 'Neplatná volba.');
        }

        if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'spolek_cast_vote_'.$vote_post_id)) {
            self::redirect_detail_error($vote_post_id, 'Neplatný nonce.');
        }
        
        $return_to = self::get_return_to(home_url('/clenove/hlasovani/'));

        [$start_ts, $end_ts] = self::get_vote_meta($vote_post_id);
        if (self::get_status($start_ts, $end_ts) !== 'open') {
            self::redirect_detail_error($vote_post_id, 'Hlasování není otevřené.');
        }

        $user_id = get_current_user_id();
        if (self::user_has_voted($vote_post_id, $user_id)) {
            self::redirect_detail_error($vote_post_id, 'Už jste hlasoval(a).');
        }

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
            self::redirect_detail_error($vote_post_id, 'Nelze uložit hlas (možná duplicitní).');
        }

        wp_safe_redirect(add_query_arg(['spolek_vote'=>$vote_post_id, 'voted'=>'1'], $return_to));
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

    private static function redirect_with_error(string $msg) {
        wp_safe_redirect(add_query_arg('err', rawurlencode($msg), self::portal_url()));
        exit;
    }

    private static function redirect_detail_error(int $vote_post_id, string $msg) {
        wp_safe_redirect(add_query_arg(['spolek_vote'=>$vote_post_id, 'err'=>rawurlencode($msg)], self::portal_url()));
        exit;
    }
}

Spolek_Hlasovani_MVP::init();
register_activation_hook(__FILE__, ['Spolek_Hlasovani_MVP', 'activate']);

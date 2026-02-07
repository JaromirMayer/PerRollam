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
    
    Spolek_Audit::log((int)$vote_post_id, null, 'cron_reminder_sending', ['members'=>count(self::get_members())]);

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

    $post = get_post($vote_post_id);
    if (!$post || $post->post_type !== self::CPT) return;

    // processed guard 4.0.1
    $processed_at = get_post_meta($vote_post_id, self::META_CLOSE_PROCESSED_AT, true);
    if (!empty($processed_at)) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_processed', [
            'processed_at' => $processed_at,
        ]);
        return;
    }

    $lock_token = self::acquire_close_lock($vote_post_id, 600); // 10 min (PDF + maily)
    if (!$lock_token) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_lock_busy', ['now' => time()]);

        // volitelné: retry za 2 min (jen pokud už není naplánováno)
        if (!wp_next_scheduled('spolek_vote_close', [$vote_post_id])) {
            wp_schedule_single_event(time() + 120, 'spolek_vote_close', [$vote_post_id]);
        }
        return;
    }

    Spolek_Audit::log($vote_post_id, null, 'cron_close_start', ['now' => time()]);

    $attempt = 0; // důležité: aby existoval i v catch

    try {
        [$start_ts, $end_ts, $text] = self::get_vote_meta($vote_post_id);

        Spolek_Audit::log($vote_post_id, null, 'cron_close_called', [
            'start_ts' => (int)$start_ts,
            'end_ts'   => (int)$end_ts,
            'now'      => time(),
        ]);

        // WP-Cron se může spustit o pár sekund dřív.
        $now = time();
        if ($now < (int)$end_ts) {
            wp_clear_scheduled_hook('spolek_vote_close', [$vote_post_id]);
            wp_schedule_single_event(((int)$end_ts) + 60, 'spolek_vote_close', [$vote_post_id]);

            Spolek_Audit::log($vote_post_id, null, 'cron_close_rescheduled', [
                'now'    => $now,
                'end_ts' => (int)$end_ts,
            ]);
            return; // finally se provede
        }

        // poslat výsledek jen po skončení
        $status_now = self::get_status((int)$start_ts, (int)$end_ts);
        if ($status_now !== 'closed') {
            // volitelný audit (doporučuju)
            Spolek_Audit::log($vote_post_id, null, 'cron_close_skip_not_closed', [
                'status' => $status_now,
                'now'    => time(),
                'end_ts' => (int)$end_ts,
            ]);
            return;
        }

        // 4.0.3 – pokus o uzávěrku
        $attempt = (int) get_post_meta($vote_post_id, self::META_CLOSE_ATTEMPTS, true);
        $attempt++;
        update_post_meta($vote_post_id, self::META_CLOSE_ATTEMPTS, (string)$attempt);
        update_post_meta($vote_post_id, self::META_CLOSE_STARTED_AT, (string) time());

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

        $eval = self::evaluate_vote($vote_post_id, $map);

        update_post_meta($vote_post_id, self::META_RESULT_LABEL, $eval['label']);
        update_post_meta($vote_post_id, self::META_RESULT_EXPLAIN, $eval['explain']);
        update_post_meta($vote_post_id, self::META_RESULT_ADOPTED, $eval['adopted'] ? '1' : '0');

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
              . "\nVyhodnocení: " . $eval['label'] . "\n"
              . $eval['explain'] . "\n"
              . "Plné znění návrhu:\n"
              . $text . "\n";

        $pdf_path = self::generate_pdf_minutes($vote_post_id, $map, $text, (int)$start_ts, (int)$end_ts);

        $attachments = [];
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
            Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                'pdf'   => basename($pdf_path),
                'bytes' => (int) filesize($pdf_path),
            ]);
        } else {
            Spolek_Audit::log($vote_post_id, null, 'pdf_generated', [
                'pdf' => null,
                'error' => 'pdf not generated',
            ]);
        }

        // --- čítače (před foreach)
        $sent = 0; $skipped = 0; $failed = 0; $no_email = 0; $total = 0;
        $members = self::get_members();

        Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_start', [
            'members' => is_array($members) ? count($members) : 0,
            'has_pdf' => !empty($attachments) ? 1 : 0,
        ]);

        foreach ($members as $u) {
            $total++;

            $exp = time() + (30 * DAY_IN_SECONDS);
            $uid = (int) $u->ID;
            $sig = self::member_pdf_sig($uid, $vote_post_id, $exp);

            $landing = trailingslashit(home_url('/clenove/stazeni-zapisu/'));

            $pdf_link = add_query_arg([
                'vote_post_id' => $vote_post_id,
                'uid'          => $uid,
                'exp'          => $exp,
                'sig'          => $sig,
            ], $landing);

            $body_with_link = $body
                . "\n\nZápis PDF ke stažení (vyžaduje přihlášení):\n<"
                . $pdf_link
                . ">\n";

            $mail_status = self::send_member_mail($vote_post_id, $u, 'result', $subject, $body_with_link, $attachments);

            if ($mail_status === 'sent') $sent++;
            elseif ($mail_status === 'skip') $skipped++;
            elseif ($mail_status === 'no_email') $no_email++;
            else $failed++;
        }

        Spolek_Audit::log($vote_post_id, null, 'result_mail_batch_done', [
            'total'    => (int)$total,
            'sent'     => (int)$sent,
            'skip'     => (int)$skipped,
            'no_email' => (int)$no_email,
            'failed'   => (int)$failed,
            'has_pdf'  => !empty($attachments) ? 1 : 0,
        ]);

        if ($failed > 0) {
    update_post_meta($vote_post_id, self::META_CLOSE_LAST_ERROR, "result mails failed: $failed");
    throw new \RuntimeException("result mails failed: $failed");
}
            // teprve když je všechno OK:
            update_post_meta($vote_post_id, self::META_CLOSE_PROCESSED_AT, (string) time());
            delete_post_meta($vote_post_id, self::META_CLOSE_STARTED_AT);
            delete_post_meta($vote_post_id, self::META_CLOSE_LAST_ERROR);
            delete_post_meta($vote_post_id, self::META_CLOSE_NEXT_RETRY);

    } catch (\Throwable $e) {
        $msg = substr((string) $e->getMessage(), 0, 500);

        update_post_meta($vote_post_id, self::META_CLOSE_LAST_ERROR, $msg);

        Spolek_Audit::log($vote_post_id, null, 'cron_close_exception', [
            'attempt' => (int)$attempt,
            'message' => $msg,
            'code'    => (int)$e->getCode(),
            'file'    => basename((string)$e->getFile()),
            'line'    => (int)$e->getLine(),
        ]);

        self::schedule_close_retry($vote_post_id, (int)$attempt, 'exception');

    } finally {
        self::release_close_lock($vote_post_id, $lock_token);
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

private static function evaluate_vote(int $vote_post_id, array $counts) : array {
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

    $autoload = SPOLEK_HLASOVANI_DIR . '/vendor/autoload.php';
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
    $table = $wpdb->prefix . 'spolek_vote_mail_log';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table
         WHERE vote_post_id=%d AND user_id=%d AND mail_type=%s AND status='sent'
         LIMIT 1",
        $vote_post_id, $user_id, $type
    ));

    return !empty($exists);
}

private static function log_mail(int $vote_post_id, int $user_id, string $type, string $status, string $error = null) : void {
    global $wpdb;
    $t = $wpdb->prefix . 'spolek_vote_mail_log';

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $t (vote_post_id, user_id, mail_type, sent_at, status, error_text)
         VALUES (%d, %d, %s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
            sent_at = VALUES(sent_at),
            status = VALUES(status),
            error_text = VALUES(error_text)",
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

    if (empty($u->user_email)) return 'no_email';

    if (self::mail_already_sent($vote_post_id, (int)$u->ID, $type)) {
        return 'skip'; // už jednou odesláno
    }

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $ok = wp_mail($u->user_email, $subject, $body, $headers, (array)$attachments);

    if ($ok) {
        self::log_mail($vote_post_id, (int)$u->ID, $type, 'sent', null);
        return 'sent';
    } else {
        self::log_mail($vote_post_id, (int)$u->ID, $type, 'fail', 'wp_mail returned false');
        return 'fail';
    }
}

private static function schedule_vote_events(int $post_id, int $start_ts, int $end_ts) : void {

    // 1) Uzavření - vždy per konkrétní post_id
    wp_clear_scheduled_hook('spolek_vote_close', [$post_id]);
    wp_schedule_single_event($end_ts, 'spolek_vote_close', [$post_id]);

    // 2) Reminder 48h (pokud používáš)
    $t48 = $end_ts - 48 * HOUR_IN_SECONDS;
    wp_clear_scheduled_hook('spolek_vote_reminder', [$post_id, 'reminder48']);
    if ($t48 > time()) {
        wp_schedule_single_event($t48, 'spolek_vote_reminder', [$post_id, 'reminder48']);
    }

    // 3) Reminder 24h (pokud používáš)
    $t24 = $end_ts - 24 * HOUR_IN_SECONDS;
    wp_clear_scheduled_hook('spolek_vote_reminder', [$post_id, 'reminder24']);
    if ($t24 > time()) {
        wp_schedule_single_event($t24, 'spolek_vote_reminder', [$post_id, 'reminder24']);
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
        if ($now < $end_ts) return 'open';
        return 'closed';
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
    
    private static function close_retry_delay_seconds(int $attempt) : int {
    // 1. pokus po pádu: 5 min, pak 15 min, 30 min, 60 min, 2 h (cap)
    $delays = [300, 900, 1800, 3600, 7200];
    $idx = max(1, $attempt) - 1;
    if ($idx >= count($delays)) $idx = count($delays) - 1;
    return (int) $delays[$idx];
}

private static function schedule_close_retry(int $vote_post_id, int $attempt, string $reason) : void {
    // už hotovo? tak nic
    $processed_at = get_post_meta($vote_post_id, self::META_CLOSE_PROCESSED_AT, true);
    if (!empty($processed_at)) return;

    if ($attempt >= self::CLOSE_MAX_ATTEMPTS) {
        Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_give_up', [
            'attempt' => $attempt,
            'max' => self::CLOSE_MAX_ATTEMPTS,
            'reason' => $reason,
        ]);
        return;
    }

    // backoff + malý jitter (0–30s)
    $delay = self::close_retry_delay_seconds($attempt);
    $jitter = (int) wp_rand(0, 30);
    $when = time() + $delay + $jitter;

    // neplánuj duplicitně
    $next = wp_next_scheduled('spolek_vote_close', [$vote_post_id]);
    if ($next && $next > (time() + 10)) {
        // už je něco naplánováno – necháme to
        update_post_meta($vote_post_id, self::META_CLOSE_NEXT_RETRY, (string) $next);
        return;
    }

    wp_schedule_single_event($when, 'spolek_vote_close', [$vote_post_id]);
    update_post_meta($vote_post_id, self::META_CLOSE_NEXT_RETRY, (string) $when);

    Spolek_Audit::log($vote_post_id, null, 'cron_close_retry_scheduled', [
        'attempt' => $attempt,
        'when' => $when,
        'delay' => $delay,
        'reason' => $reason,
    ]);
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

private static function close_lock_key(int $vote_post_id) : string {
    // option_name max 191 znaků v WP tabulkách – tohle je krátké
    return 'spolek_vote_close_lock_' . $vote_post_id;
}

/**
 * Atomický lock přes add_option(). Vrací token locku nebo null.
 */
private static function acquire_close_lock(int $vote_post_id, int $ttl = 600) : ?string {
    $key = self::close_lock_key($vote_post_id);

    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : (string) wp_rand(100000, 999999) . '-' . microtime(true);
    $exp   = time() + max(30, $ttl); // minimálně 30s

    // uložíme "exp|token"
    $value = $exp . '|' . $token;

    // add_option je v DB atomické (unikátní option_name)
    if (add_option($key, $value, '', 'no')) {
        return $token;
    }

    // existuje – zkusíme, jestli neexpiroval
    $existing = (string) get_option($key, '');
    if ($existing !== '') {
        $parts = explode('|', $existing, 2);
        $existing_exp = (int)($parts[0] ?? 0);

        if ($existing_exp > 0 && $existing_exp < time()) {
            // expirovaný lock -> smaž a zkus znovu
            delete_option($key);
            if (add_option($key, $value, '', 'no')) {
                return $token;
            }
        }
    }

    return null;
}

/**
 * Uvolní lock jen pokud token sedí (bezpečné při expiraci/novém locku).
 */
private static function release_close_lock(int $vote_post_id, string $token) : void {
    $key = self::close_lock_key($vote_post_id);

    $existing = (string) get_option($key, '');
    if ($existing === '') return;

    $parts = explode('|', $existing, 2);
    $existing_token = (string)($parts[1] ?? '');

    if ($existing_token !== '' && hash_equals($existing_token, $token)) {
        delete_option($key);
    }
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
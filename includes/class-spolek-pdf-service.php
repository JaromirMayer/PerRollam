<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF servis – generování zápisu, tvorba odkazů pro členy, validace a download handlery.
 *
 * Cíl: vyčistit PDF logiku z legacy (Spolek_Hlasovani_MVP) do samostatné třídy.
 */
final class Spolek_PDF_Service {

    private const META_PDF_PATH         = Spolek_Config::META_PDF_PATH;
    private const META_PDF_GENERATED_AT = Spolek_Config::META_PDF_GENERATED_AT;

    /**
     * HMAC podpis pro “member PDF” link – legacy (obsahuje vote_post_id + uid v URL).
     */
    public static function member_sig(int $user_id, int $vote_post_id, int $exp): string {
        $data = $user_id . '|' . $vote_post_id . '|' . $exp;
        return hash_hmac('sha256', $data, wp_salt('spolek_member_pdf'));
    }

    /**
     * HMAC podpis pro “member PDF” link – v2 (bez uid a bez post_id v URL; používá vid = veřejný token).
     */
    public static function member_sig_v2(int $user_id, string $vote_public_id, int $exp): string {
        $vote_public_id = strtolower(trim((string)$vote_public_id));
        $data = $user_id . '|' . $vote_public_id . '|' . $exp;
        return hash_hmac('sha256', $data, wp_salt('spolek_member_pdf_v2'));
    }

    /**
     * URL pro admin download PDF (správce) – admin-post handler.
     */
    public static function admin_download_url(int $vote_post_id): string {
        $vote_post_id = (int)$vote_post_id;
        return add_query_arg([
            'action'       => 'spolek_download_pdf',
            'vote_post_id' => $vote_post_id,
            '_nonce'       => wp_create_nonce('spolek_download_pdf_' . $vote_post_id),
        ], admin_url('admin-post.php'));
    }

    /**
     * Základní landing URL (front-end stránka se shortcode [spolek_pdf_landing]).
     * Lze přepsat filtrem "spolek_pdf_landing_url".
     */
    public static function landing_base_url(): string {
        $default = home_url('/clenove/stazeni-zapisu/');
        $url = (string) apply_filters('spolek_pdf_landing_url', $default);
        return $url ?: $default;
    }

    /**
     * URL pro člena – na landing stránku (obsahuje vote_post_id/uid/exp/sig).
     */
    public static function member_landing_url(int $vote_post_id, int $user_id, int $exp, string $sig): string {
        return add_query_arg([
            'vote_post_id' => (int)$vote_post_id,
            'uid'          => (int)$user_id,
            'exp'          => (int)$exp,
            'sig'          => (string)$sig,
        ], self::landing_base_url());
    }

    /**
     * URL pro člena – v2 (bez uid/post_id). Parametr vid je veřejný token hlasování.
     */
    public static function member_landing_url_v2(string $vote_public_id, int $exp, string $sig): string {
        return add_query_arg([
            'vid' => strtolower(trim((string)$vote_public_id)),
            'exp' => (int)$exp,
            'sig' => (string)$sig,
        ], self::landing_base_url());
    }

    /**
     * URL přímo na admin-post download pro člena (používá se v landing shortcodu).
     */
    public static function member_adminpost_url(int $vote_post_id, int $user_id, int $exp, string $sig): string {
        return add_query_arg([
            'action'       => 'spolek_member_pdf',
            'vote_post_id' => (int)$vote_post_id,
            'uid'          => (int)$user_id,
            'exp'          => (int)$exp,
            'sig'          => (string)$sig,
        ], admin_url('admin-post.php'));
    }

    /** Admin-post URL – v2 (bez uid/post_id). */
    public static function member_adminpost_url_v2(string $vote_public_id, int $exp, string $sig): string {
        return add_query_arg([
            'action' => 'spolek_member_pdf',
            'vid'    => strtolower(trim((string)$vote_public_id)),
            'exp'    => (int)$exp,
            'sig'    => (string)$sig,
        ], admin_url('admin-post.php'));
    }

    /**
     * Validace parametrů a podpisu (bez kontroly přihlášení/rolí).
     */
    public static function validate_member_link(int $vote_post_id, int $user_id, int $exp, string $sig): bool {
        if ($vote_post_id <= 0 || $user_id <= 0 || $exp <= 0 || $sig === '') return false;
        if (!preg_match('/^[a-f0-9]{64}$/i', $sig)) return false;
        if ($exp < time()) return false;
        $expected = self::member_sig($user_id, $vote_post_id, $exp);
        return hash_equals($expected, $sig);
    }

    /** Validace v2 linku (vid + exp + sig) pro daného aktuálního uživatele. */
    public static function validate_member_link_v2(string $vote_public_id, int $user_id, int $exp, string $sig): bool {
        $vote_public_id = strtolower(trim((string)$vote_public_id));
        if ($vote_public_id === '' || $user_id <= 0 || $exp <= 0 || $sig === '') return false;
        if (!preg_match('/^[a-f0-9]{64}$/i', $sig)) return false;
        if ($exp < time()) return false;
        $expected = self::member_sig_v2($user_id, $vote_public_id, $exp);
        return hash_equals($expected, $sig);
    }

    /**
     * Front-end landing – spustí download přes skrytý iframe a pak přesměruje.
     * Použití: [spolek_pdf_landing] na stránce /clenove/stazeni-zapisu/
     */
    public static function shortcode_pdf_landing($atts = [], $content = null): string {
        $atts = shortcode_atts([
            'after' => '',       // volitelně přesměrování po stažení
            'delay' => '1500',   // ms
        ], (array)$atts, 'spolek_pdf_landing');

        $vote_post_id = isset($_GET['vote_post_id']) ? (int)$_GET['vote_post_id'] : 0;
        $uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
        $vid = isset($_GET['vid']) ? sanitize_text_field(wp_unslash((string)$_GET['vid'])) : '';
        $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? sanitize_text_field(wp_unslash((string)$_GET['sig'])) : '';

        $is_v2 = ($vid !== '' && $exp > 0 && $sig !== '');
        $is_legacy = ($vote_post_id > 0 && $uid > 0 && $exp > 0 && $sig !== '');
        if (!$is_v2 && !$is_legacy) {
            return '<p>Neplatný odkaz.</p>';
        }

        // když není přihlášený, vrať ho na login a po loginu zpět sem
        if (!is_user_logged_in()) {
            // bezpečná konstrukce redirect URL přes site/admin URL (bez spoléhání na Host header)
            $params = [];
            if ($is_v2) {
                $params = ['vid' => $vid, 'exp' => $exp, 'sig' => $sig];
            } else {
                $params = ['vote_post_id' => $vote_post_id, 'uid' => $uid, 'exp' => $exp, 'sig' => $sig];
            }
            $landing = add_query_arg($params, self::landing_base_url());
            wp_safe_redirect(wp_login_url(esc_url_raw($landing)));
            exit;
        }

        $download_url = $is_v2
            ? self::member_adminpost_url_v2($vid, $exp, $sig)
            : self::member_adminpost_url($vote_post_id, $uid, $exp, $sig);

        $default_after = home_url('/clenove/portal/');
        $after_url = $atts['after'] !== ''
            ? (string) $atts['after']
            : (string) apply_filters('spolek_pdf_after_url', $default_after);

        $download_url = esc_url($download_url);
        $after_url    = esc_url($after_url ?: $default_after);
        $delay_ms     = max(0, (int)$atts['delay']);

        return '
<div style="max-width:720px;margin:20px auto;padding:16px;border:1px solid #ddd;">
  <h2>Stahuji zápis PDF…</h2>
  <p>Pokud se stažení nespustí automaticky, klikni zde: <a href="' . $download_url . '">Stáhnout PDF</a></p>
  <p>Po stažení budeš přesměrován.</p>

  <iframe src="' . $download_url . '" style="display:none;width:0;height:0;border:0;"></iframe>

  <script>
    setTimeout(function(){
      window.location.href = "' . $after_url . '";
    }, ' . $delay_ms . ');
  </script>
</div>';
    }

    /**
     * Handler: správce stáhne poslední vygenerované PDF (nonce + cap).
     */
    public static function handle_admin_download_pdf(): void {
        if (!is_user_logged_in() || !self::is_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        Spolek_Admin::throttle_or_die('admin_pdf', 120, HOUR_IN_SECONDS);

        $vote_post_id = isset($_GET['vote_post_id']) ? (int)$_GET['vote_post_id'] : 0;
        if (!$vote_post_id) wp_die('Neplatné hlasování.');

        $nonce = isset($_GET['_nonce']) ? sanitize_text_field(wp_unslash((string)$_GET['_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'spolek_download_pdf_' . $vote_post_id)) {
            wp_die('Neplatný nonce.');
        }

        $path = self::get_pdf_path($vote_post_id);
        self::send_pdf_file_or_die($path);
    }

    /**
     * Handler: člen (nebo správce) stáhne PDF přes podepsaný link.
     */
    public static function handle_member_pdf(): void {
        $vote_post_id = isset($_GET['vote_post_id']) ? (int)$_GET['vote_post_id'] : 0;
        $uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
        $vid = isset($_GET['vid']) ? sanitize_text_field(wp_unslash((string)$_GET['vid'])) : '';
        $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? sanitize_text_field(wp_unslash((string)$_GET['sig'])) : '';

        if ($exp <= 0 || $sig === '') {
            wp_die('Neplatný odkaz.');
        }
        if ($exp < time()) {
            wp_die('Odkaz vypršel.');
        }

        // Pokud není přihlášený, přesměruj na login a vrať se zpět na tento odkaz
        if (!is_user_logged_in()) {
            $params = [];
            if ($vid !== '') {
                $params = ['action' => 'spolek_member_pdf', 'vid' => $vid, 'exp' => $exp, 'sig' => $sig];
            } else {
                $params = ['action' => 'spolek_member_pdf', 'vote_post_id' => $vote_post_id, 'uid' => $uid, 'exp' => $exp, 'sig' => $sig];
            }
            $url = add_query_arg($params, admin_url('admin-post.php'));
            wp_safe_redirect(wp_login_url(esc_url_raw($url)));
            exit;
        }

        // Role / oprávnění (člen/správce/admin)
        if (!self::is_member_or_manager()) {
            wp_die('Nemáte oprávnění.');
        }

        // throttling – download je citlivý endpoint
        Spolek_Admin::throttle_or_die('member_pdf', 60, HOUR_IN_SECONDS);

        $current_uid = (int) get_current_user_id();

        // v2: vid + sig (bez uid/post_id v URL)
        if ($vid !== '') {
            $vid = strtolower(trim($vid));
            if (!self::validate_member_link_v2($vid, $current_uid, $exp, $sig)) {
                wp_die('Neplatný odkaz.');
            }

            if (!class_exists('Spolek_Vote_Service')) {
                wp_die('Neplatný odkaz.');
            }
            $vote_post_id = Spolek_Vote_Service::resolve_public_id($vid);
            if ($vote_post_id <= 0) {
                wp_die('Neplatný odkaz.');
            }

            $path = self::get_pdf_path($vote_post_id);
            self::send_pdf_file_or_die($path);
        }

        // legacy: vote_post_id + uid + sig
        if (!$vote_post_id || !$uid) {
            wp_die('Neplatný odkaz.');
        }

        // Pokud je přihlášený jiný uživatel než uid v odkazu, povol jen správci
        if ($current_uid !== $uid && !self::is_manager()) {
            wp_die('Neplatný odkaz.');
        }

        if (!self::validate_member_link($vote_post_id, $uid, $exp, $sig)) {
            wp_die('Neplatný odkaz.');
        }

        $path = self::get_pdf_path($vote_post_id);
        self::send_pdf_file_or_die($path);
    }

    /**
     * Generování PDF zápisu (DOMPDF). Vrací cestu na soubor nebo null.
     */
    public static function generate_pdf_minutes(int $vote_post_id, array $map, string $text, int $start_ts, int $end_ts): ?string {
        if (!self::ensure_dompdf()) {
            return null;
        }

        $post = get_post($vote_post_id);
        if (!$post) return null;

        $tz = wp_timezone();

        $title     = (string) $post->post_title;
        $generated = wp_date('j.n.Y H:i', time(), $tz);
        $start_s   = wp_date('j.n.Y H:i', (int)$start_ts, $tz);
        $end_s     = wp_date('j.n.Y H:i', (int)$end_ts, $tz);

        $ano    = (int)($map['ANO'] ?? 0);
        $ne     = (int)($map['NE'] ?? 0);
        $zdrzel = (int)($map['ZDRZEL'] ?? 0);
        $total  = $ano + $ne + $zdrzel;

        $html = '<!doctype html><html><head><meta charset="utf-8">'
              . '<style>'
              . 'body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.35; }'
              . 'h1 { font-size: 18px; margin: 0 0 10px 0; }'
              . 'h2 { font-size: 14px; margin: 16px 0 8px 0; }'
              . '.box { border: 1px solid #333; padding: 10px; margin: 10px 0; }'
              . 'table { width: 100%; border-collapse: collapse; margin-top: 8px; }'
              . 'th, td { border: 1px solid #333; padding: 6px; text-align: left; }'
              . '.muted { color: #555; }'
              . 'pre { white-space: pre-wrap; font-family: DejaVu Sans, sans-serif; }'
              . '</style>'
              . '</head><body>';

        $html .= '<h1>Zápis o hlasování per rollam</h1>';
        $html .= '<div class="muted">Vygenerováno: ' . esc_html($generated) . '</div>';

        $html .= '<div class="box">'
              . '<strong>Název hlasování:</strong> ' . esc_html($title) . '<br>'
              . '<strong>ID hlasování:</strong> ' . (int)$vote_post_id . '<br>'
              . '<strong>Otevřené od:</strong> ' . esc_html($start_s) . '<br>'
              . '<strong>Deadline:</strong> ' . esc_html($end_s) . '<br>'
              . '</div>';

        $html .= '<h2>Výsledek</h2>'
              . '<table>'
              .   '<tr><th>Volba</th><th>Počet</th></tr>'
              .   '<tr><td>ANO</td><td>' . $ano . '</td></tr>'
              .   '<tr><td>NE</td><td>' . $ne . '</td></tr>'
              .   '<tr><td>ZDRŽEL SE</td><td>' . $zdrzel . '</td></tr>'
              .   '<tr><td><strong>Celkem</strong></td><td><strong>' . $total . '</strong></td></tr>'
              . '</table>';

        $html .= '<h2>Plné znění návrhu</h2>'
              . '<div class="box"><pre>' . esc_html($text) . '</pre></div>'
              . '</body></html>';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $out = self::pdf_upload_dir();
        $fname = 'zapis-' . $vote_post_id . '-' . wp_date('Ymd-His', time(), $tz) . '.pdf';
        $path  = trailingslashit($out['dir']) . $fname;

        $pdf = $dompdf->output();
        if (!$pdf) return null;

        $ok = @file_put_contents($path, $pdf);
        if (!$ok) return null;

        update_post_meta($vote_post_id, self::META_PDF_PATH, $path);
        update_post_meta($vote_post_id, self::META_PDF_GENERATED_AT, current_time('mysql'));

        return $path;
    }

    // =====================================================================
    // Internals
    // =====================================================================

    private static function ensure_dompdf(): bool {
        if (class_exists('\\Dompdf\\Dompdf')) return true;

        $autoload = rtrim((string)SPOLEK_HLASOVANI_PATH, '/\\') . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        return class_exists('\\Dompdf\\Dompdf');
    }

    private static function pdf_upload_dir(): array {
        $up  = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'spolek-hlasovani';
        $url = trailingslashit($up['baseurl']) . 'spolek-hlasovani';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Apache ochrana – pro nginx to není spolehlivé, ale neškodí.
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Deny from all\n");
        }

        // Záloha i pro servery bez .htaccess
        $idx = $dir . '/index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, '');
        }

        return ['dir' => $dir, 'url' => $url];
    }

    private static function get_pdf_path(int $vote_post_id): string {
        return (string) get_post_meta($vote_post_id, self::META_PDF_PATH, true);
    }

    private static function send_pdf_file_or_die(string $path): void {
        if (!$path || !file_exists($path) || !is_readable($path)) {
            wp_die('Soubor nenalezen.');
        }

        // Bezpečnost: povol pouze soubory uvnitř uploads/spolek-hlasovani
        $base = self::pdf_upload_dir();
        $base_dir = realpath($base['dir']);
        $real = realpath($path);

        // normalizace prefixu (musí končit /), aby neprošel "spolek-hlasovani2"
        $base_prefix = $base_dir ? rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
        $real_norm   = $real ? rtrim($real, DIRECTORY_SEPARATOR) : '';

        if (!$base_prefix || !$real_norm || strpos($real_norm, $base_prefix) !== 0) {
            wp_die('Neplatná cesta k souboru.');
        }

        nocache_headers();
        $filename = basename($real);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    private static function is_manager(): bool {
        if (!is_user_logged_in()) return false;
        if (class_exists('Spolek_Config') && current_user_can(Spolek_Config::CAP_MANAGE)) return true;
        if (current_user_can('manage_spolek_hlasovani')) return true;
        $user = wp_get_current_user();
        return in_array('spravce_hlasovani', (array)$user->roles, true);
    }

    private static function is_member_or_manager(): bool {
        if (!is_user_logged_in()) return false;
        if (self::is_manager()) return true;
        $user  = wp_get_current_user();
        $roles = (array)$user->roles;
        return in_array('clen', $roles, true) || in_array('spravce_hlasovani', $roles, true);
    }
}

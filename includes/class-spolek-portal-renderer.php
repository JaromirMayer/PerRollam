<?php
if (!defined('ABSPATH')) exit;

/**
 * 5.3 – Portál (UI render) vytažený z legacy.
 *
 * Cíl: class-spolek-legacy.php držet jen kompatibilní obálku a business logiku,
 * UI rendering (shortcode portálu) je tady.
 */
final class Spolek_Portal_Renderer {

    // Udržujeme stejné hodnoty jako legacy (backward-compat)
    private const CPT = Spolek_Config::CPT;
    private const TABLE = Spolek_Config::TABLE_VOTES;

    /** Vykreslí celý portál (shortcode). */
    public static function render_portal(): string {

        $assets = self::portal_assets();

        if (!is_user_logged_in()) {

            // návrat po přihlášení zpět na portál
            $redirect = self::portal_url();

            // login stránka /login (i když není "WP stránka", URL funguje)
            $login_base = home_url('/login/');
            $login_url  = add_query_arg('redirect_to', $redirect, $login_base);

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

        // Správce: formulář pro nové hlasování
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

    /** Vizuální helpery portálu. */
    private static function portal_assets(): string {
        return '<style>
            .spolek-portal .spolek-section-sep{border:0;border-top:1px solid var(--spolek-accent,#2271b1);margin:14px 0;}
            .spolek-portal .spolek-login-box{max-width:720px;margin:12px 0;padding:16px;border:1px solid #ddd;background:#fff;}
            .spolek-portal .spolek-login-button{display:inline-block;padding:10px 16px;border-radius:6px;background:var(--spolek-accent,#2271b1);color:#fff;text-decoration:none;}
            .spolek-portal .spolek-login-button:hover{filter:brightness(.95);color:#fff;text-decoration:none;}
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

    private static function portal_url(): string {
        // aktuální URL stránky bez query
        return remove_query_arg([
            'spolek_vote','created','voted','err','export','archived','purged','purge_scan','purge_scan_purged',
            'notice','storage_test','storage_test_ok','storage_test_err','storage_test_storage','storage_test_dir','storage_test_err'
        ], home_url(add_query_arg([])));
    }

    private static function is_manager(): bool {
        return class_exists('Spolek_Admin') ? Spolek_Admin::is_manager() : (is_user_logged_in() && current_user_can('manage_options'));
    }

    /** @return array{0:int,1:int,2:string} */
    private static function vote_meta(int $post_id): array {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_vote_meta($post_id)
            : [(int) get_post_meta($post_id, Spolek_Config::META_START_TS, true), (int) get_post_meta($post_id, Spolek_Config::META_END_TS, true), (string) get_post_meta($post_id, Spolek_Config::META_TEXT, true)];
    }

    private static function vote_status(int $start_ts, int $end_ts): string {
        return class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_status($start_ts, $end_ts)
            : 'closed';
    }

    private static function user_has_voted(int $vote_post_id, int $user_id): bool {
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

    private static function render_create_form(): string {
        $action = esc_url(admin_url('admin-post.php'));
        $now = time();
        $default_end = $now + (7 * DAY_IN_SECONDS);

        $html  = '<h2>Nové hlasování</h2>';
        if (!empty($_GET['created'])) {
            $html .= '<p><strong>Hlasování bylo vytvořeno.</strong></p>';
        }
        if (!empty($_GET['err'])) {
            $html .= '<p><strong style="color:#b00;">Chyba: ' . esc_html((string)$_GET['err']) . '</strong></p>';
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

    private static function render_list(): string {
        $now = time();
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'meta_value_num',
            'meta_key' => Spolek_Config::META_END_TS,
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key'     => Spolek_Config::META_END_TS,
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
            [$start_ts, $end_ts] = self::vote_meta($id);

            $status = self::vote_status($start_ts, $end_ts);
            $label = $status === 'open' ? 'Otevřené' : ($status === 'closed' ? 'Ukončené' : 'Připravované');

            $link = add_query_arg('spolek_vote', $id, self::portal_url());
            $html .= '<li>';
            $html .= '<a href="'.esc_url($link).'">' . esc_html(get_the_title()) . '</a>';
            $html .= ' — <em>' . esc_html($label) . '</em>';
            if ($start_ts && $end_ts) {
                $html .= ' (od ' . esc_html(wp_date('j.n.Y H:i', $start_ts)) . ' do ' . esc_html(wp_date('j.n.Y H:i', $end_ts)) . ')';
            }
            $html .= '</li>';
        }
        wp_reset_postdata();
        $html .= '</ul>';

        return $html;
    }

    /** 4.2 – Archiv uzavřených hlasování (jen pro správce). */
    private static function render_archive_panel(): string {
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
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
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
                [$start_ts, $end_ts] = self::vote_meta($id);

                $processed_at = (string) get_post_meta($id, Spolek_Config::META_CLOSE_PROCESSED_AT, true);
                $file = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_FILE, true);
                $sha  = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_SHA256, true);
                $err  = (string) get_post_meta($id, Spolek_Config::META_ARCHIVE_ERROR, true);

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

        // Ruční spuštění purge scanu (4.3)
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
        $purged = array_filter($items, static function($it){ return !empty($it['purged_at']); });

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

    /** Uzavřená hlasování – jen seznam pro členy. */
    private static function render_closed_list(): string {
        $now = time();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'meta_value_num',
            'meta_key'       => Spolek_Config::META_END_TS,
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => Spolek_Config::META_END_TS,
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
            [$start_ts, $end_ts] = self::vote_meta($id);

            $link = add_query_arg('spolek_vote', $id, self::portal_url());

            $end_label = $end_ts ? wp_date('j.n.Y H:i', (int)$end_ts, wp_timezone()) : '–';
            $html .= '<li><a href="'.esc_url($link).'">' . esc_html(get_the_title()) . '</a>'
                . ' <span style="opacity:.75;">(ukončeno '.$end_label.')</span></li>';
        }

        wp_reset_postdata();
        $html .= '</ul></details>';

        return $html;
    }

    private static function render_detail(int $vote_post_id): string {
        $post = get_post($vote_post_id);
        if (!$post || $post->post_type !== self::CPT) {
            return '<p>Hlasování nenalezeno.</p>';
        }

        [$start_ts, $end_ts, $text] = self::vote_meta($vote_post_id);
        $status = self::vote_status($start_ts, $end_ts);

        $html = '<h2>' . esc_html($post->post_title) . '</h2>';
        if ($start_ts && $end_ts) {
            $html .= '<p><strong>Termín:</strong> ' . esc_html(wp_date('j.n.Y H:i', $start_ts)) . ' – ' . esc_html(wp_date('j.n.Y H:i', $end_ts)) . '</p>';
        }

        $html .= '<div style="white-space:pre-wrap; padding:12px; border:1px solid #ddd;">' . esc_html($text) . '</div>';

        $user_id = get_current_user_id();

        if (!empty($_GET['voted'])) {
            $html .= '<p><strong>Děkujeme, hlas byl uložen.</strong></p>';
        }
        if (!empty($_GET['err'])) {
            $html .= '<p><strong style="color:#b00;">Chyba: ' . esc_html((string)$_GET['err']) . '</strong></p>';
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

    private static function render_vote_form(int $vote_post_id): string {
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

    private static function render_manager_tools(int $vote_post_id): string {
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

        $pdf_path = (string) get_post_meta($vote_post_id, Spolek_Config::META_PDF_PATH, true);
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
}

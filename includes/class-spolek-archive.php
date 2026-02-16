<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Archive
 *
 * Vytváří archiv uzavřeného hlasování (ZIP) + vede jednoduchý index na disku.
 * Archivy se ukládají do wp-content/uploads/spolek-hlasovani-archiv/
 *
 * Obsah ZIP:
 * - summary.json
 * - proposal.txt
 * - minutes.pdf (pokud existuje)
 * - votes.csv
 * - audit.csv
 * - mail_log.csv
 */
final class Spolek_Archive {

    public const DIR_SLUG = 'spolek-hlasovani/archive';
    public const INDEX_FILE = 'archives.json';

    // Post meta (u existujícího hlasování – než dojde k purge)
    public const META_ARCHIVE_FILE   = '_spolek_archive_file';   // basename zip
    public const META_ARCHIVE_SHA256 = '_spolek_archive_sha256';
    public const META_ARCHIVED_AT    = '_spolek_archived_at';
    public const META_ARCHIVE_ERROR  = '_spolek_archive_error';

    /** Zajistí adresář + základní ochranu (best-effort). */
    public static function ensure_storage(): void {
        $dir = self::archive_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // index.html aby se v adresáři nic nelistovalo
        $index_html = $dir . '/index.html';
        if (!file_exists($index_html)) {
            @file_put_contents($index_html, "<!doctype html><meta charset=\"utf-8\"><title>403</title>", LOCK_EX);
        }

        // .htaccess (funguje na Apache; na Nginx je potřeba serverová konfigurace)
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            $rules = "Order deny,allow\nDeny from all\n";
            @file_put_contents($ht, $rules, LOCK_EX);
        }

        // index JSON (pokud neexistuje)
        $idx = self::index_path();
        if (!file_exists($idx)) {
            self::write_index([
                'version'    => 1,
                'updated_at' => time(),
                'items'      => [],
            ]);
        }
    }

    /** Vrátí seřazený seznam všech archivů z indexu. */
    public static function list_archives(): array {
        $data = self::read_index();
        $items = (array)($data['items'] ?? []);
        usort($items, function($a, $b) {
            return (int)($b['archived_at'] ?? 0) <=> (int)($a['archived_at'] ?? 0);
        });
        return $items;
    }

    /** Najde položku indexu podle vote_post_id. */
    public static function find_by_vote(int $vote_post_id): ?array {
        $vote_post_id = (int)$vote_post_id;
        foreach (self::list_archives() as $it) {
            if ((int)($it['vote_post_id'] ?? 0) === $vote_post_id) return $it;
        }
        return null;
    }

    /** Najde položku indexu podle souboru (basename). */
    public static function find_by_file(string $file): ?array {
        $file = basename((string)$file);
        foreach (self::list_archives() as $it) {
            if ((string)($it['file'] ?? '') === $file) return $it;
        }
        return null;
    }

    /**
     * Vytvoří archiv hlasování.
     * - pokud už existuje (meta + soubor), vrací ok=true a stávající soubor
     */
    public static function archive_vote(int $vote_post_id, bool $force = false): array {
        $vote_post_id = (int)$vote_post_id;

        self::ensure_storage();

        $post = get_post($vote_post_id);
        if (!$post) {
            return ['ok' => false, 'error' => 'vote_not_found'];
        }

        // Existující archiv?
        $existing_file = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_FILE, true);
        if (!$force && $existing_file !== '') {
            $existing_path = self::archive_dir() . '/' . basename($existing_file);
            if (file_exists($existing_path)) {
                $sha = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_SHA256, true);
                return ['ok' => true, 'file' => basename($existing_file), 'path' => $existing_path, 'sha256' => $sha, 'already' => true];
            }
        }

        // Základní meta hlasování
        $start_ts = (int) get_post_meta($vote_post_id, '_spolek_start_ts', true);
        $end_ts   = (int) get_post_meta($vote_post_id, '_spolek_end_ts', true);
        $text     = (string) get_post_meta($vote_post_id, '_spolek_text', true);

        // Pokud existuje legacy helper, použijeme ho (aby text odpovídal realitě)
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'get_vote_meta')) {
            try {
                $tmp = Spolek_Hlasovani_MVP::get_vote_meta($vote_post_id);
                $start_ts = (int)($tmp[0] ?? $start_ts);
                $end_ts   = (int)($tmp[1] ?? $end_ts);
                $text     = (string)($tmp[2] ?? $text);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Počty hlasů
        $counts = self::counts_map($vote_post_id);

        // Vyhodnocení
        $eval = null;
        if (class_exists('Spolek_Hlasovani_MVP') && method_exists('Spolek_Hlasovani_MVP', 'evaluate_vote')) {
            try {
                $eval = Spolek_Hlasovani_MVP::evaluate_vote($vote_post_id, $counts);
            } catch (\Throwable $e) {
                $eval = null;
            }
        }

        // Název souboru
        $slug = sanitize_title($post->post_title);
        if ($slug === '') $slug = 'hlasovani';
        $slug = substr($slug, 0, 50);

        $date = $end_ts ? wp_date('Ymd', $end_ts, wp_timezone()) : wp_date('Ymd', time(), wp_timezone());
        $token = function_exists('wp_generate_password') ? wp_generate_password(10, false, false) : (string) wp_rand(100000, 999999);

        $file = "vote-{$vote_post_id}-{$slug}-{$date}-{$token}.zip";
        $path = self::archive_dir() . '/' . $file;

        if (!class_exists('ZipArchive')) {
            $err = 'ZipArchive not available on server';
            update_post_meta($vote_post_id, self::META_ARCHIVE_ERROR, $err);
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, 'archive_failed', ['error' => $err]);
            return ['ok' => false, 'error' => $err];
        }

        $zip = new ZipArchive();
        $ok = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($ok !== true) {
            $err = 'cannot_create_zip';
            update_post_meta($vote_post_id, self::META_ARCHIVE_ERROR, $err);
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, 'archive_failed', ['error' => $err, 'zip_code' => $ok]);
            return ['ok' => false, 'error' => $err];
        }

        // Připravíme obsah do ZIPu (a zároveň manifest kontrolních součtů)
        $proposal_txt = (string) $text;
        $votes_csv = self::build_votes_csv($vote_post_id);
        $audit_csv = self::build_audit_csv($vote_post_id);
        $mail_csv  = self::build_mail_log_csv($vote_post_id);

        $manifest = [
            'proposal.txt' => ['sha256' => hash('sha256', $proposal_txt), 'bytes' => strlen($proposal_txt)],
            'votes.csv'    => ['sha256' => hash('sha256', $votes_csv),    'bytes' => strlen($votes_csv)],
            'audit.csv'    => ['sha256' => hash('sha256', $audit_csv),    'bytes' => strlen($audit_csv)],
            'mail_log.csv' => ['sha256' => hash('sha256', $mail_csv),     'bytes' => strlen($mail_csv)],
        ];

        // minutes.pdf
        $pdf_path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
        if ($pdf_path !== '' && file_exists($pdf_path)) {
            $manifest['minutes.pdf'] = [
                'sha256' => hash_file('sha256', $pdf_path),
                'bytes'  => (int) filesize($pdf_path),
            ];
        }

        // summary.json
        $summary = [
            'site'        => (string) get_bloginfo('name'),
            'site_url'    => (string) home_url('/'),
            'vote_post_id'=> $vote_post_id,
            'title'       => (string) $post->post_title,
            'start_ts'    => $start_ts,
            'end_ts'      => $end_ts,
            'start'       => $start_ts ? wp_date('c', $start_ts, wp_timezone()) : null,
            'end'         => $end_ts ? wp_date('c', $end_ts, wp_timezone()) : null,
            'processed_at'=> (int) get_post_meta($vote_post_id, (defined('Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT') ? Spolek_Hlasovani_MVP::META_CLOSE_PROCESSED_AT : '_spolek_close_processed_at'), true),
            'counts'      => $counts,
            'evaluation'  => $eval,
            'rules'       => [
                'ruleset'      => (string) get_post_meta($vote_post_id, (defined('Spolek_Hlasovani_MVP::META_RULESET') ? Spolek_Hlasovani_MVP::META_RULESET : '_spolek_ruleset'), true),
                'quorum_ratio' => (float) get_post_meta($vote_post_id, (defined('Spolek_Hlasovani_MVP::META_QUORUM_RATIO') ? Spolek_Hlasovani_MVP::META_QUORUM_RATIO : '_spolek_quorum_ratio'), true),
                'pass_ratio'   => (float) get_post_meta($vote_post_id, (defined('Spolek_Hlasovani_MVP::META_PASS_RATIO') ? Spolek_Hlasovani_MVP::META_PASS_RATIO : '_spolek_pass_ratio'), true),
                'base'         => (string) get_post_meta($vote_post_id, (defined('Spolek_Hlasovani_MVP::META_BASE') ? Spolek_Hlasovani_MVP::META_BASE : '_spolek_pass_base'), true),
            ],
            'files'       => $manifest,
            'archived_at' => wp_date('c', time(), wp_timezone()),
            'plugin'      => [
                'php' => PHP_VERSION,
                'wp'  => (defined('get_bloginfo') ? (string) get_bloginfo('version') : null),
            ],
        ];
        $zip->addFromString('summary.json', wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // proposal.txt
        $zip->addFromString('proposal.txt', $proposal_txt);

        // minutes.pdf
        if ($pdf_path !== '' && file_exists($pdf_path)) {
            $zip->addFile($pdf_path, 'minutes.pdf');
        }

        // votes.csv
        $zip->addFromString('votes.csv', $votes_csv);

        // audit.csv
        $zip->addFromString('audit.csv', $audit_csv);

        // mail_log.csv
        $zip->addFromString('mail_log.csv', $mail_csv);

        $zip->close();

        if (!file_exists($path) || filesize($path) <= 0) {
            @unlink($path);
            $err = 'zip_write_failed';
            update_post_meta($vote_post_id, self::META_ARCHIVE_ERROR, $err);
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, 'archive_failed', ['error' => $err]);
            return ['ok' => false, 'error' => $err];
        }

        $sha = hash_file('sha256', $path);
        $archived_at = time();

        update_post_meta($vote_post_id, self::META_ARCHIVE_FILE, $file);
        update_post_meta($vote_post_id, self::META_ARCHIVE_SHA256, $sha);
        update_post_meta($vote_post_id, self::META_ARCHIVED_AT, (string) $archived_at);
        delete_post_meta($vote_post_id, self::META_ARCHIVE_ERROR);

        self::upsert_index_item([
            'vote_post_id' => $vote_post_id,
            'title'        => (string) $post->post_title,
            'slug'         => $slug,
            'start_ts'     => $start_ts,
            'end_ts'       => $end_ts,
            'archived_at'  => $archived_at,
            'purged_at'    => null,
            'file'         => $file,
            'sha256'       => $sha,
            'bytes'        => (int) filesize($path),
        ]);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, 'archive_created', [
                'file'   => $file,
                'bytes'  => (int) filesize($path),
                'sha256' => $sha,
            ]);
        }

        return ['ok' => true, 'file' => $file, 'path' => $path, 'sha256' => $sha, 'already' => false];
    }

    /** Odeslání ZIP archivu (manager only – kontroluj v handleru). */
    public static function send_archive(string $file): void {
        $file = basename((string)$file);
        if ($file === '' || strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
            wp_die('Neplatný soubor.');
        }

        $path = self::archive_dir() . '/' . $file;
        if (!file_exists($path)) {
            wp_die('Soubor nenalezen.');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Smaže hlasování z DB (post + meta + řádky z vlastních tabulek),
     * ale archivní ZIP ponechá na disku (a v indexu nastaví purged_at).
     */
    public static function purge_vote(int $vote_post_id): array {
        $vote_post_id = (int)$vote_post_id;

        $post = get_post($vote_post_id);
        if (!$post) return ['ok' => false, 'error' => 'vote_not_found'];

        // Musí existovat archiv (meta nebo index)
        $file = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_FILE, true);
        if ($file === '') {
            $it = self::find_by_vote($vote_post_id);
            $file = (string)($it['file'] ?? '');
        }
        $file = basename($file);

        if ($file === '') return ['ok' => false, 'error' => 'archive_missing'];

        $path = self::archive_dir() . '/' . $file;
        if (!file_exists($path)) return ['ok' => false, 'error' => 'archive_file_missing'];

        // Bezpečnost: ověření integrity archivu před mazáním z DB
        $expected_sha = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_SHA256, true);
        if ($expected_sha === '') {
            $it = self::find_by_file($file);
            $expected_sha = (string)($it['sha256'] ?? '');
        }
        $actual_sha = hash_file('sha256', $path);
        if ($expected_sha !== '' && !hash_equals($expected_sha, $actual_sha)) {
            return ['ok' => false, 'error' => 'archive_sha_mismatch'];
        }

        // Mazání řádků z tabulek
        global $wpdb;

        $deleted_votes = 0;
        $deleted_mail  = 0;
        $deleted_audit = 0;

        if (class_exists('Spolek_Hlasovani_MVP') && defined('Spolek_Hlasovani_MVP::TABLE')) {
            $t_votes = $wpdb->prefix . Spolek_Hlasovani_MVP::TABLE;
            $deleted_votes = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_votes WHERE vote_post_id=%d", $vote_post_id));
        }

        if (class_exists('Spolek_Mailer') && method_exists('Spolek_Mailer', 'table_name')) {
            $t_mail = Spolek_Mailer::table_name();
            $deleted_mail = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_mail WHERE vote_post_id=%d", $vote_post_id));
        }

        if (class_exists('Spolek_Audit') && defined('Spolek_Audit::TABLE_AUDIT')) {
            $t_audit = $wpdb->prefix . Spolek_Audit::TABLE_AUDIT;
            $deleted_audit = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_audit WHERE vote_post_id=%d", $vote_post_id));
        }

        // Smazat původní PDF (už je v ZIPu)
        $pdf_path = (string) get_post_meta($vote_post_id, '_spolek_pdf_path', true);
        if ($pdf_path !== '' && file_exists($pdf_path)) {
            @unlink($pdf_path);
        }

        // Vyčistit cron eventy
        if (class_exists('Spolek_Cron') && method_exists('Spolek_Cron', 'clear_vote_events')) {
            Spolek_Cron::clear_vote_events($vote_post_id);
        }

        // Nakonec smazat CPT post (a tím i meta)
        wp_delete_post($vote_post_id, true);

        // Index: označit purged_at
        self::mark_purged_in_index($vote_post_id);

        return [
            'ok' => true,
            'file' => $file,
            'deleted_votes' => $deleted_votes,
            'deleted_mail'  => $deleted_mail,
            'deleted_audit' => $deleted_audit,
        ];
    }

    // ===== internals =====

    private static function archive_dir(): string {
        $up = wp_upload_dir();
        $base = rtrim((string)($up['basedir'] ?? ''), '/');
        if ($base === '') {
            // fallback (nemělo by nastat)
            $base = WP_CONTENT_DIR . '/uploads';
        }
        return $base . '/' . self::DIR_SLUG;
    }

    private static function index_path(): string {
        return self::archive_dir() . '/' . self::INDEX_FILE;
    }

    private static function read_index(): array {
        self::ensure_storage();
        $p = self::index_path();
        if (!file_exists($p)) {
            return ['version' => 1, 'updated_at' => time(), 'items' => []];
        }
        $raw = (string) @file_get_contents($p);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['version' => 1, 'updated_at' => time(), 'items' => []];
        }
        if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
        return $data;
    }

    private static function write_index(array $data): void {
        $dir = self::archive_dir();
        if (!is_dir($dir)) wp_mkdir_p($dir);

        $data['updated_at'] = time();

        $path = self::index_path();
        $tmp = $path . '.tmp';

        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $path);
    }

    private static function upsert_index_item(array $item): void {
        $data = self::read_index();
        $items = (array)($data['items'] ?? []);
        $found = false;

        foreach ($items as $k => $it) {
            if ((int)($it['vote_post_id'] ?? 0) === (int)($item['vote_post_id'] ?? 0)) {
                $items[$k] = array_merge($it, $item);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $items[] = $item;
        }

        $data['items'] = array_values($items);
        self::write_index($data);
    }

    private static function mark_purged_in_index(int $vote_post_id): void {
        $vote_post_id = (int)$vote_post_id;
        $data = self::read_index();
        $items = (array)($data['items'] ?? []);
        $now = time();

        foreach ($items as $k => $it) {
            if ((int)($it['vote_post_id'] ?? 0) === $vote_post_id) {
                $items[$k]['purged_at'] = $now;
                break;
            }
        }

        $data['items'] = array_values($items);
        self::write_index($data);
    }

    private static function counts_map(int $vote_post_id): array {
        global $wpdb;
        $map = ['ANO' => 0, 'NE' => 0, 'ZDRZEL' => 0];

        if (!(class_exists('Spolek_Hlasovani_MVP') && defined('Spolek_Hlasovani_MVP::TABLE'))) {
            return $map;
        }

        $table = $wpdb->prefix . Spolek_Hlasovani_MVP::TABLE;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT choice, COUNT(*) as c FROM $table WHERE vote_post_id=%d GROUP BY choice",
            $vote_post_id
        ), ARRAY_A);

        foreach ((array)$rows as $r) {
            $ch = (string)($r['choice'] ?? '');
            if (isset($map[$ch])) $map[$ch] = (int)($r['c'] ?? 0);
        }

        return $map;
    }

    private static function build_votes_csv(int $vote_post_id): string {
        global $wpdb;

        $lines = fopen('php://temp', 'r+');
        fputcsv($lines, ['user_id','user_email','display_name','choice','cast_at'], ';');

        if (class_exists('Spolek_Hlasovani_MVP') && defined('Spolek_Hlasovani_MVP::TABLE')) {
            $table = $wpdb->prefix . Spolek_Hlasovani_MVP::TABLE;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, choice, cast_at FROM $table WHERE vote_post_id=%d ORDER BY cast_at ASC",
                $vote_post_id
            ), ARRAY_A);

            foreach ((array)$rows as $r) {
                $uid = (int)($r['user_id'] ?? 0);
                $u = $uid ? get_user_by('id', $uid) : null;
                fputcsv($lines, [
                    $uid,
                    $u ? (string)$u->user_email : '',
                    $u ? (string)$u->display_name : '',
                    (string)($r['choice'] ?? ''),
                    (string)($r['cast_at'] ?? ''),
                ], ';');
            }
        }

        rewind($lines);
        $csv = stream_get_contents($lines);
        fclose($lines);

        return (string)$csv;
    }

    private static function build_audit_csv(int $vote_post_id): string {
        global $wpdb;

        $lines = fopen('php://temp', 'r+');
        fputcsv($lines, ['event_at','vote_post_id','user_id','event','meta'], ';');

        if (class_exists('Spolek_Audit') && defined('Spolek_Audit::TABLE_AUDIT')) {
            $table = $wpdb->prefix . Spolek_Audit::TABLE_AUDIT;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_at, vote_post_id, user_id, event, meta FROM $table WHERE vote_post_id=%d ORDER BY id ASC",
                $vote_post_id
            ), ARRAY_A);

            foreach ((array)$rows as $r) {
                fputcsv($lines, [
                    (string)($r['event_at'] ?? ''),
                    (int)($r['vote_post_id'] ?? 0),
                    (int)($r['user_id'] ?? 0),
                    (string)($r['event'] ?? ''),
                    (string)($r['meta'] ?? ''),
                ], ';');
            }
        }

        rewind($lines);
        $csv = stream_get_contents($lines);
        fclose($lines);

        return (string)$csv;
    }

    private static function build_mail_log_csv(int $vote_post_id): string {
        global $wpdb;

        $lines = fopen('php://temp', 'r+');
        fputcsv($lines, ['sent_at','vote_post_id','user_id','mail_type','status','error_text'], ';');

        if (class_exists('Spolek_Mailer') && method_exists('Spolek_Mailer', 'table_name')) {
            $table = Spolek_Mailer::table_name();

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT sent_at, vote_post_id, user_id, mail_type, status, error_text FROM $table WHERE vote_post_id=%d ORDER BY id ASC",
                $vote_post_id
            ), ARRAY_A);

            foreach ((array)$rows as $r) {
                fputcsv($lines, [
                    (string)($r['sent_at'] ?? ''),
                    (int)($r['vote_post_id'] ?? 0),
                    (int)($r['user_id'] ?? 0),
                    (string)($r['mail_type'] ?? ''),
                    (string)($r['status'] ?? ''),
                    (string)($r['error_text'] ?? ''),
                ], ';');
            }
        }

        rewind($lines);
        $csv = stream_get_contents($lines);
        fclose($lines);

        return (string)$csv;
    }
}

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

    public const DIR_SLUG = Spolek_Config::ARCHIVE_DIR_SLUG;
    public const INDEX_FILE = Spolek_Config::ARCHIVE_INDEX_FILE;

    // Post meta (u existujícího hlasování – než dojde k purge)
    public const META_ARCHIVE_FILE    = Spolek_Config::META_ARCHIVE_FILE;
    public const META_ARCHIVE_SHA256  = Spolek_Config::META_ARCHIVE_SHA256;
    public const META_ARCHIVED_AT     = Spolek_Config::META_ARCHIVED_AT;
    public const META_ARCHIVE_ERROR   = Spolek_Config::META_ARCHIVE_ERROR;
    public const META_ARCHIVE_STORAGE = Spolek_Config::META_ARCHIVE_STORAGE;

    private const STORAGE_PRIVATE        = 'private';
    private const STORAGE_UPLOADS_SECURE = 'uploads_secure';
    private const STORAGE_UPLOADS_LEGACY = 'uploads_legacy';

    /** @var bool */
    private static $bootstrapped = false;
    /** @var string|null */
    private static $root_dir = null;
    /** @var string|null */
    private static $dir_private = null;
    /** @var string|null */
    private static $dir_uploads_secure = null;
    /** @var string|null */
    private static $dir_uploads_legacy = null;

    /** Zajistí adresář + základní ochranu (best-effort). */
    /** 
     * Zajistí úložiště archivů.
     *
     * Hybridní režim:
     * - preferuje PRIVATE adresář mimo webroot (nejbezpečnější, funguje na Apache i Nginx),
     * - když není dostupný (open_basedir/práva), použije uploads do "neuhodnutelné" složky,
     * - pro zpětnou kompatibilitu umí číst i staré archivy v původní uploads složce.
     *
     * Index archives.json se ukládá do PRIMARY úložiště (private / uploads_secure) a při prvním běhu
     * se do něj sloučí položky ze starých indexů.
     */
    /** 
     * Zajistí úložiště archivů.
     *
     * Hybridní režim:
     * - preferuje PRIVATE adresář mimo webroot (nejbezpečnější, funguje na Apache i Nginx),
     * - když není dostupný (open_basedir/práva), použije uploads do "neuhodnutelné" složky,
     * - pro zpětnou kompatibilitu umí číst i staré archivy v původní uploads složce.
     *
     * Index archives.json se ukládá do PRIMARY úložiště (private / uploads_secure) a při prvním běhu
     * se do něj sloučí položky ze starých indexů.
     */
    public static function ensure_storage(): void {
        if (self::$bootstrapped) return;

        // Výpočet cest
        self::$dir_uploads_legacy = self::uploads_legacy_dir();
        self::$dir_uploads_secure = self::uploads_secure_dir();
        self::$dir_private        = self::private_dir();

        // Public dirs (uploads) – best effort
        $legacy_ok = self::ensure_dir(self::$dir_uploads_legacy, true, false);
        $secure_ok = self::ensure_dir(self::$dir_uploads_secure, true, true);

        // Private dir – vyžadujeme write test
        $private_ok = self::ensure_dir(self::$dir_private, false, true);

        // PRIMARY úložiště (fallback: uploads_secure -> uploads_legacy)
        if ($private_ok) {
            self::$root_dir = self::$dir_private;
        } elseif ($secure_ok) {
            self::$root_dir = self::$dir_uploads_secure;
        } else {
            self::$root_dir = self::$dir_uploads_legacy;
        }

        // Označit bootstrap hotový dřív, než začneme číst index (aby nedošlo k rekurzi)
        self::$bootstrapped = true;

        // Primary index
        self::ensure_index((string)self::$root_dir);

        // Sloučit staré indexy (zpětná kompatibilita)
        self::merge_index_from_dir(self::$dir_uploads_legacy, self::STORAGE_UPLOADS_LEGACY);
        self::merge_index_from_dir(self::$dir_uploads_secure, self::STORAGE_UPLOADS_SECURE);
        self::merge_index_from_dir(self::$dir_private, self::STORAGE_PRIVATE);
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

    /** Je položka indexu označená jako skrytá? */
    public static function is_hidden_item(array $it): bool {
        return !empty($it['hidden']) || !empty($it['hidden_at']);
    }

    /**
     * 6.4.4 – automatické čištění indexu (označit/skrýt záznamy, kde ZIP reálně zmizel a hlasování už je po purge).
     * @return array{hidden_new:int,unhidden:int,changed:int}
     */
    public static function cleanup_index(bool $force = false): array {
        self::ensure_storage();

        // Rate-limit: i když to někdo kliká opakovaně, index nebudeme přepisovat každou sekundu.
        if (!$force) {
            $key = 'spolek_arch_index_cleanup';
            if (get_transient($key)) {
                return ['hidden_new' => 0, 'unhidden' => 0, 'changed' => 0];
            }
            set_transient($key, 1, 6 * HOUR_IN_SECONDS);
        }

        $data = self::read_index();
        $items = (array)($data['items'] ?? []);
        if (!$items) return ['hidden_new' => 0, 'unhidden' => 0, 'changed' => 0];

        $now = time();
        $hidden_new = 0;
        $unhidden = 0;
        $changed = 0;

        foreach ($items as $k => $it) {
            if (!is_array($it)) continue;

            $vote_id = (int)($it['vote_post_id'] ?? 0);
            $file = basename((string)($it['file'] ?? ''));
            $storage = !empty($it['storage']) ? (string)$it['storage'] : null;

            $loc = ($file !== '') ? self::locate($file, $storage) : null;
            $file_exists = ($loc && !empty($loc['path']) && is_file((string)$loc['path']));

            $post_exists = true;
            if ($vote_id > 0) {
                $post_exists = (bool) get_post($vote_id);
            }

            $purged = !empty($it['purged_at']);
            $hidden = self::is_hidden_item($it);

            $should_hide = (!$file_exists) && ($purged || !$post_exists);

            if ($should_hide && !$hidden) {
                $items[$k]['hidden'] = 1;
                $items[$k]['hidden_at'] = $now;
                $items[$k]['hidden_reason'] = $purged ? 'missing_after_purge' : 'missing_no_post';
                $hidden_new++;
                $changed++;
            }

            // Pokud byl soubor obnoven, záznam znovu odskryjeme.
            if ($file_exists && $hidden) {
                $reason = (string)($it['hidden_reason'] ?? '');
                if ($reason === 'missing_after_purge' || $reason === 'missing_no_post' || $reason === '') {
                    unset($items[$k]['hidden'], $items[$k]['hidden_at'], $items[$k]['hidden_reason']);
                    $unhidden++;
                    $changed++;
                }
            }
        }

        if ($changed > 0) {
            $data['version'] = 2;
            $data['items'] = array_values($items);
            self::write_index($data);

            if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
                $uid = is_user_logged_in() ? (int) get_current_user_id() : null;
                Spolek_Audit::log(null, $uid ?: null, Spolek_Audit_Events::ARCHIVE_INDEX_CLEANUP, [
                    'hidden_new' => $hidden_new,
                    'unhidden'   => $unhidden,
                ]);
            }
        }

        return ['hidden_new' => $hidden_new, 'unhidden' => $unhidden, 'changed' => $changed];
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
     * Najde fyzickou cestu k archivu (zpětně kompatibilní – hledá ve všech úložištích).
     * @return array{path:string,storage:string}|null
     */
    public static function locate(string $file, ?string $storage_hint = null): ?array {
        self::ensure_storage();

        $file = basename((string)$file);
        if ($file === '' || strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
            return null;
        }

        $candidates = [];

        // 1) explicitní hint (meta/index)
        if ($storage_hint) {
            $candidates[] = (string)$storage_hint;
        }

        // 2) index (pokud existuje)
        if (!$storage_hint) {
            $it = self::find_by_file($file);
            if ($it && !empty($it['storage'])) {
                $candidates[] = (string)$it['storage'];
            }
        }

        // 3) fallback pořadí
        foreach ([self::STORAGE_PRIVATE, self::STORAGE_UPLOADS_SECURE, self::STORAGE_UPLOADS_LEGACY] as $st) {
            if (!in_array($st, $candidates, true)) $candidates[] = $st;
        }

        foreach ($candidates as $st) {
            $dir = self::dir_for_storage($st);
            if ($dir === '') continue;
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                return ['path' => $path, 'storage' => $st];
            }
        }

        return null;
    }

    /** Vrátí jen cestu (helper pro UI). */
    public static function locate_path(string $file, ?string $storage_hint = null): ?string {
        $loc = self::locate($file, $storage_hint);
        return $loc ? (string)$loc['path'] : null;
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
            $storage_hint = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_STORAGE, true);
            $loc = self::locate($existing_file, $storage_hint ?: null);
            if ($loc && file_exists($loc['path'])) {
                $sha = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_SHA256, true);
                return ['ok' => true, 'file' => basename($existing_file), 'path' => (string)$loc['path'], 'sha256' => $sha, 'already' => true];
            }
        }

        // Základní meta hlasování
        [$start_ts, $end_ts, $text] = class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::get_vote_meta($vote_post_id)
            : [(int) get_post_meta($vote_post_id, Spolek_Config::META_START_TS, true), (int) get_post_meta($vote_post_id, Spolek_Config::META_END_TS, true), (string) get_post_meta($vote_post_id, Spolek_Config::META_TEXT, true)];

        // Počty hlasů
        $counts = self::counts_map($vote_post_id);

        // Vyhodnocení
        $eval = class_exists('Spolek_Vote_Service')
            ? Spolek_Vote_Service::evaluate_vote($vote_post_id, $counts)
            : null;

        // Název souboru
        $slug = sanitize_title($post->post_title);
        if ($slug === '') $slug = 'hlasovani';
        $slug = substr($slug, 0, 50);

        $date = $end_ts ? wp_date('Ymd', $end_ts, wp_timezone()) : wp_date('Ymd', time(), wp_timezone());
        $token = function_exists('wp_generate_password') ? wp_generate_password(24, false, false) : (string) wp_rand(100000, 999999);

        $file = "vote-{$vote_post_id}-{$slug}-{$date}-{$token}.zip";
        $storage = self::primary_storage();
        $dir = self::dir_for_storage($storage);
        $path = rtrim($dir, '/') . '/' . $file;

        if (!class_exists('ZipArchive')) {
            $err = 'ZipArchive not available on server';
            update_post_meta($vote_post_id, self::META_ARCHIVE_ERROR, $err);
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_FAILED, ['error' => $err]);
            return ['ok' => false, 'error' => $err];
        }

        $zip = new ZipArchive();
        $ok = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($ok !== true) {
            $err = 'cannot_create_zip';
            update_post_meta($vote_post_id, self::META_ARCHIVE_ERROR, $err);
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_FAILED, ['error' => $err, 'zip_code' => $ok]);
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
        $pdf_path = (string) get_post_meta($vote_post_id, Spolek_Config::META_PDF_PATH, true);
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
            'processed_at'=> (int) get_post_meta($vote_post_id, Spolek_Config::META_CLOSE_PROCESSED_AT, true),
            'counts'      => $counts,
            'evaluation'  => $eval,
            'rules'       => [
                'ruleset'      => (string) get_post_meta($vote_post_id, Spolek_Config::META_RULESET, true),
                'quorum_ratio' => (float) get_post_meta($vote_post_id, Spolek_Config::META_QUORUM_RATIO, true),
                'pass_ratio'   => (float) get_post_meta($vote_post_id, Spolek_Config::META_PASS_RATIO, true),
                'base'         => (string) get_post_meta($vote_post_id, Spolek_Config::META_BASE, true),
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
            if (class_exists('Spolek_Audit')) Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_FAILED, ['error' => $err]);
            return ['ok' => false, 'error' => $err];
        }

        $sha = hash_file('sha256', $path);
        $archived_at = time();

        update_post_meta($vote_post_id, self::META_ARCHIVE_FILE, $file);
        update_post_meta($vote_post_id, self::META_ARCHIVE_STORAGE, $storage);
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
            'storage'      => $storage,
            'sha256'       => $sha,
            'bytes'        => (int) filesize($path),
        ]);

        if (class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::ARCHIVE_CREATED, [
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

        $loc = self::locate($file);
        if (!$loc || !file_exists($loc['path'])) {
            wp_die('Soubor nenalezen.');
        }
        $path = (string)$loc['path'];

        // Bezpečnost: pokud máme očekávaný SHA256 v indexu, ověřit integritu před stažením.
        $it = self::find_by_file($file);
        $expected_sha = $it ? (string)($it['sha256'] ?? '') : '';
        if ($expected_sha !== '') {
            $actual_sha = hash_file('sha256', $path);
            if (!hash_equals($expected_sha, $actual_sha)) {
                if (class_exists('Spolek_Audit')) {
                    Spolek_Audit::log(0, get_current_user_id(), Spolek_Audit_Events::ARCHIVE_SHA_MISMATCH, [
                        'file' => $file,
                    ]);
                }
                wp_die('Integrita archivu nesedí (SHA256). Stažení zablokováno.');
            }
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

        $is_cron = function_exists('wp_doing_cron') ? wp_doing_cron() : (defined('DOING_CRON') && DOING_CRON);

        $post = get_post($vote_post_id);
        if (!$post) {
            if ($is_cron && class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PURGE_AUTO_FAIL, [
                    'vote_post_id' => $vote_post_id,
                    'error' => 'vote_not_found',
                ]);
            }
            return ['ok' => false, 'error' => 'vote_not_found'];
        }

        // Musí existovat archiv (meta nebo index)
        $file = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_FILE, true);
        if ($file === '') {
            $it = self::find_by_vote($vote_post_id);
            $file = (string)($it['file'] ?? '');
        }
        $file = basename($file);

        if ($file === '') {
            if ($is_cron && class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PURGE_AUTO_FAIL, [
                    'vote_post_id' => $vote_post_id,
                    'error' => 'archive_missing',
                ]);
            }
            return ['ok' => false, 'error' => 'archive_missing'];
        }

        $storage_hint = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_STORAGE, true);
        if ($storage_hint === '') {
            $it = self::find_by_file($file);
            $storage_hint = (string)($it['storage'] ?? '');
        }
        $loc = self::locate($file, $storage_hint !== '' ? $storage_hint : null);
        if (!$loc || !file_exists($loc['path'])) {
            if ($is_cron && class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PURGE_AUTO_FAIL, [
                    'vote_post_id' => $vote_post_id,
                    'file' => $file ?: null,
                    'error' => 'archive_file_missing',
                ]);
            }
            return ['ok' => false, 'error' => 'archive_file_missing'];
        }
        $path = (string)$loc['path'];

        // Bezpečnost: ověření integrity archivu před mazáním z DB
        $expected_sha = (string) get_post_meta($vote_post_id, self::META_ARCHIVE_SHA256, true);
        if ($expected_sha === '') {
            $it = self::find_by_file($file);
            $expected_sha = (string)($it['sha256'] ?? '');
        }
        $actual_sha = hash_file('sha256', $path);
        if ($expected_sha !== '' && !hash_equals($expected_sha, $actual_sha)) {
            if ($is_cron && class_exists('Spolek_Audit')) {
                Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PURGE_AUTO_FAIL, [
                    'vote_post_id' => $vote_post_id,
                    'file' => $file ?: null,
                    'error' => 'archive_sha_mismatch',
                ]);
            }
            return ['ok' => false, 'error' => 'archive_sha_mismatch'];
        }

        // Mazání řádků z tabulek
        global $wpdb;

        $deleted_votes = 0;
        $deleted_mail  = 0;
        $deleted_audit = 0;

        $t_votes = Spolek_Config::table_votes();
        $deleted_votes = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_votes WHERE vote_post_id=%d", $vote_post_id));

        $t_mail = Spolek_Config::table_mail_log();
        $deleted_mail = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_mail WHERE vote_post_id=%d", $vote_post_id));

        $t_audit = Spolek_Config::table_audit();
        $deleted_audit = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_audit WHERE vote_post_id=%d", $vote_post_id));

        // Smazat původní PDF (už je v ZIPu)
        $pdf_path = (string) get_post_meta($vote_post_id, Spolek_Config::META_PDF_PATH, true);
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

        if ($is_cron && class_exists('Spolek_Audit')) {
            Spolek_Audit::log($vote_post_id, null, Spolek_Audit_Events::PURGE_AUTO_DONE, [
                'vote_post_id' => $vote_post_id,
                'file' => $file ?: null,
                'deleted_votes' => (int)$deleted_votes,
                'deleted_mail'  => (int)$deleted_mail,
                'deleted_audit' => (int)$deleted_audit,
            ]);
        }

        return [
            'ok' => true,
            'file' => $file,
            'deleted_votes' => $deleted_votes,
            'deleted_mail'  => $deleted_mail,
            'deleted_audit' => $deleted_audit,
        ];
    }

    
    // ===== internals (storage + index) =====

    /** Vrací PRIMARY úložiště (private pokud lze, jinak uploads_secure). */
    private static function archive_dir(): string {
        self::ensure_storage();
        return (string) self::$root_dir;
    }

    /** Vrátí absolutní cestu k indexu v PRIMARY úložišti. */
    private static function index_path(): string {
        return rtrim(self::archive_dir(), '/') . '/' . self::INDEX_FILE;
    }

    /** Primární úložiště jako klíč. */
    /** Primární úložiště jako klíč. */
    private static function primary_storage(): string {
        self::ensure_storage();
        if (self::$root_dir && self::$dir_private && self::$root_dir === self::$dir_private) return self::STORAGE_PRIVATE;
        if (self::$root_dir && self::$dir_uploads_legacy && self::$root_dir === self::$dir_uploads_legacy) return self::STORAGE_UPLOADS_LEGACY;
        return self::STORAGE_UPLOADS_SECURE;
    }


    /** Mapování storage klíče -> adresář. */
    private static function dir_for_storage(string $storage): string {
        self::ensure_storage();
        switch ($storage) {
            case self::STORAGE_PRIVATE:
                return (string) (self::$dir_private ?: '');
            case self::STORAGE_UPLOADS_SECURE:
                return (string) (self::$dir_uploads_secure ?: '');
            case self::STORAGE_UPLOADS_LEGACY:
                return (string) (self::$dir_uploads_legacy ?: '');
            default:
                return '';
        }
    }

    /** Legacy uploads (původní cesta – kvůli zpětné kompatibilitě). */
    private static function uploads_legacy_dir(): string {
        $up = wp_upload_dir();
        $base = rtrim((string)($up['basedir'] ?? ''), '/');
        if ($base === '') $base = WP_CONTENT_DIR . '/uploads';
        return $base . '/' . self::DIR_SLUG;
    }

    /** Secure uploads (neuhodnutelný adresář podle salt). */
    private static function uploads_secure_dir(): string {
        $up = wp_upload_dir();
        $base = rtrim((string)($up['basedir'] ?? ''), '/');
        if ($base === '') $base = WP_CONTENT_DIR . '/uploads';

        $suffix = self::secret_suffix();
        // sibling k legacy "archive" adresáři: spolek-hlasovani/archive-<suffix>
        $secure_slug = 'spolek-hlasovani/archive-' . $suffix;

        /** Filter: umožní upravit slug secure uploads úložiště */
        $secure_slug = (string) apply_filters('spolek_archive_uploads_secure_slug', $secure_slug);

        return $base . '/' . ltrim($secure_slug, '/');
    }

    /** Private úložiště mimo webroot (best-effort). */
    private static function private_dir(): string {
        $base = rtrim(dirname(ABSPATH), '/');
        $default = $base . '/spolek-archives/spolek-hlasovani/archive';

        /** Filter: umožní nastavit private úložiště absolutní cestou */
        $dir = (string) apply_filters('spolek_archive_private_dir', $default);

        return rtrim($dir, '/');
    }

    /** Stabilní suffix (12 hex) – nemá být veřejně uhodnutelný. */
    private static function secret_suffix(): string {
        $seed = '';
        if (function_exists('wp_salt')) {
            $seed = (string) wp_salt('auth');
        }
        if ($seed === '' && defined('AUTH_KEY')) {
            $seed = (string) AUTH_KEY;
        }
        if ($seed === '') {
            $seed = (string) (function_exists('home_url') ? home_url('/') : 'spolek');
        }
        return substr(hash('sha256', $seed . '|spolek_archive'), 0, 12);
    }

    /**
     * Zajistí existenci adresáře + (pokud public) základní ochranu.
     * @return bool true pokud adresář existuje a (volitelně) je zapisovatelný
     */
    private static function ensure_dir(string $dir, bool $public, bool $require_write): bool {
        $dir = rtrim((string)$dir, '/');
        if ($dir === '') return false;

        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return false;
            }
        }

        // základní write test (kvůli open_basedir/právům)
        if ($require_write) {
            $test = $dir . '/.spolek_write_test';
            $ok = @file_put_contents($test, '1', LOCK_EX);
            if ($ok === false) {
                @unlink($test);
                return false;
            }
            @unlink($test);
        }

        // index.html aby se v adresáři nic nelistovalo
        $index_html = $dir . '/index.html';
        if (!file_exists($index_html)) {
            @file_put_contents($index_html, "<!doctype html><meta charset=\"utf-8\"><title>403</title>", LOCK_EX);
        }

        if ($public) {
            // .htaccess (Apache) – best effort
            $ht = $dir . '/.htaccess';
            if (!file_exists($ht)) {
                $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                       . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
                       . "Options -Indexes\n";
                @file_put_contents($ht, $rules, LOCK_EX);
            }

            // web.config (IIS) – best effort
            $wc = $dir . '/web.config';
            if (!file_exists($wc)) {
                $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                     . "<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";
                @file_put_contents($wc, $xml, LOCK_EX);
            }
        }

        return true;
    }

    /** Vytvoří index, pokud neexistuje. */
    private static function ensure_index(string $dir): void {
        $idx = rtrim($dir, '/') . '/' . self::INDEX_FILE;
        if (!file_exists($idx)) {
            self::write_index_to($idx, [
                'version'    => 2,
                'updated_at' => time(),
                'items'      => [],
            ]);
        }
    }

    /** Sloučí index z jiného úložiště do primárního indexu. */
    private static function merge_index_from_dir(string $src_dir, string $default_storage): void {
        $src_dir = rtrim((string)$src_dir, '/');
        if ($src_dir === '' || $src_dir === rtrim((string)self::$root_dir, '/')) return;

        $src_path = $src_dir . '/' . self::INDEX_FILE;
        if (!file_exists($src_path)) return;

        $src_data = self::read_index_file($src_path);
        $src_items = (array)($src_data['items'] ?? []);
        if (!$src_items) return;

        $root = self::read_index();
        $root_items = (array)($root['items'] ?? []);

        // Lookup pro rychlé sloučení
        $by_vote = [];
        $by_file = [];
        foreach ($root_items as $k => $it) {
            $vid = (int)($it['vote_post_id'] ?? 0);
            $fil = (string)($it['file'] ?? '');
            if ($vid) $by_vote[$vid] = $k;
            if ($fil !== '') $by_file[$fil] = $k;
        }

        foreach ($src_items as $it) {
            if (!is_array($it)) continue;
            $file = basename((string)($it['file'] ?? ''));
            if ($file === '') continue;

            $it['file'] = $file;

            // doplnit storage pokud chybí
            if (empty($it['storage'])) {
                $it['storage'] = $default_storage;
            }

            // doplnit bytes, když chybí
            if (empty($it['bytes'])) {
                $p = $src_dir . '/' . $file;
                if (is_file($p)) $it['bytes'] = (int) filesize($p);
            }

            $vote_id = (int)($it['vote_post_id'] ?? 0);

            if ($vote_id && isset($by_vote[$vote_id])) {
                $root_items[$by_vote[$vote_id]] = array_merge((array)$root_items[$by_vote[$vote_id]], $it);
            } elseif (isset($by_file[$file])) {
                $root_items[$by_file[$file]] = array_merge((array)$root_items[$by_file[$file]], $it);
            } else {
                $root_items[] = $it;
            }
        }

        $root['version'] = 2;
        $root['items'] = array_values($root_items);
        self::write_index($root);
    }

    private static function read_index_file(string $path): array {
        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['version' => 2, 'updated_at' => time(), 'items' => []];
        if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
        return $data;
    }

    private static function write_index_to(string $path, array $data): void {
        $data['updated_at'] = time();

        $tmp = $path . '.tmp';
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        @file_put_contents($tmp, (string)$json, LOCK_EX);
        @rename($tmp, $path);
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
        self::ensure_storage();
        $path = self::index_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::write_index_to($path, $data);
    }


    private static function upsert_index_item(array $item): void {
        if (empty($item['storage'])) {
            $item['storage'] = self::primary_storage();
        }
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

        $table = Spolek_Config::table_votes();
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

        $table = Spolek_Config::table_votes();

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
    /**
     * Diagnostika úložiště archivů (pro portál správce).
     * Vrací primární režim, cestu a stav jednotlivých variant.
     */
    public static function storage_status(): array {
        self::ensure_storage();

        $primary = self::primary_storage();
        $labels = [
            self::STORAGE_PRIVATE        => 'PRIVATE (mimo webroot)',
            self::STORAGE_UPLOADS_SECURE => 'UPLOADS_SECURE (uploads, neuhodnutelná složka)',
            self::STORAGE_UPLOADS_LEGACY => 'UPLOADS_LEGACY (původní uploads cesta)',
        ];

        $checks = [];
        foreach ([self::STORAGE_PRIVATE, self::STORAGE_UPLOADS_SECURE, self::STORAGE_UPLOADS_LEGACY] as $k) {
            $dir = self::dir_for_storage($k);
            $checks[$k] = [
                'dir'      => (string)$dir,
                'exists'   => ($dir && is_dir($dir)),
                'writable' => ($dir && is_dir($dir) && is_writable($dir)),
                'label'    => ($labels[$k] ?? $k),
            ];
        }

        return [
            'primary'       => $primary,
            'primary_label' => ($labels[$primary] ?? $primary),
            'root_dir'      => (string)(self::$root_dir ?: ''),
            'checks'        => $checks,
        ];
    }

    /**
     * Otestuje zápis do úložiště (default: primární).
     * @return array{ok:bool,storage:string,dir:string,error?:string}
     */
    public static function test_write(string $storage = ''): array {
        self::ensure_storage();

        $storage = $storage ?: self::primary_storage();
        $dir = self::dir_for_storage($storage);

        if (!$dir || !is_dir($dir)) {
            return ['ok' => false, 'storage' => $storage, 'dir' => (string)$dir, 'error' => 'Adresář neexistuje.'];
        }

        $name = '.spolek-write-test-' . time() . '-' . wp_generate_password(10, false, false) . '.tmp';
        $tmp  = rtrim($dir, '/') . '/' . $name;

        $bytes = @file_put_contents($tmp, 'spolek write test ' . time());
        if ($bytes === false) {
            return ['ok' => false, 'storage' => $storage, 'dir' => (string)$dir, 'error' => 'Nepodařilo se zapsat testovací soubor.'];
        }

        @unlink($tmp);
        if (file_exists($tmp)) {
            return ['ok' => false, 'storage' => $storage, 'dir' => (string)$dir, 'error' => 'Zápis OK, ale testovací soubor nelze smazat.'];
        }

        return ['ok' => true, 'storage' => $storage, 'dir' => (string)$dir];
    }

}

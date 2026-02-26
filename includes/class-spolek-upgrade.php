<?php
if (!defined('ABSPATH')) exit;

/**
 * 6.5.2 – DB migrace / upgrade rutina
 *
 * Cíl:
 * - při update pluginu (bez deaktivace/aktivace) proběhne dbDelta na tabulkách
 * - zůstane zpětně kompatibilní (dbDelta doplní chybějící sloupce/indexy)
 * - minimalizace rizika souběhu (lock přes wp_options)
 */
final class Spolek_Upgrade {

    private const LOCK_KEY = 'spolek_db_upgrade_lock';

    /** Spusť upgrade, pokud je potřeba (nebo force). */
    public static function maybe_upgrade(bool $force = false): void {
        if (!class_exists('Spolek_Config')) return;

        $target = (int) Spolek_Config::DB_VERSION;
        $current = (int) get_option(Spolek_Config::OPT_DB_VERSION, 0);

        // plugin version si držíme informativně
        if (defined('SPOLEK_HLASOVANI_VERSION')) {
            update_option(Spolek_Config::OPT_PLUGIN_VERSION, (string) SPOLEK_HLASOVANI_VERSION, false);
        }

        if (!$force && $current >= $target) {
            return;
        }

        $token = self::acquire_lock(300);
        if (!$token) {
            // best-effort: nechceme zabít request
            return;
        }

        try {
            // dbDelta na tabulkách
            if (class_exists('Spolek_Votes')) {
                Spolek_Votes::install_table();
            }
            if (class_exists('Spolek_Mailer')) {
                Spolek_Mailer::install_table();
            }
            if (class_exists('Spolek_Audit')) {
                Spolek_Audit::install_table();
            }

            // Audit log až po vytvoření audit tabulky
            if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
                Spolek_Audit::log(null, null, Spolek_Audit_Events::UPGRADE_DB_START, [
                    'from' => $current,
                    'to'   => $target,
                ]);
            }

            // úložiště archivů
            if (class_exists('Spolek_Archive') && method_exists('Spolek_Archive', 'ensure_storage')) {
                Spolek_Archive::ensure_storage();
            }

            // capabilities
            if ($admin = get_role('administrator')) {
                $admin->add_cap(Spolek_Config::CAP_MANAGE);
            }
            if ($mgr = get_role('spravce_hlasovani')) {
                $mgr->add_cap(Spolek_Config::CAP_MANAGE);
            }

            update_option(Spolek_Config::OPT_DB_VERSION, (string)$target, false);

            if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
                Spolek_Audit::log(null, null, Spolek_Audit_Events::UPGRADE_DB_DONE, [
                    'db_version' => $target,
                ]);
            }

        } catch (Throwable $e) {
            $msg = substr((string)$e->getMessage(), 0, 300);
            if (class_exists('Spolek_Audit') && class_exists('Spolek_Audit_Events')) {
                Spolek_Audit::log(null, null, Spolek_Audit_Events::UPGRADE_DB_FAIL, [
                    'error' => $msg,
                ]);
            }
        } finally {
            self::release_lock($token);
        }
    }

    // ===== lock (wp_options) =====

    private static function acquire_lock(int $ttl = 300): ?string {
        $token = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : (string) wp_rand(100000, 999999) . '-' . microtime(true);

        $exp = time() + max(30, (int)$ttl);
        $value = $exp . '|' . $token;

        if (add_option(self::LOCK_KEY, $value, '', 'no')) {
            return $token;
        }

        $existing = (string) get_option(self::LOCK_KEY, '');
        if ($existing !== '') {
            $parts = explode('|', $existing, 2);
            $existing_exp = (int)($parts[0] ?? 0);
            if ($existing_exp > 0 && $existing_exp < time()) {
                delete_option(self::LOCK_KEY);
                if (add_option(self::LOCK_KEY, $value, '', 'no')) {
                    return $token;
                }
            }
        }

        return null;
    }

    private static function release_lock(string $token): void {
        $existing = (string) get_option(self::LOCK_KEY, '');
        if ($existing === '') return;
        $parts = explode('|', $existing, 2);
        $existing_token = (string)($parts[1] ?? '');
        if ($existing_token !== '' && hash_equals($existing_token, $token)) {
            delete_option(self::LOCK_KEY);
        }
    }
}

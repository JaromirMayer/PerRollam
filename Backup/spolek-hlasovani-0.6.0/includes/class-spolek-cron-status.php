<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Cron_Status
 *
 * Malý helper pro „cron status“ diagnostiku.
 *
 * Ukládá do wp_options:
 * - spolek_cron_last_<hook>
 * - spolek_cron_last_ok_<hook>
 * - spolek_cron_last_error_<hook>
 * - spolek_cron_last_error_msg_<hook>
 */
final class Spolek_Cron_Status {

    private const PREFIX = 'spolek_cron_';

    private static function suffix(string $hook): string {
        $hook = strtolower((string)$hook);
        // wp option name je ASCII + podtržítka; hooky už jsou safe, ale jistota.
        $hook = preg_replace('~[^a-z0-9_\-]~', '_', $hook);
        return (string)$hook;
    }

    private static function key(string $base, string $hook): string {
        return self::PREFIX . $base . '_' . self::suffix($hook);
    }

    /**
     * Označí běh hooku.
     *
     * @param string $hook Hook slug (např. spolek_close_scan)
     * @param bool $ok true = OK běh (bez fatální chyby)
     * @param string|null $error krátká hláška (max 300 znaků)
     */
    public static function touch(string $hook, bool $ok = true, ?string $error = null): void {
        $now = time();
        update_option(self::key('last', $hook), (string)$now, false);

        if ($ok) {
            update_option(self::key('last_ok', $hook), (string)$now, false);
            // pokud byl dřív error, necháváme ho jako historii
            return;
        }

        update_option(self::key('last_error', $hook), (string)$now, false);
        if ($error !== null && $error !== '') {
            $msg = substr((string)$error, 0, 300);
            update_option(self::key('last_error_msg', $hook), $msg, false);
        }
    }

    /** @return array{last:int,last_ok:int,last_error:int,error_msg:string} */
    public static function get(string $hook): array {
        $last      = (int) get_option(self::key('last', $hook), '0');
        $last_ok   = (int) get_option(self::key('last_ok', $hook), '0');
        $last_err  = (int) get_option(self::key('last_error', $hook), '0');
        $err_msg   = (string) get_option(self::key('last_error_msg', $hook), '');
        return ['last' => $last, 'last_ok' => $last_ok, 'last_error' => $last_err, 'error_msg' => $err_msg];
    }
}

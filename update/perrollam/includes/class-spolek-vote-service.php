<?php
if (!defined('ABSPATH')) exit;

/**
 * 5.4 – Vote service (vytažení business helperů z legacy)
 *
 * Cíl: ostatní třídy už nemají volat Spolek_Hlasovani_MVP kvůli metám/members/rules.
 * Legacy zůstává jen jako kompatibilní obálka.
 */
final class Spolek_Vote_Service {

    /** @var WP_User[]|null */
    private static $members_cache = null;

    /** Vrátí veřejný token hlasování (bezpečný pro URL), nebo prázdný string. */
    public static function public_id(int $vote_post_id): string {
        return (string) get_post_meta($vote_post_id, Spolek_Config::META_PUBLIC_ID, true);
    }

    /** Zajistí, že hlasování má veřejný token – vrací token. */
    public static function ensure_public_id(int $vote_post_id): string {
        $vote_post_id = (int)$vote_post_id;
        if ($vote_post_id <= 0) return '';

        $pid = self::public_id($vote_post_id);
        if ($pid !== '') return $pid;

        try {
            $pid = bin2hex(random_bytes(12)); // 24 hex
        } catch (Throwable $e) {
            // fallback (horší, ale stále unikátní)
            $pid = wp_hash($vote_post_id . '|' . microtime(true));
            $pid = preg_replace('/[^a-f0-9]/', '', strtolower((string)$pid));
            $pid = substr($pid ?: md5((string)$vote_post_id), 0, 24);
        }

        update_post_meta($vote_post_id, Spolek_Config::META_PUBLIC_ID, $pid);
        return $pid;
    }

    /** Najde vote_post_id podle veřejného tokenu. Vrátí 0 když nenalezeno. */
    public static function resolve_public_id(string $public_id): int {
        $public_id = trim((string)$public_id);
        if ($public_id === '' || strlen($public_id) < 12) return 0;
        if (!preg_match('/^[a-f0-9]{12,64}$/i', $public_id)) return 0;

        $q = new WP_Query([
            'post_type'      => Spolek_Config::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => Spolek_Config::META_PUBLIC_ID,
                    'value' => $public_id,
                ],
            ],
        ]);

        $ids = (array)($q->posts ?? []);
        return !empty($ids[0]) ? (int)$ids[0] : 0;
    }

    /** @return array{0:int,1:int,2:string} [start_ts,end_ts,text] */
    public static function get_vote_meta(int $post_id): array {
        $start_ts = (int) get_post_meta($post_id, Spolek_Config::META_START_TS, true);
        $end_ts   = (int) get_post_meta($post_id, Spolek_Config::META_END_TS, true);
        $text     = (string) get_post_meta($post_id, Spolek_Config::META_TEXT, true);
        return [$start_ts, $end_ts, $text];
    }

    /** upcoming | open | closed */
    public static function get_status(int $start_ts, int $end_ts): string {
        $now = time();
        if ($now < $start_ts) return 'upcoming';
        if ($now < $end_ts) return 'open';
        return 'closed';
    }

    /**
     * @return string[]
     */
    public static function member_roles(): array {
        $roles = apply_filters('spolek_member_roles', ['clen', 'spravce_hlasovani']);
        if (!is_array($roles) || empty($roles)) {
            $roles = ['clen', 'spravce_hlasovani'];
        }
        $out = [];
        foreach ($roles as $r) {
            $r = trim((string)$r);
            if ($r !== '') $out[] = $r;
        }
        $out = array_values(array_unique($out));
        return $out ?: ['clen', 'spravce_hlasovani'];
    }

    /**
     * @return WP_User[]
     */
    public static function get_members(): array {
        if (self::$members_cache !== null) {
            return self::$members_cache;
        }

        $limit = (int) apply_filters('spolek_members_limit', 200);
        if ($limit <= 0) $limit = 200;

        self::$members_cache = (array) get_users([
            'role__in' => self::member_roles(),
            'number'   => $limit,
        ]);

        return self::$members_cache;
    }

    /**
     * Evaluate vote result.
     *
     * @param array $counts ['ANO'=>int,'NE'=>int,'ZDRZEL'=>int]
     */
    public static function evaluate_vote(int $vote_post_id, array $counts): array {
        $members_total = count(self::get_members());

        $yes = (int)($counts['ANO'] ?? 0);
        $no  = (int)($counts['NE'] ?? 0);
        $abs = (int)($counts['ZDRZEL'] ?? 0);

        $participated = $yes + $no + $abs;
        $valid_votes  = $yes + $no;

        $ruleset = (string) get_post_meta($vote_post_id, Spolek_Config::META_RULESET, true);
        if (!$ruleset) $ruleset = 'standard';

        $quorum_ratio = (float) get_post_meta($vote_post_id, Spolek_Config::META_QUORUM_RATIO, true);
        $pass_ratio   = (float) get_post_meta($vote_post_id, Spolek_Config::META_PASS_RATIO, true);
        $base         = (string) get_post_meta($vote_post_id, Spolek_Config::META_BASE, true);

        if ($ruleset === 'standard') {
            if ($quorum_ratio <= 0) $quorum_ratio = 0.0;
            if ($pass_ratio <= 0)   $pass_ratio   = 0.5;
            if (!$base)             $base         = 'valid';
        } elseif ($ruleset === 'two_thirds') {
            if ($quorum_ratio <= 0) $quorum_ratio = 0.5;
            if ($pass_ratio <= 0)   $pass_ratio   = 2/3;
            if (!$base)             $base         = 'valid';
        } else {
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

    /**
     * URL stránky portálu (default), kde je shortcode [spolek_hlasovani_portal].
     * Lze přepsat filtrem.
     */
    public static function portal_base_url(): string {
        $default = home_url('/clenove/hlasovani/');
        $url = (string) apply_filters('spolek_vote_portal_base_url', $default);
        return $url ?: $default;
    }

    public static function vote_detail_url(int $vote_post_id): string {
        $vote_post_id = (int)$vote_post_id;
        // Preferujeme veřejný token (proti enumeration). Fallback na původní parametr.
        $pid = self::ensure_public_id($vote_post_id);
        if ($pid !== '') {
            return add_query_arg('v', $pid, self::portal_base_url());
        }
        return add_query_arg('spolek_vote', $vote_post_id, self::portal_base_url());
    }
}

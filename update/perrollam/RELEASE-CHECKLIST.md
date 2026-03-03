# Release checklist – Spolek: Hlasování per rollam

Tento checklist je pro „update bez strachu“.

## 1) Před vydáním
1. **Bump verze**
   - `spolek-hlasovani.php`:
     - `Version: X.Y.Z` v hlavičce
     - `SPOLEK_HLASOVANI_VERSION`
2. **Changelog**
   - doplň `CHANGELOG.md` (co je nové, případné breaking změny)
3. **Kontrola DB migrací**
   - pokud se mění DB schéma: zvyš `Spolek_Config::DB_VERSION`
   - ujisti se, že `Spolek_Upgrade::maybe_upgrade()` umí dbDelta doplnit sloupce/indexy
4. **Záloha DB (minimálně tabulky pluginu)**
   - `wp_spolek_votes`
   - `wp_spolek_vote_mail_log`
   - `wp_spolek_vote_audit`
   - plus `wp_posts/wp_postmeta` pro CPT `spolek_hlasovani`

## 2) Staging test (doporučeno)
1. Vytvoř hlasování (např. deadline +15 min)
2. Přihlas se jako člen a odhlasuj
3. V Nástrojích spusť:
   - **Integrační testy** (PDF + mail_log + uzávěrka)
4. Ověř životní cyklus:
   - uzávěrka proběhne (cron / self-heal)
   - vznikne PDF + ZIP archiv
   - ZIP jde stáhnout
   - po retenci jde spustit purge (a DB se smaže jen když ZIP existuje + sedí SHA)

## 3) Produkce: bezpečný update
1. Záloha DB + (volitelně) `wp-content/uploads/spolek-hlasovani/`.
2. Update pluginu (nahrát ZIP).
3. Po update:
   - otevři portál jako správce → **Nástroje → Healthcheck**
   - spusť **Integrační testy**
   - zkontroluj **Cron status** (nejbližší běhy, DISABLE_WP_CRON)

## 4) Když je DISABLE_WP_CRON = true
Nastav server cron (každých 5 minut):

```
*/5 * * * * curl -fsS "https://TVUJ-WEB.TLD/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

# Changelog – Spolek: Hlasování per rollam

## 0.6.7 (2026-02-26)
- Admin UX: Archiv uzavřených hlasování – checkboxy + hromadné „Smazat vybrané z DB“.

## 0.6.6 (2026-02-26)
- Fix: Member PDF link – pro správce povolen download i když je link podepsaný pro jiného uživatele (praktické pro testování). Zároveň se loguje audit event při neplatném/bypass linku.

## 0.6.5 (2026-02-26)
- Fix: PDF odkaz v e-mailu (HTML režim) – odkazy jsou klikatelné a parametry se nerozbíjí ("&amp;" / "< >").

## 0.6.4 (2026-02-26)
- 6.5: DB upgrade rutina (dbDelta) při update pluginu bez nutnosti re-aktivace.
- 6.5: Integrační testy v sekci **Nástroje** (PDF + mail_log + uzávěrka v silent režimu).
- Test e-mailu nyní zapisuje do `mail_log.csv` (přes Spolek_Mailer).

## 0.6.3
- 6.4: Admin UX – přehled hlasování (počty, quorum/pass), jedno místo pro Nástroje, lepší hlášky.
- 6.4: Automatické čištění indexu archivů (skrytí neplatných položek).

## 0.6.2
- 6.3: Archiv UI – seznam + filtry + validace existence + volitelné ověření SHA.
- 6.3: Pravidla životního cyklu sjednocena do konfigurace.

## 0.6.1
- 6.2: Bezpečnost – anti-enumeration (public token v URL), throttling citlivých endpointů.

## 0.6.0
- 6.1: Cron status + doporučený server cron + reminder dohánění.

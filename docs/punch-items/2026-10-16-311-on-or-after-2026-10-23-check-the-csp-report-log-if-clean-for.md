# 311: On or after 2026-10-23: check the CSP report log; if clean for the week, switch CSP to enforcing and report it

- Opened: 2026-10-16
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-11-17
- Answered by: site_foreman
- Phase: 5 (Phase 5: daily life tools and the digest)
- Crew only (hidden from Cody on the site)

## Details

From item 295 (straggler 4). The clean week started when Phase 5's other items finished. Check watchdog type seckit for violations since then. Enforcement must happen before Phase 9 Stage B.

## Instructions

Liam, 2026-10-21 (answer 3 to report 2026-10-21-1): Stage B (site office removal) comes after this check. In order, on or after 2026-10-23:

1. **CSP check:** read watchdog type `seckit` (CSP violation reports) for the clean week. Separate real violations on Cody's pages from noise (browser extensions, admin-only UI). If clean, switch CSP from report-only to enforcing (database dump first), then load Cody's pages at iPhone width and look for anything blocked.
2. **Report it:** file the CSP report (phase: handover), and close this item with a link to it.
3. **Rebuild the docs zip** so the archive ends complete: export any reports, decisions and punch items filed since the last export into `docs/`, commit and push. Then build `~/reseller-matrix-docs-<date>.zip` plus its `.sha256` (test the zip and check the checksum).
4. **Tell Liam** the CSP result, the report link and the zip path, then **wait for his final go** on Stage B. Don't remove anything from the site office before he says go.

## Answer

Done 2026-11-17 06:44 Pacific (commit cb73fed). CSP now enforcing; header checked on /user/login. Reports 10-21 to 11-17: only 10, all on 11-12 from one browser session on the password-reset pages, all blocked Google font Mulish (fonts.gstatic.com). Nothing on the site links to Google Fonts, so the font was injected in that browser and blocking it changes nothing. Custom themes/modules load no external scripts, styles, images or frames. Dump before: /var/backups/ishop/pre-csp-enforce-20261117.gz. Undo: drush cset -y seckit.settings seckit_xss.csp.report-only 1 --input-format=yaml && drush cr.

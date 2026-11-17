# 713: Automatic update on 2026-11-12 did not finish (preflight: git and config must be clean)

- Opened: 2026-11-12
- Waiting on: liam, site_foreman
- Status: open
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

## Details

Log on the VM: `/var/log/ishop-update/2026-11-12_0230.log`

## Instructions

**Who:** Liam, just to know. Nothing to fix by hand. **Time:** 2 minutes.

The automatic weekly update on 2026-11-12 did not finish: it stopped before changing anything, during "preflight: git and config must be clean".

Nothing was changed; the site is as it was.

1. Check the site works: open https://ishoponamazonwaytoomuch.ohbah.com/site-office and log in if asked.
2. If it does: there's nothing more to do now. Claude reads the log at the start of the next work session, fixes the cause, and closes this item.
3. If the site does **not** work: ask Claude to look at it as soon as possible, and mention this item.

**How to answer:** "Site works", or "Site broken: (what you see)".

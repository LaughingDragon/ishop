# 60: Automatic update on 2026-10-15 did not finish (checks)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: site_foreman
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

## Details

Log on the VM: `/var/log/ishop-update/2026-10-15_1110.log`

## Instructions

**Who:** Liam, just to know. Nothing to fix by hand. **Time:** 2 minutes.

The automatic weekly update on 2026-10-15 did not finish: it failed during "checks".

The site was put back exactly as it was before the update, and it is working.

1. Check the site works: open https://ishoponamazonwaytoomuch.ohbah.com/site-office and log in if asked.
2. If it does: there's nothing more to do now. Claude reads the log at the start of the next work session, fixes the cause, and closes this item.
3. If the site does **not** work: ask Claude to look at it as soon as possible, and mention this item.

**How to answer:** "Site works", or "Site broken: (what you see)".

## Answer

Deliberate test of the rollback (ISHOP_UPDATE_FORCE_FAIL=1) while building the weekly update, 2026-10-15. Rollback worked: database restored, composer files and vendor restored, maintenance mode off, login page 200, git clean. Nothing for Liam to do.

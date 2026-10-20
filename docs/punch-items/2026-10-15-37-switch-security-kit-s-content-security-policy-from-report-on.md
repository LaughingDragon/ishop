# 37: Switch Security Kit's content security policy from report-only to enforced

- Opened: 2026-10-15
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Waiting for the logged violations to be reviewed and cleared.

## Instructions

**Who:** Claude. No action for Liam or Cody.

Security Kit's content security policy (CSP) only *reports* problems at the moment, logging what it would have blocked. Once the log is clean, Claude switches it to *enforce*, so the browser actually blocks unexpected scripts.

Claude's steps:

1. Review the CSP reports: `drush watchdog:show --type=seckit --count=200`.
2. For each one, decide whether it's legitimate (for example a Drupal or Gin asset), then allow it in the policy, or confirm it's junk.
3. Turn off report-only (Security Kit, CSP, "Report Only"), export config, commit.
4. Test while logged in: the admin pages, the site office, and the answer form on a phone-width screen. Check the browser console for blocked items.
5. Close this item with the result, and note it in that session's build report.

## Answer

Not yet (Liam, 2026-10-15). 1) Prove reporting works first: done the same day, a deliberate img-src violation loaded in a real browser was posted to /report-csp-violation and logged (watchdog type seckit). 2) Keep report-only through Phases 1-5, adjusting the policy as camera, QR scanning, copy buttons and charts are added. 3) Enforce after Phase 5, once a full week of normal use shows a clean log: new punch list item opened for that.

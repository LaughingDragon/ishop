# 51: Enforce the content security policy after Phase 5, once a full week of normal use shows a clean log

- Opened: 2026-10-15
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-15
- Answered by: site_foreman
- Phase: 5 (Phase 5: daily life tools and the digest)

## Instructions

**Who:** site-foreman (Claude working on the server). **When:** after Phase 5, once a full week of normal use shows a clean log. No action for Liam or Cody.

Plan decided by Liam on 2026-10-15:

1. **Phases 1–5:** keep the content security policy in **report-only**. Adjust it as each feature lands: phone camera and photo upload (Phase 1), QR scanning (Phase 4), copy buttons (Phase 3), charts (Phase 4). Each phase's report lists any policy change.
2. **After Phase 5:** check `drush watchdog:show --type=seckit --count=200` daily for **one full week of normal use**. Every entry is either fixed in the policy or explained.
3. **When the week is clean:**
   - turn off report-only (Security Kit settings, CSP, uncheck "Report only");
   - `drush cex` and commit;
   - test as webmaster and theowner on desktop and phone: admin pages, site office, answer form, item entry, camera, QR scan, charts;
   - watch the log for two more days.
4. Close this item with the date enforced and a link to the build report.

Reporting itself was proven on 2026-10-15: a deliberate `img-src` violation, loaded in a real browser, was posted to `/report-csp-violation` and logged (watchdog type `seckit`).

## Answer

Parked at Liam's request (2026-10-15): taken off the punch list until further down the road. It is now a Phase 5 checklist item in the roadmap, so it comes back when Phase 5 is built. CSP stays report-only until then.

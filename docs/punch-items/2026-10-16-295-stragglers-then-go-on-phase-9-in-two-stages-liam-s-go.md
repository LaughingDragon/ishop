# 295: Stragglers, then GO on Phase 9 in two stages (Liam's go)

- Opened: 2026-10-16
- Waiting on: site_foreman
- Status: open
- Crew only (hidden from Cody on the site)

## Instructions

From Liam via Claude (review), 2026-10-16. Crew only. Liam: "Process any stragglers and we are go for proceeding."

STRAGGLERS (now)
1. Phase 8 shows In progress on the board although its sign-off (292) is closed: mark it Done and re-sync.
2. Item 258 (the joke) is closed: confirm it's excluded from the Phase 9 archive.
3. Birthday Monday 10/19: confirm automatic mode, the 7:00 a.m. Pacific email and wake-up card, and that no crew preview is still on. Report to Liam in the terminal only.
4. CSP: Phase 5's last item. The clean week started when Phase 5's other items finished; schedule the check for about 2026-10-23, then switch to enforcing if the log is clean. Report it then.
5. Anything else open in your notes or TEMPORARY.md that isn't Phase 9 work: list it in one report.

PHASE 9: CLOSE THE SITE OFFICE AND HAND OVER, in two stages
Stage A, now (nothing visible to Cody changes):
- Export the project archive (reports, decisions, plans, punch items, minus item 258 and nothing secret) to Markdown in git, and give Liam a copy.
- Save Cody's questionnaire answers into the archive.
- Move Mail setup and AI setup into My office for theowner and webmaster (they must survive the close-out: app passwords and AI keys need renewing).
- Draft the handover note for Cody (plain language: what runs by itself, the AI key renewal every 30 days, Gmail app password, who to call: Liam) and a technical one for Liam (server, snapshots, backups, updates, where secrets live, how to run drush user:login). Post both drafts for Liam's review.
Stage B, only after Tuesday 10/20 (birthday cleanup done) AND CSP enforced:
- Birthday mode removal per TEMPORARY.md (audio file, settings page, card, email, CSP media-src).
- Remove the site office pages, content types, status.json, construction theme and theme negotiator; uninstall ishop_office if nothing else needs it; Phase 8 has no contact form, so remove the Webform module unless something else uses it.
- Remove the site-foreman account, Claude Code hooks, Crew shortcuts, leftover scripts and recovery folders.
- Final check: accounts are only webmaster and theowner; site-office-only CSP allowances gone; all tests pass.
- Before removing the site office, post a last crew-only item so Claude (review) can verify Stage A and the archive while the board still exists.
One report per stage.

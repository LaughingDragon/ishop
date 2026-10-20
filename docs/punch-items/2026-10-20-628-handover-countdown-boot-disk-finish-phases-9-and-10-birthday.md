# 628: HANDOVER COUNTDOWN: boot disk, finish Phases 9 and 10, birthday cleanup, then close-out (Liam's go)

- Opened: 2026-10-20
- Waiting on: site_foreman
- Status: open
- Crew only (hidden from Cody on the site)

## Instructions

From Liam via Claude (review), 2026-10-20. Crew only. Liam: "Finish out phase 9 and any remaining build phases. This is the countdown to site office removal and handing over the keys." Work in this order, one report per step. Add a "Handover countdown" checklist to the status board showing these steps.

1. BOOT DISK LOW (13% free, 1.35 GB of 10 GB) - first.
- Report the 15 largest directories on / (du), before and after.
- Clean what's safe: apt cache, old kernels, journald (vacuum to ~200 MB and cap it), composer/npm/pip caches, /tmp leftovers, Cody's GitHub photo ZIP and any extracted copies once their photos are attached, the uploaded spreadsheet copy in ~ if any remains, Claude Code transcript bloat you don't need, old local backup folders on the boot disk (current backups live on the data disk).
- Move the 2 GB swap file to the data disk if it's on the boot disk (nofail, so a missing data disk never blocks boot).
- Photos and local backups must stay on the data disk; check nothing new writes photos to the boot disk.
- If after cleanup the boot disk is still under 30% free, give Liam exact console steps to grow it to 20 GB (daily snapshots exist; growing has no downtime; disks can't shrink), then grow the filesystem once he says done.
- Add an early warning: Needs you now (crew) at 25% free, not only at "low".

2. PHASE 9.
- B, marketplace inbox: preview on Cody's real emails, tune the parsers, show Liam the dry-run results (what it would have flagged, any misses), then switch it on with Cody's one-tap confirm before any Sold-here cascade.
- C, eBay Sell APIs: open a plain-steps punch item for Cody to create the free eBay developer account in his name and approve the site (OAuth consent); build orders, ship-by, returns and cases once he has. Revocable token stored like other secrets, renewal reminder, never passwords, cookies or scraping. If Cody can't do it soon, report C as "waiting on Cody" and don't let it block handover.
- Wrap-up report and sign-off item for Liam only.

3. PHASE 10: finish the last checklist item, wrap-up report, sign-off item for Liam only.

4. BIRTHDAY (it was 10/19): file the one combined birthday report for the record (what ran, email sent, anything that didn't), then remove birthday mode per TEMPORARY.md now: audio file, settings page, wake-up card, email job, CSP media-src, crew-only flags that only served it.

5. PHASE 5: CSP check on or after 10/23 as already scheduled; enforce if clean.

6. PHASE 11 (close-out), per item 295. Stage A now: archive export to Markdown (no secrets, no item 258), Cody's questionnaire answers, Mail setup and AI setup moved into My office, the two handover notes (plain one for Cody, technical one for Liam: server, snapshots, backups, updates, secrets locations, drush user:login, AI key 30-day renewal, Gmail app passwords for sending and the inbox, eBay token renewal) posted for Liam's review. Stage B only after: Phases 5, 9 and 10 signed off by Liam, CSP enforced, birthday removed. Before Stage B, post a last crew-only item so Claude (review) can verify the archive and Stage A while the board still exists. Then remove the site office, construction theme, status.json, site-foreman, hooks, Crew shortcuts, leftover scripts, Webform if unused; final check (only webmaster and theowner, tests pass); the final report goes into the archive and to Liam in the terminal.

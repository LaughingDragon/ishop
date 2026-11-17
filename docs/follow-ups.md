# Follow-ups after go-live

All three follow-ups from the 2026-10-20 handover are **done** (2026-11-17). The same list is in [ROADMAP.md](ROADMAP.md) under "After go-live: follow-ups".

## 1. Content security policy: enforcing (done 2026-11-17)

CSP ran in report-only mode from Phase 0. From 10/21 to 11/17 the only reports were 10 blocked Google font (Mulish) requests from one browser session on the password-reset pages on 11/12. The site links to no external fonts, so something in that browser injected it. Enforcing since 2026-11-17 06:44 (commit cb73fed, punch 311, report 2026-11-17-1). Checked in headless Chromium at iPhone size as theowner on 18 pages: no blocked resources, no console errors.

Undo: `drush cset -y seckit.settings seckit_xss.csp.report-only 1 --input-format=yaml && drush cr` (dump before: `/var/backups/ishop/pre-csp-enforce-20261117.gz`).

## 2. Marketplace inbox: on (done 2026-11-17)

Tuned on 30 days of real mail (151 emails). Vinted, Depop and Mercari sale emails now give the item's title and price, and match the listing when the item is on the site. A sale email and its label email become one question. Noise is recorded quietly. Nothing changes an item without Cody's tap. Switched on 06:59 (commit fbbb11b, report 2026-11-17-3). Known gaps: Depop offer emails have no title; Depop labels are a download link; no real eBay sale email seen yet.

## 3. eBay appeal: dropped (2026-11-17)

No reply to Cody's developer-account appeal since 10/21, so the eBay Sell API integration is dropped (decision 738, punch 633). Nothing had been built. eBay stays a platform on items (listing rows, Sold-here cascade, paste-ready text, Terapeak and Check sold prices), and its emails go through the inbox. Revisit only if eBay approves the account.

## 4. Inbox: automatic where certain (2026-11-17)

Liam's directive (step 3, restoring the deleted items, dropped by his later answer). Real-mail replay of the last 30 days in order, with everything rolled back afterwards: 2 automatic sales (Rolling cart on Vinted, then its label and "shipped"; Igloo cooler on Depop), both correct, no wrong matches. 4 Vinted sales asked because Cody accepted offers more than 10% under the listed price. 14 sale and label emails had no matching item (items Cody deleted). Switched on 07:33 (commit 6fb5f8c).

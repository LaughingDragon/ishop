# Phase history

Built 2026-10-15 to 2026-10-20 in phases. Liam approved each one, and each has its own build reports ([reports/](reports/README.md)). The full plan as it ended is [ROADMAP.md](ROADMAP.md), with every checklist item and its notes.

## Status at close-out prep (2026-10-20)

| Key | Phase | Status | Checklist | Opened | Latest report |
|---|---|---|---|---|---|
| foundation | Foundation: server, stack, HTTPS, hardening | done | 6/6 | 2026-10-15 | 2026-10-15 |
| 0 | Phase 0: safety net | done | 7/7 | 2026-10-15 | 2026-10-15 |
| 0.5 | Phase 0.5: the site office | done | 8/8 | 2026-10-15 | 2026-10-15 |
| 1 | Phase 1: the item model and phone app | done | 5/5 | 2026-10-15 | 2026-10-15 |
| 2 | Phase 2: fresh start (no historical import) | done | 3/3 | 2026-10-15 | 2026-10-15 |
| 3 | Phase 3: selling helpers | done | 6/6 | 2026-10-15 | 2026-10-15 |
| 4 | Phase 4: tracking | done | 4/4 | 2026-10-15 | 2026-10-15 |
| 5 | Phase 5: daily life tools and the digest | done | 6/6 | 2026-10-15 | 2026-10-15 |
| 6 | Phase 6: Amazon import and return windows (removed) | removed | 3/3 | 2026-10-15 | 2026-10-16 |
| 7 | Phase 7: AI listing (Cody's own Anthropic account) | done | 5/5 | 2026-10-15 | 2026-10-16 |
| 7.5 | Phase 7.5: pricing redesign | done | 4/4 | 2026-10-16 | 2026-10-16 |
| 8 | Phase 8: public catalog (built, switched off) | done | 2/2 | 2026-10-15 | 2026-10-16 |
| 9 | Phase 9: shipping and marketplace mail | done | 1/1 | 2026-10-16 | 2026-10-16 |
| 10 | Phase 10: bring in Cody's current listings | done | 4/4 | 2026-10-18 | 2026-10-18 |
| 11 | Phase 11: close the site office and hand over to Cody | not_started | 0/5 | 2026-10-15 | - |
| handover | Handover countdown: finish the build, then close the site office | in_progress | 4/5 | 2026-10-20 | 2026-10-20 |

"Opened" is when the phase's board entry was created. "Latest report" is the newest build report filed for that phase.

## What each phase was

- **Foundation:** the server, stack, HTTPS (Let's Encrypt), firewall, fail2ban, automatic security updates.
- **0, safety net:** git, admin theme, Security Kit (CSP report-only), login flood limits, TFA available (not required), nightly backups, private by default.
- **0.5, the site office:** the build-time status board, punch list, reports and decision log on the site itself (the records exported into this package).
- **1, item model and phone app:** items, photos, listings per platform, the phone-first add flow.
- **2, fresh start:** no historical import at first (later revisited in Phase 10, then undone for go-live).
- **3, selling helpers:** paste-ready listing text per platform, posting queue, bulk tools.
- **4, tracking:** statuses, the Sold-here cascade (take the other listings down), stale-listing nudges.
- **5, daily life tools and the digest:** bills, calendar, to-dos, mileage, receipts, the morning digest email. CSP enforcement moved to a follow-up.
- **6, Amazon import and return windows: REMOVED.** Built on 2026-10-15 and removed the next day, before any real data used it. Cody sells household overstock that's already in the house; he isn't buying items to resell, so Amazon order import and return-by dates had no use. Order ID, ASIN, purchase source and date, and the "Returned to Amazon" status went with it. Decisions 268 and 269.
- **7, AI listing:** listing drafts and suggestions on Cody's own Anthropic account, with key-expiry reminders.
- **7.5, pricing redesign:** eBay added as a platform, sold-price lookups (Terapeak and other platforms), a price book, two-step AI pricing.
- **8, public catalog:** built and switched off (no public shop for now).
- **9, shipping and marketplace mail:** A, the Shipping room (done). B, the marketplace inbox (built, not switched on) and C, eBay Sell APIs (not built), both moved to follow-ups on 2026-10-20.
- **10, bring in Cody's current listings:** spreadsheet import with Crosslister photos (2026-10-18). On 2026-10-20 the Stockroom was emptied for go-live: Cody took all his marketplace listings down and adds items fresh through the site (decision 635).
- **11, close the site office and hand over:** next, after Liam's go.
- **Handover countdown:** the last steps before close-out (boot disk tidy, Phases 9 and 10, birthday mode removal, close-out).

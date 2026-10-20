# 270: Redesign for usefulness (replaces all earlier unsent directives)

- Opened: 2026-10-16
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-16
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

From Liam, posted by Claude (review) with Liam's permission on 2026-10-16. Crew only. Read fully, propose a plan with order and effort, wait for Liam's OK. This replaces every earlier directive Liam didn't send.

WHO AND WHAT
- Cody (California, Pacific) is the only user. He sells household overstock already in the house (his wife's over-shopping is the joke in the name). Nothing is bought to resell.
- Liam (Maryland, Eastern) is the systems engineer, not a site user: give him only technical items (server, console, approvals). All usage items go to Cody, written for him.
- Cody's real workflow, which everything should serve: snap photos -> draft listing -> check what it really sells for -> post -> it sells -> take it down elsewhere -> ship. Anything that doesn't help that loop, or his daily life tools, should be removed or simplified.

1. REMOVE (no snapshot step needed: both disks have daily Google Cloud snapshots and there's no real data yet)
- Phase 6 entirely: Import from Amazon, parser, routes, tests, holiday-returns setting. Return windows: return-by field, Returned / Keeping it, return warnings in Needs you now and the digest.
- Item fields: order ID, ASIN, purchase source (+ taxonomy), purchase date; status "Returned to Amazon".
- Close punch items 242 and 251: "Not needed: the Amazon import was removed." Tell Cody to delete Amazon's data file when it arrives.
- Backups: retire the off-server bucket plan and the "10 real items" reminder; remove unused bucket scripts. Daily snapshots plus the nightly 02:15 site backup are the backup plan. Don't open backup punch items again.
- Keep "What I paid (if you remember)": optional, never nagged. Money page leads with "Earned after fees".

2. EBAY (punch item for Cody first: "Do you sell on eBay? Do you use Seller Hub?")
- If yes: add eBay as a platform everywhere (listing row, Sold-here cascade, nudges, bulk actions, Money page, tax export), with eligibility rules and paste-ready text (check the son's git history for his old eBay formatter; otherwise propose rules).

3. PRICING (replaces item 261's plan)
- "Check sold prices" on each item: opens Terapeak research in Seller Hub (Sold tab) for the identified item on Cody's own signed-in phone; fallbacks: eBay sold search, Mercari and Poshmark sold filters. Never store any eBay login or cookie on the server. Back on the site: Lower / About right / Higher, or type the price; record his choice.
- Price book: every Sold here stores price, category, condition; "Why this price" shows "You sold N similar items for $X-$Y" first.
- Two-step AI: cheapest current Claude model, one reduced-size photo, identifies item, category (from the site's own list only), condition, title, description; web search only above a value threshold ($15, setting). Reuse research for near-identical items. Measure cost per item; target a few cents.
- Not pursuing: eBay Marketplace Insights API, server-side eBay login.

4. FIXES FROM CODY'S FIRST ITEMS
- AI category must come from the site's list ("Use" always works); add Home > Holiday & Seasonal if missing.
- "Check first" platform text: with no category, say "Set the category first"; once set, name the platform's matching category or say "Skip: no category for this on <platform>".
- AI never fills Size for non-clothing (e.g. "5000mAh").

5. HOUSEKEEPING
- Item 258 is a joke from Liam to Cody: don't act on it or count it; let it close; leave it out of the Phase 9 archive.
- Birthday Monday 10/19: confirm automatic mode, the 7:00 a.m. Pacific email and the wake-up card; Liam's preview off. Report to Liam in the terminal only.
- Phase 8 (public catalog) waits for Liam's go in chat.

DOCS: update ROADMAP.md (Phase 6 removed with reason, pricing redesign added), the decision log, TEMPORARY.md; re-sync the board; one report per section.
At the start of every session, act on newly answered items first.

## Answer

Planned: the remaining parts moved to item 274 (and 278, 280). Already done: report 2026-10-16-3.

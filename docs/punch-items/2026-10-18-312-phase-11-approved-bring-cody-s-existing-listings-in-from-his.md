# 312: Phase 11 (approved): bring Cody's existing listings in from his spreadsheet, site only

- Opened: 2026-10-18
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-18
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

From Liam via Claude (review), 2026-10-18. Crew only. Approved by Liam: build it. Add Phase 11 to ROADMAP.md and the board.

WHY: Cody keeps an Excel spreadsheet of everything he currently has listed. He will NOT delist and relist anything (that would lose likes, watchers and listing history, and mass automation risks his seller accounts). Instead the site takes in his spreadsheet so all his live listings become items marked Posted, and from then on the sold-elsewhere alarm, Shipping room and marketplace inbox (Phase 10) cover them. Everything happens through the site: no browser agent, no marketplace automation.

BUILD: an Import listings page in My office (theowner and webmaster).
1. Upload his file as is: .xlsx (no need to save as CSV) or .csv.
2. Column matching screen: the site guesses which column is title, platform(s), price, listing link, status, quantity, location, notes, etc.; Cody can change each match or set a column to Ignore. Remember the matching for next time.
3. Preview before anything is saved: every row shows what item it will become, which platforms will be Posted with which links and prices, and any problems (no title, unknown platform name, duplicate of an item already on the site, a link that doesn't match the platform). Nothing is imported until Cody taps Import.
4. Import: one item per row (or per product if the sheet has one row per platform: group rows for the same item), status Listed, Cleared to sell ticked, each platform row Posted with URL, listed price and date if present; storage location default Game room > Table. No photos required; the item shows "Add photos when you can". Never fetch or scrape the marketplace pages.
5. Rows marked sold in the sheet: import as Sold on that platform (with price) so they reach the Money page, or skip them, Cody's choice on the preview screen.
6. Undo: an import can be undone as a whole for 7 days (deletes only the items it created).
7. Ignore and don't store columns the site doesn't use (anything like buyer names or addresses).

TEST with a made-up sheet first. Liam will put Cody's real spreadsheet on the server for you (location in the terminal); dry-run it through the preview only and report what you see (rows, platforms, problems), but leave the actual import for Cody to do himself from the page.
Then open a punch item for Cody: "Bring in your spreadsheet of current listings", plain steps.
Report per step. Order relative to Phase 10: after Phase 10 part A (Shipping room), before part B, so the marketplace inbox can match emails to his existing items.

## Answer

Built and done (Phase 10, renumbered from 11): Import listings page, dry run reported, Cody imported his sheet; sold items added for tracking at Liam's direction. 110 items.

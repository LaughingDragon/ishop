# 622: Stockroom redesign: whole rows open the item, list first, modern layout (Liam approved)

- Opened: 2026-10-18
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-18
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

From Liam via Claude (review), 2026-10-18. Crew only.

PART 1, APPROVED: STOCKROOM REDESIGN. Now that 93 real items are on the table, the Stockroom is Cody's most-used page. Today it opens with "1. What to do" and "2. Which items", sold items appear first, and each row needs a small Open button. Rebuild it around modern list design (think iOS Mail / Files):

1. List first. Page header: "Stockroom" with the count ("93 on the table"). Under it a single search box that filters as he types, plus a row of filter chips: Status (default: Listed + Ready + Draft; Sold behind its own chip), Platform, Category, and a Sort menu (Newest, Price high/low, Longest listed). Search and chips stay pinned while the list scrolls. Remove the "1. What to do / 2. Which items" scaffolding.
2. Whole row is the link. Each row is ONE tap target (an <a> wrapping the row) that opens the item: 64px thumbnail with rounded corners (a camera-icon placeholder saying "No photo" when there isn't one), title on up to 2 lines, then a quiet meta line: price, status chip, small platform pills for where it's Posted (P, M, eB, V...), location in muted text. A chevron (>) on the right signals "opens". Min row height 72px, full-width hover and pressed states, visible focus ring, accessible name = the title. Remove the Open buttons.
3. Selection without mis-taps: a "Select" button top-right turns on select mode. In select mode, checkboxes appear on the left and tapping a row toggles it instead of opening it; "Done" leaves select mode. No checkbox or other control nested inside the row link (valid HTML, no accidental opens).
4. Bulk actions in a bottom bar that appears only when something is selected: "3 selected" with Mark posted, Price, Move, Status, then the existing review-before-apply step. Safe-area aware on iPhone.
5. Empty, loading and no-results states that say what to do ("No matches. Clear filters"). Lazy-load thumbnails; show 50 at a time with "Show more" (or infinite scroll).
6. Keep everything in the Reseller Matrix theme (red/gold, Bungee headings, 19px text, 48px targets), working at iPhone width first. Test on iPhone-size and desktop, file a report with before/after screenshots.

PART 2, PROPOSAL ONLY (wait for Liam's OK): Money accuracy after the import.
- The 21 imported sold items without real sale dates are counted as sold on import day, so "Sold this month" shows $549.42 for October. Proposal: mark undated imported sales "sold before the site" so they count in All time only, never in this month or season.
- The scoreboard's "287 Listed" counts platform listings while the Stockroom says 93 items. Proposal: show items listed (93) as the big number, with "287 listings across your sites" as a small line under it.

## Answer

Part 1 built (9cc95b0, 51c9bdd, d4d3226): list first, search + chips pinned, whole rows open the item, Select mode with the bottom bar, empty/no-match states, 50 at a time. Report 2026-10-18-3 has before/after screenshots. Part 2 (money accuracy) is a proposal under Decisions needed in that report, waiting on Liam's OK; nothing built.

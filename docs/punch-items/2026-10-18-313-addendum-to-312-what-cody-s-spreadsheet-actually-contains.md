# 313: Addendum to 312: what Cody's spreadsheet actually contains

- Opened: 2026-10-18
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-18
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

From Claude (review) for Liam, 2026-10-18. Crew only. Claude read Cody's file (Reselling - Master Tracker.xlsx); Liam will put it on the server and tell you where. Build the item 312 importer to handle this format well; keep the column matching generic for future sheets.

WHAT IT IS: the son's Crosslister master Google Sheet saved as Excel. 4 tabs. Import from the "Items" tab only. "To Post" (work queue), "Money" (formulas) and "Rules" are ignored.

ITEMS TAB: 111 rows, one per item. Columns: Crosslister #, Title, Brand, Size, Condition, Type, Photos (a count), List price, Poshmark, Mercari, Depop, Grailed, Vinted, Facebook, SOLD?, Sold for, Sold on, Date sold, Notes.
- Platform columns use our exact vocabulary: Posted, TO POST, SOLD HERE, Not needed - sold, Not allowed. Map 1:1 (case-insensitive).
- 22 rows SOLD? = Yes, $631.42 total. 88 unsold rows are Posted on at least one platform; 1 unsold row is posted nowhere.
- No URLs anywhere: import platform rows as Posted without links.
- "Sold on" is unreliable (platform names, dates or blank) and "Date sold" is always empty. Take the sold platform from the column that says SOLD HERE; if Sold on holds a date, use it as the sold date, else leave the date blank.
- No eBay column: imported items get eBay as undecided/blank, NOT "To post" (don't create 88 eBay reminders).
- Type maps to our categories (Home goods, Toy / game / collectible, Men's clothing..., Unisex clothing / accessory / jewellery, Electronic device, Women's clothing..., Sporting / outdoor gear, Office / desk / craft): propose the mapping on the matching screen.
- Notes (17 rows) are the son's instructions to Cody (e.g. "ACTION FOR CODY: ...", "measure it before posting"): import into internal notes; any that need action can show once in Needs you now.
- Titles have trailing spaces: trim.

BONUS: Crosslister # matches the son's database in ~/ishop/reference/crosslister-reference/data/crosslister.db. On the preview, offer "Fill in descriptions and details from the old Crosslister records" (description, flaws, size, condition, colour, price range) matched by Crosslister #; never overwrite what the sheet says about platform status or sold. Photos: attach the few that exist in the reference folder; for the rest show "Add photos when you can" (the son's GitHub may have them; ask Liam, don't fetch).

CHECK BEFORE COMMITTING: the To Post tab marks only 61 tasks done while Items says 86 Posted on Poshmark alone. Items is the master; the preview should show per-platform Posted counts so Cody can confirm they match reality.

Dry-run on the real file through the preview, report the counts and problems to Liam, and leave the actual Import button for Cody.

## Answer

Handled: Items tab only, platform columns 1:1, eBay blank, Sold-on dates used, notes to Needs you now, Crosslister fill-in and surviving photos. Row 37 (sold, no platform) left out.

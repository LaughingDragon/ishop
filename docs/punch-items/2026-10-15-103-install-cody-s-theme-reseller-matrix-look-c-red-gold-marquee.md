# 103: Install Cody's theme: Reseller Matrix (Look C, Red & Gold Marquee)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: site_foreman
- Phase: 1 (Phase 1: the item model and phone app)

## Instructions

Cody chose Look C (Red & Gold Marquee) from Claude's three looks and says "great job". Liam asked Claude to put it on the site. Opened by Claude (review) for Liam on 2026-10-15.

LIAM (2 minutes):
Upload reseller-matrix-theme.zip (from the chat with Claude) to the VM through the SSH window's Upload file button. It lands in your home folder.

SITE-FOREMAN:
1. Unzip ~/reseller-matrix-theme.zip into web/themes/custom/ (folder reseller_matrix), fix ownership to match the other custom code, php -l reseller_matrix.theme, commit. Remove the zip from ~.
2. Read its README.md.
3. Add the theme negotiator from the README to ishop_office so /site-office and the site office node types (office_report, office_decision, office_phase, office_plan, office_punch) stay on the construction theme.
4. Install Reseller Matrix as the default front-end theme (Gin stays admin). Place blocks per the README. Main menu = the seven rooms: Front desk, Stockroom, Shipping room, My office, Mailroom, Calendar wall, Break room. Rooms not built yet link to a simple 'Coming soon' page in the theme rather than a 404.
5. Front page (/) = the Reseller Matrix dashboard using the README's markup contract: 'Welcome back, boss!', date line, Open for business badge, Needs you now (real data only; plain 'Nothing needs you right now' when empty), and the seven room tiles with real glance lines where data exists.
6. If Phase 1 /add is not finished yet, keep the header's Add item button but point it at node/add/item for now.
7. Check logged out (front door gate), as theowner on iPhone Safari width and desktop, and the site office still in hard hats.
8. Log decision: 'Cody chose Look C (Red & Gold Marquee) as the main theme' (decided by Cody). File a build report and close this item with its link.

## Answer

Done: Reseller Matrix (Look C) is the default theme, the dashboard is at /, the site office stays in Construction. Build report: https://ishoponamazonwaytoomuch.ohbah.com/site-office/reports/2026-10-15-20

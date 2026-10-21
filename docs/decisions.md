# Decision log

Every decision logged on the site office, oldest first.

## 2026-10-15 (13): Stack: Apache 2.4 + PHP-FPM, PHP 8.4, MariaDB, Drupal 11 (not the Drupal 12 beta)

- Decided by: liam
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

Apache so Drupal's .htaccess protections work unchanged. Drupal 11 is the stable release; 12 was still in beta.

## 2026-10-15 (14): Domain ishoponamazonwaytoomuch.ohbah.com via FreeDNS, with HTTPS from Let's Encrypt

- Decided by: liam
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

ohbah.com is already on FreeDNS (afraid.org), so a subdomain costs nothing. Let's Encrypt certificates renew automatically.

## 2026-10-15 (15): Rebuild everything inside Drupal; the son's Crosslister app is the specification only, not run here

- Decided by: liam

One system to maintain. The Python app's rules, formatters and tests are ported, not run.

## 2026-10-15 (16): No historical import: the site starts fresh from today

- Decided by: liam
- Phase: 2 (Phase 2: fresh start (no historical import))

Cody's existing items are already posted on the marketplaces. A one-off import stays possible later, only if Liam asks.

## 2026-10-15 (17): AI features use Cody's own Anthropic account and key, never the son's, and are built last (Phase 7)

- Decided by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

Cody controls his own spending, with a monthly limit set in the Anthropic console.

## 2026-10-15 (18): Public catalog is built but switched off; no checkout unless Liam asks

- Decided by: liam
- Phase: 8 (Phase 8: public catalog (built, switched off))

Selling directly would make Cody responsible for sales tax, returns and shipping.

## 2026-10-15 (19): Automatic reboots about 4 a.m. Pacific; fail2ban ignores Google's IAP and SSH-in-browser ranges

- Decided by: liam
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

Security updates need reboots, and 4 a.m. Pacific is when nobody uses the site. fail2ban ignores 35.235.240.0/20 (IAP) and 172.253.0.0/16 (Google SSH-in-browser, Liam's actual SSH source) so Liam can't lock himself out.

## 2026-10-15 (20): The site name "I Shop on Amazon Way Too Much" is provisional

- Decided by: liam

Cody still has to confirm it (see the punch list).

## 2026-10-15 (21): Phase progress comes from checkboxes in ROADMAP.md, which stays the source of truth in git

- Decided by: liam
- Phase: 0.5 (Phase 0.5: the site office)

Ticks live next to the plan they describe, git records when each was ticked, and drush ishop:plans copies the counts to the status board.

## 2026-10-15 (22): Site office pages are a small custom controller, not Views; reports render through markdown_easy

- Decided by: site_foreman
- Phase: 0.5 (Phase 0.5: the site office)

The status board needs computed columns (percent done, latest report) and the same data as status.json, so one controller serves both and guarantees plain semantic HTML with no JavaScript. Reports are stored as Markdown and rendered by the contrib markdown_easy filter (league/commonmark with raw HTML stripped), followed by core's HTML tag filter. Liam can veto this.

## 2026-10-15 (23): Two-factor login skipped for now

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

The TFA module is installed and configured but required for no role. Strong passwords and login flood limits instead. Revisit later.

## 2026-10-15 (24): Accounts: theowner (Cody) is a full administrator; webmaster is Liam's; site-foreman is the agent's, working only through Drush

- Decided by: cody
- Phase: 0 (Phase 0: safety net)

Cody decided his own account has full rights. This replaces the roadmap's original restricted owner role, which was never created. Liam set up webmaster and site-foreman.

## 2026-10-15 (25): Cody's username changed from "The Owner" to theowner, to match the roadmap

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Asked and decided 2026-10-15. Cody logs in as theowner from now on.

## 2026-10-15 (47): Disks: system disk grows to 20 GB; a new 10 GB data disk holds the site and database, and backups stay on the system disk

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Liam asked for more space and separate system and site disks. Claude suggested keeping the backups on the other disk from the site, so either disk can fail without losing both. The Apache program stays on the system disk with the rest of Debian; only site data moves. Waiting on Liam's console steps (punch list).

## 2026-10-15 (49): Outgoing mail for the daily digest: Option A, a Gmail account with an app password

- Decided by: liam
- Phase: 5 (Phase 5: daily life tools and the digest)

Chosen on the punch list (answered 2026-10-15). One email a day to one person; a transactional service can replace it later. The app password goes into a file only the web server can read, never into config, git or the site office.

## 2026-10-15 (54): Content security policy stays report-only through Phases 1-5; enforce after Phase 5 and a clean week

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Camera, QR scanning, copy buttons and charts arrive in Phases 1-5 and will each need policy changes. Reporting was proven working first with a deliberate violation.

## 2026-10-15 (55): Disk: no resize; a separate 10 GB data disk holds the site and database, and backups stay on the system disk

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Liam corrected the earlier plan (which also grew the system disk to 20 GB): just add the extra 10 GB drive and move the site off the system drive onto it. Replaces the earlier disk decision.

## 2026-10-15 (56): Drupal updates: Liam checks weekly, and the server should apply updates on a schedule

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Debian packages already update nightly. A weekly automatic Drupal/Composer update with backup, checks and automatic rollback is proposed on the punch list for Liam's approval.

## 2026-10-15 (57): Site name "I Shop on Amazon Way Too Much" confirmed

- Decided by: cody

Answered "accepted" on the punch list, 2026-10-15. Replaces the earlier "provisional" entry.

## 2026-10-15 (68): Punch list "Waiting on": site-foreman for server work, Claude (review) for browser reviews and judgment

- Decided by: liam
- Phase: 0.5 (Phase 0.5: the site office)

site-foreman is Claude working on the server (Drush, code, the VM). Claude (review) is Claude in a browser or chat; it answers its items through the answer form when Liam asks it to check in, choosing "Claude (review)" under "Who is answering?". The CSP enforcement item and all earlier Claude entries were server work, so they were relabelled site-foreman.

## 2026-10-15 (72): After the data disk loss: no separate data disk; nightly backups copied to Cloud Storage; git pushed to a private remote after every session; daily snapshots of the boot disk; disk deletions start with a snapshot

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

These gaps turned a disk mix-up into a loss (incident report 2026-10-15). Liam approved items 1-5.

## 2026-10-15 (74): Front page stays blank until the real design is ready

- Decided by: liam
- Phase: 0.5 (Phase 0.5: the site office)

Liam 2026-10-15: the logo and / show the empty front page for now. The future front page is a front-desk guidance system: Cody as the boss, the site as his business, perhaps a secretary-style site persona (a character, not a real person). Belongs to the design work.

## 2026-10-15 (75): Front page goes to the site office for now

- Decided by: liam
- Phase: 0.5 (Phase 0.5: the site office)

Liam answered "site office" (2026-10-15), replacing decision 74's "blank until design". The front-desk / site persona idea still belongs to the design work.

## 2026-10-15 (79): Phase 1 approved as proposed, plus the review's 10 gaps

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Punch item 78: Claude (review) listed gaps, and Liam delegated the decisions to its recommendations (custom listing entity; Color and Original price added; no Crosslister ID; purchase source taxonomy; custom manifest; boot disk to 30 GB after a snapshot; photos backed up daily to the bucket; six To post rows plus a confirmed, reversible Sold-here cascade). Storage locations and Cody's phone are asked of Cody.

## 2026-10-15 (84): Off-site backup copies and snapshot schedules skipped for now

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Liam on punch item 73 (2026-10-15): 'skip off site backups and backup schedules. its delaying development'. ishop-offsite.timer stays installed but disabled; nightly backups stay on the VM only. The git repo is now pushed to GitHub (LaughingDragon/ishop), so code is off the VM; the database and photos are not.

## 2026-10-15 (88): Item photos and local backup files go on the extra 10 GB disk; the boot disk stays 10 GB; snapshot schedules on both disks

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Liam changed Phase 1 step 1 (2026-10-15): use ishopway2muchdata (mounted /srv/ishop-data, by UUID, nofail) for private://items and /var/backups/ishop. Code, database and Drupal stay on the boot disk; MariaDB and Apache must not depend on the mount; if it's missing, photo pages say photo storage is offline. Replaces 'grow the boot disk' and, for snapshots, decision 84.

## 2026-10-15 (99): Cody's phone is an iPhone: test the phone flow on iPhone Safari first

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Liam corrected his earlier answer (Android). Affects step 4: HEIC photos (already converted on upload), Safari's camera input takes one photo per tap (Take another / Done), and the home-screen install is Safari > Share > Add to Home Screen.

## 2026-10-15 (100): Cody is the only designer: his questionnaire answers are the design brief

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Liam, 2026-10-15. Liam and Claude turn Cody's answers into looks; Cody picks.

## 2026-10-15 (108): Cody chose Look C (Red & Gold Marquee) as the main theme

- Decided by: cody
- Phase: 1 (Phase 1: the item model and phone app)

From the three looks built from his style questionnaire (punch item 103). Installed 2026-10-15 as the default front-end theme, with the Reseller Matrix dashboard as the front page; the site office stays in the Construction theme.

## 2026-10-15 (110): Cody uses Google Chrome on his iPhone (not Safari)

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Liam, 2026-10-15. Chrome on iPhone runs Apple's WebKit engine, so camera, HEIC and uploads behave as in Safari; home-screen install is Chrome's Share button, Add to Home Screen (iOS 16.4+). Refines decision 99.

## 2026-10-15 (118): Cody uses Safari on his iPhone for the site (he has the Google app, not Chrome)

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Liam, 2026-10-15: Cody was mistaken about Chrome; Liam had him use Safari, and the server logs confirm iPhone Safari. Replaces decision 110. Home-screen install: Safari, Share, Add to Home Screen.

## 2026-10-15 (123): Phase 1 signed off

- Decided by: liam
- Phase: 1 (Phase 1: the item model and phone app)

Punch item 114, 2026-10-15. Items, photos (HEIC to JPEG), platform listings with the Sold-here cascade, the data disk and the phone Add item flow are done; Cody added his first real item (121) from his iPhone in Safari.

## 2026-10-15 (131): No off-site backups for now

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Liam, 2026-10-15: 'stop it with the off site backups. we haven't made anything yet. Proceed without them.' Reaffirms decision 84 over Claude (review)'s request. Nightly backups stay local (data disk); the off-site copy job stays installed but off; the board no longer warns about it.

## 2026-10-15 (139): Phase 2 signed off; the old 112 records will not be imported

- Decided by: liam
- Phase: 2 (Phase 2: fresh start (no historical import))

Liam on item 128: "signed off. Go no old records". The site starts fresh; existing listings are added with the quick add when needed.

## 2026-10-15 (156): Vinted bulk CSV dropped

- Decided by: liam
- Phase: 3 (Phase 3: selling helpers)

Liam on item 154: "drop it". Vinted has no bulk upload for regular sellers; the Post this item page covers Vinted.

## 2026-10-15 (176): No QR labels or tagging; storage location stays a plain field on each item

- Decided by: liam
- Phase: 4 (Phase 4: tracking)

Liam, 2026-10-15. Locations show as a path (e.g. Game room > Table) and are one of the Stockroom filters.

## 2026-10-15 (177): Phase 3 signed off

- Decided by: liam
- Phase: 3 (Phase 3: selling helpers)

Liam on item 174: "Signed off". All six selling helpers built and tested (wrap-up report 2026-10-15-36).

## 2026-10-15 (178): Phase 4 plan approved: built-in Stockroom filters, a Date sold field, donation values entered by Cody

- Decided by: liam
- Phase: 4 (Phase 4: tracking)

Liam said "ok" in chat to the Phase 4 proposal: (a) Stockroom search and filters built in, no Search API; (b) Date sold on each listing, set on Sold here; (c) Estimated donation value entered by Cody, never guessed. Money page in My office and the tax export (CSV with donation log) follow. One report per step.

## 2026-10-15 (196): Phase 4 signed off

- Decided by: liam
- Phase: 4 (Phase 4: tracking)

Liam on item 194: "Signed Off". Stockroom search and filters, Date sold, Money page, year-end tax export (wrap-up report 2026-10-15-41).

## 2026-10-15 (197): Phase 5 plan approved

- Decided by: liam
- Phase: 5 (Phase 5: daily life tools and the digest)

Liam said "ok" in chat: (a) calendar built in, no Smart Date / Calendar View; (b) order: mileage and expenses, bills, calendar, notes and to-dos, document vault, daily digest, then CSP enforcement; (c) daily digest on by default for Cody with an off switch, replaced by the birthday email on the birthday. One report per step.

## 2026-10-15 (226): Phase 7 plan approved

- Decided by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

Liam said "ok" in chat: (a) Claude Opus 5.5; (b) site-side AI cap $25 a month (under Cody's $32 Anthropic limit), new runs pause at the cap; (c) order: photos to draft listing with web-searched sold comps and sources first, then token cost per item on the Money page, then bulk photo drop; (d) bulk split gap 45 minutes, adjustable. Cody always reviews before saving.

## 2026-10-15 (231): Cody's morning digest started

- Decided by: liam
- Phase: 5 (Phase 5: daily life tools and the digest)

Liam on item 227: "start it", after the test to webmaster. Daily at 07:00 Pacific; skipped on the birthday; Cody can turn it off on his account page.

## 2026-10-15 (241): Phase 6 plan approved with changes: ticked import, about-30-day window from order date, holiday rule a setting, minimal fields, file deleted, no digital orders

- Decided by: liam
- Phase: 6 (Phase 6: Amazon import and return windows (removed))

OK to all four recommendations, with changes: default return window 30 days labeled "about" and editable per item; say on screen that it's counted from the order date (delivery is usually later, so this warns early); holiday rule as a setting, not hard-coded; import keeps only the needed fields and deletes the uploaded Amazon file afterwards; skip digital orders. Open Cody's "request your Amazon data" item now.

## 2026-10-16 (259): AI site monthly cap raised from $25 to $32 to match Cody's Anthropic account limit

- Decided by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

Liam asked 2026-10-16 (Phase 7 follow-up before sign-off); replaces the $25 in decision 226

## 2026-10-16 (260): Phase 7 signed off (AI listing helper)

- Decided by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

Liam answered item 236 'Signed off' 2026-10-16 06:52. Cost follow-up (cheaper default) proposed separately

## 2026-10-16 (264): AI default: Claude Sonnet 5.5, 1092px photos, resale-site search (max 4), price labelled as an estimate; plus a 'See eBay sold prices' button for Cody

- Decided by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

Cost test 2026-10-16 (item 261, $0.30 spent): cheap setup $0.02-0.09/item vs $0.30-0.64; neither setup found real sold prices (search returns current listings; eBay blocks fetching its sold search), so Cody checks real sold prices himself in one tap

## 2026-10-16 (265): Build a 'Cleared to sell' checkbox: posting blocked until ticked; new items unticked; existing items 121, 255, 256 ticked

- Decided by: liam
- Phase: 6 (Phase 6: Amazon import and return windows (removed))

Confirmed 2026-10-16 in chat

## 2026-10-16 (266): Phase 8: Liam's 'Ok' on item 253 is the go; build after the redesign is done and reported

- Decided by: liam
- Phase: 8 (Phase 8: public catalog (built, switched off))

Confirmed 2026-10-16 in chat

## 2026-10-16 (268): Backups: Google Cloud daily snapshots of both disks (7-day retention) plus the nightly 02:15 site backups. The off-server bucket plan and the 10-item reminder are retired; no backup punch items again

- Decided by: liam
- Phase: 0 (Phase 0: safety net)

Liam, 2026-10-16: the scheduled snapshots already give an off-VM copy

## 2026-10-16 (269): Redesign: Cody sells household overstock already in the house. Phase 6 (Amazon import, return windows) and the purchase fields removed; Money leads with 'Earned after fees'

- Decided by: liam
- Phase: 6 (Phase 6: Amazon import and return windows (removed))

Liam and Cody, 2026-10-16: nothing is bought to resell. 'What I paid (if you remember)' stays optional and is never flagged; profit only where it was entered; original price stays.

## 2026-10-16 (276): Item 270 plan: Check sold prices, then price book, then two-step AI, then eBay (if Cody says yes on 267), then Phase 8

- Decided by: liam
- Phase: 7.5 (Phase 7.5: pricing redesign)

Liam approved the proposed order in chat, 2026-10-16. Pricing first because it serves Cody's daily loop; the two-step AI replaces decision 264.

## 2026-10-16 (287): Two-step AI listing run: Claude Haiku 5.5 from one photo, Sonnet 5.5 price search only from $15 (research reused for near-identical items)

- Decided by: liam
- Phase: 7.5 (Phase 7.5: pricing redesign)

Liam: build it (report 2026-10-16-7). Test: $0.0005 under $15, about $0.056 above.

## 2026-10-16 (291): Phase 8 built without Pathauto, Metatag or Simple XML Sitemap: readable addresses, link-preview tags and the sitemap are built into ishop_shop

- Decided by: site_foreman
- Phase: 8 (Phase 8: public catalog (built, switched off))

Those modules act on every node, including the private item and office nodes (aliases and tags for pages visitors must never see). The shop needs them for its own pages only, which takes about 60 lines. The approved outcomes are unchanged: /shop/{title-words}, description and Open Graph tags, and a sitemap with the shop pages only.

## 2026-10-16 (294): Phase 7.5 (pricing redesign) is done

- Decided by: liam
- Phase: 7.5 (Phase 7.5: pricing redesign)

Liam signed off item 289 ("Signed Off", 11:24).

## 2026-10-16 (300): Phase 10 approved: A Shipping room, B marketplace inbox (business Gmail, IMAP, read-only), C eBay Sell APIs via Cody's OAuth consent; order A, B, C; built before Phase 9 Stage B

- Decided by: liam
- Phase: 9 (Phase 9: shipping and marketplace mail)

Liam approved the Phase 10 proposal in chat, one report per part; all of Cody's marketplace accounts use the business Gmail

## 2026-10-16 (301): Phase 8 marked Done

- Decided by: liam
- Phase: 8 (Phase 8: public catalog (built, switched off))

Liam signed off item 292; straggler 1 in item 295

## 2026-10-16 (302): Shipping room privacy and money: buyer address only in the private label PDF (deleted after delivery); Money still counts sales by date sold, with shipping stages alongside

- Decided by: site_foreman
- Phase: 9 (Phase 9: shipping and marketplace mail)

Liam's Phase 10 rule: store only what's needed. Labels are deleted 30 days after delivery, or 60 days after shipping if never marked Delivered; a scanned USPS barcode's 420+ZIP prefix is dropped. Totals by date sold keep the tax export and season numbers from moving when a parcel arrives.

## 2026-10-18 (334): Dry run of Cody's listings spreadsheet OK'd; Cody imports it himself from the page

- Decided by: liam
- Phase: 10 (Phase 10: bring in Cody's current listings)

Liam: "Ok dry run" (2026-10-18) after the crew-only report 2026-10-18-1: 111 rows, 22 sold $631.42, per-platform counts.

## 2026-10-18 (626): Sales with no date (imported) are marked 'sold before the site' and count in All time only (tax export keeps them in the import year); the scoreboard's Listed counts items, with listings as a small line

- Decided by: liam
- Phase: 10 (Phase 10: bring in Cody's current listings)

Punch 622 part 2: undated imported sales put $549.42 into October; 287 Listed counted listings, not items

## 2026-10-20 (635): Go-live reset: Stockroom emptied (import 1 undone, 4 test drafts deleted) and Phases 5, 9 and 10 closed. Unfinished items (CSP enforcement 10/23, marketplace inbox, eBay APIs) moved to After go-live follow-ups.

- Decided by: liam
- Phase: 10 (Phase 10: bring in Cody's current listings)

Cody took all his marketplace listings down so every item goes through the site fresh; nothing is imported. Sets the site ready for live.

## 2026-10-20 (638): eBay Sell APIs dropped for now: the marketplace inbox covers eBay through eBay's emails. Cody appeals the developer account rejection (punch 637); revisit if approved.

- Decided by: liam
- Phase: 9 (Phase 9: shipping and marketplace mail)

eBay rejected Cody's developer account with no reason given; appeals are often auto-answered.

## 2026-10-20 (639): eBay: one appeal by Cody (punch 633). Approved: build the Sell API connection. Declined or no reply by 2026-11-19: drop the eBay API entirely; eBay stays a platform on items. Post-launch, not handover.

- Decided by: liam

Liam's close-out prep brief, 2026-10-20. Replaces decision 638. Build as specified: OAuth consent, token stored like other secrets, renewal reminder; never passwords, cookies or scraping.

## 2026-10-21 (645): Posting flow simplified: one-tap "I posted it" with Undo, listing link optional, platforms that don't fit set to Not allowed automatically, compact listing rows, item status follows listings

- Decided by: liam, claude (review)

Cody got stuck on his first new item (644) at "Paste the listing link... Go to Listings", which landed on a 7 x 6 grid. The listing link was never meant to be required. The site never posts to marketplaces itself: no public listing APIs for most of them, and automating their sites breaks their terms. eBay posting only if the API appeal is approved; paid crosslisting services are the option if Cody wants one-tap crossposting.

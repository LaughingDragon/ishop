# I Shop on Amazon Way Too Much: build roadmap

Read this whole file before starting work, then read `NOTES.md`. Work one
phase at a time, demo each phase to Liam, and wait for his OK before the next.

Each phase's bullets are checkboxes. The agent ticks one only when that work is
done and verified, then runs `drush ishop:plans` so the site office status
board picks up the new percentage. This file in git is the source of truth.

## Who and what

- **Owner and user:** John Cody Lee ("Cody"). Not technical. Every screen he
  sees must be simple, large, and work well on his phone.
- **Developer:** Liam. Explain in plain language. Ask before anything
  destructive, anything touching accounts or billing, or any big
  architecture choice.
- **Goal:** a private workspace that runs Cody's resale business and daily
  life with as little effort from him as possible. He takes photos and
  answers prompts; the site remembers, reminds, formats, and does the math.
- **Site:** https://ishoponamazonwaytoomuch.ohbah.com (Drupal 11, Apache,
  PHP-FPM, MariaDB, project in /var/www/ishop).

## Reference material

`~/ishop/reference/crosslister-reference/` is Cody's son's working Python app
(Crosslister). It is the **specification**, not code to run here:

- `AGENTS.md`, `ARCHITECTURE.md`, `docs/PLATFORM_RULES.md`: read first.
- `app/eligibility.py`: what can be sold on which platform. Rules were verified
  against live sell forms; port them exactly.
- `app/formatters/`: per-platform character limits, condition vocabularies,
  photo sizes, description truncation that always preserves the flaws block.
- `tests/`: regression cases. Port them as PHPUnit tests for the custom module.
- `data/`: the son's old inventory. Not imported (see Phase 2); do not touch.

## Standing rules

1. **Secrets:** never print, log, commit, or write keys or passwords into notes.
   API keys live only in Drupal's Key module, sourced from a file outside the
   web root or an environment variable.
2. **Config as code:** after every change, `drush cex` and commit to the local
   git repo in /var/www/ishop. Small commits, clear messages.
3. **Contrib first:** prefer a maintained Drupal 11 contrib module over custom
   code. Check each has a stable Drupal 11 release before installing; tell Liam
   if one doesn't and propose an alternative.
4. **Custom code** lives in one module family under
   `web/modules/custom/ishop*` with PHPUnit tests.
5. **Private by default:** everything requires login. Only Phase 8 exposes
   anything publicly, and it ships switched off.
6. **Business and personal data are separate** content types and views.
7. **Verify by running it.** Test on a phone-sized screen. At the end of
   every session update `NOTES.md` and file a build report in the site
   office (Phase 0.5 onward).
8. **Nothing posts to marketplaces automatically.** They have no public
   listing APIs and their terms forbid automated posting.
9. **The close-out is always the last phase and has the highest number.** It is done
   when everything else is finished. A new phase goes above it and takes the close-out's
   number; the close-out moves down one (Liam, 2026-10-18).

## Foundation: server, stack, HTTPS, hardening

Done before Phase 0. Details live in `NOTES.md` and the foundation build
report in the site office.

- [x] GCE VM (e2-medium, Debian 13) surveyed; 2 GB swap added.
- [x] Stack: Apache 2.4 + PHP-FPM 8.4, MariaDB 11.8 (localhost only),
  Drupal 11.4, Drush 13. Project in /var/www/ishop, local git repo.
- [x] Domain ishoponamazonwaytoomuch.ohbah.com (FreeDNS A record).
- [x] HTTPS: Let's Encrypt certificate, HTTP to HTTPS redirect, automatic
  renewal (timer and dry run checked), short HSTS.
- [x] Hardening: unattended upgrades with reboots about 4 a.m. Pacific,
  nftables firewall, fail2ban for SSH, LLMNR and mDNS off, secrets outside
  the web root and out of git.
- [x] Accounts: webmaster (Liam), theowner (Cody), site-foreman (the agent).

## Phase 0: safety net

- [x] Two-factor login: skipped for now by decision. Enforce strong passwords
  and login flood limits instead. Security Kit.
- [x] Backup and Migrate: nightly database + private files backup, kept 14 days,
  stored outside the web root.
- [x] Gin admin theme + Gin Toolbar.
- Accounts:
  - [x] `webmaster`: administrator, for Liam. Password never in any file the
    agent can read.
  - [x] `theowner`: Cody's account, created by him, full administrator.
  - [x] `site-foreman`: the agent's Drupal account, role "Site foreman"
    (`site_foreman`: content and config it is asked to change; no user or
    permission administration).
  - [x] The agent works through Drush and code, never web logins. The
    `ishop:*` Drush commands save everything as site-foreman (they switch to
    that account internally), so revision logs show site-foreman.

## Phase 0.5: the site office

A private section at `/site-office`, like the trailer on a construction site:
the plans on the wall and the daily reports. Build it before Phase 1 so every
later phase reports here. Visible only to webmaster, theowner and
site-foreman (role "site office"); never public, noindex.

- [x] **Plans:** ROADMAP.md rendered as a page. The file in git stays the source
  of truth; a Drush command re-syncs the page whenever the file changes.
- [x] **Status board** (the landing page): one row per phase showing Not started /
  In progress / Blocked / Done, percent of checklist items done, last update
  date, and a link to that phase's latest report.
- [x] **Build reports:** one per work session, written by the agent at the end of
  each session. Fields: date, phase, summary, what was done, what's next,
  blockers, decisions needed from Liam or Cody, tests run and results, git
  commit hashes. Newest first.
- [x] **Punch list:** open questions and decisions waiting on a person, with who
  it's waiting on. Closing an item records the answer.
- [x] **Decision log:** what was decided, by whom, when, why.
- [x] Custom Drush command, e.g. `drush ishop:report path/to/report.md`, that
  creates a build report from a Markdown file (rendered safely, never raw
  HTML) and updates the status board. The agent runs it as site-foreman.
- [x] Pages must read cleanly for a browser-based AI assistant reviewing
  progress: plain semantic HTML, real headings, tables for the status board,
  dates in ISO format, no content hidden behind tabs or JavaScript-only
  widgets. Also provide `/site-office/status.json` (same access rules) with
  each phase's status, percent done, and latest report link.
- [x] Reports never contain secrets, passwords, keys, or personal data about
  customers.

## Phase 1: the item model and phone app

Approved 2026-10-15 (punch item 78: proposal plus Claude (review)'s gaps; Liam delegated the decisions).

- [x] **Data disk** (changed by Liam 2026-10-15; the boot disk stays 10 GB): the extra 10 GB disk
  `ishopway2muchdata` holds item photos (private://items) and local backup files (/var/backups/ishop) only.
  It is snapshotted first, then formatted and mounted by UUID with nofail at /srv/ishop-data. Code, database
  and Drupal stay on the boot disk; MariaDB and Apache don't depend on the mount. If it's missing, the site
  runs and photo pages say "Photo storage offline". status.json and the status board report the mount and
  free space on both disks. Item photos are left out of the
  nightly private-files tarball.
- [x] **Item** content type, plus taxonomies and photos:
  - Fields: title (counter at 60 and 70, maximum 80), photos (Media, first one is the cover), description
    (counter at 1000), flaws/condition notes, brand, size (free text), color, category, condition,
    quantity (default 1), lifecycle status (Draft / Ready / Listed / Sold / Donated / Kept), asking
    price, AI price range (min/max), original price (retail value), "What I paid (if you remember)",
    weight, package size (L x W x H), storage location, internal notes, and "show in shop" (Phase 8).
    No Crosslister ID. (Redesign 2026-10-16: purchase date, purchase source, order ID, ASIN, return-by
    and the status "Returned to Amazon" removed; see Phase 6.)
  - Only photos are required on the phone. A "ready to list" checklist shows what's missing.
  - Taxonomies: condition (6 terms), category (13 top-level terms), brand, storage location (empty until
    Cody answers). ~~Purchase source~~ removed in the redesign.
  - Photos: Media "Item photo" in private files. On upload: HEIC/HEIF converted to JPEG, turned the right
    way up, metadata (GPS) stripped, long edge at most 2560 px. ImageMagick's policy.xml allows only
    JPEG, PNG, WebP and HEIC/HEIF, and keeps its resource limits.
- [x] **Platform listing** custom entity: one per item per platform (Poshmark, Mercari, Depop, Grailed,
  Vinted, Facebook). Fields: status (To post / Posted / Sold here / Take down / Not needed - sold /
  Posted - at risk / Not allowed), URL, listed price, date posted, sold price, fees, shipping paid by the
  seller, eligibility override and note.
  - Six "To post" rows on every new item, and a listings grid on the item page.
  - "Sold here" cascade, confirmed before it applies; un-marking restores the previous statuses.
- [x] **Phone:** /add with "Take photos" / "Choose photos", "Take another" / "Done" after each camera shot,
  per-photo "Make cover" and up/down arrows, and optional quick fields. A custom web manifest so it
  installs to the home screen (no PWA beta, no offline cache). Tested on iPhone first: Cody uses
  Safari on an iPhone.
- [x] **Tests and reports:** unit tests for the rules, rendering checks, and a build report per step.

## Phase 2: fresh start (no historical import)

Cody's existing inventory is already posted on the marketplaces. The site
starts from today; do NOT build a migration of the son's database. The
reference project is used only for its rules and formatting logic.

- [x] Make adding an item fast enough that entering one he's already selling
  takes under a minute: photos optional, platforms pre-ticked "Posted,"
  listing URL paste field per platform. (Built 2026-10-15 as /add?listed=1: one paste box that
  recognises each link's platform, checkboxes remembered from last time.)
- [x] "Quick add from a live listing" form: paste a Poshmark/Mercari/etc. URL,
  enter title and price, done. No scraping of marketplace pages.
- [x] ~~Only if Liam later asks: a one-off import of the son's 112 items as
  "Posted" records so the sold-elsewhere alarm covers them.~~ Not doing:
  Liam signed off Phase 2 on 2026-10-15 with "Go no old records" (item 128).

## Phase 3: selling helpers

- [x] Port eligibility rules: green/amber/grey chip per platform on every item,
  hover or tap for the reason. (Rules and reasons ported word for word; chips on each listing card.)
- [x] "Post this item" page: one tab per platform, every field cut to that
  platform's limits, copy buttons, photos resized per platform (image styles),
  ZIP download, Previous/Next through the to-post queue. (/item/N/post; fields
  ported from the old formatters and matched against them; plus "Save photos to phone".)
- [x] Depop bulk CSV export (/post/depop-bulk: CSV + photos ZIP for Depop's bulk drafts upload).
  ~~Vinted bulk CSV~~ dropped: Vinted has no bulk upload (Liam, item 154, "drop it"); the
  Post this item page covers Vinted.
- [x] **Sold-elsewhere alarm (built in Phase 1 without ECA: confirmed cascade + "Needs you now"):** marking a platform "Sold here" sets every
  other Posted platform to "Take down" and puts it at the top of his dashboard.
- [x] Stale nudges: Posted 30+ days without selling, flag with a
  suggested price drop or "consider donating." (Built without ECA: worked out per platform from
  "Date posted": 30+ days about 10% off shown as the new price, 90+ days consider donating. The
  3 most overdue appear in "Needs you now" with Done / Snooze 2 weeks / Keep as is; all on
  /post/stale; a line on the item's listings; daily cron clean-up of old answers.)
- [x] Bulk actions on the inventory list: the Stockroom (/room/stockroom) lists every item; tick
  items, then mark posted on a platform, change the asking price (set, or lower by %), move to a
  storage location, or set the status (Listed, Kept, Donated). Confirm
  first, then "What changed". Built as a custom form (big touch targets, iPhone first) rather
  than Views Bulk Operations.

## Phase 4: tracking

- [x] Storage locations: a plain field on each item, shown as a path ("Game room > Table"),
  and one of the Stockroom filters. ~~QR labels, tagging and scanning~~: not doing (Liam,
  2026-10-15).
- [x] Stockroom search and filters: title search, brand, size, status, posted on a platform,
  location (including everything inside it), price range. Built in, no Search API or Facets
  (Liam, decision 178); each filtered view has its own link.
- [x] Money page in My office: sold by platform, "Earned after fees" (sale price minus fees and
  shipping) as the main number, profit only where "What I paid" was entered (redesign 2026-10-16),
  average days to sell (from Date sold), longest-sitting live listings, for this season /
  month / year / last year / all time. Real data only; plain labelled bars, no Charts module.
  The "This season" scoreboard and the office glance use the same numbers (Money).
- [x] Year-end tax export (My office): Sales CSV per calendar year (date sold, platform, sale
  price, fees, shipping, earned after fees, what I paid, profit, totals) and a Donations CSV
  (date donated from the item's history, estimated value entered by Cody, never guessed); a
  missing sale price is flagged in a Check column, a blank "What I paid" never is. Mileage and expenses join when Phase 5 adds them.

## Phase 5: daily life tools and the digest

- [x] Calendar wall with repeating events (built in, no Smart Date / Calendar View; decision 197):
  next 14 days first, then the month grid and list; events (once / weekly / monthly / yearly,
  optional time), bill due dates and game days shown automatically; the front desk tile shows
  what's next this week.
- [x] Bills and subscriptions with due dates and reminders: My office > Bills and subscriptions;
  each bill shows in "Needs you now" 3 days before it's due (and while overdue) with a Paid
  button that rolls it to the next due date (monthly bills keep their day of the month; one-offs
  are archived); business bills are logged in Expenses when paid.
- [x] Mileage and expense log (post office runs, supplies; packing materials are supplies, kept in the game room).
  My office > "Log a trip or an expense": quick-add forms ("Same as last time" for the regular
  trip), receipt photos (JPEG, no GPS, private), latest entries; Mileage and Expenses CSVs in the
  year-end tax export. Module ishop_life.
- [x] Private document vault (receipts, warranties, manuals), attachable to items: the
  Mailroom (photos or PDFs, several at once; photos lose GPS; private files only), filter by
  kind, "Documents" on each item page with "Add a receipt, warranty or manual", warranty end
  dates on the Calendar wall.
- [x] Notes and to-dos (Break room): to-dos with an optional date and a big Done button
  (Undo in "Recently done"); dated ones also show on the Calendar wall and, on the day or
  overdue, in "Needs you now"; notes with Edit/Delete.
- [x] **Daily digest email** at about 7 a.m. Pacific: items to post, take-downs,
  bills due, today's events, and a storage check (data disk
  mounted, free space on both disks; see DiskStatus in ishop_office). Short and readable on a
  phone. Needs a working mail transport (Symfony Mailer + an SMTP relay;
  ask Liam which, never store SMTP passwords in config).
  (Built 2026-10-15: drush ishop:digest, ishop-digest.timer 07:00; test to webmaster only sent;
  Cody's copy starts after Liam's OK, item 227; skipped on the birthday; off switch on Cody's
  account page.)
- **Moved to After go-live (Liam, 2026-10-20):** Enforce the content security policy (moved here from punch item 51 at
  Liam's request, 2026-10-15): it stays report-only until this phase is done;
  then, after a full week of normal use with a clean violation log, switch
  Security Kit's CSP from report-only to enforce.

## Phase 6: Amazon import and return windows (removed)

**Removed 2026-10-16: Cody sells household overstock already in the house; he is not
buying items to resell** (Liam and Cody; see the redesign decision). Built on 2026-10-15
and taken out the next day, before any real data used it.

- [x] ~~Return-by date, the holiday-returns setting, "Returned" and "Keeping it" buttons.~~
  Removed, with the item fields order ID, ASIN, purchase source (and its vocabulary),
  purchase date and the status "Returned to Amazon".
- [x] ~~Import from Amazon (/add/amazon) from the "Your Orders" export.~~ Removed: page,
  parser, routes and tests. Punch items 242 and 251 closed "Not needed".
- [x] ~~Return-window warnings in Needs you now and the digest.~~ Removed.

## Phase 7: AI listing (Cody's own Anthropic account)

- [x] Wait until Cody has his own Anthropic account with a monthly spend limit
  set in the Anthropic console. Never use his son's key. (Done: his own key, $32 account
  limit, saved and tested by Cody, item 224.)
- [x] AI module + Anthropic provider; key via the Key module: module ishop_ai, official
  Anthropic PHP SDK, /site-office/ai-setup for Cody (and webmaster) to save, test and remove his
  own key (file in secrets/, Key module "anthropic_api_key"; one tiny test call on save).
- [x] From an item's photos: identify the item, search the web for sold comps,
  fill title/description/brand/size/condition/category, set a price range with
  links to the comps shown under "Why this price." Match the son's pipeline in
  `app/ai_provider/claude.py`. Cody always reviews before saving. (Built: "Write my listing" on
  the item page and /add, background queue every minute, suggestion beside current values with
  ticks, Why this price with sources; queued while AI is paused or at the $25 monthly cap.)
- [x] Bulk photo drop: a pile of photos split into items by time taken (45-minute
  gap default), per the son's `import_inbox.py`. (Built: /add/bulk, linked from "Add item";
  the camera's time is read from each photo before its location data is stripped; Cody can
  change the gap, split or merge groups, then one draft item is made per group; "Also write
  each listing with AI" queues a run for each.)
- [x] Log token usage per item so cost is visible on the money page: every run records tokens,
  searches and cost; the Money page shows AI cost for the period (per item, highest first) and
  this month against the $25 cap; each item shows its AI cost so far.

## Phase 7.5: pricing redesign

From Liam's redesign brief (punch item 270, 2026-10-16), plan approved in chat with the order
below. Serves Cody's loop: photos, draft, check what it really sells for, post, sell, take
down elsewhere, ship. Replaces item 261's plan (decision 264). Not pursuing: eBay Marketplace
Insights API, any server-side eBay login.

- [x] **Check sold prices** on each item: Terapeak research in Seller Hub (Sold tab) for the
  identified item, opened on Cody's own signed-in phone (no eBay login or cookie ever stored on
  the server); fallbacks eBay sold search, Mercari and Poshmark sold filters. Back on the site:
  Lower / About right / Higher, or type the price; his choice is recorded. Done bfde524, 1466ce7
  (reports 2026-10-16-5); Cody's check is item 277.
- [x] **Price book:** every Sold here stores price, category and condition; "Why this price"
  shows "You sold N similar items for $X-$Y" first. Done 154ba2f (report 2026-10-16-6).
- [x] **Two-step AI:** cheapest current Claude model, one reduced-size photo: identifies the
  item, category (site list only), condition, title, description. Web search only above a
  value threshold ($15, a setting). Research reused for near-identical items. Cost per item
  measured; target a few cents. Done 265cccd (report 2026-10-16-8, decision 287): $0.0005 under $15,
  about $0.056 with the search.
- [x] **eBay as a platform** (Cody said yes, items 267/278; go in item 280): listing row, Sold-here
  cascade, nudges, bulk actions, Money page, tax export, eligibility and paste-ready text
  (from the son's surviving eBay formatter). Done 98ef039, report 2026-10-16-4.

## Phase 8: public catalog (built, switched off)

Liam's "Ok" on item 253 took every recommendation (decision 266). Module `ishop_shop`.

- [x] Public view of items with "show in shop" ticked, Ready or Listed, cleared to sell, with a price and a
  photo: /shop (photo cards, newest first, category filter) and /shop/{title-words-ID} (photos, title,
  description, flaws, size, condition, price). Buyers get **links to his live marketplace listings only**
  (Posted, with that platform's link): no contact form, his email address never shown. Public photo copies
  (1600 px, metadata stripped, unguessable names) exist only while the item is in the shop and the switch is on.
  Readable addresses, description/Open Graph tags and /shop/sitemap.xml are built in (no Pathauto, Metatag or
  Simple XML Sitemap: they would act on the private item nodes too).
- [x] One setting turns the whole catalog on or off (Site office > Public shop, Cody and Liam only). Default
  off: /shop, the item pages and the sitemap answer "Not found" and no public photos exist. While on, visitors
  to the front page go to /shop; signed-in Cody still lands on the Reseller Matrix. Stays off until Liam says.

## Phase 9: shipping and marketplace mail

(Numbered Phase 10 until 2026-10-18; older reports and code comments say "Phase 10 A/B/C".)

Approved by Liam 2026-10-16 ("build it, one report per part"). Built before the close-out's Stage B (now Phase 11), so its
reports and Cody's setup items still have the site office; close-out Stage A continues alongside.
All of Cody's marketplace accounts use the business Gmail.

- [x] **A. Shipping room** (/room/shipping-room): sold-and-unshipped items oldest first, each with
  its ship-by date (estimated from the platform's usual handling time until the real one is known),
  label PDF (private; deleted 30 days after delivery: it holds the buyer's address), weight and
  package size (Physical Fields 1.5) and packing note. Shipped records the tracking number (carrier
  worked out from the number; a scanned USPS barcode loses its ZIP prefix) and links to the
  carrier's own tracking page; Delivered finishes the sale, and the Money page shows delivered,
  on the way and to ship. Every step can be undone. "Needs you now" and the digest list sales to ship.
- **Moved to After go-live (Liam, 2026-10-20):** B. Marketplace inbox: IMAP read of the business Gmail (app password stored like the SMTP
  one; Cody enters it on Mail setup), only from known marketplace senders (eBay, Poshmark, Mercari,
  Depop, Grailed, Vinted, Facebook). Reads sold, offer, ship-by, label attached, shipping delay,
  return requested, case/dispute and payout emails: "Needs you now" items, labels attached to the
  matching item, sold emails start the Sold-here cascade after one tap from Cody. Other marketplace
  mail: "New message from X: open it". Stores only what is needed (no buyer addresses beyond the
  label), marks mail processed, never sends or deletes mail. Tested on real emails already in the
  inbox before it is switched on.
- **Moved to After go-live (Liam, 2026-10-20):** C. eBay Sell APIs through OAuth consent from Cody's own eBay account (developer account in
  Cody's name, set up from a punch item with plain steps): orders and ship-by, returns, cases.
  Revocable token stored like other secrets, with a renewal reminder before it expires. No
  passwords, cookies or page scraping, ever.

## Phase 10: bring in Cody's current listings

(Numbered Phase 11 when approved on 2026-10-18.)

Approved by Liam 2026-10-18 (punch 312, addendum 313). Built after Phase 9 A (Shipping room) and before Phase 9 B
(marketplace inbox) is switched on, so the marketplace inbox can match emails to his existing items. Site only: no
browser agent, no marketplace automation, nothing delisted or relisted.

- [x] **Import listings page** (My office, theowner and webmaster): upload the .xlsx or .csv as
  is, match columns (remembered for next time), match category/condition values, preview every
  item with its per-platform statuses and problems, then Import (one item per product). Sold rows
  imported as sold or skipped (Cody's choice). Buyer details never stored. Undo a whole import
  for 7 days. Optional fill-in from the old Crosslister records (photos where they exist).
- [x] Dry run of Cody's real spreadsheet through the preview, reported to Liam (counts per
  platform, sold total, problems).
- [x] Cody imports it himself from the page (punch item after Liam OKs the dry run). Done
  2026-10-18: 110 items (88 listed, 1 ready, 21 sold for tracking, no photos on sold items);
  row 37 (sold, no platform) left out. Spreadsheet notes wait in "Needs you now" until Done.
- [x] **Add photos** (Liam): each item has an Add photos page (several at once, up to 10, cover
  first) with "Save and go to the next item without photos"; every photo's stored file name
  carries its item number. Old Crosslister photos added where they survived (GitHub copy).
  A sold item's photos are deleted 30 days after delivery (return window; per platform).
- **Dropped (Liam, 2026-10-20):** Cody adds photos to the items that have none. Instead the Stockroom
  was emptied for go-live: import 1 undone and the 4 test drafts deleted (109 + 4 items, their photos,
  listings and sales). Cody took ALL his marketplace listings down and adds items fresh through the
  site. Backups: /var/backups/ishop/pre-empty-stockroom-20261020-1157.sql.gz and
  /srv/ishop-data/backups/pre-empty-stockroom-20261020-1157-files.tar.gz.

## Phase 11: close the site office and hand over to Cody

(Numbered Phase 9 until 2026-10-18; older reports, decisions and punch items call it "Phase 9".)

DRAFT (2026-10-15): written from Liam's one-line brief and TEMPORARY.md, because the earlier,
fuller specification was not available to the site foreman. Liam to replace or confirm.

- [ ] Clear TEMPORARY.md: every temporary feature switched off and removed as its section
  says (birthday mode, its song, email timer and code), until TEMPORARY.md is empty.
- [ ] Owner's guide for Cody: one page in the Reseller Matrix (adding items, posting,
  Stockroom, money and the tax export, bills, calendar, to-dos), written for the phone.
- [ ] Hand-over of accounts and services, as Liam decides: Cody's administrator account,
  who holds the Google Cloud project, the GitHub repository, the Gmail used for site mail,
  and the domain name (ohbah.com is shared).
- [ ] Final backup and a restore check, then the site office's records (plans, reports,
  punch list, decisions) exported for Liam and Cody to keep.
- [ ] Close the site office: its pages, the site_office role and the site-foreman account
  switched off; Claude's server access (SSH key, GitHub deploy key) removed.

## Handover countdown: finish the build, then close the site office

Liam's go, 2026-10-20 (crew-only punch items 628 and 629; Liam's message in the terminal is the
authority). Work in this order, one report per step.

- [ ] **1. Boot disk for the system only.** The 10 GB boot disk holds the OS and packages; everything
  that grows lives on the data disk (no bigger boot disk). Survey before and after (15 largest
  directories), clean what's safe, move /home, the swap file and build caches to the data disk
  (by UUID, nofail), cap journald at 200 MB. Site code and MariaDB: proposal only, Liam's OK first.
  Announced reboot test. Needs you now (crew) warns at 25% free on either disk.
- [x] **2. Phase 9.** Closed by Liam 2026-10-20; B and C moved to After go-live. B: inbox preview on Cody's real mail, tune, show Liam, switch on (Cody confirms
  each sale with one tap). C: eBay developer account + OAuth item for Cody; build after he does it
  (marked "waiting on Cody" if slow, never blocks handover). Wrap-up report, Liam's sign-off.
- [x] **3. Phase 10.** Closed by Liam 2026-10-20; Stockroom emptied for go-live. Last checklist item, wrap-up report, Liam's sign-off.
- [ ] **4. Birthday mode removed** (birthday was 10/19): one combined report of what ran, then remove
  it per TEMPORARY.md (audio, settings page, wake-up card, email job, CSP media-src, crew-only flags).
- **5. Moved to After go-live (Liam, 2026-10-20):** Phase 5: CSP enforced on or after 10/23 if the report log is clean.
- [ ] **6. Phase 11 close-out.** Stage A: Markdown archive (no secrets, no item 258), Cody's
  questionnaire answers, Mail setup + AI setup moved into My office, handover notes (plain for Cody,
  technical for Liam). Stage B after 5, 9, 10 signed off (done 10-20), birthday removed, and Liam told
  first so Claude (review) can check the archive: remove the site office and its leftovers; final check.

## After go-live: follow-ups

Not phases: early phases closed 2026-10-20 (Liam) so the site goes live with an empty Stockroom.

- [ ] **CSP enforced** on or after 10/23 if the report log is clean (punch 311).
- [ ] **Marketplace inbox (was 9 B):** uses the business Gmail app password the site sends with
  (fdf9ecd). Preview 10-20: 81 emails, all genuine; offers match, but sale emails get no title (no
  match), labels don't attach to sold items, and "other" mail is too noisy. eBay sale, ship-by,
  return and case emails must match their items (eBay is email-only). Fix, re-preview on new
  site-made items, show Liam, switch on.
- **eBay Sell APIs (was 9 C): DROPPED for now (Liam, 2026-10-20).** eBay rejected Cody's developer
  account (punch 633); the marketplace inbox covers eBay through its emails. Cody appeals (punch 637);
  if eBay approves, revisit: OAuth
  accept/decline pages and eBay's account-deletion notice endpoint, then the keyset item for Cody.

## Later, only if the business grows

Drupal Commerce + Commerce Stripe checkout. Do not build until Liam asks.
Selling directly makes Cody responsible for sales tax, returns, and shipping.

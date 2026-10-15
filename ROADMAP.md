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
- [x] Liam (in the Cloud console): daily disk snapshot schedule, 7-day retention.
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
  free space on both disks. Liam puts both disks on the snapshot schedule. Item photos are left out of the
  nightly private-files tarball.
- [x] **Item** content type, plus taxonomies and photos:
  - Fields: title (counter at 60 and 70, maximum 80), photos (Media, first one is the cover), description
    (counter at 1000), flaws/condition notes, brand, size (free text), color, category, condition,
    quantity (default 1), lifecycle status (Draft / Ready / Listed / Sold / Returned to Amazon / Donated /
    Kept), asking price, AI price range (min/max), original price, cost paid, purchase date, purchase
    source, order ID, Amazon ASIN, return-by date, weight, package size (L x W x H), storage location,
    internal notes, and "show in shop" (Phase 8). No Crosslister ID.
  - Only photos are required on the phone. A "ready to list" checklist shows what's missing.
  - Taxonomies: condition (6 terms), category (13 top-level terms), brand, storage location (empty until
    Cody answers), purchase source (Amazon).
  - Photos: Media "Item photo" in private files. On upload: HEIC/HEIF converted to JPEG, turned the right
    way up, metadata (GPS) stripped, long edge at most 2560 px. ImageMagick's policy.xml allows only
    JPEG, PNG, WebP and HEIC/HEIF, and keeps its resource limits.
- [x] **Platform listing** custom entity: one per item per platform (Poshmark, Mercari, Depop, Grailed,
  Vinted, Facebook). Fields: status (To post / Posted / Sold here / Take down / Not needed - sold /
  Posted - at risk / Not allowed), URL, listed price, date posted, sold price, fees, shipping paid by the
  seller, eligibility override and note.
  - Six "To post" rows on every new item, and a listings grid on the item page.
  - "Sold here" cascade, confirmed before it applies; un-marking restores the previous statuses.
- [ ] **Phone:** /add with "Take photos" / "Choose photos", "Take another" / "Done" after each camera shot,
  per-photo "Make cover" and up/down arrows, and optional quick fields. A custom web manifest so it
  installs to the home screen (no PWA beta, no offline cache). Tested on iPhone first: Cody uses
  Google Chrome on an iPhone (WebKit underneath).
- [x] **Tests and reports:** unit tests for the rules, rendering checks, and a build report per step.

## Phase 2: fresh start (no historical import)

Cody's existing inventory is already posted on the marketplaces. The site
starts from today; do NOT build a migration of the son's database. The
reference project is used only for its rules and formatting logic.

- [ ] Make adding an item fast enough that entering one he's already selling
  takes under a minute: photos optional, platforms pre-ticked "Posted,"
  listing URL paste field per platform.
- [ ] "Quick add from a live listing" form: paste a Poshmark/Mercari/etc. URL,
  enter title and price, done. No scraping of marketplace pages.
- [ ] Only if Liam later asks: a one-off import of the son's 112 items as
  "Posted" records so the sold-elsewhere alarm covers them.

## Phase 3: selling helpers

- [ ] Port eligibility rules: green/amber/grey chip per platform on every item,
  hover or tap for the reason.
- [ ] "Post this item" page: one tab per platform, every field cut to that
  platform's limits, copy buttons, photos resized per platform (image styles),
  ZIP download, Previous/Next through the to-post queue.
- [ ] Depop and Vinted bulk CSV exports (Views Data Export).
- [ ] **Sold-elsewhere alarm (ECA):** marking a platform "Sold here" sets every
  other Posted platform to "Take down" and puts it at the top of his dashboard.
- [ ] Stale nudges (ECA + cron): Posted 30+ days without selling, flag with a
  suggested price drop or "consider donating."
- [ ] Views Bulk Operations on the inventory list.

## Phase 4: tracking

- [ ] Storage locations with printable QR labels (Barcodes, Entity Print); a scan
  page that opens the item. Phone camera scanning via the browser.
- [ ] Search API (database backend) + Facets: brand, size, status, platform,
  location, price.
- [ ] Money page (Charts): sold by platform, profit after fees using the son's fee
  math, average days to sell, longest-sitting items.
- [ ] Year-end tax export: sales, cost, fees, profit per item, plus mileage and
  expenses. Donation log for unsold items with estimated values.

## Phase 5: daily life tools and the digest

- [ ] Calendar with recurring events (Smart Date, Calendar View).
- [ ] Bills and subscriptions with due dates and reminders.
- [ ] Mileage and expense log (post office runs, supplies; packing materials are supplies, kept in the game room).
- [ ] Private document vault (receipts, warranties, manuals), attachable to items.
- [ ] Notes and to-dos.
- [ ] **Daily digest email** at about 7 a.m. Pacific: items to post, take-downs,
  returns closing soon, bills due, today's events, and a storage check (data disk
  mounted, free space on both disks; see DiskStatus in ishop_office). Short and readable on a
  phone. Needs a working mail transport (Symfony Mailer + an SMTP relay;
  ask Liam which, never store SMTP passwords in config).

## Phase 6: Amazon import and return windows

- [ ] Upload Amazon's order history export (from Amazon's "Request your data").
  Each line becomes a draft item with cost, purchase date, order ID.
  Skip duplicates on re-import.
- [ ] Return-by date defaults to purchase date + 30 days, editable per item.
- [ ] Digest and dashboard warn 7 and 2 days before a return window closes:
  "return it or list it?"

## Phase 7: AI listing (Cody's own Anthropic account)

- [ ] Wait until Cody has his own Anthropic account with a monthly spend limit
  set in the Anthropic console. Never use his son's key.
- [ ] AI module + Anthropic provider; key via the Key module.
- [ ] From an item's photos: identify the item, search the web for sold comps,
  fill title/description/brand/size/condition/category, set a price range with
  links to the comps shown under "Why this price." Match the son's pipeline in
  `app/ai_provider/claude.py`. Cody always reviews before saving.
- [ ] Bulk photo drop: a pile of photos split into items by time taken (45-minute
  gap default), per the son's `import_inbox.py`.
- [ ] Log token usage per item so cost is visible on the money page.

## Phase 8: public catalog (built, switched off)

- [ ] Public view of items with "show in shop" checked and not sold: photos,
  description, price, and a contact form (Webform + Honeypot) or links to his
  marketplace listings. Pathauto, Metatag, Simple XML Sitemap.
- [ ] One setting turns the whole catalog on or off. Default off.
- Note (2026-10-15): item photos are private files, so the public catalog needs public derivatives
  (image-style copies) of the photos it shows.

## Later, only if the business grows

Drupal Commerce + Commerce Stripe checkout. Do not build until Liam asks.
Selling directly makes Cody responsible for sales tax, returns, and shipping.

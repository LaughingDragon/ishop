# Reseller Matrix: architecture

The Drupal 11 site at `/var/www/ishop` (`https://ishoponamazonwaytoomuch.ohbah.com`). It is a private resale-business
site with one real user, Cody (account `theowner`). Checked against the code, config and server on 2026-10-20
(git HEAD `9c525f5`; updated after the close-out prep cleanup: birthday mode, tests and dev packages removed). This document gives secret locations only, never their values.

---

## 1. Stack

| Layer | Version / detail |
|---|---|
| OS | Debian 13 (trixie, 13.7), kernel 6.12 cloud; GCE `ishopwaytoomuch`, us-central1-a, e2-medium. TZ America/Los_Angeles |
| Web server | Apache 2.4.69 + PHP-FPM. Vhosts: `/etc/apache2/sites-available/ishop.conf`, `ishop-le-ssl.conf` (Let's Encrypt, HSTS 1 day), `000-catchall.conf` (other names/bare IP → 301 to domain) |
| PHP | 8.4.26; ini overrides `/etc/php/8.4/{fpm,cli}/conf.d/90-ishop.ini` |
| Database | MariaDB 11.8.6, database `ishop`, user `ishop@localhost`, bound to 127.0.0.1 |
| Drupal | 11.4.10 (`drupal/core-recommended ~11.4.0`, so patch releases only) |
| Drush | 13.8 (`vendor/bin/drush`; `drush/drush.yml` sets the URI) |
| Host security | nftables (`/etc/nftables.conf`), fail2ban sshd jail, unattended-upgrades with a 04:00 auto-reboot, certbot.timer |

Project layout: Composer root `/var/www/ishop`; web root `web/`; config sync `config/sync` (529 files);
private files `private/`; secrets `secrets/`; ops scripts and unit copies `scripts/`.

### Contrib modules that matter

| Module | Why it is here |
|---|---|
| backup_migrate | Nightly DB and private-files backups to `private://backup_migrate/` (keep 14). Run by `ishop-backup.timer`, not cron |
| key | Holds the TFA encryption key and Cody's Anthropic API key with the file provider, so config stores only the path |
| encrypt + real_aes | Encryption profile `tfa`, used by TFA to encrypt users' TOTP secrets |
| tfa | Two-factor login (TOTP, `tfa_totp`). Enabled, but no role is required to use it |
| password_policy (+ _length, _username) | Policy `ishop_default`: at least 12 characters, must not contain the username, no expiry |
| flood_control | Admin UI for login flood limits |
| seckit | Security headers. CSP is in report-only mode, and violations are logged to watchdog type `seckit` |
| webform (+ webform_ui) | `ask_cody` (site-office style questionnaire) and `contact` |
| field_group | Groups fields on the item edit form |
| physical | `physical_dimensions` and `physical_measurement` fields for item size and weight (used for shipping) |
| imagemagick, image_effects, file_mdm (+ _exif, _font), sophron | Photo preparation: HEIC to JPEG, EXIF orientation, metadata strip, resize (`PhotoPrep`); sophron supplies MIME mapping |
| markdown_easy | Renders the site-office text format `office_markdown`. **Only ishop_office uses it** |
| gin, gin_toolbar | Admin theme |

Core modules worth knowing about: media + media_library (item photos), layout_builder, navigation (new admin nav;
`ishop_ui` puts back the View/Edit tabs that navigation hides), automated_cron (every 3 h), dblog, update.

Composer libraries used by custom code: `anthropic-ai/sdk` (ishop_ai), `openspout/openspout` (spreadsheet
reading for the listing import), `zbateson/mail-mime-parser` (ishop_inbox).

---

## 2. Custom modules (`web/modules/custom`)

Dependency graph from the `.info.yml` files:

```
ishop_office  (no custom deps)
ishop_items   -> ishop_office
ishop_life    -> ishop_items
ishop_ai      -> ishop_items, ishop_office, key
ishop_matrix  -> ishop_items, ishop_office, ishop_life, ishop_ai
ishop_inbox   -> ishop_items, ishop_matrix, ishop_office
ishop_shop    -> ishop_items, ishop_matrix
ishop_ui      -> block
```

Most code uses PHP-attribute hooks (`src/Hook/*`, `#[Hook]`), autowired services and Drush 13 attribute commands.
Unit tests were in each module's `tests/src/Unit`. They were removed from the server on 2026-10-20 (no dev packages there) and stay in git history: `git log --diff-filter=D --name-only -- 'web/modules/custom/*/tests'`, then `git checkout <rev>^ -- <path>` on a dev copy with `composer install` (dev) to run them.

### ishop_office: site office (build-time only; to be removed at close-out)

This is the private project office at `/site-office`: a status board, roadmap/phases, build reports, the punch list,
the decision log, and Cody's style questionnaire results. Access requires the permission `access site office` (role
`site_office`).

- **Classes:** `OfficeManager` (all writes are saved as the site-foreman account via account switching),
  `OfficeStructure` (creates the office bundles, fields and text format on install; constants `ROLE`, `CREW = [1, 2]`,
  `FORMAT`), `Vocabulary`, `Parser\{RoadmapParser, ReportParser, SecretScanner}` (SecretScanner refuses reports or
  answers that look like they contain secrets or email addresses), `Controller\OfficeController` (board, lists,
  `status.json`), `Form\AnswerPunchForm`, `Form\MailSetupForm`, `Plugin\Mail\IshopMail`, `DiskStatus`,
  `Theme\OfficeThemeNegotiator`, `EventSubscriber\CrewOnlySubscriber` (returns 403 for crew-only nodes even to
  administrators).
- **Drush:** `ishop:office`, `ishop:plans`, `ishop:report`, `ishop:phase`, `ishop:punch`, `ishop:punch-instructions`,
  `ishop:punch-close`, `ishop:punch-reopen`, `ishop:decision`, `ishop:crew-only`.
- **Hooks:** node_access_records / node_grants / query_node_access_alter / node_access (realms `ishop_office` and
  `ishop_office_crew`), node_view, preprocess_node, theme, block_access, page_attachments (adds noindex to office
  pages), **modules_installed (revokes every permission any module install gives to anonymous)**; `DiskHooks`
  implements `hook_ishop_needs` (a crew-only "disk under 25% free" line on the front desk).
- **Defines:** the hook `ishop_office_status_alter`.
- **Routes:** `/site-office`, `/site-office/reports`, `/site-office/punch-list`, `/site-office/decisions`,
  `/site-office/ask-cody/results`, `/site-office/screenshots/{name}`, `/site-office/status.json`,
  **`/site-office/mail-setup`** (`ishop_office.mail_setup`).
- **Content types:** `office_phase`, `office_plan`, `office_report`, `office_punch`, `office_decision`; webform
  `ask_cody`; text format `office_markdown`.

#### Things outside ishop_office that depend on it (must be moved before uninstall)

| Dependent | What it uses | Effect if ishop_office is removed as-is |
|---|---|---|
| **settings.php** (mail block) | Sets `system.mail` interface `default` and `webform` to **`ishop_mail`**, the plugin `ishop_office\Plugin\Mail\IshopMail` | **All outgoing mail breaks** (unknown mail plugin) |
| ishop_items | `DiskStatus::photosOnline()` in `AddController`, `AddItemForm`, `BulkAddForm`, `ItemPhotosForm`, `ItemHooks` | Fatal errors on `/add`, `/add/bulk`, `/item/{n}/photos` |
| ishop_matrix | `DiskStatus` (in `Digest` storage check), `IshopMail::problem()` (front-desk email alert in `MatrixController`), route `ishop_office.mail_setup` | Front desk and morning digest break |
| ishop_inbox | `MailSetupForm::DIR`, `::savedAddress()`, `::normalizePassword()`, renders `MailSetupForm` (`InboxController::mailSetup`); `RouteSubscriber` alters route `ishop_office.mail_setup`; `Inbox` links to it | Inbox credentials and the combined mail-setup page break |
| ishop_ai | Implements `hook_ishop_office_status_alter` (harmless without office); unit test uses `IshopMail::isAuthFailure()` | Only the `.info.yml` dependency blocks uninstall |
| ishop_ai, ishop_matrix, ishop_inbox, ishop_items `.info.yml` | Declare `ishop_office:ishop_office` | Drupal refuses to uninstall ishop_office until these lines are removed |
| `/site-office/*` setup pages owned by other modules (`ai-setup`, `public-shop`) | Sit under the office path, so `OfficeThemeNegotiator` renders them in the Construction theme | Without office they fall back to Reseller Matrix. They should get new paths |
| `/site-office/mail-setup` access | Needs the permission `access site office` plus the account check | Cody loses the mail setup page together with the role and permission |
| `scripts/ishop-update.sh` | Runs `drush ishop:report` and `ishop:punch`, and its post-update check expects `/site-office` to give anonymous a 403 | The weekly update's checks and reporting fail |
| Messages | Digest and ShopController text tell the reader to "see the site office board" / "Switch it in the site office" | Stale wording only |
| State keys | `ishop_office.mail_last`, `ishop_office.mail_last_ok` (written by `IshopMail`) | Need a new namespace if IshopMail moves |
| Security guard | `hook_modules_installed` that revokes anonymous permissions | Protection is lost; move it to a module that stays (for example ishop_ui) |

### ishop_items: items, platform listings, photos, shipping

This is the core of the business: items (node type `item`), one `platform_listing` entity per item per platform,
photo prep, posting helpers, sale cascade, shipping, stale nudges, the spreadsheet import, and the tax export.

- **Entity:** `platform_listing` (content, revisionable; `Entity\PlatformListing`, `ListingAccessControlHandler`,
  constraint `IshopUniqueItemPlatform`). Platforms: poshmark, mercari, ebay, depop, grailed, vinted, facebook.
- **Services:** `PhotoPrep`, `ItemPhotos`, `ListingManager`, `BulkActions`, `StockroomFilters`, `StaleNudges`, `Money`,
  `TaxExport`, `PriceBook`, `Shipping`, `ListingImport\ListingImporter`, `EventSubscriber\WebpTypeSubscriber`.
  Other classes: `Cascade` (when one platform sells, the others go to "take down"), `Cleared`, `Eligibility`,
  `PostPack`, `PlatformCategories`, `DepopBulk`, `ShipBy`, `Tracking`, `Stale`, `SoldSearch`, `ReadyCheck`.
- **Drush:** `ishop:import-preview`.
- **Hooks:** cron (deletes old shipping labels, deletes orphan /add photos after a day, deletes a finished sale's
  photos, cleans up stale nudges), entity_extra_field_info, file_presave (photo prep), form_node_form_alter,
  ishop_needs, node_insert / node_update / node_predelete, node_view, page_attachments, preprocess_page, theme.
  Defines `hook_ishop_sold_search_title_alter`.
- **Permissions:** `manage platform listings`, `import listings`.
- **Config:** `ishop_items.pricing`, `ishop_items.shipping`.
- **Routes:** see section 4 (`/add*`, `/item/{n}/post*`, `/item/{n}/photos`, `/post/*`, `/room/shipping-room*`,
  `/room/stockroom/sheet-notes*`, `/room/my-office/import-listings*`, `/room/my-office/tax/{y}/{sales|donations}`,
  `/manifest.webmanifest`, `/listing/{id}`).

### ishop_life: daily-life tools

Mileage and expense log, bills, calendar events, to-dos, notes, and the document vault (receipts, warranties). All of
these are nodes.

- **Services:** `LogBook`, `Bills`, `Calendar`, `Todos`, `Vault`; `LifeStructure` creates its bundles in code.
- **Controllers:** Log, Bills, BreakRoom, Mailroom. **Forms:** Bill, Document, Event, Expense, Mileage, Note,
  QuickAdd, Todo.
- **Hooks:** node_view. **Drush:** none.
- **Routes:** `/room/my-office/log`, `/room/my-office/tax/{y}/{mileage|expenses}` (the same path pattern as the
  items tax export; the two are separated by the `kind` requirement), `/room/my-office/bills`, `.../bills/{n}/paid`,
  `/room/break-room`, `/room/break-room/todo/{n}/{action}`, `/room/mailroom`.

### ishop_matrix: front of house

The dashboard (front desk), the seven-room main menu, My office (money, scoreboard, tax), the calendar wall, the
season settings and the morning digest.

- **Services:** `Digest`, `Season` (State); `Rooms` (room list).
- **Controllers:** `MatrixController` (dashboard, `needs()`, which collects `hook_ishop_needs`), `CalendarController`.
  **Forms:** `SeasonForm`.
- **Drush:** `ishop:digest`.
- **Hooks:** block_access, form_user_form_alter, mail, page_attachments_alter, preprocess_html,
  preprocess_page. Defines `hook_ishop_needs`.
- **Routes:** `/reseller-matrix` (the site front page), `/room/stockroom`, `/room/my-office`, `/room/calendar-wall`,
  `/room/{room}` (fallback / Coming soon).

### ishop_ai: AI listing help (Cody's own Anthropic account)

"Write my listing": queued Claude runs that suggest listing fields and a price range, plus a key setup page with
expiry reminders.

- **Services:** `AnthropicKey` (reads the key through the Key module), `AiKeyManager` (tests a new key before it
  replaces the old one; sends reminders), `AiStatus` (State `ishop_ai.status`), `ListingWriter` (prompt and output
  parsing), `AiRuns` (queue, pause, monthly cap). Custom table `ishop_ai_run`.
- **Forms:** `AiSetupForm` (accounts theowner and webmaster only), `SuggestionForm`. **Controller:**
  `WriteListingController`.
- **Drush:** `ishop:ai-run`, `ishop:ai-reminders`.
- **Hooks:** form alters on the add and bulk-add forms, ishop_office_status_alter, ishop_sold_search_title_alter, mail,
  node_view.
- **Routes:** `/site-office/ai-setup`, `/item/{n}/write-listing`.

### ishop_inbox: marketplace inbox (read-only IMAP; currently off)

This module reads marketplace emails from the business Gmail and turns them into "Needs you now" items.

- **Classes:** `Inbox` (check, preview, switch), `Imap\ImapClient` (allow-list of commands: CAPABILITY, LOGIN,
  EXAMINE, UID SEARCH, UID FETCH, LOGOUT; reads with BODY.PEEK, so messages stay unread), `MessageReader`
  (mail-mime-parser), `Senders` (a sender counts only if the From domain is a marketplace and DKIM/DMARC passed),
  `Classifier`, `Matcher`, `ListingRef`, `InboxCredentials`, `Routing\RouteSubscriber`. Custom table
  `ishop_inbox_message`.
- **Drush:** `ishop:inbox-check`, `ishop:inbox-preview`, `ishop:inbox-switch on|off`.
- **Hooks:** ishop_needs. **Routes:** `/inbox/{id}/{action}`. It also takes over the controller of
  `/site-office/mail-setup` to add `InboxSetupForm` under the sending form.

### ishop_shop: public catalog (switch currently off)

This module serves `/shop`, `/shop/{slug}` and `/shop/sitemap.xml`. Items appear when they are published, have
"Show in shop" ticked, are ready/listed, cleared, priced and have a photo. The routes are open (`_access: TRUE`) but
return 404 to everyone except theowner and webmaster while `ishop_shop.settings:enabled` is false (it is false
now).

- **Services:** `Shop`, `ShopPhotos` (public photo copies that exist only while the switch is on),
  `EventSubscriber\ShopSubscriber`. **Form:** `ShopSwitchForm` at `/site-office/public-shop`.
- **Hooks:** cron (photo sync), entity_insert / entity_update / entity_delete, theme. **Drush:** none.

### ishop_ui: interface tweaks

`UiHooks`: block_build_local_tasks_block_alter (keeps the View/Edit tabs visible, expanded and large) and
page_attachments. It has no routes, services or Drush commands.

---

## 3. Content model (from `config/sync`)

### Node types

| Type | Purpose | Fields |
|---|---|---|
| `item` | An item for resale | asking/original price, cost paid, AI price min/max, price check (+ at, + price), brand / category / condition / storage_location (taxonomy refs), color, size, description, flaws, internal notes, packing note, dimensions (physical), weight (physical), quantity, photos (media `item_photo`), item status (draft, ready, listed, sold, donated, kept), cleared to sell, show in shop, donation value |
| `mileage` | Mileage log | log date, miles, trip, log note |
| `expense` | Expense log | log date, amount, expense category, receipt (file), log note |
| `bill` | Repeating bill | amount, due date, due day, repeat, last paid, business flag, log note |
| `event` | Calendar event | event date, event time, due day, repeat, log note |
| `todo` | To-do | due, done, done on |
| `note` | Note | log note |
| `document` | Vault document | files, kind, linked item, valid until, log note |
| `office_phase`, `office_plan`, `office_report`, `office_punch`, `office_decision` | Site office only (removed at close-out) | markdown/summary/work done/next steps/blockers/tests/commits, phase refs, punch status, waiting on, instructions, answer, answered by, crew-only flag, etc. |

### Custom entity: `platform_listing` (ishop_items)

It is revisionable, with tables `platform_listing` and `platform_listing_revision`, and paths `/listing/{id}`
(+ `/edit`, `/delete`). Base fields: item (node ref), platform, status (to_post, posted, sold_here, take_down,
not_needed_sold, at_risk, not_allowed), url, listed_price, sold_price, fees, shipping_paid, date_posted, date_sold,
date_sold_unknown, eligibility_override and eligibility_note, sold_category and sold_condition (snapshot used for
the price book), cascade_sale, status_before_cascade, item_status_before_sale, ship_by, ship_by_estimated,
shipping_label (private file), carrier, tracking_number, date_shipped, date_delivered, created, changed.

### Taxonomies

`brand`, `category`, `condition`, `storage_location`, `tags`.

### Media types

`item_photo` (image + `field_taken_at`, used by items), plus the core defaults `image`, `document`, `audio`, `video`,
`remote_video`.

### Other storage

- Custom tables `ishop_ai_run` and `ishop_inbox_message`.
- Key/value: `ishop_items.listing_imports`, the stale-nudge collection.
- State: `ishop_ai.status`, `ishop_items.listing_import_memory`, `ishop_items.stale_cleanup`, `ishop_matrix.season`,
  `ishop_matrix.digest`, `ishop_office.mail_last(_ok)`.

### Webforms

`contact`; `ask_cody` (site office).

---

## 4. Key pages for Cody

The front page (`system.site:page.front`) is `/reseller-matrix`. The 403 page is `/user/login`.

| Path | Purpose |
|---|---|
| `/` (= `/reseller-matrix`) | **Front desk**: "Needs you now" (from `hook_ishop_needs`), room glances, scoreboard |
| `/room/stockroom` | Stockroom: item list with search/filter chips and bulk actions |
| `/room/stockroom/sheet-notes` | Notes to check before selling (Done per note) |
| `/room/shipping-room` | To ship / on the way: label upload, shipped (tracking), delivered |
| `/room/my-office` | Money, season scoreboard, tax section, season settings |
| `/room/my-office/log` | Mileage and expense log |
| `/room/my-office/bills` | Bills (mark paid) |
| `/room/my-office/tax/{year}/{sales\|donations\|mileage\|expenses}` | CSV tax exports |
| `/room/my-office/import-listings` | Spreadsheet listing import (with undo) |
| `/room/mailroom` | Messages and reminders |
| `/room/calendar-wall` | Events, bills, to-dos, game days |
| `/room/break-room` | Personal to-dos and notes |
| `/add`, `/add/bulk` | Add an item from the phone (photo-first); bulk add groups photos by time taken |
| `/item/{nid}/photos` | Reorder and add photos |
| `/item/{nid}/post`, `/item/{nid}/post/{platform}` (+ `photos.zip`) | Per-platform posting pack |
| `/item/{nid}/write-listing` | AI "Write my listing" suggestions |
| `/post/depop-bulk` (+ CSV, photos.zip) | Depop bulk upload files |
| `/post/stale` | Stale-listing nudges |
| `/listing/{id}` | A single platform listing |
| `/shop` | Public catalog (off; preview for theowner and webmaster) |
| **Setup:** `/site-office/mail-setup` | Gmail sending address and app password, plus the inbox section |
| **Setup:** `/site-office/ai-setup` | Anthropic API key (status, renew) |
| **Setup:** `/site-office/public-shop` | Public shop on/off |

Crew only: `/site-office/*` board pages.

---

## 5. Roles, accounts and permissions

| uid | Account | Roles | Notes |
|---|---|---|---|
| 1 | `webmaster` | administrator, site_office | Liam; superuser |
| 2 | `site-foreman` | site_foreman, site_office | Build-time account for Claude. **No password**, so no browser login. Drush runs with full rights anyway. **To be removed at close-out** (note that `OfficeStructure::CREW` hard-codes uid 2) |
| 3 | `theowner` | administrator, site_office | Cody; the only real site user |

| Role | Summary |
|---|---|
| administrator | `is_admin: true` (everything, including bypass node access). This is why office crew-only content also needs `CrewOnlySubscriber` |
| site_office | `access content`, `access site office`, `use text format office_markdown`. Remove at close-out |
| site_foreman | Broad site building (content types, fields, views, blocks, themes, modules, config sync, bypass node access, manage platform listings) but **not** users, roles or permissions. Remove at close-out |
| content_editor | Core default; no users |
| authenticated | access content, setup own tfa, delete own files, view media, basic_html / webform_default formats |
| anonymous | **No permissions** (the site is private; ishop_office's `modules_installed` hook enforces this) |

Several per-account checks use account names rather than roles: `MailSetupForm::ACCOUNTS`, `AiSetupForm::ACCOUNTS`
and `Shop::ACCOUNTS` are all `['theowner', 'webmaster']`.

---

## 6. Cron and systemd timers

The server clock is Pacific. Unit files are in `/etc/systemd/system/ishop-*`, with copies in
`/var/www/ishop/scripts/` (identical, except that **`ishop-backup.service`/`.timer` have no copy in scripts/**). The
Drush services run as `www-data`, oneshot, PrivateTmp.

| Timer | Schedule (Pacific) | Runs |
|---|---|---|
| `ishop-ai-queue` | Every minute | `drush ishop:ai-run` (queued AI listing runs) |
| `ishop-inbox` | Every 10 min (:00:30, :10:30, ...) | `drush ishop:inbox-check`. **It still fires, but the check returns at once while `ishop_inbox.settings:enabled` is false** |
| `ishop-backup` | Daily 02:15 | `drush php:script scripts/ishop-backup.php`: forces both B&M schedules (`nightly_db`, `nightly_private_files`, keep 14). The schedules' period is 52 weeks, so cron never runs them |
| `ishop-update` | Thu 02:30 | `scripts/ishop-update.sh` as liampward: composer update within limits, updb/cr/cex, checks, auto-rollback, site-office report |
| `ishop-digest` | Daily 07:00 | `drush ishop:digest` (morning email; only when switched on) |
| `ishop-ai-reminders` | Daily 07:05 | `drush ishop:ai-reminders` (API key expiry reminders) |

Drupal cron runs through **automated_cron every 3 hours** (interval 10800s). It runs the cron hooks of ishop_items
(label, orphan photo and sold-photo cleanup, stale nudges) and ishop_shop (photo sync), plus core/contrib cron.

System timers that matter: `apt-daily-upgrade` 03:30 (drop-in) followed by the unattended-upgrades auto-reboot at
04:00, `certbot.timer`, `logrotate`, `fstrim`, `phpsessionclean`.

---

## 7. Themes

| Theme | Use |
|---|---|
| `reseller_matrix` (custom, base stable9) | **Default front-end theme**: dark charcoal, red header, gold marquee, Bungee/Work Sans. Regions primary_menu (rooms), highlighted, content_above/content/content_below, footer. The README has a markup contract and `preview/` mockups |
| `gin` (+ gin_toolbar) | Admin theme |
| `construction` (custom, base stable9) | **Site office only**. `OfficeThemeNegotiator` applies it to every non-admin route under `/site-office` (including the setup pages), office node types and the `ask_cody` webform. Remove with ishop_office |
| olivero, claro, stable9 | Installed. Olivero and Claro are unused; stable9 is the base theme |

---

## 8. Disk layout

Two 10 GB GCE persistent disks. Device letters change between boots (the boot disk is currently `sdb`), so
everything is mounted by UUID/PARTUUID.

**Boot disk** (ext4 `/`, 9.7 GB, about 44% used): the OS and packages, the site code `/var/www/ishop`, MariaDB data
`/var/lib/mysql`, `/var/log`, and the private-file folders that are *not* bind-mounted (`private/receipts`,
`shipping-labels`, `vault`, `styles`, `office-screenshots`). The site and the database stay on the boot
disk on purpose, so the site still runs if the data disk is missing. Apache, PHP-FPM and MariaDB have no
`RequiresMountsFor` on the data disk.

**Data disk** (ext4, mounted at `/srv/ishop-data`, about 31% used), with marker file `.ishop-data-disk` (checked by
`DiskStatus`):

| On data disk | Bind-mounted to |
|---|---|
| `/srv/ishop-data/items` | `/var/www/ishop/private/items` (item photos) |
| `/srv/ishop-data/backups` | `/var/backups/ishop` (update/manual/pre-change dumps) |
| `/srv/ishop-data/backups/bm` | `/var/www/ishop/private/backup_migrate` (nightly B&M files) |
| `/srv/ishop-data/home` | `/home` |
| `/srv/ishop-data/swapfile` (2 GB swap) | (swap, not mounted) |

fstab approach:

- The root filesystem and the EFI partition are mounted by PARTUUID.
- The data disk is mounted by `UUID=` with `defaults,discard,nofail,x-systemd.device-timeout=10s`.
- Each bind mount and the swap file use `nofail,x-systemd.requires-mounts-for=/srv/ishop-data`, so a missing data
  disk does not block boot. With the disk missing, the bind targets stay as empty boot-disk folders, and
  `DiskStatus::photosOnline()` makes the photo upload forms refuse.

Off-server backup is GCE snapshots of the disks plus the
nightly local B&M copies.

---

## 9. Secrets (locations only)

All of these are outside the web root (`web/`) and git-ignored (`/secrets/` and `/private/` in `.gitignore`).
`/var/www/ishop` is `drwxr-s---` liampward:www-data. No secret is stored in config or the database.

| File | Purpose | Mode |
|---|---|---|
| `secrets/db.settings.php` | Database credentials, `require`d from settings.php | 0640 liampward:www-data |
| `secrets/hash_salt.txt` | Drupal hash salt, read by settings.php | 0640 |
| `secrets/tfa_encryption.key` | TFA encryption key (Key `tfa_encryption`, file provider, base64; used by the encrypt profile `tfa` / real_aes) | 0640 |
| `secrets/anthropic/api_key.txt` | Cody's Anthropic API key (Key `anthropic_api_key`, file provider), written by `/site-office/ai-setup` | 0640 www-data:www-data, dir 2770 |
| `secrets/mail/address.txt` | Gmail sending address | 0640 www-data:www-data, dir 2770 |
| `secrets/mail/app_password.txt` | Gmail app password (SMTP, and IMAP by default) | 0640 |
| `secrets/mail/inbox_address.txt`, `inbox_app_password.txt` | Optional separate inbox credentials (`InboxCredentials`). **Not present now** | written 0640 atomically |

Other protections:

- `secrets/` is 2750 (setgid www-data).
- `web/sites/default/settings.php` is 0440 in a 0550 directory. Editing it with `sed -i` drops the group and takes the
  site down.
- `private/.htaccess` denies all requests.
- The site-office `SecretScanner` blocks reports that look like they contain secrets.
- The setup forms never show saved values again; the AI key is displayed masked.

---

## 10. Mail

### Sending

- The Gmail SMTP override lives in `settings.php`. When both `secrets/mail/address.txt` and `app_password.txt` are
  readable and non-empty, settings.php overrides `system.mail` so that interface `default` and `webform` =
  `ishop_mail`, with mailer DSN `smtp://smtp.gmail.com:587` using that address and password. It also sets
  `system.site:mail` to that address (Gmail sends only as the signed-in account).
- Without those files, config falls back to `php_mail` / sendmail. A local exim4 listens on localhost:25 only.
- `ishop_mail` = `ishop_office\Plugin\Mail\IshopMail`. It extends core's `SymfonyMailer` and records the last result
  (time, ok, mail id, whether it was an auth failure; never the recipient) in State `ishop_office.mail_last(_ok)`.
  `IshopMail::problem()` puts "Email stopped working: renew the app password" on the front desk after a 535 auth
  failure. **Both live in ishop_office; see section 2.**
- Cody sets the credentials on `/site-office/mail-setup` (`MailSetupForm`, theowner/webmaster only). Saving sends a
  test email.
- Mail senders: ishop_matrix (digest), ishop_ai (key reminders), webform, and core (password resets).

### Marketplace inbox

- ishop_inbox reads `imap.gmail.com:993`. It is read-only by construction: `EXAMINE` plus `BODY.PEEK[]` and a command
  allow-list.
- It counts mail only from the seven marketplaces' domains with DKIM/DMARC passing. It classifies each message and
  matches it to a listing, then raises "Needs you now" rows.
- Credentials: it uses the sending address and app password unless separate inbox files are saved (via the inbox
  section of the mail setup page).
- **State on 2026-10-20: switched off** (`ishop_inbox.settings: enabled: false`). `ishop-inbox.timer` still runs
  every 10 minutes and `ishop:inbox-check` returns without connecting. Turn it on with `drush ishop:inbox-switch on`
  (records `enabled_since`) or from the setup page.

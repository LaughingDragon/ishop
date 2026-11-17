# Final state (2026-11-17)

What changed between the 2026-10-21 export and go-live, and how the site stands now. Details are in the reports dated 2026-11-12 and 2026-11-17.

## Since 2026-10-21

- **Running on its own (10/21 to 11/16).** Cody used the site daily. Nightly backups, the morning digest and mail ran every day. Cody added about 70 items.
- **Weekly update broke, fixed 11/12.** The 10/22 update (core 11.4.11) left scaffold files uncommitted, so 10/29, 11/05 and 11/12 stopped at the preflight check. Scaffold files are now committed and the script stages and rolls back `web/` (c32e3c5). The next run is Thursday 02:30 Pacific (punch 713 stays open until it succeeds).
- **Bounced admin email, fixed 11/12.** Update Manager mailed `admin@<site domain>`, which has no mail service. Alerts now go to Liam from `secrets/mail/alerts_to.txt`. The inbox lists delivery failures for crew only (eece4a6). Rule: never use an address at the site's domain.
- **AI queue stuck 11/16 12:48 to 11/17 06:43, fixed.** A queued draft for a deleted item crashed the runner every minute (string ID passed to an int parameter), so nothing behind it ran (b9a9c0c).
- **AI keys (ee8ca05).** Keys can have no expiry (the default). A daily 07:05 health check (one Haiku call, about $0.0003 a month) pauses AI and emails Cody once if the key or credit fails. The key test moved from Opus to Haiku.
- **CSP enforcing** (cb73fed). **Marketplace inbox on** (fbbb11b). **eBay API dropped** (decision 738). See [follow-ups.md](follow-ups.md).
- **Go-live look (ff67968).** The site office is crew only (webmaster, site-foreman). Cody's account sees no office link, reports or board. AI setup, Mail setup and the public-shop switch moved to `/room/my-office/...` in the Reseller Matrix theme. The "Coming soon / being built" card is gone. Error messages say "tell Liam".

## How it stands

- **Visitors:** the Reseller Matrix login page only. The public shop is off.
- **Cody (theowner, administrator):** front desk, Stockroom, Shipping room, My office (money, taxes, logs, bills, AI setup, Mail setup), Mailroom, Calendar wall, Break room.
- **Mail:** sends through the business Gmail (one app password, also used by the inbox). Alerts go to Liam. The morning digest goes to Cody at 07:00.
- **AI:** Cody's own Anthropic key, $32 monthly cap, daily health check.
- **Timers:** AI queue (every minute), inbox (every 10 minutes), digest (07:00), AI health check and reminders (07:05), backup (02:15), weekly update (Thursday 02:30), certbot.
- **Disks (11/17):** boot 4.7 GB free (49% used), data 5.7 GB free (42% used).
- **Still to do (Part 6, after Liam's go):** remove the site office and its leftovers (content types, Construction theme and negotiator, the site-foreman account and role, Claude Code hooks, crew shortcuts, leftover scripts, Webform if unused). Mail plugin, Mail setup form, DiskStatus, the anonymous-permission guard and the update script's reporting move out of `ishop_office` first ([architecture.md](architecture.md)).
- **One habit to change:** Cody has been deleting items when they sell instead of marking them sold, so those sales are missing from Money and taxes. The inbox's one-tap "Yes, mark sold" makes marking them easy.

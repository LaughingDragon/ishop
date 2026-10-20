# Technical handover note (Liam)

*DRAFT for review.* The detail is in [architecture.md](architecture.md) and [runbooks.md](runbooks.md); this is the one-page version.

## Server

- GCE VM `ishopwaytoomuch`, us-central1-a, e2-medium, static external IP. Debian 13, unattended-upgrades with a **04:00 Pacific** auto-reboot.
- Apache + PHP-FPM 8.4, MariaDB (127.0.0.1 only), Drupal 11.4 (patch releases only), Drush 13.
- Let's Encrypt via `certbot.timer`. HSTS is 1 day on purpose (the domain is shared; no includeSubDomains).
- nftables firewall (enabled at boot) and fail2ban (sshd, aggressive; `ignoreip` includes 172.253.0.0/16 for Google's browser SSH).
- Access: the browser SSH window in the Cloud Console, as `liampward`. Project: `/var/www/ishop` (git, remote `origin` on GitHub, deploy key `~/.ssh/ishop_github_deploy`). There's no dev code on the server: tests and dev packages stay in git history.

## Disks

- **Boot disk (10 GB):** OS, packages, site code, MariaDB, `/var/log` (journald capped at 200 MB). The site runs even without the data disk.
- **Data disk (10 GB, device name `ishop-data`, mounted by UUID at `/srv/ishop-data`, nofail):** `/home`, the 2 GB swap file, item photos (`private/items`), nightly backups (`private/backup_migrate`), manual dumps (`/var/backups/ishop`).
- Never identify a disk by device letter (`sda`/`sdb` swap between boots). Snapshot before any detach or delete.
- Crew-only "Needs you now" warns when either disk drops under 25% free. At close-out prep: boot 5.2 GB free, data 6.7 GB free.

## Snapshots and backups

- **GCE daily snapshots** of both disks (your schedule): the off-server copy (decision 268). The VM can't create snapshots (no Compute scope).
- **Nightly Backup and Migrate** at 02:15 Pacific (`ishop-backup.timer`): database + private files, keep 14 each, on the data disk.
- Restore steps: [runbooks.md](runbooks.md).

## Updates

- `ishop-update.timer`, Thursdays 02:30 Pacific: `composer update --no-dev` within limits, updb/cr/cex, checks, auto-rollback on failure. Logs in `/var/log/ishop-update/`.
- Minor core upgrades are manual.
- **Phase 11 Stage B dependency:** the script files reports and punch items on the site office and checks `/site-office` for a 403. Change it before or with the site office removal.

## Where secrets live (locations only)

| What | Where |
|---|---|
| Database credentials | `secrets/db.settings.php` |
| Hash salt | `secrets/hash_salt.txt` |
| TFA encryption key | `secrets/tfa_encryption.key` (Key `tfa_encryption`) |
| Gmail address + app password (send, and inbox read) | `secrets/mail/address.txt`, `secrets/mail/app_password.txt` |
| Cody's Anthropic API key | `secrets/anthropic/api_key.txt` (Key module, file provider) |
| GitHub deploy key | `~/.ssh/ishop_github_deploy` |

`secrets/` and `private/` are gitignored, owner liampward, group www-data, no world access.

## Accounts and access

- `webmaster` (uid 1, you) and `theowner` (uid 3, Cody), both administrators. `site-foreman` (uid 2, no password) is removed at close-out.
- No password, or locked out: `cd /var/www/ishop && vendor/bin/drush user:login --name=webmaster` (one-time link, valid 24 h).
- Private by default: anonymous visitors only get the login page. Registration is admin-only. Flood limits: 5 failed logins per account per 6 h, 20 per IP per hour (unblock in the Flood Control UI).
- TFA is installed but not required for any role (your decision; revisit any time).

## Timers (all Pacific)

`ishop-backup` 02:15 daily; `ishop-update` Thu 02:30; `ishop-digest` 07:00; `ishop-ai-reminders` 07:05; `ishop-ai-queue` every minute (AI drafts); `ishop-inbox` every 10 min (a no-op until the inbox is switched on). Unit copies are in `scripts/`.

## Open after go-live

[follow-ups.md](follow-ups.md): CSP enforcement on or after 10/23, inbox matching fixes, and the eBay appeal outcome by 11/19.

## Before removing the site office (Phase 11 Stage B)

Other modules depend on ishop_office (the full list is in [architecture.md](architecture.md), "Things outside ishop_office that depend on it"):

- **All outgoing mail** (the `ishop_mail` plugin).
- The **Mail setup** page and its permission.
- **DiskStatus** (the photo upload forms and the digest).
- The anonymous-permission guard.
- The `/site-office/ai-setup` and `/site-office/public-shop` paths.
- The update script.

Move those first, then remove the office.

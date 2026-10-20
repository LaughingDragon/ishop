# Runbooks

Server commands run in the browser SSH window (Compute Engine > VM instances > `ishopwaytoomuch` > SSH), from the project folder:

```bash
cd /var/www/ishop
```

`vendor/bin/drush` is Drush. It runs with full rights and needs no login.

---

## Renew Cody's AI key (every 30 days)

Anthropic API keys last 30 days. Cody gets reminder emails 7 days and 2 days before, and on the day before it expires (`ishop-ai-reminders.timer`, 07:05). If it lapses, AI pauses and everything else keeps working.

Cody does this himself, on the **AI setup** page (signed in as `theowner`), which lists the same steps:

1. Go to console.anthropic.com and sign in with **his own** account.
2. Open Settings > API keys > **Create key**, and name it "Reseller Matrix YYYY-MM".
3. Copy the key (it's shown once and starts with `sk-ant-`).
4. Paste it into **New Anthropic API key**. If Anthropic shows an expiry date, enter it; otherwise leave the default (30 days).
5. Tap **Test and switch to this key**. If the test fails, nothing changes and the old key keeps working.

The key is stored in a file in `secrets/anthropic/` through the Key module; the value is never shown. To check from the server: the AI setup page shows the last 4 characters, the expiry date, and the last success and error.

## Replace the Gmail app password (sending and inbox)

The business Gmail sends the site's mail (SMTP) and, once the inbox is switched on, is read by it (IMAP). **One Google app password covers both.** Replace it if Google revokes it, the Gmail password changes, or mail stops ("Needs you now" says so, and so does the mail line on the site office board).

1. Signed in to the business Gmail: https://myaccount.google.com/apppasswords. Create one named "iShop" (the name is just a label).
2. On the site: **Mail setup** (`/site-office/mail-setup` while the site office exists). In the **top** part, check the Gmail address, paste the 16 letters into **App password from Google**, and tap **Save and send a test email**. A test email arrives.
3. The inbox uses the same password automatically. To check it: in the **Marketplace inbox** part, leave the password box empty and tap **Test the inbox**.
4. Delete the old app password in Google afterwards.

The files are `secrets/mail/address.txt` and `secrets/mail/app_password.txt` (owner liampward, group www-data, mode 0640). An inbox-only password (`inbox_app_password.txt`) is optional and isn't used now.

## Restore from the nightly backup

Backup and Migrate runs at **02:15 Pacific** every night (`ishop-backup.timer`) and keeps 14 of each:

- Database: `private/backup_migrate/*.mysql.gz`
- Private files (photos, labels, receipts, vault): `private/backup_migrate/*.tar.gz`

They're on the data disk (`/srv/ishop-data/backups/bm`, bind-mounted at `private/backup_migrate`).

**Database:**

```bash
cd /var/www/ishop
ls -lt private/backup_migrate/ | head          # pick the file
vendor/bin/drush sql:dump --gzip --result-file=/var/backups/ishop/before-restore-$(date +%Y%m%d-%H%M).sql   # undo point
vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer
gunzip -c private/backup_migrate/FILE.mysql.gz | vendor/bin/drush sql:cli
vendor/bin/drush cr
vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
```

**Private files:** extract the `.tar.gz` into a scratch folder first, compare, then copy back what's missing (keep owner and group `www-data`). Don't extract over `private/` blind. The Backup and Migrate UI (`/admin/config/development/backup_migrate`) can also restore, signed in as webmaster.

Manual dumps taken before risky changes are in `/var/backups/ishop/` (also on the data disk).

## Restore from a Google Cloud snapshot

Both disks have **daily GCE snapshots** on Liam's snapshot schedule (decision 268). The VM can't make or restore snapshots itself (its service account has no Compute scope); this is done in the Cloud Console.

1. **Take a snapshot of the current disk first** (Compute Engine > Disks > the disk > Create snapshot), so the restore can be undone.
2. Identify the disk by **name, size and "In use by"**, never by device letter (`sda`/`sdb` swap between boots).
3. Compute Engine > Snapshots > pick the snapshot > **Create disk** (same zone, us-central1-a).
4. Stop the VM. On the VM's Edit page, detach the old disk and attach the new one. Boot disk: replace it under "Boot disk". Data disk: attach it with **device name `ishop-data`**.
5. Start the VM. The data disk is mounted by filesystem UUID, so a disk made from a snapshot of the data disk mounts on its own. A disk made from another source needs `/etc/fstab` updated (`blkid` shows the UUID).
6. Check: `findmnt /srv/ishop-data /home /var/www/ishop/private/items /var/backups/ishop`, `swapon --show`, the login page returns 200, and `vendor/bin/drush status`.

## Sign in without a password (`drush user:login`)

```bash
cd /var/www/ishop
vendor/bin/drush user:login --name=webmaster      # or --name=theowner for Cody
```

It prints a one-time link to the live URL (the URI is set in `drush/drush.yml`). Open it, then set a new password on the account page. The link works once and expires after 24 hours.

## Weekly updates

`ishop-update.timer` runs **Thursdays at 02:30 Pacific**: `scripts/ishop-update.sh` as liampward.

- It refuses to start unless git and config are clean and the data disk is mounted.
- Maintenance mode on, then a database dump to `/var/backups/ishop/update`.
- `composer update --no-dev` within composer.json limits (Drupal core gets patch releases only: `~11.4.0`).
- `drush updb`, `cr`, `cex`, then checks: Drush bootstrap, login page 200, site office 403 to anonymous.
- **Pass:** commit and report. **Fail:** restore composer files, vendor and database, and open a punch item for Liam.
- Logs are in `/var/log/ishop-update/`. If something looks wrong: `systemctl status ishop-update.service` and read the newest log.
- Core **minor** upgrades (11.5 and later) are a manual job in a work session: change the constraint, `composer update --no-dev`, `drush updb`, test, commit.

After the site office is removed (Phase 11 Stage B), the script's reporting and its `/site-office` check have to change, because it files reports and punch items there.

## If the data disk is missing

The site and database are on the boot disk on purpose, so **the site still runs** without the data disk. What stops:

- Photo uploads: the add and photo forms refuse, because `DiskStatus::photosOnline()` checks the marker file `.ishop-data-disk`. Existing photos don't show either: they live on the data disk.
- Nightly backups and the weekly update (which refuses to start).
- Swap (2 GB swap file on the data disk).
- `/home`: the bind mount from the data disk is missing, so `/home/liampward` is an empty boot-disk folder. The browser SSH window still works (Google's guest agent sets up keys), but the files in the home folder aren't there.

Fix:

1. In the Cloud Console, check the VM's disks: the 10 GB data disk should be attached with device name `ishop-data`. If it's detached, attach it. **Snapshot before any detach or delete.**
2. On the VM: `lsblk -f` (look for the ext4 data disk by size and UUID), then `sudo mount -a` and `findmnt /srv/ishop-data`.
3. Then `sudo systemctl restart` anything that failed (`systemctl --failed`), or reboot once.
4. If the disk is lost: create a disk from the newest data-disk snapshot (see above).

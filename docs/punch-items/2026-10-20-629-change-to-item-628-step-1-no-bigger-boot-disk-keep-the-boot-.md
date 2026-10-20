# 629: Change to item 628 step 1: NO bigger boot disk. Keep the boot disk for the system only

- Opened: 2026-10-20
- Waiting on: site_foreman
- Status: open
- Crew only (hidden from Cody on the site)

## Instructions

From Liam via Claude (review), 2026-10-20. Crew only. Replaces item 628 step 1 (the rest of 628 stands).

Liam will NOT pay for a larger boot disk. Do not offer console steps to grow it. Layout rule from now on: the 10 GB boot disk holds the operating system and installed packages only; everything that grows lives on the 10 GB data disk (ishopway2muchdata, about 9.8 GB free). This is how Liam runs his other Debian servers.

Step 1, in order:
a) Report the 15 largest directories on / (du -xh), before and after.
b) Clean what's safe (as in 628: apt cache, old kernels, caches, leftover ZIPs and spreadsheet copies, old backup folders on the boot disk).
c) Move to the data disk, each as its own mount or bind mount by UUID, with nofail:
   - /home (includes Claude Code's data and the reference repo)
   - the swap file
   - composer, npm and other build caches
   - /var/backups/ishop and private files (photos), already there: confirm
   - /var/log: journald capped at 200 MB (SystemMaxUse) and logrotate compressing, staying on the boot disk is fine if that keeps it small; only move logs if they're still large after capping, and make sure logging still works if the data disk is missing.
d) The site code (/var/www/ishop) and the MariaDB data: propose to Liam whether to move them too. If you do, the failure from the disk incident must be impossible: remove the old copies from the boot disk entirely (no stale copy that could quietly start), and give apache2/php-fpm/mariadb a systemd RequiresMountsFor on the data disk so they refuse to start without it and status.json/your alert says so loudly. Wait for Liam's OK on (d) only.
e) Verify with a reboot test (announce it to Liam first): everything comes back, data disk mounted, site up, then report free space on both disks.
f) Early warning in Needs you now (crew) at 25% free on either disk.
Update the technical handover note with the final layout.

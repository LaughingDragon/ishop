# Working rules for agents on this project

Short rules for any agent (Claude Code or otherwise) working on the ishop site.
The running log with history and locations is ~/ishop/NOTES.md on the VM.

## Every session
- Start with `vendor/bin/drush ishop:office` (status board and open punch list), then **read every punch item
  answered or closed since your last build report and act on those answers first** (Liam's rule, 2026-10-15).
- One commit per change; run `drush cex` first. Never commit secrets (secrets/ and private/ are gitignored).
- End with a build report: `drush ishop:report file.md --dry-run`, then for real.
- Push git to the private remote at the end of every session, once it is set up.

## Punch list
- "Waiting on": `site-foreman` for server work, `claude` (Claude (review)) only for browser reviews or judgment.
- Every item waiting on Liam, Cody or Claude (review) carries full step-by-step instructions ending with "How to answer".
  Check facts on the VM before writing steps.

## Who does what
- **Liam does design and review only.** Punch items for Liam never ask him to add items, take photos or otherwise use
  the site as a seller. Real-use checks (adding items, the phone flow) go to **Cody**; Liam reads, decides, signs off.

## Destructive actions
- Nothing destructive without Liam's OK.
- **Disks:** any instruction that detaches or deletes a disk starts with a snapshot step, and names the disk by
  **name, size and "In use by"** (as shown in Compute Engine → Disks). Three disks once had near-identical names
  (ishop2much-data, ishopway2much-data, ishopway2muchdata), and the live one was deleted (incident, 2026-10-15).
- Before disk or database work, have a fresh database dump somewhere other than the thing being changed.
- Never run a script that unmounts or replaces a folder from inside that folder.
- **settings.php:** never edit it with `sed -i` (it replaces the file and drops the www-data group, so the web
  server can't read it and the site goes down). Edit in place with Python (or restore `chgrp www-data` at once).

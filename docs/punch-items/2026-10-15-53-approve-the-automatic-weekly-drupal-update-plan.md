# 53: Approve the automatic weekly Drupal update plan

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Liam's answer on the updates item: he checks weekly, and the server should run updates, upgrades and Composer updates of all modules and dependencies on a schedule.

## Instructions

**Who:** Liam. **Time:** 5 minutes to read and decide.

You asked for the server to run updates on a schedule. Here's what already happens and what I propose to add. Nothing below is built yet.

### Already automatic

- **Debian packages:** OS, Apache, PHP, MariaDB, certbot. Unattended upgrades every night at about 03:30 Pacific, rebooting at 4 a.m. if needed.
- **HTTPS certificate:** renews automatically (certbot.timer).

### Proposed: weekly Drupal and module updates

A systemd timer, **Thursdays at 03:00 Pacific**. Drupal security releases come out on Wednesdays (US time), so this catches them the night after. It runs as the project owner and does:

1. A fresh database dump, plus a git snapshot of `composer.lock` and config.
2. `composer update --with-all-dependencies` **within the version limits in composer.json**. Contrib modules take bug-fix and minor updates. Drupal core takes **patch updates only** (for example 11.4.10 → 11.4.11): core minor versions (11.4 → 11.5) are left for a work session, because they sometimes need attention. To enforce that, I'd tighten core's limit from `^11.4` to `~11.4.0`.
3. `drush updb` (database updates), `drush cr`, then `drush cex`, so any config changes from updates are captured.
4. **Checks:** the site boots, the login page returns 200, `/site-office` still returns 403 to anonymous visitors, the unit tests pass, and `composer audit` is clean.
5. **If all pass:** commit to the local git repo and file a build report in the site office ("Automatic update: …").
6. **If anything fails:** restore the previous `composer.lock` and vendor folder and the database dump, so the site is back as it was, and open a punch list item for you with the log.

**Your weekly check** stays as a backstop: glance at the site office on Thursday for the update report, or a punch list item if it failed.

**Major upgrades** (Drupal 12, Debian 14) are never automatic. They're planned as their own task.

**How to answer:** "Approved", or changes (day/time, include core minor updates or not, anything else). Once approved I'll build it in a work session, test it once by hand with a forced failure, and report.

## Answer

Approved as proposed (Liam, in chat, 2026-10-15: "I approve all builds").

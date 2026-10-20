# 32: Who checks Drupal core and module security updates, and how often?

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Unattended upgrades cover Debian packages only. Drupal and its modules are updated with Composer (see What to do).

## Instructions

**Who:** Liam. **Time:** 2 minutes to decide.

Debian's automatic updates cover the operating system, Apache, PHP and MariaDB. They do **not** cover Drupal itself or its modules. Those need a person (or Claude) to check and apply them.

### Options

- **A. Claude checks at the start of every work session.** It runs the checks, applies any security update, runs tests, commits, and notes it in the build report. No work for you, but nothing is checked between sessions.
- **B. A fixed weekly check, every week.** Drupal security releases normally come out on Wednesdays (US time). Whoever does it runs the commands below.
- **A + B together** (my suggestion): Claude checks in every session, and you run the one-line check weekly if there's been no session that week.

### The weekly check (on the VM)

```
cd /var/www/ishop && composer audit
```

"No security vulnerability advisories found" means you're done. Otherwise, ask Claude to apply the update, or run:

```
composer update "drupal/*" --with-all-dependencies && vendor/bin/drush updb -y && vendor/bin/drush cr
```

**How to answer:** "A", "B, on (day)", or "A + B".

## Answer

Me Webmaster once a week. We should have the server run updates, upgrades, and composer all modules and dependenices on a schedule

# Temporary things on the site

Anything added for a limited time, and how to remove it cleanly. Remove each
entry from this file when it's gone.

## Birthday mode (punch item 135, part 1; added 2026-10-15)

Cody's birthday celebration on the Reseller Matrix pages. **Crew only until
the day:** Cody must not see it early (Liam), so its punch item, reports and
settings page are hidden from his account.

**How it works**
- On automatically all day on the birthday (Pacific time), for everyone
  signed in. The date and an on/off/auto switch are at
  `/site-office/birthday` (webmaster and site-foreman only, by account).
  "Preview it for me only" shows it to the account that ticks it.
- Settings live in State (`ishop_matrix.birthday`), not config, so switching
  never touches git. Read them with
  `drush ev 'print_r(\Drupal::service(\Drupal\ishop_matrix\Birthday::class)->settings());'`.
- The first page each account opens on the day launches the party (plays at
  once where the browser allows, otherwise a full-screen sign waits for one
  tap; iPhone Safari always needs the tap). Once per account per day.
- The song is `private/birthday/cody-birthday.mp3` (private files: not in
  git, not public), served only to accounts with the mode on, from
  `/birthday/song.mp3`.
- **Cody's requested video** (added 2026-10-15, his own request; it does not
  replace the song): `Birthday::VIDEO_URL`. Noted on the settings page. On
  the day, a dashboard card "Your requested wake-up call" below the birthday
  sign links out to YouTube in a new tab. No embed, no CSP change.
- **Birthday email** to Cody's account email at 7:00 a.m. Pacific on the day,
  once, through the site's Gmail (logged like all mail by `ishop_mail`).
  Sends only if the subject and text on the settings page are saved and
  "Approved" is ticked. Webmaster can send a test to webmaster only.
  `scripts/ishop-birthday-mail.{service,timer}` (installed in
  /etc/systemd/system, enabled) run `drush ishop:birthday-mail` daily;
  `drush ishop:birthday-mail --check` says whether it would send now.

**Files**
- `web/modules/custom/ishop_matrix/src/Birthday.php`
- `web/modules/custom/ishop_matrix/src/BirthdayMail.php`
- `web/modules/custom/ishop_matrix/src/Controller/BirthdayController.php`
- `web/modules/custom/ishop_matrix/src/Form/BirthdayForm.php`
- `web/modules/custom/ishop_matrix/src/Drush/Commands/BirthdayCommands.php`
- `web/modules/custom/ishop_matrix/css/birthday.css`
- `web/modules/custom/ishop_matrix/js/birthday.js`
- `web/modules/custom/ishop_matrix/tests/src/Unit/BirthdayTest.php`, `BirthdayMailTest.php`
- `scripts/ishop-birthday-mail.service`, `scripts/ishop-birthday-mail.timer`
- `web/modules/custom/ishop_matrix/ishop_matrix.services.yml`: the Birthday,
  BirthdayMail and logger.channel.ishop_matrix entries (keep Season).
- Marked `TEMPORARY (birthday mode)` in: `ishop_matrix.routing.yml` (2 routes),
  `ishop_matrix.libraries.yml` (`birthday`), `src/Hook/MatrixHooks.php`
  (`isParty`, `pageAttachments`, `mail`, `pageBottom`, the body class, the
  motto), `src/Controller/MatrixController.php` (the hero, the video card,
  "Needs you now" text, cache metadata).

**After the birthday**
1. `/site-office/birthday`: set "Off, even on the birthday" (or
   `drush ev '$b=\Drupal::service(\Drupal\ishop_matrix\Birthday::class); $s=$b->settings(); $s["mode"]="off"; $s["preview"]=[]; $b->save($s);'`).
2. Stop the email timer: `sudo systemctl disable --now ishop-birthday-mail.timer`,
   then `sudo rm /etc/systemd/system/ishop-birthday-mail.{service,timer} && sudo systemctl daemon-reload`.
3. Delete the song: `rm /var/www/ishop/private/birthday/cody-birthday.mp3 && rmdir /var/www/ishop/private/birthday`
   (also in that night's private-files backups, which age out after 14 days).
4. Remove the files and marked code above, then `drush state:delete ishop_matrix.birthday`,
   `drush cr`, tests and phpcs, commit.
5. Keep (permanent, not birthday-specific): the crew-only office nodes
   (`field_crew_only`, `drush ishop:crew-only`), the `wordmark ??=` line in
   `reseller_matrix.theme`, CSP `media-src 'self'`, and the football `Season`.
6. Delete this section.

# 35: Tell Cody his username is now theowner

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

His password did not change.

## Instructions

**Who:** Liam. **Time:** 2 minutes.

Cody's username changed today from **The Owner** to **theowner** (all lower case, no space). His password did **not** change.

1. Send Cody a message like this:

   > Your login for the shop site has changed. Go to https://ishoponamazonwaytoomuch.ohbah.com/user/login. Username: **theowner** (all lower case, one word). Your password is the same as before.

2. **If he has forgotten his password**, make him a one-time login link. On the VM, run:
   ```
   cd /var/www/ishop && vendor/bin/drush uli --name=theowner
   ```
   Send him the link it prints, by text or message. It works **once** and expires in 24 hours. After he opens it, he sets a new password on the page it shows and presses **Save**. Never send or store the password itself.

**How to answer:** "Told him", plus "sent a login link" if you did.

## Answer

Done

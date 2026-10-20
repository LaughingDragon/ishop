# 29: Choose an outgoing mail service for the daily digest

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 5 (Phase 5: daily life tools and the digest)

## Details

Symfony Mailer plus an SMTP relay. Its password will go in the Key module, never in config.

## Instructions

**Who:** Liam. **Time:** 5 minutes to decide; setup comes later in Phase 5. **Needed by:** Phase 5 (the daily digest email).

The site has to send Cody a short email each morning. Google Cloud blocks ordinary outgoing mail (port 25), so the site needs a mail service it can log in to on port 587.

### Options

| Option | Cost | Setup | Good for |
|---|---|---|---|
| **A. A Gmail account + app password** | Free | A Google account with 2-Step Verification, then an "app password" | A few emails a day to Cody. Simplest. |
| **B. Brevo, Mailgun, Postmark or SendGrid** | Free tier or a few dollars a month | Sign up, then add DNS records for a sending domain | More reliable delivery and logs. Needs a domain you control the DNS for. |
| **C. Amazon SES** | Pennies | AWS account, domain checks, request to leave its sandbox | Only if you already use AWS. |

**My suggestion:** A for now. It's only one email a day to one person. We can switch to B later.

### What to do

1. Pick A, B or C.
2. For A: decide which Gmail account sends the email. Use a new one just for the site, not anyone's personal account.
3. **Don't** create or send any password yet. When we reach Phase 5, I'll give you one command that stores it in a file only the web server can read. It never goes in the site office, chat or git.

**How to answer:** the option and the details, for example "A, sending from (the address) via smtp.gmail.com port 587". Including the sending address is fine: after you press Save, a box appears, **"This email address is ours"**. Tick it and press **Save answer and close** again. Never include the password or app password.

## Answer

Option A

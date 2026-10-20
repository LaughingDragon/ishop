# 124: Set up the site's email: Gmail 2-Step Verification and an app password

- Opened: 2026-10-15
- Waiting on: cody
- Status: closed
- Closed: 2026-10-15
- Answered by: cody
- Phase: 5 (Phase 5: daily life tools and the digest)

## Details

Punch item 116 (Liam: proceed). Cody creates the app password and pastes it into /site-office/mail-setup himself; it's stored only in secrets/mail on the server. The page sends a test email.

## Instructions

**Who:** Cody. **Time:** about 10 minutes. Your iPhone in Safari is fine. Use your **business Gmail** account, the one the site should send email from.

**Why:** right now the site can't send any email, not even a password reset. An "app password" lets the site send email from your Gmail without knowing your real Gmail password.

**Keep it private:** the app password goes **only** into the site's Mail setup page (step 8). Don't text it, email it, screenshot it, or type it into a punch item, not even for Liam or Claude.

### A. Turn on 2-Step Verification (skip if it's already on)
1. Go to **myaccount.google.com** and sign in to your business Gmail.
2. Tap **Security** (it may say **Security & sign-in**).
3. Under **How you sign in to Google**, tap **2-Step Verification**, then **Turn on**, and follow the steps. A text-message code to your phone is fine.

### B. Make an app password
4. Go to **myaccount.google.com/apppasswords** (same account). If Google says the page isn't available, step 3 isn't finished yet.
5. For the app name, type **Reseller Matrix site**, then tap **Create**.
6. Google shows **16 letters** in a yellow box. Tap and hold to **copy** them. Leave that page open for now.

### C. Save it on the site
7. Open **ishoponamazonwaytoomuch.ohbah.com/site-office/mail-setup** and log in if asked.
8. Type your **Gmail address** carefully, then **paste** the 16 letters into **App password from Google**. Tap **Save and send a test email**.
9. Check your Gmail inbox for **"Reseller Matrix: mail is working"**. Then go back to Google's page and tap **Done**. The letters disappear for good; that's normal.

**If the page says Google refused it:** check the address for typos. Then in **myaccount.google.com/apppasswords**, delete **Reseller Matrix site**, make a new one, and save again.

**How to answer:** type **Done, test email arrived**, or copy what the Mail setup page said. Never the app password; the site refuses to save anything that looks like one. Then tap **Save answer and close**.

## Answer

done

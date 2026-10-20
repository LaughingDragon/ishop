# 224: Connect your Anthropic account: save your API key on the AI setup page

- Opened: 2026-10-15
- Waiting on: cody
- Status: closed
- Closed: 2026-10-15
- Answered by: cody
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

## Details

Phase 7 step 1 (Liam, now while Cody is awake). The page saves the key privately (secrets file, Key module) and makes one tiny test call. Follows item 27 (account and $32/month limit already done).

## Instructions

**Who:** Cody. **Time:** about 5 minutes. A computer is easiest (copying the key), but the iPhone works too.

You already have your own Anthropic account with a $32 monthly spend limit (item 27). Now the site needs a key to use it. The **AI setup** page below is the one safe place to put it: it goes straight into a private file on the server and is never shown again. Never put a key in a punch-list answer, an email or a text.

1. Go to **console.anthropic.com** and sign in with **your own** account (never your son's).
2. Open **Settings → API keys** (sometimes just **API keys** in the menu) and choose **Create key**. Name it **ishop site**.
3. Anthropic shows the key **once**. It starts with **sk-ant-**. Tap **Copy**.
4. In another tab, go to **ishoponamazonwaytoomuch.ohbah.com/site-office/ai-setup** and log in if asked.
5. Paste the key into **Anthropic API key** and tap **Save and test the key**.
6. You should see **Key works (sk-ant-…1234)**, with the last 4 characters of your key.
   - If it says the account **has no credit**: add credit in the console (**Settings → Billing**), then come back and tap **Test the saved key**.
   - If it says the key **wasn't accepted**: create a new key (step 2) and paste that one.
7. Close the console tab. You don't need to keep the key anywhere else; if it's ever lost, just create a new one.

**How to answer:** type **Key works**, or copy the message the page showed (the page only ever shows the last 4 characters, so that's safe). **Never paste the key itself here**: the answer box refuses anything that looks like a key. Then tap **Save answer and close**.

## Answer

Key Works

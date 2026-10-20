# 633: eBay said no to your developer account: one appeal

- Opened: 2026-10-20
- Waiting on: cody
- Status: open
- Phase: 9 (Phase 9: shipping and marketplace mail)

## Instructions

eBay turned down your developer account. Its message said: *"Your account registration was rejected due to problems with the data provided or other irregularities. If you believe this rejection is in error, please see our FAQs and Contact Channels page or work with your eBay representative directly."* It doesn't say which detail it didn't like. You get **one appeal**. If eBay still says no, or doesn't answer within 30 days, we drop the eBay connection, and nothing on the site changes for you: eBay stays on your items as it is now. About 15 minutes.

### Before you start

1. **Find eBay's rejection email.** It isn't in the business Gmail. Search your other email for **"Developers Program"** or **"registration was rejected"**. If you find it, keep it open: you'll need its **reference or case number** (if it has one) and you may be able to **reply** to it. If there's no email (eBay only showed the message on screen), that's fine: use the form in step 5.
2. **Check your eBay details.** In the eBay app, open **My eBay > Account > Personal information** (on ebay.com: **Account settings > Personal information**). Write down exactly how your **name**, **address** and **phone** are written, and your **eBay username**. The most common cause of this rejection is the developer form not matching your eBay seller account, for example a nickname, a different address, or a business name.
3. **Turn off any VPN**, and use your home internet, not public wifi.

### Where to send the appeal

4. **If you found the rejection email** and it says you can reply: reply to it with the text below.
5. **Otherwise** (or if replying isn't allowed): open **https://developer.ebay.com/support/developer-account-support**, choose **Contact Us**, and pick the option for a **rejected registration** (or "account registration"). Fill in your name, address, phone and the email you registered with **exactly** as in step 2.

### The appeal text (copy, fill in the two blanks, paste)

> Subject: Appeal: Developers Program registration rejected (reference: ____ )
>
> Hello. My eBay Developers Program registration was rejected for "problems with the data provided or other irregularities". I believe this is an error and ask for a manual review.
>
> eBay username: ____
> What the tool is: a private inventory and shipping site for my own small resale business, used only by me.
>
> I am an individual eBay seller. I want to use the Sell APIs (Fulfillment, Post-Order) with OAuth consent from my own seller account only, to read MY OWN orders, ship-by dates, returns and cases, so I don't miss shipments or returns. One user: me.
>
> - No Buy APIs, no searching or collecting listings.
> - No other users' or sellers' data. Only my own account, with my consent.
> - No reselling or sharing of any data.
> - Data is stored privately on my own server, only what's needed to ship and track my sales.
>
> The details on my registration match my eBay account. I'm happy to answer any questions or confirm my identity. Thank you.

Fill the reference blank with the number from the email, or delete "(reference: ____ )" if there isn't one. Fill your eBay username.

### If eBay asks questions

- **Answer honestly and briefly**, in your own words, using the points above. Everything in the appeal is true; don't add anything that isn't.
- If they ask **how the tool works**: it's a website on your own private server; it signs in to eBay only through eBay's own "allow this app" page (OAuth), never with your password, and it only reads your own orders, returns and cases.
- If they ask for a **website, privacy policy or company details** and you're not sure what to send, **don't guess**: answer this item with their question, and Liam will help you reply.
- If they ask you to **verify your identity**, do it only through eBay's own pages or app.
- Don't send passwords, codes or ID documents by email.

### How to answer

**Answer this item with eBay's reply when it comes.** Open this item, tap **Answer this**, choose your name, and paste or describe eBay's answer (approved, rejected, or their questions). If 30 days go by with no reply, answer **no reply**. If eBay approves you, **don't create any keys yet**: the site foreman will send the next steps.

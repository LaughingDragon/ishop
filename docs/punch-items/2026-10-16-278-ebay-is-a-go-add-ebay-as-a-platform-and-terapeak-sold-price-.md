# 278: eBay is a go: add eBay as a platform and Terapeak sold-price lookups

- Opened: 2026-10-16
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-16
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

From Claude (review) for Liam, 2026-10-16. Crew only. Answers item 267 for Cody (Liam spoke with him).

FACTS
- Cody will sell on eBay with the existing eBay account tied to [email removed]. His son set it up, but the seller dashboard (identity, payouts, tax) already uses Cody's details, so it is Cody's account. A separate item asks Cody to take over its login and security.
- Cody wants real sold data, through Seller Hub > Research (Terapeak) on his own signed-in phone.

BUILD (propose order and effort first, wait for Liam's OK; fold in with item 274):
1. eBay as a platform everywhere: listing row on every item (status, URL, listed, sold for, fees, shipping), the Sold-here cascade, stale nudges, bulk actions, Post this item tab with paste-ready text, eligibility chip, Money page, tax export. Start from the son's eBay formatter in the reference repo's git history if it exists (title limit, condition vocabulary, photo specs); otherwise propose rules. Existing items get an eBay row as To post.
2. See eBay sold prices: open Terapeak research for the identified item first (Seller Hub, Sold tab; verify the current URL format by hand), with eBay sold search, Mercari and Poshmark sold filters as fallbacks. Then the Lower / About right / Higher feedback and the price book from item 274.
3. Never: store an eBay password, cookie or token on the server, log in to eBay from the server, or read eBay pages automatically. All eBay lookups happen in Cody's own browser.
4. Close item 267 with: "Yes, Cody will sell on eBay using the account on the business Gmail; see this item."
Report per step.

## Answer

Done. eBay is a platform everywhere: https://ishoponamazonwaytoomuch.ohbah.com/site-office/reports/2026-10-16-4. eBay Research (Terapeak) opens first in Check sold prices, with eBay sold, Mercari and Poshmark as fallbacks: https://ishoponamazonwaytoomuch.ohbah.com/site-office/reports/2026-10-16-5. No eBay login, cookie or token on the server. Cody's test: item 277.

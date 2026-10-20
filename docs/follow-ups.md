# Follow-ups after go-live

None of these are part of the handover. The same list is in [ROADMAP.md](ROADMAP.md) under "After go-live: follow-ups".

## 1. Content security policy: switch to enforcing, on or after 2026-10-23

The CSP has run in **report-only** mode since Phase 0. Violations are logged to the Drupal log (watchdog type `seckit`), and there were none on 2026-10-20. The log now keeps 10,000 rows, so it covers the full week.

- On or after 10/23: `cd /var/www/ishop && vendor/bin/drush ws --type=seckit --count=50`.
- If it's clean for the week, switch Security Kit's CSP from report-only to enforce (`/admin/config/system/seckit`, or `drush cset seckit.settings seckit_xss.csp.report-only false --input-format=yaml -y`), then `drush cex`, commit, and check the pages Cody uses still load (front desk, Stockroom, Add, item pages, Shipping room, My office, AI setup).
- If there are violations, fix the cause first (usually a new external resource).

## 2. Marketplace inbox (was Phase 9 B): fix matching, then switch on

The inbox reads the business Gmail over IMAP, read-only, with the **same app password the site sends with** (commit fdf9ecd). It's built and switched **off**. A read-only test on 2026-10-20 found 81 marketplace emails, all genuine. Before switching it on:

- **Sale emails get no item title**, so they don't match an item: Mercari "You've made a sale: TITLE"; Vinted "You sold an item on Vinted" (the title is in the body). Fix the classifier.
- **Label emails don't attach when the item is already marked sold** (Vinted's "TITLE shipping label – use by …"). Fix the matcher.
- **"Other" marketplace mail is too noisy** (71 of 81; 43 from Vinted). Show only messages that need Cody.
- **eBay is email-only now**, so eBay sale, ship-by, return and case emails must match their items.
- Re-test with `drush ishop:inbox-preview --days=30` (read-only; add `--subjects` in the terminal only) once Cody has items on the site again. Show Liam, then `drush ishop:inbox-switch on`.

## 3. eBay appeal outcome, by 2026-11-19

eBay rejected Cody's developer account ("problems with the data provided or other irregularities"). Cody gets **one appeal** (punch item 633, decision 639).

- **Approved:** build the eBay Sell API connection as specified: OAuth consent from Cody's own seller account; token stored like other secrets (a file in `secrets/`, owner and group only); a renewal reminder before it expires; never passwords, cookies or page scraping. eBay requires a marketplace account-deletion notification endpoint before a production keyset works, and an HTTPS accept/decline URL for the RuName.
- **Declined, or no reply within 30 days:** drop the eBay API integration entirely and log the decision. There's no eBay API code or settings to remove (none was built). eBay stays as a platform on items: listing rows, the Sold-here cascade, paste-ready text and Check sold prices via Terapeak don't need the API.

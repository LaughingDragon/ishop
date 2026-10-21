# Overview

**Reseller Matrix** is the private website for Cody's resale business ("I Shop on Amazon Way Too Much"). Cody sells household overstock from home on eBay, Poshmark, Mercari, Depop, Grailed, Vinted and Facebook Marketplace. The site holds his items and photos, writes and prices listings (with AI on his own Anthropic account), tracks where each item is posted, runs the Shipping room, keeps his money, mileage and tax records, and emails him a morning digest.

| | |
|---|---|
| Live URL | https://ishoponamazonwaytoomuch.ohbah.com (private: anonymous visitors only see the login page) |
| Who uses it | **Cody** (California, Pacific time), account `theowner` (administrator). The only real user. |
| Who runs it | **Liam** (Maryland, Eastern time), systems engineer, account `webmaster` (administrator). Server, Google Cloud, approvals. Doesn't use the site day to day. |
| Built by | Claude (Claude Code on the server as `site-foreman`), 2026-10-15 to 2026-10-20, under Liam's approvals |
| Server | Google Compute Engine VM `ishopwaytoomuch` (us-central1-a, e2-medium), Debian 13, Apache, PHP 8.4, MariaDB, Drupal 11.4, Drush 13 |
| Code | git at `/var/www/ishop`, remote `origin` (GitHub, private) |
| Site time zone | America/Los_Angeles (Pacific). All timers are in Pacific time. |

## What it doesn't do

The site never posts to the marketplaces itself. Most of them have no public listing API, and automating their websites breaks their terms of service. Cody posts by hand from **Post this item** (copy fields, photos, an "Open <Platform> to list" link) and taps **I posted it** (decision 645). eBay posting can be built only if Cody's eBay developer appeal is approved (decision 639). If Cody ever wants one-tap crossposting, paid crosslisting services are the option.

## Accounts

After close-out the site has two accounts:

- `webmaster` (uid 1): Liam.
- `theowner` (uid 3): Cody.

`site-foreman` (uid 2) is the build account Claude used on the server. It has no password, and it's removed at close-out (Phase 11 Stage B).

## Where to start

- Something broke or needs renewing: [runbooks.md](runbooks.md)
- How it's put together: [architecture.md](architecture.md)
- Why something is the way it is: [decisions.md](decisions.md) and [phase-history.md](phase-history.md)
- What's still open after go-live: [follow-ups.md](follow-ups.md)

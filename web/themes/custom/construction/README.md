# Construction theme

Construction-zone theme for **I Shop on Amazon Way Too Much** while the site
is being built: the site office, and the "gate" that visitors see (login,
password reset, access denied). Safety yellow and asphalt, hazard stripes,
road-sign status pills, a hard-hat logo. Content is capped at 960 px with
rounded corners throughout.

- Base theme: `stable9` (clean core markup, no styling to fight).
- Fonts are self-hosted in `fonts/` (Barlow Condensed, Barlow, JetBrains
  Mono; SIL Open Font License). No requests to Google, no JavaScript, so it
  works under a strict content security policy (`font-src 'self'`).
- Cody's main theme will replace this as the default later. Nothing here is
  shared with it.

## Install

```bash
cd /var/www/ishop
# put this folder at web/themes/custom/construction
drush theme:install construction
drush config:set system.theme default construction -y
drush cr
drush cex -y     # then commit
```

Gin stays the admin theme. When the theme is installed, Drupal copies block
placements from the old default theme into regions with the same machine
name (`primary_menu`, `highlighted`, `breadcrumb`, `content_above`,
`content`, `content_below`). Check **Block layout → Construction** afterwards:
make sure **Main page content**, **Messages**, **Page title** and **Primary
tabs** are in `content` or `highlighted`, and remove anything that doesn't
belong (search, "Powered by Drupal", a second site-branding block). The
header branding and footer motto are built into the page template.

## Files

| Path | What it is |
|---|---|
| `css/tokens.css` | All colors, radius, widths and font stacks as CSS variables. Change looks here. |
| `css/base.css` | Typography, links, plain tables, messages, local tabs. |
| `css/layout.css` | 960 px container, header, nav, footer, hazard stripes. |
| `css/forms.css` | Inputs and buttons. |
| `css/site-office.css` | Site office components (markup contract below). |
| `css/gate.css` | Login / access-denied layout, loaded only on the gate. |
| `templates/layout/page.html.twig` | Header, main, footer for every page. |
| `templates/layout/page--gate.html.twig` | The gate. Chosen in `construction.theme` for `user.login`, `user.pass`, `user.reset.*` and any 403. |
| `preview/*.html` | Static previews that use the real CSS. Open in a browser. They are also the reference markup for the contract. |

## Markup contract for the site office

Plain semantic HTML already looks right: headings, lists and any `<table>`
get the status-board style. Add these classes in the site office controller
for the full look. `preview/site-office.html` shows every one in use.

| Class | Element | Use |
|---|---|---|
| `so-nav` | `<nav>` with `<a>` children | Office tabs. Mark the current one `aria-current="page"`. |
| `so-sign` > `so-sign__inner` > `so-sign__text` + `so-stats` | `<section>` | Yellow road-sign summary panel. |
| `so-stat` > `so-stat__value` + `so-stat__label` | `<div>`, `<span>`s | Dark number tile. |
| `so-table-wrap` | `<div>` around a `<table>` | Table scrolls sideways on phones. |
| `so-pill` + `so-pill--done` / `--progress` / `--idle` / `--blocked` | `<span>` | Status badge. Shapes differ, not just colors. |
| `so-progress` (+ `is-done`) | `<progress value max>` | Caution-tape bar; solid green when done. No inline styles needed. |
| `so-progress-row` > `so-progress` + `so-progress-row__pct` | `<div>` | Bar with its percentage. |
| `so-diamond` (`so-diamond--small`) | `<span>` wrapping a `<span>` | Orange warning diamond with a number or `!`. |
| `so-callout` (`so-callout--warn`) > `so-callout__body` | `<section>` | Punch item / notice row. |
| `so-card` (`so-card--link` on an `<a>`) > `so-card__title`, `so-card__meta` | | Report list cards. |
| `so-date` > `so-date__month` + `so-date__day` | `<span>` | Date tile on a card. |
| `so-report` > `so-report__band` (`so-report__kicker`, `h1`, `so-report__facts` > `so-report__fact` with `<b>` + `<span>`) and `so-report__body` | `<article>` | Build report page. |
| `so-report__blockers` | `<section>` | Blockers panel inside a report. |
| `so-hashes` > `li` > `so-hash` | `<ul>`, `<code>` | Commit hash chips. |
| `button button--hazard` | `<a>` or `<button>` | Yellow action button. |

Status colors: done `#1F6B3A` (white text), in progress `#F26A1B` (dark
text), not started `#DDD8CE` (dark text), blocked `#C1272D` (white text).
All text meets 4.5:1 contrast; touch targets are at least 44 px.

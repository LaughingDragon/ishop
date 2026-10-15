# Reseller Matrix theme

Cody's main theme: **Look C, Red & Gold Marquee**, chosen by Cody from three
options built from his style questionnaire. Dark charcoal ground, a red
header with the gold "Reseller Matrix" wordmark, a strip of gold marquee
bulbs, Bungee sign lettering for headings, Work Sans for text at 19 px
("a bit bigger"), icon-plus-word room tiles, 960 px max width, rounded
corners.

- Base theme `stable9`. No JavaScript. Fonts self-hosted in `fonts/`
  (SIL Open Font License), so it works under a strict CSP.
- The bulb strip twinkles slowly (well under 3 flashes per second) and stops
  for visitors with "reduce motion" turned on.
- Status is always shown with a word, never color alone.

## Install (after Phase 1's functions are done)

```bash
# place at web/themes/custom/reseller_matrix
drush theme:install reseller_matrix
drush config:set system.theme default reseller_matrix -y
drush cr && drush cex -y   # then commit
```

### Keep the site office on the construction theme

The site office stays in the construction theme ("that's us"). Add a theme
negotiator to the ishop_office module:

```php
// src/Theme/OfficeThemeNegotiator.php
namespace Drupal\ishop_office\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

final class OfficeThemeNegotiator implements ThemeNegotiatorInterface {
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();
    return $route && str_starts_with($route->getPath(), '/site-office');
  }
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return 'construction';
  }
}
```

```yaml
# ishop_office.services.yml
services:
  ishop_office.theme_negotiator:
    class: Drupal\ishop_office\Theme\OfficeThemeNegotiator
    tags:
      - { name: theme_negotiator, priority: 10 }
```

Report pages on /node/N that belong to the site office (punch items,
reports) need the same rule: extend `applies()` to those bundles.

### Block layout

Place the **Main navigation** menu block in `primary_menu` (it becomes the
rooms bar), Messages and Primary tabs in `highlighted`, Page title and Main
page content in `content`. Nothing in `footer` is required. The header,
"Add item" button and Account/Log out links are built into the templates.
Main menu items, in order: Front desk, Stockroom, Shipping room, My office,
Mailroom, Calendar wall, Break room (each room's page as it gets built).

## Markup contract (the Reseller Matrix front page, /)

`preview/dashboard.html` shows all of it. Icons come from
`templates/includes/room-icon.html.twig` (front_desk, stockroom, shipping,
my_office, mailroom, calendar, break_room, add).

| Class | Element | Use |
|---|---|---|
| `rm-greeting` > `rm-greeting__text` (h1 + p) + `rm-badge` | section | "Welcome back, boss!", date line, "Open for business" badge |
| `rm-needs` > h2 + `rm-needs__row`s (text + `rm-button`) | section | "Needs you now": take-downs, return windows. `rm-needs__empty` when nothing. |
| `rm-section-title` | h2 | Section headings like "The building" |
| `rm-rooms` > li > `rm-room` | ul / a | Room tiles |
| `rm-room__icon` (icon + `rm-room__word`) | span | Red icon tile with a one-word label under the picture |
| `rm-room__text` > `rm-room__name` + `rm-room__glance` | span | Room name and its one-line status |
| `rm-button` (`--gold`, `--ghost`, `--ghost-light`) | a / button | Buttons, 48 px tall |
| `rm-chip` (`--todo`, `--live`, `--sold`, `--alert`) | span | Listing status: To post, Posted, Sold here, Take down |
| `rm-card` | div | Generic panel |
| `rm-table-wrap` | div around table | Tables scroll sideways on phones |

Glance lines must use real data only; when there's nothing, say so plainly
("Nothing to ship today").

## Colors (tokens.css)

Ground #161312, surface #221C1A, raised #2D2422, red #C8312B, gold #F2C14E,
text #FBF3E4, soft text #D3C6B2, done #2E7D4A. Never blue with green
(Cody's one dislike).

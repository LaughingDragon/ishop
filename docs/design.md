# Design choices

Cody is the site's only designer (Liam's rule). Everything here came from Cody's [style questionnaire](cody-questionnaire.md) (2026-10-15) and his picks afterwards.

## The look: Look C, "Red & Gold Marquee"

Cody chose Look C from three looks built from his answers (decision 108, punch item 103). It's the `reseller_matrix` theme (`web/themes/custom/reseller_matrix`, its README has the full markup contract):

- Dark charcoal ground, a red header with the business name as the big gold title and **"Reseller Matrix"** as the small motto underneath (the name Cody chose for the main page).
- A strip of gold marquee bulbs that twinkles slowly (well under 3 flashes per second) and stops for visitors with "reduce motion" turned on.
- Bungee sign lettering for headings, Work Sans for text at 19 px ("a bit bigger"). The fonts are self-hosted, so they work under a strict CSP.
- Icon-plus-word room tiles: Front desk, Stockroom, Shipping room, My office, Mailroom, Calendar wall, Break room (the rooms Cody picked).
- Colors: ground #161312, surface #221C1A, raised #2D2422, red #C8312B, gold #F2C14E, text #FBF3E4, soft text #D3C6B2, done #2E7D4A.
- **Never blue with green** ("blue and green combos really chap my hide"). Status is always shown with a word, never color alone.
- Phone first: Cody uses an **iPhone with Safari**, and the site about equally on his phone and computer. One login that lasts ("asking me to log in more than once" drives him crazy).

## Football and Gold Rush accents

Approved 2026-10-15 (decision 138), football flavour only:

- Yard-line strip under the marquee bulbs (10 … 50 … 10).
- A **"This season"** scoreboard on the front desk: sold, listed and gold struck since the season start.
- **Game days** that Cody sets in My office: a gold "Game day" badge and the kickoff time in the greeting.
- **"Touchdown!"** when a sale means taking the other listings down.
- Footer: "Gold Rush Edition, est. 1849-ish".
- **No** team names, nicknames, logos, helmets, player names, numbers, photos or team slogans anywhere.

The season start and game days are Cody's data (State, not config), so editing them never makes config differ from git.

The site office and build pages use a separate theme, **Construction**, which goes away with the site office. The admin theme is Gin.

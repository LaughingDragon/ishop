# 135: Build Birthday mode and the football / Gold Rush accents (both approved)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: site_foreman
- Crew only (hidden from Cody on the site)

## Instructions

Approved by Liam and Cody on 2026-10-15. Designs: Claude's canvas artboards "Birthday mode" and "Football & Gold Rush accents". Opened by Claude (review) for Liam. Birthday mode first: the birthday is only days away.

LIAM:
- Upload Cody's own copy of his song to ~/cody-birthday.mp3 (SSH window, Upload file).
- Confirm Cody's exact birthday date (answer this item with it if the agent hasn't got it).

SITE-FOREMAN, PART 1: BIRTHDAY MODE (temporary, add to TEMPORARY.md)
- Colors: ground #1B1030, panels #261845, gold #F2C14E, pink #E0398A, orange #F27A2E, lavender #8A3FD1, text #FFF4E0.
- Four 8px horizontal party stripes (gold, orange, pink, lavender) replace the bulb strip; header motto "Reseller Matrix · birthday edition".
- Dashboard hero: gold 3px-bordered sign with CSS confetti, pink badge "Today is a very big deal", h1 "Happy birthday, boss!", line "The whole building is celebrating. Every room, every shelf, every listing. Take the day. You've earned it.", big gold "Start the party" button that toggles to "Pause the party".
- Needs you now: "Nothing that can't wait until tomorrow. It's your birthday." Room icon tiles turn pink.
- Song: move ~/cody-birthday.mp3 into private files (not git, not public), delete it from ~. <audio loop>, no autoplay (browsers block it); the button starts it; remember playing in sessionStorage so other pages offer "Resume the party". CSP media-src 'self'. No confetti animation with reduced motion.
- On automatically all day on the birthday (Pacific), plus a manual on/off for webmaster. Reseller Matrix pages only; the site office stays in hard hats.
- Test on iPhone Safari (tap to play, loops). After the birthday: switch off, delete the audio, remove per TEMPORARY.md.

SITE-FOREMAN, PART 2: FOOTBALL AND GOLD RUSH ACCENTS (permanent)
- Yard-line strip under the bulb strip: 34px, background #1D1816, 2px #4A3A36 ticks every 48px, Bungee 13px numbers 10 20 30 40 50 40 30 20 10 in #8C7B70, the 50 in gold; aria-hidden.
- Dashboard "This season" scoreboard above Needs you now: Sold, Listed, "Gold struck" (total sold amount), gold Bungee digits on #0F0D0C, real data since a season start date setting (default Jan 1); zeros when empty.
- Game days list Cody can edit (date plus optional kickoff time) in My office. On game days the "Open for business" badge becomes a gold "Game day" badge with a football icon and the date line adds "Kickoff at [time]".
- Copy: "Touchdown!" before sale take-down rows; footer "Gold Rush Edition, est. 1849-ish".
- Rules: no team names or nicknames, logos, helmets, player names, numbers or photos, or team slogans anywhere. Keep our red #C8312B and gold #F2C14E. Birthday mode overrides these accents on the birthday.

File a report for each part and close this item with their links.

## Answer

Liam answered **Done** (15:46). Both parts are built:

- Part 1, birthday mode (crew only): [report 2026-10-15-30](../reports/2026-10-15-30.md). The date is set to Monday 10/19 from Liam's answer on item 136; Liam's iPhone preview passed.
- Part 2, football and Gold Rush accents: [report 2026-10-15-31](../reports/2026-10-15-31.md). On the birthday, birthday mode hides the yard lines, scoreboard, game-day badge and footer edition line.

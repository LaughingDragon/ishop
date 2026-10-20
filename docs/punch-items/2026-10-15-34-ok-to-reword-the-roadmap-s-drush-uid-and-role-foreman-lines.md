# 34: OK to reword the roadmap's "drush --uid" and role "foreman" lines?

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Drush 13 has no --uid option. The office commands switch to the site-foreman account internally instead, so authorship and revision logs still show site-foreman. The existing role is named site_foreman ("Site foreman").

## Instructions

**Who:** Liam. **Time:** 2 minutes.

Two lines in ROADMAP.md (Phase 0, Accounts) don't match how things actually work.

**Now:**
> - `site-foreman`: the agent's Drupal account, role "foreman" (...)
> - The agent works through Drush and code, never web logins. Use `drush --uid=<site-foreman uid>` for content operations so changes are attributed to site-foreman in revision logs.

**Proposed:**
> - `site-foreman`: the agent's Drupal account, role "Site foreman" (`site_foreman`) (...)
> - The agent works through Drush and code, never web logins. The `ishop:*` Drush commands save everything as site-foreman (they switch to that account internally), so revision logs show site-foreman.

**Why:** Drush 13 has no `--uid` option. The site office commands get the same result a different way.

1. Read the two versions.
2. Decide: use mine, change it, or leave the roadmap as it is.

**How to answer:** "Yes, use yours", "No, leave it", or your own wording. I'll edit the file, commit it and re-sync the plans page.

## Answer

Yes. reword

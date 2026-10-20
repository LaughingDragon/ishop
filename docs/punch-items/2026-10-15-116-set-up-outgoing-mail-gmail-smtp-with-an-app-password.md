# 116: Set up outgoing mail (Gmail SMTP with an app password)

- Opened: 2026-10-15
- Waiting on: site_foreman
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 5 (Phase 5: daily life tools and the digest)

## Details

Queued per Liam 2026-10-15. Today Drupal uses PHP mail() with no relay, so nothing is delivered: password resets and any notification can't reach anyone. Plan from ROADMAP Phase 5: Symfony Mailer + Gmail SMTP; the app password lives in secrets/, never in config. Liam provides the Gmail account and app password when Phase 5 starts.

## Answer

Proceed. Walk Cody (theowner) through setting up his [email removed]
to use 2 step and getting an app password setup securely without revealing it to the public.

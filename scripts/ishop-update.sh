#!/usr/bin/env bash
#
# Weekly unattended Drupal and module update for ishop, approved by Liam on
# 2026-10-15. Run by ishop-update.service (as liampward) from ishop-update.timer.
#
# 1. Refuse to start unless git and config are clean.
# 2. Maintenance mode on, database dump to /var/backups/ishop-update.
# 3. composer update within composer.json limits (core: patch releases only).
# 4. drush updb, cr, cex. Maintenance mode off.
# 5. Checks: bootstrap, login page 200, site office 403 to anonymous, unit tests.
# 6. Pass: commit and file a site office build report.
#    Fail: restore composer files, vendor and database, then open a punch list
#    item for Liam and file a report.
#
# ISHOP_UPDATE_FORCE_FAIL=1 makes the checks fail, to test the rollback.

set -Eeuo pipefail

PROJECT=/var/www/ishop
SITE=https://ishoponamazonwaytoomuch.ohbah.com
DUMPS=/var/backups/ishop-update
LOGS=/var/log/ishop-update
STAMP=$(date +%Y-%m-%d_%H%M)
TODAY=$(date +%F)
LOG="$LOGS/$STAMP.log"
WORK=$(mktemp -d)
DRUSH="$PROJECT/vendor/bin/drush"
DUMP=""
STEP="start"
UPDATING=0

cd "$PROJECT"
exec > >(tee -a "$LOG") 2>&1

step() { STEP="$1"; echo "== $(date +%T) $1"; }

# Writes a build report and files it. Args: title, summary, done, next, tests, commits.
file_report() {
  cat > "$WORK/report.md" <<EOF
---
date: $TODAY
phase: foundation
commits: [$6]
title: "$1"
---
## Summary

$(printf '%b' "$2")

## What was done

$(printf '%b' "$3")

## What's next

$(printf '%b' "$4")

## Tests

$(printf '%b' "$5")

Full log on the VM: \`$LOG\`
EOF
  "$DRUSH" ishop:report "$WORK/report.md" || echo "!! could not file the build report"
}

# Opens a punch list item for Liam about a failed or skipped run.
open_punch() {
  cat > "$WORK/punch.md" <<EOF
**Who:** Liam, just to know. Nothing to fix by hand. **Time:** 2 minutes.

The automatic weekly update on $TODAY did not finish: $1

$2

1. Check the site works: open $SITE/site-office and log in if asked.
2. If it does: there's nothing more to do now. Claude reads the log at the start of the next work session, fixes the cause, and closes this item.
3. If the site does **not** work: ask Claude to look at it as soon as possible, and mention this item.

**How to answer:** "Site works", or "Site broken: (what you see)".
EOF
  "$DRUSH" ishop:punch "Automatic update on $TODAY did not finish ($STEP)" --waiting-on=liam,claude \
    --phase=foundation --instructions-file="$WORK/punch.md" \
    --details="Log on the VM: \`$LOG\`" || echo "!! could not open a punch list item"
}

rollback() {
  echo "== $(date +%T) rolling back"
  git checkout -- composer.json composer.lock config/sync || true
  git clean -fdq -- config/sync || true
  composer install --no-interaction --no-progress || true
  if [[ -n "$DUMP" && -f "$DUMP" ]]; then
    gunzip -c "$DUMP" | "$DRUSH" sql:cli || echo "!! database restore failed"
  fi
  "$DRUSH" cr || true
  "$DRUSH" state:set system.maintenance_mode 0 --input-format=integer || true
}

on_error() {
  local code=$?
  trap - ERR INT TERM
  echo "!! failed during: $STEP (exit $code)"
  if [[ $UPDATING == 1 ]]; then
    rollback
    if [[ "$("$DRUSH" status --field=bootstrap 2>/dev/null)" == "Successful" ]] \
      && [[ "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/user/login")" == "200" ]]; then
      restored="The site was put back exactly as it was before the update, and it is working."
    else
      restored="**The automatic restore did not bring the site back cleanly.** Please ask Claude to look at it now."
    fi
    open_punch "it failed during \"$STEP\"." "$restored"
    file_report "Automatic update failed during $STEP; rolled back" \
      "The weekly update failed during \"$STEP\". $restored" \
      "- Took a database dump, ran composer update and the checks.\n- On failure: restored composer.json, composer.lock, config, vendor and the database." \
      "Claude investigates in the next work session (punch list)." \
      "Failed at: $STEP." ""
  else
    open_punch "it stopped before changing anything, during \"$STEP\"." "Nothing was changed; the site is as it was."
  fi
  rm -rf "$WORK"
  exit "$code"
}
trap on_error ERR INT TERM

step "preflight: git and config must be clean"
if [[ -n "$(git status --porcelain)" ]]; then
  echo "!! uncommitted changes in $PROJECT:"; git status --short
  false
fi
"$DRUSH" config:status 2>&1 | tee "$WORK/cstatus.txt"
grep -q "No differences" "$WORK/cstatus.txt"

step "maintenance mode on, database dump"
UPDATING=1
"$DRUSH" state:set system.maintenance_mode 1 --input-format=integer
"$DRUSH" sql:dump --gzip --result-file="$DUMPS/pre-$STAMP.sql"
DUMP="$DUMPS/pre-$STAMP.sql.gz"
test -s "$DUMP"
composer show --locked --format=json > "$WORK/before.json"

step "composer update"
composer update --with-all-dependencies --no-interaction --no-progress
composer show --locked --format=json > "$WORK/after.json"
CHANGES=$(python3 -I - "$WORK/before.json" "$WORK/after.json" <<'PY'
import json, sys
def load(p):
    return {x["name"]: x["version"] for x in json.load(open(p)).get("locked", [])}
a, b = load(sys.argv[1]), load(sys.argv[2])
lines = []
for name in sorted(set(a) | set(b)):
    if a.get(name) != b.get(name):
        lines.append(f"- `{name}`: {a.get(name, 'new')} -> {b.get(name, 'removed')}")
print("\n".join(lines))
PY
)

# A forced-failure test carries on so the whole update and rollback path runs.
if [[ -z "$CHANGES" && "${ISHOP_UPDATE_FORCE_FAIL:-0}" != "1" ]]; then
  step "no updates available"
  # Versions are unchanged; drop any lock file metadata churn so git stays clean.
  git checkout -- composer.json composer.lock
  "$DRUSH" state:set system.maintenance_mode 0 --input-format=integer
  UPDATING=0
  AUDIT=$(composer audit --no-interaction 2>&1 | tail -3 || true)
  file_report "Automatic weekly update: nothing to update" \
    "No Drupal, module or library updates were available." \
    "- Checked for updates within the composer.json limits; none.\n- Security advisories: $AUDIT" \
    "Next run next Thursday at 02:30 Pacific." \
    "No changes, so no checks were needed." ""
  rm -f "$DUMP"
  rm -rf "$WORK"
  exit 0
fi
echo "$CHANGES"

step "database updates and config export"
"$DRUSH" updb -y
"$DRUSH" cr
"$DRUSH" cex -y
"$DRUSH" state:set system.maintenance_mode 0 --input-format=integer

step "checks"
test "$("$DRUSH" status --field=bootstrap)" == "Successful"
test "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/user/login")" == "200"
test "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/site-office")" == "403"
test "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/site-office/status.json")" == "403"
vendor/bin/phpunit -c web/core web/modules/custom/ishop_office/tests/src/Unit
if [[ "${ISHOP_UPDATE_FORCE_FAIL:-0}" == "1" ]]; then
  echo "!! ISHOP_UPDATE_FORCE_FAIL=1: failing on purpose to test the rollback"
  false
fi
UPDATING=0

step "commit and report"
git add composer.json composer.lock config/sync
git -c user.name="ishop weekly update" -c user.email="ishop-update@ishoponamazonwaytoomuch.ohbah.com" \
  commit -q -m "Automatic weekly update $TODAY" -m "$CHANGES"
HASH=$(git rev-parse --short HEAD)
AUDIT=$(composer audit --no-interaction 2>&1 | tail -3 || true)
file_report "Automatic weekly update: $(echo "$CHANGES" | wc -l) package(s) updated" \
  "Weekly update applied and checked." \
  "$CHANGES" \
  "Next run next Thursday at 02:30 Pacific." \
  "- Drupal boots; login page 200; /site-office and status.json 403 to anonymous visitors.\n- Unit tests pass.\n- Security advisories after the update: $AUDIT" \
  "$HASH"

step "tidy: keep the last 4 dumps and 26 logs"
ls -1t "$DUMPS"/pre-*.sql.gz 2>/dev/null | tail -n +5 | xargs -r rm -f
ls -1t "$LOGS"/*.log 2>/dev/null | tail -n +27 | xargs -r rm -f
rm -rf "$WORK"
echo "== $(date +%T) done"

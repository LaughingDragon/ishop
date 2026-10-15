#!/usr/bin/env bash
#
# Proves the off-server backups can be restored, without touching the live
# site: downloads the newest database dump from the bucket, loads it into a
# scratch database, compares row counts with the live one, checks one photo's
# checksum, then drops the scratch database.
#
#   sudo scripts/ishop-restore-check.sh
#
# Reads only from the bucket. Needs the bucket name in /etc/default/ishop-offsite.

set -Eeuo pipefail
cd /

source /etc/default/ishop-offsite
[[ -n "${ISHOP_BUCKET:-}" ]] || { echo "!! ISHOP_BUCKET not set"; exit 1; }
[[ $EUID == 0 ]] || { echo "!! run as root (sudo)"; exit 1; }

PREFIX="gs://$ISHOP_BUCKET/$(hostname)"
SCRATCH=ishop_restore_check
WORK=$(mktemp -d)
trap 'mysql -e "DROP DATABASE IF EXISTS $SCRATCH" 2>/dev/null || true; rm -rf "$WORK"' EXIT

say() { echo "== $(date +%T) $*"; }

say "newest database dump in the bucket"
DUMP=$(gcloud storage ls "$PREFIX/backup-*.mysql.gz" | sort | tail -1)
[[ -n "$DUMP" ]] || { echo "!! no database dumps in $PREFIX"; exit 1; }
echo "   $DUMP"
gcloud storage cp --quiet "$DUMP" "$WORK/db.mysql.gz"
gzip -t "$WORK/db.mysql.gz"

say "load into the scratch database $SCRATCH"
mysql -e "DROP DATABASE IF EXISTS $SCRATCH; CREATE DATABASE $SCRATCH CHARACTER SET utf8mb4"
zcat "$WORK/db.mysql.gz" | mysql "$SCRATCH"

say "compare with the live database"
q() { mysql -N -e "SELECT COUNT(*) FROM $1.node_field_data WHERE type='$2'" 2>/dev/null || echo "?"; }
for type in item office_report office_punch office_decision; do
  printf '   %-16s restored %-5s live %s\n' "$type" "$(q $SCRATCH $type)" "$(q ishop $type)"
done
printf '   %-16s restored %-5s live %s\n' "listings" \
  "$(mysql -N -e "SELECT COUNT(*) FROM $SCRATCH.platform_listing" 2>/dev/null || echo '?')" \
  "$(mysql -N -e "SELECT COUNT(*) FROM ishop.platform_listing")"

say "one photo back from the bucket"
PHOTO=$(gcloud storage ls --recursive "$PREFIX/items/**" 2>/dev/null | grep -v '/$' | grep -v '.ishop-data-disk' | head -1 || true)
if [[ -z "$PHOTO" ]]; then
  echo "   no photos in the bucket yet"
else
  LOCAL="/var/www/ishop/private/items/${PHOTO#"$PREFIX/items/"}"
  gcloud storage cp --quiet "$PHOTO" "$WORK/photo"
  if cmp -s "$WORK/photo" "$LOCAL"; then echo "   $PHOTO matches the live file"; else echo "!! $PHOTO differs from $LOCAL"; exit 1; fi
fi
say "restore check passed (scratch database dropped)"

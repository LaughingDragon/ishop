#!/usr/bin/env bash
#
# Copies the nightly Backup and Migrate files off the VM, into a private
# Cloud Storage bucket. Approved by Liam 2026-10-15 after the data disk loss.
# Runs from ishop-offsite.timer (02:45 Pacific, after the 02:15 backups).
#
#   scripts/ishop-offsite.sh --dry-run   # show what would be copied
#   scripts/ishop-offsite.sh             # copy
#
# The bucket name lives in /etc/default/ishop-offsite (ISHOP_BUCKET=...). The
# VM's service account only needs to create and read objects: files are never
# overwritten or deleted from here; the bucket's lifecycle rule ages them out.
# On failure it opens a punch list item for site-foreman.

set -Eeuo pipefail

DRY=0
[[ "${1:-}" == "--dry-run" ]] && DRY=1
SRC=/var/backups/ishop-bm
DRUSH=/var/www/ishop/vendor/bin/drush
BUCKET="${ISHOP_BUCKET:-}"
DAYS=2   # copy files from the last two days, so a missed night catches up

fail() {
  echo "!! $*" >&2
  if [[ $DRY == 0 ]]; then
    "$DRUSH" --root=/var/www/ishop ishop:punch "Off-server backup copy failed on $(date +%F)" \
      --waiting-on=site-foreman --phase=0 \
      --details="scripts/ishop-offsite.sh: $*. Check: journalctl -u ishop-offsite.service" >/dev/null 2>&1 || true
  fi
  exit 1
}
trap 'fail "stopped at line $LINENO"' ERR

[[ -n "$BUCKET" ]] || fail "ISHOP_BUCKET is not set (see /etc/default/ishop-offsite)"
[[ -d "$SRC" ]] || fail "$SRC is missing"

mapfile -t FILES < <(find "$SRC" -maxdepth 1 -type f -mtime -"$DAYS" -name 'backup-*' | sort)
(( ${#FILES[@]} > 0 )) || fail "no backup files from the last $DAYS days in $SRC"

DEST="gs://$BUCKET/$(hostname)/"
echo "== $(date +%T) ${#FILES[@]} files -> $DEST"
if [[ $DRY == 1 ]]; then
  printf '   would copy %s\n' "${FILES[@]}"
  exit 0
fi

# --no-clobber: files already in the bucket are skipped, never replaced.
gcloud storage cp --no-clobber "${FILES[@]}" "$DEST"

# Check every file arrived with the same size.
for f in "${FILES[@]}"; do
  want=$(stat -c %s "$f")
  got=$(gcloud storage ls -l "$DEST$(basename "$f")" | awk 'NR==1 {print $1}')
  [[ "$got" == "$want" ]] || fail "$(basename "$f"): $got bytes in the bucket, $want on the VM"
done
echo "== $(date +%T) done, ${#FILES[@]} files checked"

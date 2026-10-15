#!/usr/bin/env bash
#
# One-off (Phase 1 step 1, Liam 2026-10-15): put item photos and local backup
# files on the extra 10 GB disk "ishopway2muchdata". Code, database and Drupal
# stay on the boot disk and never depend on this disk.
#
#   sudo scripts/setup-data-disk.sh --dry-run
#   sudo scripts/setup-data-disk.sh            # only after Liam's snapshot
#
# Layout on the disk (mounted at /srv/ishop-data by UUID, nofail):
#   items/        -> bind-mounted at /var/www/ishop/private/items
#   backups/      -> bind-mounted at /var/backups/ishop
#   backups/bm/   -> bind-mounted at /var/www/ishop/private/backup_migrate
# Each has a marker file .ishop-data-disk; Drupal checks it to tell whether
# photo storage is online. When the disk is missing, the empty mountpoints on
# the boot disk are root-owned and read-only, so nothing is written there by
# mistake. Old backup folders on the boot disk are copied, not deleted.

set -Eeuo pipefail
cd /

DRY=0
[[ "${1:-}" == "--dry-run" ]] && DRY=1
DEV=/dev/disk/by-id/google-ishopway2muchdata
MNT=/srv/ishop-data
SITE=/var/www/ishop
MARKER=.ishop-data-disk
STAMP=$(date +%Y%m%d_%H%M)

say() { echo "== $(date +%T) $*"; }
die() { echo "!! $*" >&2; exit 1; }
run() { if [[ $DRY == 1 ]]; then echo "   would run: $*"; else "$@"; fi; }

[[ $EUID == 0 ]] || die "run as root (sudo)"

say "checks"
[[ -b $DEV ]] || die "$DEV not found: is ishopway2muchdata attached?"
REAL=$(readlink -f "$DEV")
ROOTDEV=$(findmnt -no SOURCE / | sed 's/[0-9]*$//')
[[ "$REAL" != "$ROOTDEV" ]] || die "$REAL is the boot disk"
[[ -z "$(blkid -o value -s TYPE "$REAL" 2>/dev/null)" ]] || die "$REAL already has a filesystem; refusing to format it"
[[ $(lsblk -no NAME "$REAL" | wc -l) == 1 ]] || die "$REAL has partitions; refusing"
[[ $(lsblk -bno SIZE "$REAL") == $((10 * 1024 * 1024 * 1024)) ]] || die "$REAL is not 10 GiB"
[[ -z "$(head -c 64M "$REAL" | tr -d '\0' | head -c 1)" ]] || die "$REAL is not blank (non-zero data in the first 64 MiB)"
! findmnt "$MNT" >/dev/null || die "$MNT is already mounted"
if [[ -d "$SITE/private/items" ]] && [[ -n "$(find "$SITE/private/items" -type f | head -1)" ]]; then
  die "$SITE/private/items already holds files; mounting over them would hide them"
fi
echo "   $REAL: 10 GiB, blank, not the boot disk"

say "format (ext4, label ishop-data)"
run mkfs.ext4 -q -L ishop-data -m 1 -E lazy_itable_init=0,lazy_journal_init=0,discard "$REAL"
UUID=$( [[ $DRY == 1 ]] && echo "<uuid>" || blkid -o value -s UUID "$REAL")

say "mountpoints on the boot disk: empty, root-owned, read-only"
for d in "$MNT" "$SITE/private/items" /var/backups/ishop; do
  [[ -d "$d" ]] && run find "$d" -mindepth 1 -type d -empty -delete
  run mkdir -p "$d"
  run chown root:root "$d"
  run chmod 0555 "$d"
done

say "fstab (backup: /etc/fstab.pre-data-disk-$STAMP)"
LINES="# ishop data disk ishopway2muchdata: item photos and local backups only (Phase 1, $STAMP).
# nofail + short timeout: the site and database boot without it.
UUID=$UUID $MNT ext4 defaults,discard,nofail,x-systemd.device-timeout=10s 0 2
$MNT/items $SITE/private/items none bind,nofail,x-systemd.requires-mounts-for=$MNT 0 0
$MNT/backups /var/backups/ishop none bind,nofail,x-systemd.requires-mounts-for=$MNT 0 0
$MNT/backups/bm $SITE/private/backup_migrate none bind,nofail,x-systemd.requires-mounts-for=$MNT 0 0"
if [[ $DRY == 1 ]]; then
  echo "   would replace the /var/backups/ishop-bm line and append:"; echo "$LINES" | sed 's/^/     /'
else
  cp -a /etc/fstab "/etc/fstab.pre-data-disk-$STAMP"
  sed -i -e '/^# ishop: backups folder on the system disk/d' -e '\#^/var/backups/ishop-bm #d' /etc/fstab
  printf '%s\n' "$LINES" >> /etc/fstab
  findmnt --verify >/dev/null 2>&1 || { cp -a "/etc/fstab.pre-data-disk-$STAMP" /etc/fstab; die "fstab check failed; restored"; }
  systemctl daemon-reload
fi

say "mount the disk, create folders and markers"
run umount "$SITE/private/backup_migrate"
run chown root:root "$SITE/private/backup_migrate"
run chmod 0555 "$SITE/private/backup_migrate"
run mount "$MNT"
for d in items backups backups/bm backups/update backups/move backups/recovery; do
  run mkdir -p "$MNT/$d"
done
run chown www-data:www-data "$MNT/items" "$MNT/backups/bm"
run chmod 2775 "$MNT/items" "$MNT/backups/bm"
run chown liampward:www-data "$MNT/backups/update" "$MNT/backups/move"
run chmod 2750 "$MNT/backups/update" "$MNT/backups/move"
run chmod 0700 "$MNT/backups/recovery"
for d in items backups backups/bm; do
  if [[ $DRY == 1 ]]; then echo "   would write $MNT/$d/$MARKER"; else
    echo "ishopway2muchdata $UUID $STAMP" > "$MNT/$d/$MARKER"; chmod 0444 "$MNT/$d/$MARKER"
  fi
done

say "copy the existing local backups onto the disk (originals kept)"
run rsync -a /var/backups/ishop-bm/ "$MNT/backups/bm/"
run rsync -a /var/backups/ishop-update/ "$MNT/backups/update/"
run rsync -a /var/backups/ishop-move/ "$MNT/backups/move/"
run rsync -a /var/backups/ishop-recovery/ "$MNT/backups/recovery/"

say "bind mounts"
run mount "$SITE/private/items"
run mount /var/backups/ishop
run mount "$SITE/private/backup_migrate"

say "checks"
if [[ $DRY == 0 ]]; then
  findmnt "$MNT"; findmnt "$SITE/private/items"; findmnt /var/backups/ishop; findmnt "$SITE/private/backup_migrate"
  for f in "$SITE/private/items/$MARKER" "/var/backups/ishop/$MARKER" "$SITE/private/backup_migrate/$MARKER"; do
    sudo -u www-data test -r "$f" || die "www-data can't read $f"
  done
  sudo -u www-data touch "$SITE/private/items/.write-test" && sudo -u www-data rm "$SITE/private/items/.write-test"
  diff -rq /var/backups/ishop-bm "$MNT/backups/bm" | grep -v "Only in $MNT" && die "backup copy differs" || true
  df -h / "$MNT"
fi
say "done. Old folders /var/backups/ishop-{bm,update,move,recovery} are kept until Liam OKs removing them."

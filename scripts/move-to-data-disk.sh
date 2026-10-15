#!/usr/bin/env bash
#
# One-off: move the site (/var/www/ishop) and the database (/var/lib/mysql)
# onto the separate data disk, approved by Liam on 2026-10-15. Run as root:
#
#   sudo scripts/move-to-data-disk.sh --dry-run [DEVICE]   # checks only
#   sudo scripts/move-to-data-disk.sh [DEVICE]             # does it
#
# DEVICE defaults to /dev/disk/by-id/google-ishop-data (the "device name"
# given when attaching the disk). The site is offline while files are copied.
#
# Layout afterwards:
#   data disk  -> /srv/ishop-data (ext4, label ishop-data)
#   /srv/ishop-data/www/ishop  bind-mounted on /var/www/ishop
#   /srv/ishop-data/mysql      bind-mounted on /var/lib/mysql
#   /var/backups/ishop-bm (system disk) bind-mounted on
#     /var/www/ishop/private/backup_migrate, so backups stay on the system disk
# The old copies are kept as /var/www/ishop.pre-move and /var/lib/mysql.pre-move
# until Liam confirms; the original paths become empty mount points, so if the
# data disk is ever missing the services stay down instead of using stale data.

set -Eeuo pipefail

DRY=0
if [[ "${1:-}" == "--dry-run" ]]; then DRY=1; shift; fi
DEVICE="${1:-/dev/disk/by-id/google-ishop-data}"
MNT=/srv/ishop-data
SITE=https://ishoponamazonwaytoomuch.ohbah.com
STAMP=$(date +%Y-%m-%d_%H%M)
SERVICES=(apache2 php8.4-fpm mariadb)
TIMERS=(ishop-backup.timer ishop-update.timer)

say() { echo "== $(date +%T) $*"; }
die() { echo "!! $*" >&2; exit 1; }
run() { if [[ $DRY == 1 ]]; then echo "   would run: $*"; else "$@"; fi; }
trap 'echo "!! stopped with an error. If the site is down, restart it: systemctl start ${SERVICES[*]} ${TIMERS[*]}" >&2' ERR

[[ $EUID == 0 ]] || die "run as root (sudo)"

say "checks"
[[ -b "$DEVICE" ]] || die "$DEVICE is not a block device. Is the disk attached, with device name ishop-data?"
REAL=$(readlink -f "$DEVICE")
ROOTDEV=$(findmnt -no SOURCE / | sed 's/[0-9]*$//')
[[ "$REAL" != "$ROOTDEV"* ]] || die "$DEVICE is the system disk"
if findmnt -S "$REAL" >/dev/null || lsblk -no MOUNTPOINT "$REAL" | grep -q .; then die "$REAL is mounted"; fi
[[ -z "$(blkid -o value -s TYPE "$REAL" 2>/dev/null)" ]] || die "$REAL already has a filesystem; refusing to format it"
[[ $(lsblk -no NAME "$REAL" | wc -l) == 1 ]] || die "$REAL has partitions; refusing to touch it"
SIZE=$(lsblk -bno SIZE "$REAL")
NEED=$(( $(du -sb /var/www/ishop | cut -f1) + $(du -sb /var/lib/mysql | cut -f1) ))
(( SIZE > NEED * 2 )) || die "data disk too small: $SIZE bytes for $NEED bytes of data"
! findmnt /var/www/ishop >/dev/null || die "/var/www/ishop is already a mount point; already moved?"
echo "   device $REAL, $(( SIZE / 1024 / 1024 )) MiB; data to move $(( NEED / 1024 / 1024 )) MiB"

say "format and mount the data disk at $MNT"
run mkfs.ext4 -q -L ishop-data -m 1 -E lazy_itable_init=0,lazy_journal_init=0,discard "$REAL"
run mkdir -p "$MNT"
UUID=$( [[ $DRY == 1 ]] && echo "<uuid>" || blkid -o value -s UUID "$REAL")

say "database dump to the system disk (belt and braces)"
run install -d -o liampward -g www-data -m 0750 /var/backups/ishop-move
run sudo -u liampward /var/www/ishop/vendor/bin/drush --root=/var/www/ishop sql:dump --gzip --result-file="/var/backups/ishop-move/pre-move-$STAMP.sql"

say "fstab and service ordering"
FSTAB_LINES="# ishop data disk (added $STAMP)
UUID=$UUID $MNT ext4 defaults,discard,nofail 0 2
$MNT/www/ishop /var/www/ishop none bind,nofail,x-systemd.requires-mounts-for=$MNT 0 0
$MNT/mysql /var/lib/mysql none bind,nofail,x-systemd.requires-mounts-for=$MNT 0 0
/var/backups/ishop-bm /var/www/ishop/private/backup_migrate none bind,nofail,x-systemd.requires-mounts-for=/var/www/ishop 0 0"
if [[ $DRY == 1 ]]; then echo "   would append to /etc/fstab:"; sed 's/^/     /' <<<"$FSTAB_LINES"; else
  cp /etc/fstab "/etc/fstab.pre-ishop-data-$STAMP"
  printf '%s\n' "$FSTAB_LINES" >> /etc/fstab
fi
for unit in mariadb:/var/lib/mysql php8.4-fpm:/var/www/ishop apache2:/var/www/ishop; do
  name=${unit%%:*}; path=${unit#*:}
  run mkdir -p "/etc/systemd/system/$name.service.d"
  if [[ $DRY == 1 ]]; then echo "   would write /etc/systemd/system/$name.service.d/ishop-data.conf (RequiresMountsFor=$path)"; else
    printf '[Unit]\nRequiresMountsFor=%s\n' "$path" > "/etc/systemd/system/$name.service.d/ishop-data.conf"
  fi
done
run systemctl daemon-reload
run mount "$MNT"

say "site offline: stop timers and services"
run systemctl stop "${TIMERS[@]}"
run systemctl stop "${SERVICES[@]}"

say "copy site and database to the data disk"
run mkdir -p "$MNT/www" "$MNT/mysql"
run rsync -aHAX --numeric-ids /var/www/ishop/ "$MNT/www/ishop/"
run rsync -aHAX --numeric-ids /var/lib/mysql/ "$MNT/mysql/"
say "backups to the system disk"
run install -d -o liampward -g www-data -m 2770 /var/backups/ishop-bm
run rsync -aHAX --numeric-ids /var/www/ishop/private/backup_migrate/ /var/backups/ishop-bm/
run rm -rf "$MNT/www/ishop/private/backup_migrate"
run install -d -o liampward -g www-data -m 2770 "$MNT/www/ishop/private/backup_migrate"
say "verify copies"
if [[ $DRY == 0 ]]; then
  diff -rq --no-dereference --exclude=backup_migrate /var/www/ishop "$MNT/www/ishop" || die "site copy differs; services still stopped, nothing switched. Restart them with: systemctl start ${SERVICES[*]} ${TIMERS[*]}"
  diff -rq --no-dereference /var/lib/mysql "$MNT/mysql" || die "database copy differs; services still stopped, nothing switched. Restart them with: systemctl start ${SERVICES[*]} ${TIMERS[*]}"
  diff -rq /var/www/ishop/private/backup_migrate /var/backups/ishop-bm || die "backup copy differs; nothing switched"
fi

say "switch: keep old copies as *.pre-move, mount the new ones"
run mv /var/www/ishop /var/www/ishop.pre-move
run mv /var/lib/mysql /var/lib/mysql.pre-move
run install -d -m 0755 /var/www/ishop /var/lib/mysql
run mount /var/www/ishop
run mount /var/lib/mysql
run mount /var/www/ishop/private/backup_migrate

say "start services and timers"
run systemctl start "${SERVICES[@]}"
run systemctl start "${TIMERS[@]}"

say "checks"
if [[ $DRY == 0 ]]; then
  findmnt /var/www/ishop; findmnt /var/lib/mysql; findmnt /var/www/ishop/private/backup_migrate
  test "$(sudo -u liampward /var/www/ishop/vendor/bin/drush --root=/var/www/ishop status --field=bootstrap)" == "Successful"
  test "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/user/login")" == "200"
  test "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/site-office")" == "403"
  df -h / "$MNT"
fi
say "done. Old copies kept in /var/www/ishop.pre-move and /var/lib/mysql.pre-move until Liam confirms."

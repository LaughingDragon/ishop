# 80: Grow the boot disk to 30 GB (snapshot first)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 1 (Phase 1: the item model and phone app)

## Details

Phase 1 step 1. Boot disk 10 GB, 2.4 GB free; item photos need room.

## Instructions

**Who:** Liam, in the Cloud console. **Time:** 10 minutes. **Downtime:** none; the site keeps running.

This grows the **boot disk** (Phase 1 decision 8). Disks can grow but never shrink.

The disk to change, and only this one:
- **Name:** `ishopwaytoomuch`
- **Size:** 10 GB now, 30 GB after
- **In use by:** `ishopwaytoomuch`

**Not** `ishopway2muchdata`, the blank 10 GB extra disk.

### 1. Snapshot it first
1. Open **console.cloud.google.com**, then **Compute Engine → Disks**.
2. Find the row with Name **ishopwaytoomuch**, Size **10 GB** and In use by **ishopwaytoomuch**. Tick it.
3. Click **Create snapshot**, name it `ishopwaytoomuch-before-grow`, and click **Create**.
4. Go to **Compute Engine → Snapshots** and wait until it shows a green tick.

### 2. Grow it
1. Back on **Compute Engine → Disks**, click **ishopwaytoomuch**, then **Edit**.
2. Change **Size** from 10 to **30**, and check the number before saving.
3. Click **Save**. Don't restart the VM.

Or in Cloud Shell, after the snapshot:
```
gcloud compute disks snapshot ishopwaytoomuch --snapshot-names=ishopwaytoomuch-before-grow --zone=us-central1-a
gcloud compute disks resize ishopwaytoomuch --size=30GB --zone=us-central1-a
```

### Then site-foreman
Grows the partition and filesystem while the site runs (`growpart` and `resize2fs`, both ready on the VM), checks the free space, and files a report.

**How to answer:** "Done", plus the snapshot name.

## Answer

Do NOT grow the boot disk. Liam chose Option A in chat with Claude on 2026-10-15 (entered here by Claude (review) at Liam's request). The 30 GB figure came from the disk confusion; the site is ~600 MB and photos are the only growth.

New plan for Phase 1 step 1:
- Boot disk ishopwaytoomuch (10 GB) keeps Debian, code, Drupal and the MariaDB database. Nothing about them moves.
- The existing 10 GB data disk ishopway2muchdata holds ONLY item photos (private://items) and the local backup files (/var/backups/ishop).
- Snapshot ishopway2muchdata first (name, size and In use by), then format and mount it by UUID with nofail, e.g. at /srv/ishop-data.
- MariaDB and Apache must NOT depend on the mount. If it is missing, the site runs and photo pages show a clear "photo storage offline" notice. No RequiresMountsFor on the services.
- Add a health check to status.json and the daily digest: data disk mounted, free space on both disks.
- Open a punch item for Liam: attach the daily snapshot schedule to ishopway2muchdata too (boot disk already covered).
- Off-server bucket backups stay as they are.
Then file a report and continue the Phase 1 build.

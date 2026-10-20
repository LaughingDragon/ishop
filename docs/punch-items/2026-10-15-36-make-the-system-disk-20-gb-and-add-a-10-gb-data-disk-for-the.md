# 36: Make the system disk 20 GB and add a 10 GB data disk for the site

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam

## Details

Liam asked on 2026-10-15 to resize the disk and add a second 10 GB disk, with the system and the site on separate disks. The disk was 73% full (2.5 GB free of 9.7 GB).

## Instructions

**Who:** Liam, in the Cloud console, about 10 minutes. Then Claude does the work inside the VM (the site goes offline for about 10 minutes).
**Cost:** about $1–2 a month for the extra 20 GB in total, depending on the disk type.

The system disk is 10 GB and 73% full. Plan:
- **System disk (`ishopwaytoomuch`): 10 → 20 GB.** Debian, Apache, PHP, MariaDB (the programs) and the **nightly backups**.
- **New data disk (`ishop-data`): 10 GB.** The site: project files, uploads and private files, and the **database files**.

Keeping the backups on the other disk from the site means a failure of either disk still leaves one of them. The daily snapshots remain the off-disk copy.

### Part 1: make the system disk bigger

1. Open **console.cloud.google.com** and go to **Compute Engine → Disks**.
2. Click **ishopwaytoomuch**, then **Edit**.
3. Change **Size** from 10 to **20** GB, then **Save**. The VM keeps running. A disk can grow but never shrink.

### Part 2: create the data disk and attach it

4. Go to **Compute Engine → VM instances** and click **ishopwaytoomuch**, then **Edit** at the top.
5. Under **Additional disks**, click **+ Add new disk** and fill in:
   - **Name:** `ishop-data`
   - **Disk source type:** Blank disk
   - **Disk type:** Balanced persistent disk
   - **Size:** 10 GB
   - **Deletion rule:** **Keep disk** (the data survives if the VM is ever deleted)
   - Under **Advanced** (if shown): **Device name** `ishop-data`
6. Click **Save** on the disk panel, then **Save** at the bottom of the VM page. No restart is needed.

### Part 3: snapshots for both disks

7. If the daily snapshot schedule (`ishop-daily`) already exists: go to **Compute Engine → Disks**, click **ishop-data**, then **Edit**, set **Snapshot schedule** to **ishop-daily**, then **Save**. If it doesn't exist yet, do it as part of the snapshot item, for both disks.

### Or all of it in Cloud Shell

```
gcloud compute disks resize ishopwaytoomuch --size=20GB --zone=us-central1-a
gcloud compute disks create ishop-data --size=10GB --type=pd-balanced --zone=us-central1-a
gcloud compute instances attach-disk ishopwaytoomuch --disk=ishop-data --device-name=ishop-data --zone=us-central1-a
gcloud compute disks add-resource-policies ishop-data --resource-policies=ishop-daily --zone=us-central1-a
```

(Skip the last line if the snapshot schedule doesn't exist yet.)

### What Claude does next (no action for you)

1. Take a fresh database backup. If the snapshot schedule exists, ask you for a manual snapshot first.
2. Grow the system partition and file system to 20 GB, with no restart, and check with `df -h`.
3. Format `ishop-data` and mount it at boot by its ID, so the VM still boots if the disk is ever missing.
4. Take the site offline for about 10 minutes:
   - stop Apache, PHP and MariaDB;
   - copy the project and the database files to the data disk and check them;
   - mount them back at their usual paths, so no settings change;
   - move the backup folder onto the system disk;
   - start everything again.
5. Check the site, the site office, a test backup and a reboot. Keep the old copies until you say all is well, then delete them.
6. Record all of it in a build report.

**How to answer:** "Done" (or "Done with changes: ..."), plus when the 10-minute outage is OK, for example "any time" or "after 8 p.m.".

## Answer

Incorrerct. We just added an extra 10GB drive.
We need to move the stuff off the system drive to the drive and mount it somewhere.

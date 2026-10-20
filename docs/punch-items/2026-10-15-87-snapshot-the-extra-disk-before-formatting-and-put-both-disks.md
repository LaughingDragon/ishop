# 87: Snapshot the extra disk before formatting, and put both disks on the snapshot schedule

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 1 (Phase 1: the item model and phone app)

## Details

Phase 1 step 1, changed plan: ishopway2muchdata holds item photos and local backups. Formatting waits for this snapshot.

## Instructions

**Who:** Liam, in the Cloud console. **Time:** 10 minutes. **Downtime:** none.

The blank extra disk is about to become the home for item photos and local backup files. **Snapshot it before site-foreman formats it**, and put both disks on your daily snapshot schedule.

The two disks, by name, size and In use by:

| Name | Size | In use by | What it is |
|---|---|---|---|
| `ishopway2muchdata` | 10 GB | `ishopwaytoomuch` | the blank extra disk, to become the photo and backup disk |
| `ishopwaytoomuch` | 10 GB | `ishopwaytoomuch` | the boot disk: system, code, database |

### 1. Snapshot the extra disk
1. Open **console.cloud.google.com**, then **Compute Engine → Disks**.
2. Find the row with Name **ishopway2muchdata**, Size **10 GB** and In use by **ishopwaytoomuch**. Tick it, then click **Create snapshot**.
3. Name it `ishopway2muchdata-before-format`, and click **Create**.
4. Go to **Compute Engine → Snapshots** and wait for the green tick.

### 2. Daily snapshots of both disks
1. Go to **Compute Engine → Disks** and click **ishopway2muchdata**, then **Edit**. Set **Snapshot schedule** to your daily schedule, then **Save**.
2. Do the same for **ishopwaytoomuch**.

Or in Cloud Shell (replace SCHEDULENAME with your schedule's name):
```
gcloud compute disks snapshot ishopway2muchdata --snapshot-names=ishopway2muchdata-before-format --zone=us-central1-a
gcloud compute disks add-resource-policies ishopway2muchdata --resource-policies=SCHEDULENAME --zone=us-central1-a
gcloud compute disks add-resource-policies ishopwaytoomuch --resource-policies=SCHEDULENAME --zone=us-central1-a
```

### Then site-foreman
Formats **ishopway2muchdata** only, mounts it at `/srv/ishop-data` (by UUID, `nofail`), and moves item photos and the local backup files onto it. The site and database stay on the boot disk and keep working if the extra disk is ever missing. A report follows.

**How to answer:** "Done", plus the snapshot name and your schedule's name.

## Answer

done

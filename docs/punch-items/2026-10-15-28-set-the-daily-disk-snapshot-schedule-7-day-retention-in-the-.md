# 28: Set the daily disk snapshot schedule (7-day retention) in the Cloud console

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Until then the nightly backups sit on the same disk as the site, so a lost disk loses both. (Earlier this was deferred until after Phase 7.)

## Instructions

**Who:** Liam. **Time:** about 10 minutes. **Cost:** small. Snapshots are incremental, so this disk should cost well under $1 a month.

The nightly backups are on the same disk as the site, so losing the disk loses both. A daily snapshot of the disk, kept 7 days, fixes that.

### In the Cloud console

1. Open **console.cloud.google.com** and choose the project that holds the VM.
2. Go to **Compute Engine → Snapshots**, then the **Snapshot schedules** tab, then **Create snapshot schedule**.
3. Fill in:
   - **Name:** `ishop-daily`
   - **Region:** `us-central1` (the VM is in us-central1-a)
   - **Snapshot location:** Regional, `us-central1`
   - **Schedule frequency:** Daily
   - **Start time:** 10:00 UTC. That's 3 a.m. Pacific in summer and 2 a.m. in winter, after the 02:15 backup in summer and before the 4 a.m. reboot window.
   - **Auto-delete snapshots after:** 7 days
   - **Deletion rule:** Keep snapshots (they survive if the disk is ever deleted)
4. Press **Create**.
5. Go to **Compute Engine → Disks**, then click the disk **ishopwaytoomuch**, then **Edit**.
6. Under **Snapshot schedule**, choose **ishop-daily**, then **Save**.
   - If the data disk **ishop-data** exists by now (see the disk item), do steps 5 and 6 for it too, so both disks are snapshotted.
7. The next day, check **Compute Engine → Snapshots**: a snapshot whose name starts with `ishopwaytoomuch` should be listed.

### Or with gcloud (Cloud Shell)

```
gcloud compute resource-policies create snapshot-schedule ishop-daily \
  --region=us-central1 --max-retention-days=7 \
  --on-source-disk-delete=keep-auto-snapshots \
  --daily-schedule --start-time=10:00 --storage-location=us-central1
gcloud compute disks add-resource-policies ishopwaytoomuch \
  --resource-policies=ishop-daily --zone=us-central1-a
# and, once the data disk exists:
gcloud compute disks add-resource-policies ishop-data \
  --resource-policies=ishop-daily --zone=us-central1-a
```

**How to answer:** "Done", plus anything you set differently. I'll tick the roadmap item and close this.

## Answer

completed

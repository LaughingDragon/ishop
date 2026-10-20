# 263: Take a boot-disk snapshot before the redesign (no Amazon, no returns)

- Opened: 2026-10-16
- Waiting on: liam
- Status: closed
- Closed: 2026-10-16
- Answered by: liam
- Phase: 6 (Phase 6: Amazon import and return windows (removed))

## Instructions

**Who:** Liam, in the Google Cloud Console. **Time:** about 3 minutes (the snapshot itself takes a few more minutes to finish).

This is a safety copy before the redesign removes the Amazon-import and return-window parts of the site (decision coming with the report). The server can't take snapshots itself (it has no permission for that), so this one is yours. Nothing is deleted in this step.

1. Open **console.cloud.google.com**, then **Compute Engine > Disks**.
2. Find the BOOT disk. Check all three:
   - Name: **ishopwaytoomuch**
   - Size: **10 GB**
   - In use by: **ishopwaytoomuch**
   - (The other 10 GB disk, **ishopway2muchdata**, holds the photos and backups. Leave it alone for this step.)
3. Click the boot disk's name, then **Create snapshot** at the top.
4. Name it **ishop-boot-pre-redesign-20261016**. Leave Location and everything else as they are. Click **Create**.
5. Open **Compute Engine > Snapshots** and wait until it shows a green tick (Ready).

**How to answer:** type **Snapshot ready** plus the snapshot's name, or say what you saw if anything looked different. Then tap **Save answer and close**.

## Answer

Not needed: Liam confirmed both disks have daily scheduled snapshots (7-day retention) and there's no real data yet.

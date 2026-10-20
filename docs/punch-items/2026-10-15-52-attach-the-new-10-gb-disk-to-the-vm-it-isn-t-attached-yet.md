# 52: Attach the new 10 GB disk to the VM (it isn't attached yet)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Liam's answer on the earlier disk item: no resize, just an extra 10 GB drive, with the site moved off the system drive onto it. On 2026-10-15 the VM sees only its original disk.

## Instructions

**Who:** Liam, in the Cloud console. **Time:** 5 minutes.

The new 10 GB disk exists, but it is **not attached to the VM**: inside the VM, Linux sees only the original disk (checked 2026-10-15 11:00 Pacific). Until it's attached, there's nothing to move the site onto.

### Reopened: the VM still doesn't see it

This item was answered "done" at 11:07, but at 11:13 (and again later) Linux on the VM still saw **only the original 10 GB disk**. A disk attached to this VM appears instantly, so check:

- **VM instances → ishopwaytoomuch → Storage / Additional disks:** is the new disk listed? If not, it isn't attached to *this* VM. It may be attached to another VM, or the VM edit wasn't saved: Save is at the very bottom of the edit page.
- **Disks list:** what does the **In use by** column say for the new disk? It should be **ishopwaytoomuch**.
- **Zone:** both must be **us-central1-a**.

If it is attached and still doesn't show, tell Claude the disk's name and what "In use by" says.

### Attach it

1. Open **console.cloud.google.com** and go to **Compute Engine → Disks**. Find the new 10 GB disk and note its **Name** and **Zone**.
   - The zone **must be us-central1-a**, the same as the VM. A disk in another zone can't be attached. If it's wrong, delete it and create it again in us-central1-a.
2. Go to **Compute Engine → VM instances**, click **ishopwaytoomuch**, then **Edit** (top of the page).
3. Under **Additional disks**, click **+ Attach existing disk** and fill in:
   - **Disk:** the new disk
   - **Mode:** Read/write
   - **Deletion rule:** **Keep disk** (the site's data survives if the VM is ever deleted)
   - Under **Advanced** (if shown): **Device name** `ishop-data`
4. Click **Save** on the disk panel, then **Save** at the bottom of the VM page. No restart is needed.
5. Add it to the daily snapshots: **Compute Engine → Disks**, click the new disk, then **Edit**. Set **Snapshot schedule** to your daily schedule, then **Save**.

Or in Cloud Shell (replace DISKNAME and SCHEDULENAME):
```
gcloud compute instances attach-disk ishopwaytoomuch --disk=DISKNAME --device-name=ishop-data --mode=rw --zone=us-central1-a
gcloud compute instances set-disk-auto-delete ishopwaytoomuch --disk=DISKNAME --no-auto-delete --zone=us-central1-a
gcloud compute disks add-resource-policies DISKNAME --resource-policies=SCHEDULENAME --zone=us-central1-a
```

### Then Claude moves the site onto it (about 10 minutes offline)

- **Moves to the new disk, mounted at `/srv/ishop-data`:** the project (`/var/www/ishop`: code, uploads, private files) and the database files (`/var/lib/mysql`). They are mounted back at their usual paths, so no settings change.
- **Stays on the system disk:** Debian, the Apache, PHP and MariaDB programs, and the **nightly backups**, so one disk failing never takes both the site and its backups.
- **Afterwards:** check the site, the site office, a backup and a reboot. The old copies stay until you confirm, then they're deleted to free about 0.6 GB on the system disk.

**How to answer:** "Attached, disk name ___", plus when 10 minutes offline is OK, for example "any time".

## Answer

Done

# 64: Create and attach a 10 GB data disk (the attached one is 100 GB)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Disks can't shrink. The site moved onto the 100 GB disk ishop2much-data at 11:18 on 2026-10-15; Liam meant 10 GB.

## Instructions

**Who:** Liam, in the Cloud console. **Time:** 5 minutes.

The attached data disk (**ishop2much-data**) is **100 GB**; you meant 10 GB. Google Cloud disks can grow but **never shrink**, so the fix is a new 10 GB disk. The site already runs on the 100 GB disk (moved 2026-10-15 at 11:18), so **do not delete or detach it yet**. Claude copies the site across first.

**Cost meanwhile:** a 100 GB disk costs roughly $4–10 a month depending on type, billed by the second, so a day or two costs pennies. No rush.

### Reopened: the VM still doesn't see a 10 GB disk

This was answered "Done. added", but Linux on the VM sees only the system disk (10 GB) and **ishop2much-data** (100 GB). Please check:

- **Compute Engine → Disks:** is there a 10 GB disk? What does its **In use by** column say? It must be **ishopwaytoomuch**. If it's blank, the disk was created but not attached: do steps 1–3 below and pick **+ Attach existing disk** instead of "Add new disk".
- **Zone:** the disk must be in **us-central1-a**.
- On the VM edit page, the **Save** button is at the very bottom; the disk panel's own Save isn't enough.

### Create and attach the 10 GB disk

1. Open **console.cloud.google.com**, then **Compute Engine → VM instances**, click **ishopwaytoomuch**, then **Edit**.
2. Under **Additional disks**, click **+ Add new disk** and fill in:
   - **Name:** `ishop-data`
   - **Disk source type:** Blank disk
   - **Disk type:** Balanced persistent disk
   - **Size:** **10** GB (check this field before saving)
   - **Deletion rule:** **Keep disk**
   - Under **Advanced**: **Device name** `ishop-data`
3. Click **Save** on the disk panel, then **Save** at the **very bottom** of the VM page.
4. Check: **Compute Engine → Disks** should list **ishop-data**, **10 GB**, **In use by: ishopwaytoomuch**.
5. Add it to the daily snapshot schedule: click **ishop-data**, then **Edit**, set **Snapshot schedule** to your daily schedule, then **Save**.

Or in Cloud Shell (replace SCHEDULENAME):
```
gcloud compute disks create ishop-data --size=10GB --type=pd-balanced --zone=us-central1-a
gcloud compute instances attach-disk ishopwaytoomuch --disk=ishop-data --device-name=ishop-data --zone=us-central1-a
gcloud compute instances set-disk-auto-delete ishopwaytoomuch --disk=ishop-data --no-auto-delete --zone=us-central1-a
gcloud compute disks add-resource-policies ishop-data --resource-policies=SCHEDULENAME --zone=us-central1-a
```

### Then

Claude checks the VM can see it, copies the site and database across (about 1 minute offline), switches over and tests. A separate punch list item will then tell you how to detach and delete the 100 GB disk.

**How to answer:** "Done", plus the size shown in the Disks list.

## Answer

Done. Forgot to save

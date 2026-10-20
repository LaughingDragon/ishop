# 73: Set up off-server backups: bucket, VM storage access, boot disk snapshots

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Prevention items 2 and 4 from the incident report, approved by Liam 2026-10-15.

## Instructions

**Who:** Liam, in the Cloud console. **Time:** about 20 minutes. **Downtime:** 2–3 minutes in part B (the VM has to be stopped once).

### A. Create the backup bucket
1. Open **console.cloud.google.com**, then **Cloud Storage → Buckets**, then **+ Create**.
2. **Name:** `ishop-offsite-627091137633`.
3. **Location type:** Region, **us-central1**.
4. **Storage class:** Standard.
5. **Access control:** tick **Enforce public access prevention**, and choose **Uniform**.
6. Click **Create**.
7. Open the bucket, go to the **Lifecycle** tab, and click **Add a rule**. Choose **Delete object**, with two conditions: **Age: 30 days**, and **Object name matches prefix** `ishopwaytoomuch/backup-`. Click **Create**. The prefix matters: item photos go in the same bucket (under `ishopwaytoomuch/items/`) and must **never** age out.
8. Go to the **Permissions** tab and click **Grant access**.
   - **New principals:** the VM's service account. Copy it from **Compute Engine → VM instances → ishopwaytoomuch → Details → API and identity management → Service account**. It starts with `627091137633-compute`.
   - **Roles:** add **Storage Object Creator** and **Storage Object Viewer**.
   - Click **Save**.

These two roles let the VM add files and read them back, but not delete or overwrite them.

### B. Let the VM write to storage (stops the site for 2–3 minutes)
The VM's access is currently read-only for storage. Google only allows changing this while the VM is stopped.
1. Go to **Compute Engine → VM instances** and click **ishopwaytoomuch**. Click **Stop**, then wait until the status says Stopped.
2. Click **Edit**. Under **API and identity management**, find **Access scopes**. Choose **Set access for each API**, set **Storage** to **Read Write**, and leave the rest as they are.
3. Click **Save** at the very bottom, then click **Start / Resume**.
4. After 2 minutes, check that https://ishoponamazonwaytoomuch.ohbah.com/site-office loads.

### C. Daily snapshots of the boot disk
1. Go to **Compute Engine → Disks** and click **ishopwaytoomuch**. This is the boot disk: 10 GB, **In use by: ishopwaytoomuch**. It now holds the whole site.
2. Click **Edit**, set **Snapshot schedule** to your daily schedule, and click **Save**.

### D. Optional: delete the blank disk (saves a little money)
The boot disk is growing to 30 GB instead (punch item 80), so this extra disk isn't needed.
Only do this for **ishopway2muchdata**: 10 GB, empty, **In use by: ishopwaytoomuch**. **Not** ishopwaytoomuch, the boot disk.
1. **Snapshot first:** go to **Compute Engine → Disks**, tick **ishopway2muchdata**, click **Create snapshot**, name it `ishopway2muchdata-before-delete`, then click **Create**.
2. Go to **VM instances → ishopwaytoomuch → Edit**. Under **Additional disks**, remove **ishopway2muchdata**, then click **Save** at the very bottom.
3. Go to **Compute Engine → Disks**. Check that **ishopway2muchdata** shows nothing under **In use by**, then tick it and click **Delete**.

### Cloud Shell instead (A to C)
```
SA=$(gcloud compute instances describe ishopwaytoomuch --zone=us-central1-a --format='value(serviceAccounts[0].email)')
gcloud storage buckets create gs://ishop-offsite-627091137633 --location=us-central1 --uniform-bucket-level-access --public-access-prevention
echo '{"rule":[{"action":{"type":"Delete"},"condition":{"age":30,"matchesPrefix":["ishopwaytoomuch/backup-"]}}]}' > lc.json
gcloud storage buckets update gs://ishop-offsite-627091137633 --lifecycle-file=lc.json
gcloud storage buckets add-iam-policy-binding gs://ishop-offsite-627091137633 --member=serviceAccount:$SA --role=roles/storage.objectCreator
gcloud storage buckets add-iam-policy-binding gs://ishop-offsite-627091137633 --member=serviceAccount:$SA --role=roles/storage.objectViewer
gcloud compute instances stop ishopwaytoomuch --zone=us-central1-a
gcloud compute instances set-service-account ishopwaytoomuch --zone=us-central1-a --service-account=$SA --scopes=logging-write,monitoring-write,service-management,service-control,trace,storage-rw
gcloud compute instances start ishopwaytoomuch --zone=us-central1-a
gcloud compute disks add-resource-policies ishopwaytoomuch --resource-policies=SCHEDULENAME --zone=us-central1-a
```
(Replace SCHEDULENAME with the name of your daily schedule.)

### Then site-foreman
Sets the bucket name on the VM, runs a test copy, checks every file arrived, and turns on the nightly 02:45 copy.

**How to answer:** "Done A–C" (plus D if you did it), or say which step didn't work.

## Answer

skip off site backups and back8up schedules. its delaying development

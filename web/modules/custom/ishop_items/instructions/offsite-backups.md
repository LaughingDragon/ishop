**Who:** Liam, in the Cloud console. **Time:** about 15 minutes. **Downtime:** 2–3 minutes in part B (the VM is stopped once).

**Why now:** the site now holds 10 real items. You paused off-server backups until there was real data (decision 131); this item opened by itself when the 10th item was added. Until this is done, the database and photos exist only on this VM. The server side is ready; it needs only a bucket and permission to write to it.

### A. Create the backup bucket
1. Open **console.cloud.google.com**, then **Cloud Storage → Buckets → + Create**.
2. **Name:** `ishop-offsite-627091137633`. **Location type:** Region, **us-central1**. **Storage class:** Standard.
3. **Access control:** tick **Enforce public access prevention**, choose **Uniform**, then **Create**.
4. Open the bucket, go to the **Lifecycle** tab, then **Add a rule**. Choose **Delete object**, with two conditions: **Age 30 days** and **Object name matches prefix** `ishopwaytoomuch/backup-`. Click **Create**. The prefix matters: photos (under `ishopwaytoomuch/items/`) must **never** expire.
5. Go to the **Permissions** tab, then **Grant access**:
   - **New principals:** the VM's service account. Copy it from **Compute Engine → VM instances → ishopwaytoomuch → Details → API and identity management → Service account**. It starts with `627091137633-compute`.
   - **Roles:** **Storage Object Creator** and **Storage Object Viewer**. Then **Save**.

   These let the VM add files and read them back, but never delete or overwrite them.

### B. Let the VM write to storage (site offline 2–3 minutes)
1. Go to **Compute Engine → VM instances → ishopwaytoomuch** and click **Stop**. Wait for **Stopped**.
2. Click **Edit**. Under **Access scopes**, choose **Set access for each API**, set **Storage** to **Read Write**, and leave the rest.
3. Click **Save** at the very bottom, then **Start / Resume**. Wait 2 minutes and check the site loads.

### Cloud Shell instead
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
```

### Then site-foreman
Sets the bucket name and copies the backups and photos. Checks every file arrived. Restores the newest database dump into a scratch database to prove it works. Turns on the nightly 02:45 copy. The status board then shows "Last off-server backup".

**How to answer:** "Done A and B", or which step didn't work.

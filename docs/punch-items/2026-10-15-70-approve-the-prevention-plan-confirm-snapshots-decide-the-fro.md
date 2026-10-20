# 70: Approve the prevention plan, confirm snapshots, decide the front page

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

After the data disk loss on 2026-10-15. Details in the incident report.

## Instructions

**Who:** Liam. **Time:** 10 minutes.

Today's incident report (build report "Incident: data disk loss") lists five prevention steps. They need your yes or no, plus two quick checks.

### 1. Read the proposal
Open the site office, then **Build reports**, then **Incident: data disk loss**. Under **What's next** are prevention items 1 to 5.

### 2. Snapshots (in the Cloud console)
1. Open **console.cloud.google.com**, then **Compute Engine → Snapshots**.
2. Note each snapshot's **Name** and **Source disk**. Is there one of `ishopwaytoomuch` taken today? Is there any of `ishop2much-data` or `ishopway2much-data`?
3. If there's none of `ishopwaytoomuch` from today: go to **Compute Engine → Disks**, tick **ishopwaytoomuch** (10 GB, **In use by: ishopwaytoomuch**), click **Create snapshot**, name it `ishopwaytoomuch-after-recovery`, then click **Create**.

### 3. Front page
Right now the logo and the site's home address show an empty page. Should they go straight to the site office instead? Visitors who aren't logged in would see the login gate, as they do now.

**How to answer:**
- "Approve 1-5", or name the items you don't want.
- The snapshot names, with the source disk of each.
- Front page: "site office" or "leave it".

## Answer

Approve 1–5. Dropping the separate data disk, backups copied off the server, git pushed to a private remote, and snapshots on the boot disk are exactly the gaps that turned a mistake into a loss.

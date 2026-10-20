# 31: Is the VM's external IP address static (reserved)?

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: foundation (Foundation: server, stack, HTTPS, hardening)

## Details

DNS points at 136.71.47.60. If the address is ephemeral, stopping and starting the VM changes it and the site disappears until the FreeDNS record is updated. I could not check this from the VM. Cloud console: VPC network, IP addresses.

## Instructions

**Who:** Liam. **Time:** 5 minutes.

The domain points at **136.71.47.60**. If that address is *ephemeral* (temporary), Google may give the VM a new one after it is stopped and started. The site would then vanish until the DNS record is updated. A *static* (reserved) address never changes.

### Check

1. Open **console.cloud.google.com**, then **VPC network → IP addresses**.
2. Find **136.71.47.60**.
3. Read the **Type** column: **Static** or **Ephemeral**.

### If it says Ephemeral, make it static

4. On that row, click the three dots (⋮), then **Promote to static IP address**. Name it `ishop-ip`, then **Reserve**. The address stays exactly the same, so DNS needs no change.

   Or in Cloud Shell:
   ```
   gcloud compute addresses create ishop-ip --addresses=136.71.47.60 --region=us-central1
   ```

**Cost:** an address attached to a running VM costs the same whether static or ephemeral. A static address is only charged extra while it is *not* attached to a running VM.

**How to answer:** "Was static", or "Was ephemeral, now promoted to static".

## Answer

Yes

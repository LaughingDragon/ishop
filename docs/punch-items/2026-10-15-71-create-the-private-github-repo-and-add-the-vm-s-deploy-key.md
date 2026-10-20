# 71: Create the private GitHub repo and add the VM's deploy key

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: liam
- Phase: 0 (Phase 0: safety net)

## Details

Prevention item 3 from the incident report, approved by Liam 2026-10-15.

## Instructions

**Who:** Liam, on github.com. **Time:** 5 minutes.

### 1. Create a private repository
1. On **github.com**, click **+** at the top right, then **New repository**.
2. **Name:** `ishop`. Choose **Private**. Leave **Add a README**, .gitignore and license **off**; the repository must be empty.
3. Click **Create repository**.

### 2. Let the VM push to it, and only to it
1. In the new repository, open **Settings**, then **Deploy keys**, then **Add deploy key**.
2. **Title:** `ishopwaytoomuch VM`.
3. **Key:** paste this one line exactly. It's a public key, so it's safe to share:

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFyf387DSqjCg999eBcRFJiNi1d+m2MZzwhzM2sfjH+c ishopwaytoomuch deploy key (git push)
```

4. Tick **Allow write access**, then click **Add key**.

### Then site-foreman
Pushes the full history and pushes again at the end of every work session. The weekly update job will push too.

**How to answer:** the repository's address, like `yourname/ishop`.

## Answer

https://github.com/LaughingDragon/ishop

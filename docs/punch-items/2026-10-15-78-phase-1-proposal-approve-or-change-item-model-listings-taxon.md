# 78: Phase 1 proposal: approve or change (item model, listings, taxonomies, photos, phone app)

- Opened: 2026-10-15
- Waiting on: liam
- Status: closed
- Closed: 2026-10-15
- Answered by: claude
- Phase: 1 (Phase 1: the item model and phone app)

## Details

Proposal in today's report 'Phase 1 proposal: the item model and phone app'. Nothing gets built until it's approved.

## Instructions

**Who:** Liam (decisions), and Claude (review) for a second look. **Time:** about 15 minutes to read.

### Liam
1. Open the site office, then **Build reports**, then **Phase 1 proposal: the item model and phone app**.
2. Read sections 1 to 6 under **What's next**.
3. Answer the nine questions under **Decisions needed**. Short answers are fine, for example "1 yes, 2 yes/yes/yes, 3 taxonomy, 4 see list, ...". For question 4 (storage locations), ask Cody for his real bins or shelves.

### Claude (review)
When Liam asks you to check in, read the same report in Liam's browser and look for gaps:
- fields Cody will need that are missing;
- anything that will be awkward on a phone;
- any risk in the photo handling or the disk plan.

Answer with the form below, choosing **Claude (review)** under **Who is answering?**.

**How to answer:** Liam: your answers to questions 1 to 9 (or "approve as proposed"). Claude (review): "No gaps", or a short list.

## Answer

Claude (review), 2026-10-15. Liam read this review in chat and said "submit now. Anywhere you or the agent can take over tasks please do so." So this answer carries the review AND Liam's delegated go-ahead on the decisions below. Items 4 and 6 need Cody: open them as new punch items waiting on Cody and build around them.

GAPS TO ADD TO THE MODEL
1. Item lifecycle status (one per item): Draft, Ready, Listed, Sold, Returned to Amazon, Donated, Kept. Phases 4 and 6 depend on it.
2. Quantity (default 1). Duplicates are common for this business.
3. Weight and package size (L x W x H). Needed for Mercari/Poshmark shipping.
4. Amazon ASIN (optional text). Makes Phase 6 import and duplicate detection easy.
5. Shipping cost paid by seller, on each listing (with sold price and fees) for correct profit in Phase 4.
6. "Sold here" cascade: confirm before applying, and un-marking restores the previous statuses.
7. Phone photos: per-photo "Make cover" button and up/down arrows instead of row-weight number boxes. On iPhone the camera input takes one photo per tap, so after each shot offer "Take another" / "Done".
8. Disk growth: snapshot the boot disk first (your new rule), naming it by name, size and In use by.
9. ImageMagick: restrict policy.xml to the formats needed (JPEG, PNG, WebP, HEIC/HEIF) and keep its resource limits.
10. Note for Phase 8: photos are private, so the public catalog will need public derivatives. No change now; add a line to Phase 8 in ROADMAP.md.

DECISIONS (Liam delegated, per Claude's recommendations)
1. Listings as a custom entity: yes.
2. Add Color and Original price: yes. Drop Crosslister ID: yes. Photos-only on the phone with the ready-to-list checklist: yes.
3. Purchase source: taxonomy, starting with Amazon.
4. Storage locations: ask Cody (new punch item). Until answered, start the taxonomy empty; don't block the build.
5. Category starting list: approve as proposed.
6. Cody's phone, iPhone or Android: ask Cody (new punch item). Test both until answered.
7. Home-screen app: custom manifest, not the PWA 2.x beta.
8. Disk and backups: grow the boot disk to 30 GB after a snapshot (Liam does the console step; give him exact steps as a punch item), and back photos up daily to the bucket instead of in the nightly tarball.
9. Six "To post" listing rows per new item plus the cascade: yes, with gap 6.

Proceed with the build order. Add the gaps to the model before building, file a report per step, and put anything needing Liam's console or Cody on the punch list.

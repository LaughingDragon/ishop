# 261: AI cost: cheaper default for Write my listing (test about $1 first?)

- Opened: 2026-10-16
- Waiting on: liam
- Status: closed
- Closed: 2026-10-16
- Answered by: liam
- Phase: 7 (Phase 7: AI listing (Cody's own Anthropic account))

## Instructions

**Who:** Liam. **Time:** about 3 minutes, reading only.

**What I found.** "Write my listing" has cost $0.30 to $0.64 an item (average $0.45, 3 runs). That buys about 70 items a month under Cody's $32 limit. Almost all of the cost is *input*: each run started at about 10,000 tokens (photos plus instructions) but was billed 54,000 to 135,000. That's because the AI re-reads the photos and every search result after each of its 5 web searches. Photos sent at 1568 px cost about 3,000 tokens each; at 1092 px they cost about 1,800 (measured with Anthropic's free token counter on item 256).

The bigger problem: **neither of today's drafts found real sold prices.** Item 255 found only new/asking prices, and on item 256 the AI said it hit its search limit before finding sold prices. Both prices are estimates. So today's spend isn't reliably buying sold comps.

**Proposal (new default):**
1. Model: **Claude Sonnet 5.5** instead of Claude Opus 5.5 (half the price per token).
2. Photos sent to the AI at **1092 px** instead of 1568 px (about 45% fewer photo tokens).
3. Web search limited to resale sites (eBay, Poshmark, Mercari, Depop, Grailed, Vinted, StockX), so searches land on sale records instead of shops. Max **4** searches instead of 5.
4. Everything else stays: same prompt, same fields, the honesty rule (no search = no "market data" shown), Cody reviews every draft.

**Expected cost:** about **$0.15 to $0.20 an item** (roughly 160 to 200 items a month on $32). That's an estimate from today's token counts, not a measurement yet.

**Before switching**, I'd run a side-by-side test: the new setup on the 3 items that already have drafts (121 puzzle, 255 ornaments, 256 power bank). I'd compare the brand/size reading, the price, and whether real sold prices came back. That costs about **$0.60 to $1.00** on Cody's Anthropic account, and the test drafts are shown only in my report, not on his items. If the test is worse at reading tags (smaller photos) or finds fewer sold prices, I'll say so and suggest keeping Opus at the smaller photo size instead (about $0.25 an item).

**How to answer:** open this item, choose **Liam** under "Who is answering?", and type one of:
- **Test it**: run the side-by-side test (about $1 on Cody's account), then report. No switch yet.
- **Switch now**: change the default without testing.
- **Keep as is**: no change.
- Or write your own changes.

Then tap **Save answer and close**.

## Answer

Switch now: change the default without testing.

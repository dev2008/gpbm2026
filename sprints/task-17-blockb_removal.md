# Task: Remove "block B" — 2,100 mislabelled rows in `legacy_play_log`

**Status:** Not started. Fully diagnosed during the `game_id` backfill (Aug 2026).
**Priority:** Low. The rows are already isolated (`game_id IS NULL`) and cannot reach the app.

---

## 1. What it is

`legacy_play_log` holds **2,100 rows** labelled NFLAR season 2024 that are actually
**NFLAR 2025, weeks 4-18**. Identified precisely as `play_log_id BETWEEN 923175 AND 925274`
with `league_code = 'NFLAR' AND season = 2024`.

## 2. Evidence (do not re-derive)

- Block B's 14 week/opponent pairs match season **2025 on 14 of 14**; next best season scores 9
- Row counts match 2025 **exactly** on all nine non-colliding weeks: 153/153, 148/148, 136/136,
  145/145, 145/145, 154/154, 147/147, 161/161, 152/152
- Block totals exactly 2,100, matching the sum of those fourteen fixtures
- Ingest order runs NCAA5 2024 -> block B -> NCAA5 2025 -> NCAA5 2026 -> NFLAR 2025, so block B
  occupies NFLAR 2025's sequence position but was stamped 2024, then re-imported correctly at
  id 929532+ without the bad rows being removed

**NFLAR 2025 already holds a complete, correct copy.** These rows are redundant, not unique.

## 3. Why it mattered

Nine of the fourteen weeks fail to resolve on their own. **Five (weeks 6-10) coincidentally
match 2024's real fixture** and would have received wrong `game_id`s — passing every structural
check, because both team codes genuinely match a real fixture, just not the right one.

The `team_codes` fix made this worse rather than better: block B week 6 is `OA-PE`, and 2024
week 6 really was Oakland. Before the correction it failed because `OA` resolved to "Las Vegas
Raiders"; after, it matches.

The backfill excluded the whole block. After exclusion, NFLAR 2024 weeks 6-10 came back at
143, 150, 144, 139, 153 — exactly the figures predicted arithmetically beforehand.

## 4. Proposed work

```sql
-- Confirm the block is still exactly what it was
SELECT COUNT(*) AS rows_in_block, SUM(game_id IS NOT NULL) AS any_populated
FROM   legacy_play_log
WHERE  league_code = 'NFLAR' AND season = 2024
  AND  play_log_id BETWEEN 923175 AND 925274;
-- expect 2100 / 0
```

Then either:

- **(a) Delete.** Simple, and 2025 holds the correct copy. But `legacy_play_log` is a frozen
  historical archive and deleting from it sets a precedent worth being deliberate about.
- **(b) Relabel to season 2025.** Preserves the rows, but creates genuine duplicates against
  the correct 2025 copy — which then need the treatment in
  `task-15-legacy_duplicate_fixtures.md`. Probably worse.
- **(c) Leave, and flag.** Add a nullable `excluded_reason` column, or rely on
  `game_id IS NULL` plus documentation. Zero risk, zero benefit beyond tidiness.

**Recommendation: (a) or (c).** Not (b).

## 5. Before deciding

- Take a backup of the affected rows regardless: `CREATE TABLE ... AS SELECT` before any delete
- Check whether any other season has the same shape. The detector is a matchup-week over ~180
  rows where `COUNT(*) / COUNT(DISTINCT result_text)` is near 1.0 (`lessons.md` §19). Only
  NFLAR 2024 wks 6-10 qualified at the time of the backfill, but re-run after any bulk import
- If deleting, re-run the `P.4` NULL accounting in `stage5_backfill.sql` afterwards so the
  documented breakdown stays accurate — category 4 drops from 2,100 to 0

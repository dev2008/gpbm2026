# Findings: `legacy_play_log.game_id` backfill (Part 1)

**Status:** **Complete.** Migration executed and verified 11 Aug 2026.
**Superseded in part:** NFLAR 2034 was re-parsed through the live pipeline immediately
afterwards, removing 3,281 legacy rows from this table. All figures below are post-re-parse —
see §8 for what changed and why.
**Scope:** Part 1 only. Part 2 (surfacing legacy play-by-play in `game.php` / `replay.php`)
remains undesigned — see `task-legacy_playbyplay_part2.md`.
**Source doc:** `task-legacy_playbyplay.md`

---

## 1. Result

| | |
|---|---:|
| Rows in `legacy_play_log` | 250,169 |
| Theoretically resolvable (ceiling) | 202,439 |
| **Populated** | **199,577 (98.59% of ceiling)** |
| Distinct games linked | 1,325 |
| Ambiguous resolutions | 0 |
| Orphaned `game_id` values | 0 |
| Discrepancy vs. validated staging set | 0 |

Every remaining `NULL` is accounted for — the five categories below sum to exactly 50,592:

| Reason | Rows |
|---|---:|
| League has no `leagues` row (7 defunct leagues) | 46,680 |
| NCAA5 2016 — no `seasons` row | 589 |
| Week has no `weeks` row (NCAA5 2017 wks 10–11, 2037 wk 0) | 461 |
| Block B — NFLAR 2025 data mislabelled as 2024 | 2,100 |
| Fixture absent from `f_games` | 762 |

Coverage by live league: **NFLAR 98.2%**, **NCAA5 97.8%**.

The `NULL` total is unchanged at 50,592 — every one of the 3,281 rows removed by the 2034
re-parse was populated, so the ceiling and the populated count fell together.

---

## 2. Summary

The design in the task document was sound in outline and wrong in four specifics, each of
which would have written incorrect data. All four were caught by validation before anything was
executed, and `legacy_play_log` was not written to until every gate had passed.

| | Task doc said | Actually |
|---|---|---|
| Unresolved rows | ~663 | **50,592** — the ceiling is 202,439, not 250,169 |
| Resolver join | offence side only | offence-only **silently mis-assigns**; both sides required |
| `team_codes` | trusted | **four codes carried wrong names**, 7,562 rows affected |
| — | *(not anticipated)* | **2,100 rows are another season's data**, 759 of them dangerous |

---

## 3. What validation caught

### 3.1 The unresolved population was understated by two orders of magnitude

The task doc's §5 predicted ~663 unresolved, citing `schema.md` §9's "240 no-opponent playoff
rows and 423 sentinel/rollup rows". Those are **`f_games` row counts at game grain**. Stage 1
confirmed it exactly: excluding no-opponent fixtures from `f_games` removes precisely **663**
rows. The number was real; it was applied to the wrong table.

`lessons.md` §2 already recorded that most of `legacy_play_log` could not gain `game_id` links.
The task doc contradicted it. Where two project documents disagree, the one with a query behind
it wins.

This mattered operationally. Measured against the doc's expectation, a correct result would
have looked like catastrophic failure, and the likely response would have been to "fix" a
working resolver.

### 3.2 Four `team_codes` entries carried wrong names

Surfaced because 10,154 rows resolved on the offence side but failed when the defence side was
also checked. Investigating that gap rather than dismissing it found the cause.

| Code | Was | Is |
|---|---|---|
| `WC` | Dallas Cowboys | Washington Commanders |
| `TN` | Tennessee Volunteers | Tennessee Titans |
| `OA` | Las Vegas Raiders | Oakland Raiders |
| `GE` | Georgia Tech Yellow Jackets | Georgia Bulldogs |

Each was confirmed from **three independent directions**: resolved from the defence side,
resolved from the offence side (which does not use the mapping under suspicion), and tested at
franchise level via `f_games`. All four sat at **0.0% agreement** with their stored names
across 7,562 rows.

`TN` was additionally proved from a turn file — `NFLAR-PE_s2021_w12_vs_Titans.txt` carries the
code and the name in the same document, with play lines reading `0:00  TN  80  1st and 10` and
the scoreline `10-21 TN` in a game headed *Philadelphia Eagles vs Tennessee Titans*.

All four were applied as `UPDATE`s, so no code gained a second name. Two codes were added
separately by Alan: `LV` (Las Vegas Raiders) and `TV` (Tennessee Volunteers), the latter
freeing `TN` for its real meaning.

**This is the `AF`/"Carolina Panthers" failure shape from `lessons.md` §3 recurring** — a code
that is real, heavily used, and carrying the wrong name.

### 3.3 Block B — 2,100 rows of NFLAR 2025 filed under 2024

The most dangerous finding, and the one nobody was looking for.

NFLAR 2024 arrives in two `play_log_id` ranges. Block A (≤ 920368) matches `f_games` on every
week. Block B (923175–925274, exactly 2,100 rows) matches on weeks 6–10 **only**.

Identified conclusively:

- Block B's 14 week/opponent pairs match season **2025 on 14 of 14**. Next best season: 9.
- Row counts match 2025 **exactly** on all nine non-colliding weeks (153/153, 148/148, 136/136,
  145/145, 145/145, 154/154, 147/147, 161/161, 152/152).
- Ingest order runs NCAA5 2024 → block B → NCAA5 2025 → NCAA5 2026 → NFLAR 2025. Block B
  occupies NFLAR 2025's sequence position but was stamped 2024, then re-imported correctly at
  id 929532+. The bad rows were never removed.

2025 already holds a correct copy, so excluding block B discards nothing.

**The hazard:** on weeks 6–10 block B's fixture coincides with 2024's real fixture. Those **759
rows would pass every structural check** — both codes genuinely match a real fixture, just not
the right one. Neither the ambiguity check nor the both-sides join catches it.

**It got worse with the `team_codes` fix.** Block B week 6 is `OA–PE`, and 2024 week 6 really
was Oakland. Before the correction it failed because `OA` resolved to "Las Vegas Raiders".
After, it matches. Fixing `team_codes` without excluding block B would have *increased* the
contamination.

Verified after exclusion: NFLAR 2024 weeks 6–10 came back at **143, 150, 144, 139, 153** — the
exact figures derived arithmetically before the exclusion was written.

### 3.4 Duplicate copies — 16 fixtures, ~4,400 rows (benign here)

Distinct from block B and harmless for Part 1: the same fixture recorded 2–3 times,
identifiable by `rows_per_distinct_text` near 2.0.

Mechanism confirmed against a turn file — NFLAR 2013 wk 11 PE v MV is Alan v Gordon, so both
coaches' turn files exist and both were ingested: 154 plays in the file, 306 rows in the
archive at ratio 2.00. `lessons.md` §5 records that both sides' play text is byte-identical,
which is why they duplicate cleanly.

Not all are explained by that. NFLAR 2033 wk 14 PE v Arizona has **three** copies (453 rows,
ratio 1.74, three ingest passes ~7,000 ids apart) against a coach who is not a Milnes.
Mechanism unknown; consequence nil for Part 1.

**Safe to backfill** because every copy belongs to the fixture it claims, so the `game_id` is
correct. It becomes a Part 2 problem — `game.php` would render each play two or three times.

---

## 4. Design decisions and rationale

### 4.1 Join both sides of the matchup, not just the offence

| | Rows resolved | Games in 120–180 band |
|---|---:|---:|
| `offense_only` | 198,714 | **88.3%** |
| `both_sides` | 188,560 (pre-fix) | **97.8%** |

`offense_only` resolves ~10,000 more rows and manufactures **117 half-filled junk games** to
hold them. When a mislabelled code sits on the offence side, only that team's offensive snaps
go to the wrong franchise's fixture, producing a partial game. `WC` on offence resolved 1,012
rows under `offense_only` with **zero** confirmed by `both_sides`.

The *distribution* check settled this. Row counts alone made `offense_only` look better.

### 4.2 Resolve into a staging table, not a direct `UPDATE`

MySQL's multi-table `UPDATE` does not error on fan-out — it applies one arbitrarily chosen
match and reports success. On 253,450 rows that failure is invisible afterwards. Resolving into
`tmp_lpl_resolution` with **no unique key on `play_log_id`** made ambiguity countable rather
than silently collapsed. Stage 5 then joined off the validated staging table, so the rows
written were exactly the rows checked — confirmed by a discrepancy of 0.

### 4.3 Materialise `f_games` locally once

~20k tuples copied into `tmp_fgames_map` rather than joining 205k rows across databases
repeatedly. Honours the task doc §3 decision (query `f_games` once, never again), makes every
iteration local, and confines cross-database concerns to one statement.

### 4.4 Exclude the whole of block B, not just the dangerous weeks

Only 759 of 2,100 rows were hazardous; the rest fail on their own. Excluding all 2,100 keeps
one coherent defect as one rule — carving out five weeks would leave something nobody could
reconstruct later.

Deleting block B from `legacy_play_log` was explicitly **out of scope** — see
`task-blockb_removal.md`.

### 4.5 No foreign key on `game_id`

Deliberately inconsistent with `plays`, `drives` and `team_game_stats`, which all reference
`games` with cascade.

| Option | Effect |
|---|---|
| **No FK, index only** (chosen) | Orphans possible; detectable and repairable in one query |
| FK `ON DELETE CASCADE` | One game deletion destroys rows in a frozen archive |
| FK `RESTRICT` | Breaks the delete-and-reupload procedure in `lessons.md` §10 |

Reasoning is recorded in the column's DDL comment per `lessons.md` §12. The orphan check
(`LEFT JOIN games WHERE g.game_id IS NULL`) returned 0 at completion.

---

## 5. Things found that are not this task

| Finding | Scale | Status |
|---|---|---|
| `games.label` names present-day identities, not the identity the franchise played under | 3,487 NFLAR / 516 NCAA5 games | `task-games_label_rebuild.md` |
| Block B — mislabelled rows still in the archive | 2,100 rows | `task-blockb_removal.md` |
| Duplicate-copy fixtures | **13** fixtures, ~3,939 rows (was 16 / ~4,843 before the 2034 re-parse) | `task-legacy_duplicate_fixtures.md` |
| NFLAR 2005 wk 13 missing its overtime | ~20 plays | Noted below |
| NCAA5 has 12 franchises, not 24 | — | Recorded in the identity model |

**NFLAR 2005 wk 13** deserves a note because I got it wrong twice. It shows 182 rows against a
turn file's 202. I first assumed the gap was a counting artefact, then that the 182 was
legitimate because the game went to overtime (the file's clock runs to 70:54). The archive's
`max_time` is **3599** — one second short of regulation. So it is a long regulation game whose
**overtime period is absent from the archive**. Fixture and `game_id` are correct; the coverage
gap is real but small.

**Two things that looked like defects and are not:**

- **Minnesota Vikings play-by-play absent 2014–2026.** Gordon was not actively coaching the
  Vikings for those years. Expected, not a gap.
- **NCAA5 2010, 2011, 2017 nearly empty in `games`.** Partial seasons Alan played. Expected.

Both were initially written up as unexplained gaps because the analysis measured against a
baseline of "every fixture should have play-by-play", which is wrong — the archive only ever
covers games a coach actually played and uploaded.

---

## 6. Where I got it wrong

Recorded because the project's honesty standard applies to my errors as much as to data
defects.

1. **Claimed the NFLAR 2024 doubling was "the same fixture simulated twice."** Inferred from
   id-block shape and clock ranges; never tested. Wrong — it was a different season's data.
   Alan's pushback ("that makes no sense") prompted the check that found it.
2. **Never ran the obvious query.** Three rounds of increasingly elaborate diagnostics before
   simply asking *what matchups does each 2024 week contain*, which answered it immediately.
3. **Counted per week rather than per matchup-week**, which is what made the re-run theory look
   plausible — it conflates "two coached teams both played" with "one game recorded twice".
4. **Recommended switching to `offense_only`** after it resolved 10,154 more rows, then
   reversed when the distribution showed those rows were wrong.
5. **Wrote a broken spot-check** using `OR` across offence/defence, which matched every Eagles
   game *and* every Packers game and proved nothing about the week alignment the whole resolver
   depends on.
6. **Designed R.2/R.3 with a filter that let every opponent through**, producing meaningless
   "Denver Broncos, 20 of 21 missing" rows and failing to answer the coverage question asked.
7. **Estimated the `team_codes` recovery at +7,562** by counting only the defence side; the
   real figure was +15,057. Caught by a dry run.
8. **Predicted 7,006 rows would stay `NULL` as "fixture not in `f_games`"**; the real figure
   after the corrections was 762.
9. **Explained NFLAR 2005 wk 13 as an overtime game** without checking `max_time`, which shows
   the overtime is missing.
10. **Wrote up the MV and NCAA5 gaps as defects** rather than asking what coverage should be
    expected.
11. **Proposed `INSERT`s for `OA` and `TN`** where `UPDATE`s were correct — resolved once Alan
    established that a rename gets its own new code.
12. **Guessed `WF` for Wake Forest** without checking `team_codes`, so a spot-check silently
    returned nothing.
13. **`ORDER BY` on an aggregate alias**, which MariaDB rejects — aborted a run.
14. **Advised `2>&1`**, which swallowed the `mysql` password prompt and produced a confusing
    `using password: NO` failure, then compounded it by suggesting the username was wrong.

The pattern worth keeping: every one was caught by validation output or by Alan's pushback —
none by re-reading my own reasoning. The two most expensive (1 and 3) were both cases of
building an elaborate explanation on an assumption a five-second query would have refuted.

---

## 7. Files

| File | Writes | Purpose |
|---|---|---|
| `stage0_diagnostics.sql` | none | Baseline: ceiling, collation, fan-out, week alignment |
| `stage1_2_resolve.sql` | staging tables | Materialise `f_games`, resolve both strategies |
| `stage2b_diagnose.sql` | none | Play-count anomalies, strategy gap |
| `stage2c_classify.sql` | none | Distribution, duplicate-vs-merged, coach-name test |
| `stage2d_teamcodes.sql` | none | Code-name agreement, both-direction naming |
| `stage2f_census.sql` | none | Archive census + turn-file ground truth |
| `stage2g_blockb.sql` | none | Block B identification, `team_codes` dry run |
| `stage2h_coverage.sql` | none | Coverage completeness, code locations |
| `stage2i_franchise.sql` | none | Franchise-level structural invariants |
| `stage4_prepare.sql` | `team_codes` (4 rows) | Apply fix, re-resolve, validation gate |
| `stage5_backfill.sql` | `legacy_play_log` | `ALTER` + `UPDATE` + verify |

Staging tables `tmp_lpl_resolution` and `tmp_fgames_map` can now be dropped. They were kept
through the documentation pass so the result stays re-derivable.

Rollback, if ever needed:
```sql
UPDATE legacy_play_log SET game_id = NULL;
ALTER TABLE legacy_play_log DROP COLUMN game_id;
```

---

## 8. Superseded by the NFLAR 2034 re-parse (11 Aug 2026)

Immediately after this backfill completed, NFLAR 2034 was re-parsed end to end through the live
pipeline. It is recorded here because it changes figures quoted throughout this document.

**Why.** `f_games` was missing NFLAR 2034 weeks 10–14 entirely — 60 games present in `games`
with full `team_game_stats`, but no fixture rows behind them and no play-by-play at all. Rather
than repair a pipeline being retired, the season was re-sourced from the turn files, which the
parser handles better than the original migration did.

**Scope.** NFLAR 2034 only. Earlier seasons carry format changes the parser has not been
validated against, and the return diminishes the further back you go.

**What changed here:**

| | Before | After |
|---|---:|---:|
| Rows in `legacy_play_log` | 253,450 | 250,169 |
| Populated | 202,858 | 199,577 |
| Ceiling | 205,720 | 202,439 |
| Distinct games linked | 1,344 | 1,325 |
| Duplicate-copy fixtures | 16 (~4,843 rows) | 13 (~3,939 rows) |

The `NULL` breakdown in §1 is unaffected — all 3,281 removed rows were populated.

**What it fixed beyond the gap:** the three duplicated fixtures in 2034 (wk 2 ×2, wk 9 ×1) are
gone, replaced by single clean games; weeks 10–14 have play-by-play for the first time; MV's
week 9 game, absent from the archive, now exists; and all 204 games carry correct labels built
from the turn files' own team names rather than `franchises.label`.

**Verification:** 12 games and 24 stat rows in every week 0–16; `max_play_seq` equal to the row
count in all 32 games with no repeated `play_seq`; 204 labels correct and 0 wrong; zero orphaned
`game_id` links; `standings_weekly` rebuilt as `parsed` rather than `derived` across all 16
weeks.

**Runbook:** `RUNBOOK-reparse_nflar_2034.md`, with `reparse_2034_step*.sql`. Backups
`bak_2034_*` retained until the result is accepted.

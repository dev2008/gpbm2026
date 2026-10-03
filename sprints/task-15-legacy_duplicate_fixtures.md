# Task: Handle duplicate-copy fixtures in `legacy_play_log`

**Status: COMPLETE — 12 Aug 2026.** 2,928 rows removed across 22 fixtures.
`legacy_play_log` 250,169 → 247,241 rows.
**Unblocks:** `task-14-legacy_playbyplay_part2.md`.
**Spawned:** `task-20-legacy_reparse_candidates.md` (43 damaged fixtures), `todo.md` Feature 21.

---

## 1. Result

| Pass | Population | Fixtures | Rows removed | Backup |
|---|---|---:|---:|---|
| Linked | games with a `game_id` | 16 | 2,051 | `bak_f15_legacy_dupes` |
| Unlinked | matchup-weeks with no `game_id` | 6 (NFLA 2009–2010) | 877 | `bak_f15_legacy_dupes_unlinked` |

After: 197,526 linked rows, 49,715 unlinked, 1,325 distinct games (unchanged — no fixture lost
every copy). Every matchup-week in the archive now falls in 100–200 plays bar nine short ones,
all listed in `task-20`. Rollback is a single `INSERT … SELECT *` from either backup;
`legacy_play_log` has no inbound FKs and no FK on `game_id`, so nothing else needs putting back.

**The fixtures.** Linked: 181, 310, 311, 396, 461, 1150, 2382, 3669, 4506, 4614, 4730, 9078,
9349, 9575, 9602, 9608. Unlinked: NFLA 2009 wk5 NE–NJ, 2010 wks 2/14 NE–NJ, wk18 NJ–SS,
wk19 NG–NJ, wk20 NJ–TB.

## 2. What this document originally said, and where it was wrong

Kept deliberately — each error cost real time and each has a general lesson behind it.

**"13 fixtures, ~3,939 rows."** The true figure is 22 and 2,928. Three separate causes:

- **The detector filtered `HAVING COUNT(*) > 180`** — a volume filter, not a duplication one. It
  missed game 9621 (141 rows, ratio 1.44). See below: 9621 turned out not to be a duplicate,
  but the filter could equally have hidden a real one.
- **The detector joined `games`**, so it could only see rows with a `game_id`. Every early stage
  of the analysis inherited that filter and was blind to **50,592 rows**, 46,680 of them in
  seven defunct leagues — which *do* feed the `v_playcall_*_all` views, since those apply no
  league and no `game_id` filter. Six NFLA matchup-weeks were duplicating in the aggregates the
  whole time. `lessons.md` §27.
- **Games 310 and 311 were invisible to a text-based detector**, because their duplicate copy
  has `result_text` NULL on every row.

**§4's proposed fix — `DISTINCT` on `(game_id, time_gone_seconds, result_text)` — would not have
worked.** Game 9602 holds three copies of which only two are byte-identical, so distinct-on-text
leaves 260 rows for a 154-play game. It also merges lines across ingest passes rather than
keeping one coherent copy.

**§3's "unexplained" list included game 9621, which is not a duplicate at all.** Its 141 rows
are one contiguous run with a monotonically advancing clock and no repeated
`(time, down, distance, offense)` tuple. It scored 141 rows against 98 distinct texts because
**`result_text` has two storage formats** — 286 rows carry only the cleaned tail rather than the
whole line, and prose repeats naturally. The rule would have deleted 43 legitimate plays and
passed its own simulation. `lessons.md` §25 corrects §23.

**§5's "NFLAR 2005 wk13 is missing its overtime" is not a one-off.** No row in the entire archive
exceeds `time_gone_seconds` 3599, and 15 NFLAR games have `went_to_ot = 1`. `lessons.md` §29.

## 3. The rule that was used

1. Decompose each affected fixture into maximal runs of consecutive `play_log_id`.
2. Keep one block, by: **most non-NULL `result_text` rows**, then most rows, then lowest
   `min(play_log_id)`. Each clause is there because a real case needed it — 310/311 hold an
   all-NULL copy the same size as the good one and *first* in id order, so neither size nor
   position alone picks correctly; 9602's cleaned-tail copy has 145 rows against 154 because it
   merged plays.
3. Inside the kept block, drop later occurrences of a repeated `result_text` — **only on
   clock-prefixed rows**.

For the unlinked pass, keyed on the matchup-week
`(league_code, season, week, LEAST(off,def), GREATEST(off,def))`, the island rule and the
occurrence rule were run independently and agreed exactly: 877 rows each, zero in either alone.
Two signals sharing no assumption. `lessons.md` §28.

## 4. Method

Staged throughout: read-only inspection → staging table → backup → gated delete → verification.
The delete joined off the backup, so the rows removed were exactly the rows counted and gated
(`lessons.md` §19 applied to a delete).

Scripts, in order: `f15_stageA_identify.sql`, `f15_stageB_blocks.sql`, `f15_stageC_verify.sql`,
`f15_stageD_stage.sql`, `f15_stageE_backup.sql`, `f15_stageF_delete.sql`,
`f15_stageG_source_check.sql`, `f15_ruleset_check.sql`, `f15_stageH_unlinked.sql`,
`f15_stageI_stage_unlinked.sql`, `f15_stageI2_gate_repair.sql`,
`f15_stageJ_delete_unlinked.sql`.

**Three of the verification gates were themselves defective** — one testing a different
condition from the one it named, one emitting nothing on success, one hardcoding a
miscalculated constant. None indicated a data problem; all three are in `lessons.md` §26.

## 5. Cleanup, when Feature 14 has rendered legacy play-by-play successfully

```sql
DROP TABLE bak_f15_legacy_dupes;
DROP TABLE bak_f15_legacy_dupes_unlinked;
DROP TABLE migration_f15_dupe_candidates;
DROP TABLE migration_f15_unlinked_candidates;
DROP TABLE migration_f15_predelete_snapshot;
```

Not before. The two `bak_` tables are the only rollback path.

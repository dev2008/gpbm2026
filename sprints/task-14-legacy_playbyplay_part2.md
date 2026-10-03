# Task: Part 2 — surface legacy play-by-play in `game.php` and `replay.php`

**Status:** Not started, not designed. Part 1 (`legacy_play_log.game_id` backfill) is
**complete** — 202,858 rows linked, 98.61% of the achievable ceiling.
**Blocked on:** `task-15-legacy_duplicate_fixtures.md`.

**Note (Aug 2026):** NFLAR 2034 was re-parsed through the live pipeline and now has rows in
`plays`, not `legacy_play_log`. It needs no legacy handling at all — and it is the reference
for what good looks like: `play_seq`, `quarter` and `score_after` all present.

Supersedes §4 Step 4 of `task-legacy_playbyplay.md`, which called this "trivial aliasing,
already scoped as low-effort". It is not — see §3.

---

## 1. Goal

`game.php`'s `render_plays_section()` and `replay.php`'s equivalent query currently read only
`plays` (~1,070 rows, live pipeline). With `game_id` now populated on `legacy_play_log`,
historical games can show play-by-play too — 1,344 games, 202,858 plays.

## 2. Column mapping

`legacy_play_log` -> `plays` shape:

| legacy | plays |
|---|---|
| `time_gone_seconds` | `time_gone_seconds` |
| `down` | `down` |
| `distance` | `yards_to_go` |
| `formation_code` | `formation` |
| `offense_call_code` | `off_call` |
| `defense_call_code` | `def_call` |
| `result_text` | `result_text` |
| `yards_gained` | `yards_gained` |
| *(computed)* | `quarter` |
| *(absent)* | `play_seq`, `score_after`, `offense_franchise_id` |

**Quarter is inferred, not stored.** Confirmed immutable rule, given directly:

```
Q1:  0-14 min   Q2: 15-29   Q3: 30-44   Q4: 45-59   OT: 60-75  (= quarter 5)
```

Write as an explicit `CASE` on `FLOOR(time_gone_seconds / 60)`, not a formula — OT's bucket is
16 minutes wide, so `FLOOR(seconds/900)+1` does not fit.

Feature 1's `_all` union views already solve part of this shape problem. **Check whether they
can be reused or extended rather than writing a parallel UNION.**

## 3. Why this is not trivial

**Duplicates.** 16 fixtures hold the same game 2-3x (~4,400 rows), including several Alan v
Gordon games. Rendering them raw shows every play two or three times. Must be resolved first —
`task-15-legacy_duplicate_fixtures.md`.

**Ordering.** No `play_seq`. `time_gone_seconds` is correct within a quarter, but ties need a
secondary key. Confirm whether multiple plays share a timestamp within one copy.

**No `score_after`.** The live replay shows a running score. Legacy rows cannot without
reconstructing it from scoring plays, which is a parsing job in itself. Decide whether to omit
the score column for legacy games or derive it.

**Data-quality tier.** Legacy rows lack the live parser's enrichment (formation
carry-forward, coach-benched detection). They should read honestly as a different tier — a
visual marker was flagged early and is still unresolved.

**Coverage is uneven.** Some fixtures are short: 6 games under 60 plays, one at 96. NFLAR 2005
wk13 is missing its overtime entirely. The UI should not imply completeness it does not have.

## 4. Suggested sequencing

1. Resolve duplicates (separate task)
2. Confirm ordering and tie-breaking against real data
3. Decide the score-column question
4. Extend the query — reuse Feature 1's union views if possible
5. Add the legacy-tier marker
6. Verify against turn files: game 9427 (NFLAR 2032 wk1, 143 plays, independently confirmed by
   the live parser) is the best reference point

## 5. Reference points for verification

| Game | Fixture | Expected |
|---|---|---|
| 9427 | NFLAR 2032 wk1 PE v GB | 143 plays |
| — | NFLAR 2024 wk4 PE v St Louis Rams | 155 (turn file: 156 lines) |
| — | NFLAR 2024 wk18 GB v PE | 149 (turn file: 152 lines) |
| 4614 | NFLAR 2013 wk11 MV v PE | 306 raw, **153 after de-duplication** |
| 9602 | NFLAR 2033 wk14 AC v PE | 453 raw, ~155 after de-duplication |

NFLAR 2034 game ids from earlier in this project (9802, 9879, 9961 and so on) no longer exist —
that season's games were deleted and recreated, and now start at 10054.

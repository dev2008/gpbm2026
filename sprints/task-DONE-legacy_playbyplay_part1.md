> **COMPLETE (Aug 2026).** Part 1 done — 199,577 rows linked. Part 2 is now Feature 14
> (`task-14-legacy_playbyplay_part2.md`). §5's "~663 unresolved" estimate is **wrong** — it is
> an `f_games` row count applied to the wrong table; the real figure is 50,592. See
> `findings-legacy_playbyplay_backfill.md`.

# Task: Backfill `legacy_play_log.game_id`, then surface legacy play-by-play in the app

**Status:** ~~Design complete, both prerequisite checks confirmed clean. Ready to move to an
actual migration script. Not started as its own session — this doc exists so a fresh
conversation can pick it up without re-deriving everything below from scratch.

---

## 1. Goal

`legacy_play_log` (253,450 rows, in `gplan_pbm`) is the historical play-by-play archive —
vastly more coverage than the live `plays` table (~1,070 rows and growing from the current
upload pipeline). Right now it has **no link to `games.game_id` at all**, so even once a
historical game is reachable via `team.php` (season selector, already built), `game.php`'s Play
by Play / Live Replay sections show nothing for it — they only ever query `plays`, never
`legacy_play_log`.

Two-part task:
1. **Backfill** — add `legacy_play_log.game_id`, populate it via a one-time resolver query.
2. **Surface it** — once populated, extend `game.php`'s Play by Play query (and `replay.php`'s)
   to `UNION` `plays` with `legacy_play_log`, so historical games get play-by-play too.

This document covers part 1 in full detail (it's the part that's actually been designed) and
flags part 2 as a distinct, not-yet-designed follow-on — don't conflate the two.

**Not the same problem as, and doesn't overlap with:** `team.php` only showing a franchise's
current season — that was a real, separate gap, already fixed (season selector, live in
`team.php`). This task is purely about play-by-play *detail* once a game is already reachable,
not about reaching the game itself.

---

## 2. What's already confirmed — don't re-derive these

### `legacy_play_log` (`gplan_pbm`, frozen/static — see §3)

Columns relevant here: `play_log_id`, `league_code`, `season`, `week`, `time_gone_seconds`,
`offense_team_code`, `defense_team_code`, plus play detail (`down`, `distance`, `formation_code`,
`offense_call_code`, `defense_call_code`, `yards_gained`, `result_text`, boolean flags, etc.).

- **No `game_id`, `quarter`, or `play_seq` column.** Only `time_gone_seconds` for ordering
  within a game — confirmed this works fine chronologically (it's a real cumulative value, same
  shape as `plays.time_gone_seconds`).
- **Quarter must be inferred**, not stored — from `time_gone_seconds`, using the confirmed
  immutable rule (given directly, not derived — no query could have produced this):
  ```
  Q1:  0–14 minutes
  Q2: 15–29 minutes
  Q3: 30–44 minutes
  Q4: 45–59 minutes
  OT: 60–75 minutes   (matches plays.quarter's own convention: 1-4, 5 = OT)
  ```
  i.e. `quarter = CASE WHEN minutes BETWEEN 0 AND 14 THEN 1 WHEN ... BETWEEN 60 AND 75 THEN 5 END`
  using `FLOOR(time_gone_seconds / 60)` as minutes. Written as an explicit `CASE` on minute
  ranges, not a formula (`FLOOR(seconds/900)+1`-style) — the given rule doesn't map cleanly to a
  single formula (OT's bucket is 16 minutes wide, not 15), so match the stated boundaries
  literally rather than reverse-engineering a formula that happens to fit them.
- **`league_code` has a known typo variant, `NFLAr`** (lowercase r) — confirmed present
  elsewhere in the historical data (`schema.md` §9). **Not yet checked whether this specific
  typo appears in `legacy_play_log` itself** — worth a direct check before assuming either way:
  ```sql
  SELECT DISTINCT league_code FROM legacy_play_log WHERE league_code LIKE 'NFLA%';
  ```

### `f_games` (`gplan_main`, frozen/static, cross-database on the same server)

Full schema confirmed via direct `DESCRIBE` — key columns for this task:

| Column | Notes |
|---|---|
| `league`, `season`, `week` | Confirmed clean: only `NCAA5`/`NFLAR` present (no `NFLAr` typo in this table specifically) |
| `team`, `franchise` | This row's team name and franchise ID. `franchise` is `varchar(8)` (**text**, not integer) but holds clean digit-strings (`2001`–`2038`-range) — confirmed via direct `SELECT DISTINCT`, no padding/prefix surprises. `schema.md` already established these values map directly to `gplan_pbm.franchise_id`, no translation table needed. |
| `opp_team`, `opp_franchise` | The *opponent's* team name and franchise ID, **on the same row** — `f_games` stores one row per team-perspective (confirmed, `schema.md` §2 — "twice per row, wide"), so both sides of a game are available from a single row match, no second lookup needed. |
| `homeaway` | Home/away orientation, directly available — needed to build the `games.uk_game` key correctly. |

- **`games` was itself built from `f_games`** — `schema.md` §9: *"paired f_games rows,
  deduplicated on (week, {franchise, opponent})."* Resolving through `f_games` should land
  cleanly on existing `games` rows via `uk_game (week_id, home_franchise_id, away_franchise_id)`,
  by construction — this isn't a new, independent resolution path, it's retracing the same one
  `games` itself came from.
- **Week numbering**: confirmed directly — *"week is a historical game fact recorded in the
  turn."* `legacy_play_log.week` and `f_games.week` aren't two independently-computed
  conventions that happen to usually agree; they're the same recorded fact reaching two
  different tables via two different pipelines. Real confidence here, but **no direct spot-check
  has actually been run** — cheap to do before trusting it fully:
  ```sql
  -- Eagles vs. Packers, NFLAR 2032, known to be week 1 from the turn file directly
  SELECT DISTINCT week FROM legacy_play_log
  WHERE league_code = 'NFLAR' AND season = 2032
    AND (offense_team_code IN ('PE','GB') OR defense_team_code IN ('PE','GB'))
  ORDER BY week LIMIT 5;
  ```

### The collision risk — checked directly, confirmed clean, permanently

`team_codes.code` is deliberately **not unique** — the same 2-4 letter code can mean a
*different* team depending on era (confirmed via `new_schema.sql`'s own comment on that column).
Naively joining `legacy_play_log` → `team_codes` → `franchises.label` (current names only) risks
silently resolving an old play to the *wrong* franchise if a code was ever reused by a team
that's also a current franchise — this was a real, live concern earlier in this project (it's
exactly what went wrong with an earlier, now-corrected design elsewhere in this app, see
`security.md` §7 and `lessons.md` §11 for that unrelated but structurally similar mistake).

**Resolved for this task specifically** — the actual collision check, run directly against
`f_games`, confirmed clean:
```sql
SELECT league, season, team, COUNT(DISTINCT franchise) AS franchise_count
FROM f_games
GROUP BY league, season, team
HAVING COUNT(DISTINCT franchise) > 1;
```
**Zero rows returned.** And critically — since `f_games` is frozen (confirmed directly, not
inferred), this is a *permanent* fact about a closed dataset, not a snapshot that could still
change. Combined with the domain rule given directly (*"team names are unique in each
league/season/week combination"*), resolving through `f_games` at the exact `(league, season,
week)` grain is safe: even though `team_codes` can hand back multiple stale `team_name`
candidates for one `offense_team_code`, only the one actually in use during that specific week
will find a match in `f_games` at that exact week — the season/week scoping self-disambiguates,
no extra logic needed to pick the "right" candidate.

---

## 3. Confirmed: one-off extract, not a live import

Explicit decision, already made: **`f_games`/`gplan_main` is queried once, for this backfill,
and never again.** Not brought into `gplan_pbm`'s live schema, no ongoing sync, no live
cross-database queries from the app afterward. Same pattern the original migration itself
already used (`migration_data.sql` extracted from `f_games` once, loaded `gplan_pbm`, never
touched `gplan_main` again) — this is that same playbook, just backfilling one more column
post-hoc instead of at initial load time. Reasoning already covered directly: bringing `f_games`
into the live schema would reintroduce the exact "one row per team-perspective, ~90 mirror
columns" anti-pattern the whole redesign was built to eliminate, for zero ongoing benefit — once
`legacy_play_log.game_id` is populated, nothing ever needs to ask `f_games` anything again.

---

## 4. Implementation plan

### Step 1 — add the column
```sql
ALTER TABLE legacy_play_log
    ADD COLUMN game_id INT UNSIGNED NULL,
    ADD INDEX idx_lpl_game (game_id);
```
Nullable — the ~240 no-opponent historical playoff rows and ~423 sentinel/rollup rows
(`schema.md` §9, already documented, predates this task) genuinely have no matching `games` row
and never will. Honest `NULL` for those, same pattern this schema already uses everywhere else
for genuine unknowns — not a gap this task needs to solve.

### Step 2 — the backfill query (draft — written from the schema above, not yet run/tested)

```sql
UPDATE legacy_play_log lpl
JOIN team_codes tc ON tc.code = lpl.offense_team_code
JOIN gplan_main.f_games fg
    ON fg.league = lpl.league_code
   AND fg.season = lpl.season
   AND fg.week = lpl.week
   AND fg.team = tc.team_name
JOIN leagues l ON l.code = lpl.league_code
JOIN seasons s ON s.league_id = l.league_id AND s.year = lpl.season
JOIN weeks w ON w.season_id = s.season_id AND w.week_number = lpl.week
JOIN games g
    ON g.week_id = w.week_id
   AND ((g.home_franchise_id = CAST(fg.franchise AS UNSIGNED) AND g.away_franchise_id = CAST(fg.opp_franchise AS UNSIGNED))
     OR (g.home_franchise_id = CAST(fg.opp_franchise AS UNSIGNED) AND g.away_franchise_id = CAST(fg.franchise AS UNSIGNED)))
SET lpl.game_id = g.game_id
WHERE lpl.game_id IS NULL;
```

Notes on this draft:
- Joins on `offense_team_code` only (via `f_games.team`) — deliberately does **not** also
  resolve `defense_team_code` separately, since `f_games.opp_franchise` already gives the
  opponent directly from the same row (see §2). Worth adding a validation join confirming
  `defense_team_code` also resolves to `opp_team` via `team_codes`, as a safety cross-check
  before trusting a row — not strictly required for the join to work, but cheap insurance against
  a row where the two sides don't actually agree.
- Not yet tested against live data — this is a first draft built directly from the confirmed
  schema/facts above, not something that's been run or refined against real rows yet. Expect to
  iterate once it's actually run (likely: check row counts before/after, spot-check a handful of
  known games, look at what's left `NULL` and confirm it matches the expected ~663-row gap).
- Performance: `legacy_play_log` lacked indexes on `season`/`week`/`offense_team_code` at the
  start of this project (a separate, already-fixed issue — see `lessons.md`/earlier
  conversation) — confirm those indexes are still in place before running this at full scale;
  this join pattern is exactly what they were added for.

### Step 3 — verify

```sql
-- How many resolved vs. didn't?
SELECT COUNT(*) AS total, SUM(game_id IS NOT NULL) AS resolved FROM legacy_play_log;

-- Spot-check a known game: Eagles vs. Packers, NFLAR 2032 Wk 1
SELECT lpl.play_log_id, lpl.game_id, g.label
FROM legacy_play_log lpl
LEFT JOIN games g ON g.game_id = lpl.game_id
WHERE lpl.league_code = 'NFLAR' AND lpl.season = 2032 AND lpl.week = 1
  AND (lpl.offense_team_code IN ('PE','GB') OR lpl.defense_team_code IN ('PE','GB'))
LIMIT 5;
```

### Step 4 — separate follow-on, not part of this backfill: surface it in the app

Once `game_id` is populated, `game.php`'s `render_plays_section()` and `replay.php`'s
equivalent query need to `UNION` `plays` with `legacy_play_log` (aliasing columns to a common
shape: `time_gone_seconds`, `down`→`distance`(`yards_to_go`), `formation_code`→`formation`,
`offense_call_code`/`defense_call_code`→`off_call`/`def_call`, computed `quarter` per §2,
`result_text` as-is). Column shape differences are trivial aliasing, already scoped as
low-effort. Two things flagged early on, not yet resolved:

- **Legacy rows likely need a visual "legacy" marker** in the Playback/Table views — no
  `score_after` equivalent, and potentially less parsing fidelity than the live `plays` pipeline
  (formation-carry-forward, coach-benched detection, etc.) — should read honestly as a different
  data quality tier, not presented identically to live-parsed plays.
- **Ordering**: `legacy_play_log` has no `play_seq` — chronological order via
  `time_gone_seconds` is fine within a quarter, but confirm ties (multiple plays at the same
  recorded second, if that occurs) don't need a secondary sort key.

This step hasn't been designed in any real detail yet — treat §4 (this section) as "known to be
needed," not as a plan.

---

## 5. Non-goals (explicitly ruled out already, don't relitigate)

- Bringing `f_games`/`gplan_main` into the live `gplan_pbm` schema — no.
- An ongoing/live sync mechanism — no, `f_games` is frozen, one-time extract is correct and
  sufficient.
- 100% resolution — the ~240 no-opponent playoff rows and ~423 sentinel/rollup rows
  (`schema.md` §9) are a known, pre-existing, permanent gap, not something this task needs to
  close.

---

## 6. Open items before/while implementing

- [ ] Run the `NFLAr` typo check against `legacy_play_log` specifically (§2)
- [ ] Run the week-numbering spot-check against a known game (§2)
- [ ] Run the backfill draft query (§4 Step 2) against a test copy or with care, then verify
      (§4 Step 3) — expect to iterate on the query itself
- [ ] Confirm `legacy_play_log`'s indexes on `season`/`week`/`offense_team_code` are in place
      before running at full scale
- [ ] Design §4 Step 4 properly (the UNION + legacy-marker UI work) once the backfill itself is
      done and verified — not before

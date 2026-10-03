# Task: Rebuild `games.label` from week-grain team identities

**Status:** Not started. Scoped and root-caused during the `legacy_play_log.game_id` backfill
(Aug 2026). Highest user-visible impact of the follow-on items.

---

## 1. The defect

`games.label` names each franchise by its **current** identity rather than the identity it was
playing under that week.

| League | Games affected | Seasons |
|---|---:|---:|
| NFLAR | 3,487 | 39 |
| NCAA5 | 516 | 27 |

Examples confirmed live:

- `NFLAR 2014 Wk 13-20` and **all of 2021** read "Dallas Cowboys" for games franchise 2014
  played as **Washington Commanders**
- `NFLAR 2024 Wk 6: Philadelphia Eagles vs Las Vegas Raiders` — franchise 2011 was playing as
  **Oakland** in 2024; Las Vegas does not start until 2029
- `NFLAR 2024 Wk 4: Philadelphia Eagles vs Los Angeles Rams` — the turn file says
  **St Louis** Rams

## 2. Root cause

`franchises.label` is a `STORED GENERATED` column (`city` + `nickname`) and therefore always
reflects the slot's present-day identity. `games.label` was built from it.

A franchise is a slot; its real-life identity changes as coaches join and leave, sometimes
mid-season. See `schema.md`, "Franchises, team identities and team codes".

Same shape as the `games.label` calendar-year bug in `lessons.md` §9 — a label built from the
wrong-grain source. Worth noting `games.label` has now been wrong twice for the same reason.

## 3. The correct source

`gplan_main.f_games.team` and `.opp_team`, which record the identity at `(league, season, week)`
grain. Uniqueness confirmed across the full 2,588-record identity map: one team name maps to
exactly one franchise per week, and one franchise to exactly one name per week — zero
exceptions either way.

Note this means a **one-off extract from `f_games`**, same pattern as the backfill used. Do not
bring `f_games` into the live schema (`task-legacy_playbyplay.md` §3).

## 4. Scoping query

```sql
SELECT   l.code AS league,
         COUNT(DISTINCT g.game_id) AS mislabelled_games,
         COUNT(DISTINCT s.year)    AS seasons_affected
FROM     games g
JOIN     weeks   w ON w.week_id   = g.week_id
JOIN     seasons s ON s.season_id = w.season_id
JOIN     leagues l ON l.league_id = s.league_id
JOIN     <f_games map> fg
         ON  fg.league = l.code AND fg.season = s.year AND fg.week = w.week_number
         AND fg.franchise IN (g.home_franchise_id, g.away_franchise_id)
WHERE    g.label NOT LIKE CONCAT('%', fg.team, '%')
GROUP BY l.code;
```

## 5. Open design questions — decide before building

- **Rebuild or resolve at render time?** A stored `label` is fast but goes stale the same way
  again. Resolving identity at render time from a week-grain lookup is always right but touches
  every page. A third option: keep `label` but rebuild it correctly and add a check to the
  parser so new games are built from the turn's own identity, not `franchises.label`.
- **What builds new labels today?** `extract_games.php` must be fixed too, or the defect
  reappears with every upload. Check whether it reads `franchises.label`.
- **Scope beyond `games.label`.** `team.php`, `coach.php` and `game.php` may each derive team
  names from `franchises.label` independently. Audit before assuming a `games.label` fix is
  sufficient.
- **Preserve current-identity display anywhere?** A franchise page arguably *should* show the
  present-day name. Decide where historical accuracy applies and where it does not.

## 6. Verification

The scoping query in §4 should return zero rows afterwards. Spot-check against turn files —
`NFLAR-PE_s2024_w04_vs_Rams.txt` (St Louis Rams) and
`NFLAR-PE_s2021_w12_vs_Titans.txt` (Tennessee Titans) are known-good references.

---

## 7. Changed since this was written (11 Aug 2026)

**NFLAR 2034 was re-parsed**, so all 204 of its games were deleted and recreated with new ids
(now 10054+). Two consequences for this task:

- **`label_L1_identities.sql`'s gate `L1.3d` will now report ~204 games with no identity**, not
  the 120 it found before. `f_games` has never covered NFLAR 2034 weeks 10–16, and the whole
  season's games are now parser-created. This is expected, not a regression.
- **2034's labels are already correct.** The parser builds them from the turn file's own team
  names, so they carry the identity the franchise was actually playing under. The 3,487 + 516
  figure in §1 was measured before the re-parse and is now slightly lower for NFLAR.

**The prerequisite has not moved:** `franchise_identities` still needs building, and the gap
past `f_games`' horizon still needs the parser to write identity rows from the turn file's team
names — which it already parses for the label. That is the durable fix and it removes this
task's dependency on a frozen table entirely.

`migration_fgames_map` (renamed from `tmp_fgames_map`) holds the f_games extract if it is
wanted, but `label_L1_identities.sql` reads `gplan_main.f_games` directly and does not need it.

# Task: Legacy fixtures that may want dropping and re-parsing

**Status:** Not started. Surfaced during Feature 15 (`task-15-legacy_duplicate_fixtures.md`),
Aug 2026. Nothing here was touched by that feature.
**Blocked on:** Feature 11 (`franchise_identities`) for 16 of the 43 games — **but only on the
parser path**. See §4.
**Turn files:** confirmed available (Alan, Aug 2026). They are the only route — see §4a.
**Chosen approach:** semi-manual. Upload the files and extract the missing data via a
purpose-built page or an agent task. See §5a.

---

## 1. What this is

Feature 15 de-duplicated `legacy_play_log` and, in the course of doing so, censused the whole
archive. Forty-three games came out carrying a defect that de-duplication does not fix. They
are candidates for the treatment NFLAR 2034 got: drop the legacy rows, re-source from the turn
file, parse through the live pipeline into `plays`.

All three live categories are **source-level losses**, not migration losses — see §4a. Nothing
can be recovered from `gplan_main.n_playbyplay`; the turn files are the only route.

They are **not** a single problem. Four distinct categories, with genuinely different value,
risk and difficulty:

| | Category | Games | Identity-blocked | Character |
|---|---|---:|---:|---|
| **A** | Short fixture (<100 plays) | 7 | 2 | Coverage gap — the archive holds part of a game |
| **B** | Long fixture (>200 plays) | 0 | — | None remain after Feature 15 |
| **C** | No `result_text` on any row | 21 | 12 | Every other column populated; play text absent |
| **D** | Overtime missing | 15 | 3 | Regulation complete, OT period never imported |
| **E** | Cleaned-tail ingest format | 1 | 0 | Complete but lower fidelity |

Game 4789 appears in both A and D. Distinct games: 43.

---

## 2. Recommended order, and why it is not the row-count order

**D first.** These games are *wrong*, not merely thin — the archive shows a tied or incomplete
game where `games` records a decided result. Twelve of the fifteen are free of the identity
blocker. And the live parser demonstrably handles overtime: `plays` holds 55 rows at
`quarter = 5`, `time_gone_seconds` 3600–4498, produced by `extract_playbyplay.php` from the same
source format.

**A second.** Smallest set, and probably not a parse failure at all — 9114 stops at 502s of
3600, 2440 at 924s. That shape says truncated upload, not mis-parse, so re-parsing the same
file may reproduce the same result. Check one before committing to the rest.

**C last.** One bounded ingest fault — every column populated except the play text, two tight
clusters — but the most exposed set, with 12 of 21 blocked on identity if the parser path is
used. Also the least urgent: the rows are structurally sound and Feature 14 can render them with
the text column blank rather than wrong, so the game is readable either way.

**E is not a re-parse candidate.** Game 9621 is complete and coherent; it is listed only so the
lower-fidelity ingest is on record. See §5.

---

## 3. The candidates

### A. Short fixtures (<100 plays after de-duplication)

| League | Season | Wk | game_id | Plays | Clock stops | Fixture |
|---|---:|---:|---:|---:|---|---|
| NCAA5 | 2038 | 7 | 2440 | 38 | 924s | Pittsburgh Panthers v Maryland Terrapins |
| NCAA5 | 2038 | 8 | 2445 | 14 | 3558s | Iowa State Cyclones v Pittsburgh Panthers |
| NFLAR | 2013 | 4 | 4759 | 26 | 1704s | Philadelphia Eagles v Atlanta Falcons |
| NFLAR | 2013 | 5 | 4779 | 26 | 1551s | New York Giants v Philadelphia Eagles |
| NFLAR | 2013 | 6 | 4791 | 26 | 1566s | Philadelphia Eagles v Kansas City Chiefs |
| NFLAR | 2013 | 6 | 4789 | 96 | 2330s | Minnesota Vikings v Indianapolis Colts |
| NFLAR | 2031 | 14 | 9114 | 22 | 502s | Tampa Bay Buccs v Minnesota Vikings |

2445 is the odd one — 14 plays but a clock reaching 3558s, so it is scattered across the whole
game rather than truncated at a point. Different failure from the rest of the category.

### C. No `result_text` on any row (21 games, ~3,164 rows)

Two contiguous clusters, both inside `play_log_id` 799296–803366 — one ingest run:

- **NCAA5 2003 wks 1–5:** 278, 282, 287, 288, 293, 294, 296, 299, 302, 305
- **NFLAR 2008 wks 8–13:** 3360, 3365, 3374, 3383, 3388, 3398, 3400, 3572, 3577, 3585, 3588

Games 310 and 311 were *partially* in this state — a duplicate copy with NULL text alongside a
good copy. Feature 15 removed the NULL copies, which is why the count is 21 and not 23.

### D. Overtime missing (15 games, all NFLAR)

2784 (2005 wk4), 2653 (2005 wk13), 3005 (2006 wk2), 3300 (2007 wk6), 3429 (2008 wk16),
3623 (2009 wk11), 4711 (2013 wk1), 4789 (2013 wk6), 5100 (2015 wk10), 6981 (2022 wk3),
7565 (2025 wk0), 7688 (2025 wk1), 8084 (2027 wk11), 8508 (2028 wk6), 8770 (2029 wk7).

Every one has a final margin of exactly 3 or 6 points, which is what sudden death produces, so
`games.went_to_ot` is credible. Full detail on how this was established is in
`lessons.md` — the short version is that `plays` proves the live parser captures OT while
`legacy_play_log` holds no row above 3599 in 248,118.

---

## 4a. Evidence: all three categories are source-level losses

Checked against `gplan_main.n_playbyplay`, the table `legacy_play_log` was migrated from
(Stage G, Aug 2026). **The migration was row-complete: 253,450 source rows, 253,450 migrated.**

| Category | Source holds it? | Evidence |
|---|---|---|
| **A** short fixtures | No | Source row count per fixture equals the migrated count exactly on all 7 — 14, 22, 26, 26, 26, 38, 96 both sides |
| **C** no play text | No | `a_text` is `TEXT NOT NULL`; 4,071 rows hold the empty string, and they reconcile exactly to the affected weeks |
| **D** no overtime | No | `a_minutes` runs 0–59 across all 253,450 rows. Zero rows at 60+, and zero for each of the 15 fixtures individually |

**Overtime detail.** The minute distribution shows the two-minute drill spiking at 28/29
(9,560/7,620) and 58/59 (8,905/5,258) against a ~3,937 median, then stopping dead at 59. That is
a complete regulation game, parsed correctly, with the overtime period never captured — most
likely a parser that stopped at the end of the `4th Quarter` block or whose `mm:ss` pattern would
not accept minutes above 59. The alternative, OT stored with a reset clock, is ruled out: it
would create backwards clock steps inside games, and a check across the whole archive found
exactly one, which was a duplicate-row artefact in game 181.

So these games have been missing their overtime since the day each was played — for the oldest,
21 years. `games.went_to_ot` has been right about them the entire time, which is how they were
found.

**The empty-text rows in full (4,071):**

| League | Season | Weeks | Empty rows | In `gplan_pbm` |
|---|---:|---|---:|---|
| NCAA5 | 2003 | 1–5 | 1,472 | the 10 NCAA5 category-C games |
| NFLAR | 2008 | 8–13 | 1,692 | the 11 NFLAR category-C games |
| NCAA5 | 2003 | 6 | 278 | the duplicate copies of games 310/311, removed by Feature 15 |
| NFLA | 2008 | 16–18 | 629 | defunct league, no `games` rows, unreachable from the app |

NCAA5 2003 wk 6 holds 556 source rows against 278 empty — that week was **double-ingested at
source**, so the duplication behind games 310/311 predates `gplan_pbm` entirely. And `NFLA` 2008
wks 16–18 falls in the same window as NFLAR 2008 wks 8–13, so one ingest fault spanned two
leagues. The `NFLA` rows need no action: no `game_id`, no UI path.

**A method note.** The first attempt at this check assumed `n_playbyplay.a_id` mapped onto
`legacy_play_log.play_log_id`. It does not — `a_id` spans 107,181–29,005,625 across 253,450 rows,
and the migration renumbered. A gate comparing league, season, week, both team codes and the
clock on every matched pair disagreed on essentially all of them, which voided that half of the
analysis. Anything joining these two tables must match on
`(a_league, a_season, a_week, a_off, a_def, a_minutes*60 + a_seconds)`, never on the id.

---

## 4. The blocker: franchise identity

**Three parsers resolve a turn file's team names against `franchises.label`.** That column is
`STORED GENERATED` from `city` + `nickname`, so it always reads as the slot's *present-day*
identity (`schema.md` §12). Where a game's week-grain identity differs, the lookup finds
nothing and the parser **silently skips the game** — no error, no rows.

`schema.md` §13 already records this as a known defect expected to "recur at the next identity
change". It does not need a future change to bite here; these games are 2003–2029.

**16 of the 43 are affected:**

| League | Season | Wk | game_id | Identity that week | Label today |
|---|---:|---:|---:|---|---|
| NCAA5 | 2003 | 1 | 278 | Georgia Bulldogs | Georgia Tech Yellow Jackets |
| NCAA5 | 2003 | 1 | 282 | USC Trojans | Texas Longhorns |
| NCAA5 | 2003 | 2 | 287 | USC Trojans | Texas Longhorns |
| NCAA5 | 2003 | 3 | 294 | Virginia Tech Hokies | Iowa State Cyclones |
| NCAA5 | 2003 | 4 | 299 | Virginia Tech Hokies | Iowa State Cyclones |
| NCAA5 | 2003 | 5 | 305 | West Virginia Mountaineers | Notre Dame Fighting Irish |
| NFLAR | 2007 | 6 | 3300 | Indianapolis Colts | New England Patriots |
| NFLAR | 2008 | 8 | 3577 | Washington Commanders | Dallas Cowboys |
| NFLAR | 2008 | 10 | 3365 | Washington Commanders | Dallas Cowboys |
| NFLAR | 2008 | 12 | 3383 | Cleveland Browns | Jacksonville Jaguars |
| NFLAR | 2008 | 12 | 3388 | Seattle Seahawks | San Diego Chargers |
| NFLAR | 2008 | 13 | 3398 | Washington Commanders | Dallas Cowboys |
| NFLAR | 2008 | 13 | 3400 | St Louis Rams | Los Angeles Rams |
| NFLAR | 2013 | 1 | 4711 | Atlanta Falcons | Carolina Panthers |
| NFLAR | 2013 | 4 | 4759 | Atlanta Falcons | Carolina Panthers |
| NFLAR | 2013 | 6 | 4789 | Indianapolis Colts | New England Patriots |

Franchise 2014 (Washington/Dallas) accounts for three of them. The Atlanta/Carolina slot is the
same one behind the `AF`/"Carolina Panthers" team-code mislabelling in `lessons.md` §3 — the
identity drift and that defect are two symptoms of one cause.

**This blocks the parser path only.** Feature 11 removes it permanently and is the right fix in
general. But for 43 known games there is a cheaper route: let the operator supply the franchise
pair by hand at extract time. That is exactly what `franchise_identities` would supply
automatically, and with 43 games a dropdown costs less than building the table first. **So this
task does not have to wait for Feature 11** — see §5a.

Re-run the sizing query after any identity work:

```sql
SELECT lg.code AS league, s.year AS season, w.week_number AS week, g.game_id,
       m.team AS identity_that_week, f.label AS label_today
FROM   games g
JOIN   weeks w ON w.week_id = g.week_id
JOIN   seasons s ON s.season_id = w.season_id
JOIN   leagues lg ON lg.league_id = s.league_id
JOIN   migration_fgames_map m ON m.season = s.year AND m.week = w.week_number
       AND m.franchise IN (g.home_franchise_id, g.away_franchise_id)
JOIN   franchises f ON f.franchise_id = m.franchise
WHERE  g.game_id IN ( /* the 43 */ )
  AND  m.team <> f.label
ORDER  BY lg.code, s.year, w.week_number;
```

---

## 5. Sequencing: parse first, delete after

The obvious plan is *back up, drop the legacy rows, re-parse, restore if it fails*. Invert the
middle two steps.

`extract_playbyplay.php` writes **`plays`**, not `legacy_play_log`. They are separate tables
with no shared key, so dropping the legacy rows first buys nothing and gives up the safety
margin. Parsing first means:

- if the parser skips a game or produces the wrong shape, **nothing has been deleted** and there
  is no restore path to exercise
- the legacy rows stay available as the comparison baseline while the new rows are verified
- the only interim cost is that Feature 1's `_all` union views double-count those games until
  the legacy rows are retired — a read-side artefact, not data loss

This is what the 2034 re-parse effectively did: it re-sourced from turn files and retired 3,281
legacy rows afterwards, not before.

**Cascade notes (`lessons.md` §22), which apply the moment a `games` row is deleted:**

| Table | Cascades from a `games` delete? |
|---|---|
| `team_game_stats`, `plays`, `drives` | **Yes** |
| `legacy_play_log` | **No** — no FK, orphans silently |
| `standings_weekly` | **No** — keyed on `week_id`, survives entirely |
| `franchise_season_records` | **No** — and nothing writes it at all (Feature 13) |

`standings_weekly` surviving is the trap: `extract_standings.php` excludes any upload whose week
already has standings rows, so leaving them behind means no upload for that week will ever
appear in the standings list again. `extract_games.php` has the equivalent exclusion on games.
`RUNBOOK-reparse_nflar_2034.md` handles all of this — follow it rather than rediscovering it.

---

## 5a. Chosen approach: semi-manual extraction

Forty-three games does not justify an automated pipeline, and the file formats are old enough
that each one wants looking at. Either a purpose-built PHP page or an agent task, with these
properties:

1. **Reuse `extract_playbyplay.php`'s parser, do not rewrite it.** It already handles overtime
   correctly — `plays` holds 55 rows at `quarter = 5`, `time_gone_seconds` 3600–4498 — along with
   the QB-replacement merge, multi-line touchdowns and kickoff formation synthesis. All of that
   was hard-won (`lessons.md` §4, §5) and none of it should be reimplemented.
2. **Manual franchise-pair selection**, overriding the `franchises.label` lookup. This is what
   removes the Feature 11 dependency for the 16 games in §4.
3. **Preview before writing.** Show parsed play count and the per-quarter split for the operator
   to approve. For a category-D game a `quarter = 5` bucket must appear; for category A the play
   count should jump from 14/22/26 to ~150, and if it does not, the source file is genuinely
   short and the game is a documented gap rather than a recoverable one.
4. **Write `plays`, never `legacy_play_log`.** Recovered games then sit in the same shape as
   NFLAR 2034, carrying `play_seq`, `quarter` and `score_after` — which `legacy_play_log` has no
   columns for.
5. **Retire the legacy rows as a separate confirmed step**, after the new rows are verified.
   Until that happens a recovered game has rows in both `plays` and `legacy_play_log`, and
   Feature 14's union would render it twice.

Point 3 is the control that matters for the 2003-era formats: per-game approval means an
unvalidated format shows up as a bad preview rather than as bad data.

### Drive it from a worklist, not from a disabled filter

The obvious implementation is a copy of `extract_playbyplay.php` with its dropdown exclusion
removed. Don't remove it — **replace it with a narrower one.** Turning the exclusion off exposes
all 1,325 games to reprocessing when exactly 43 are wanted.

A worklist table — `migration_f20_recovery_targets`, one row per target game with its category
and a status column — gives three things a disabled filter does not: a healthy game cannot be
touched by accident, progress is resumable across sessions, and the table becomes the record of
what was done to which game rather than something tracked on paper. The page can then report
"17 of 43 recovered" from the data itself.

### Read the selector query before assuming there is a filter to replace

`lessons.md` §5 records that the dropdown exclusion keys off `game_id` via `games`. That leaves
the load-bearing question open: does it exclude a game because a **`games` row exists**, or
because **`plays` rows exist** for it? All 43 targets have a `games` row and zero `plays` rows,
so under the second reading they already appear in the dropdown and there is nothing to change.
Check the actual query first — Feature 15 lost time twice to filters inherited without being
read (`lessons.md` §27).

### Two hazards to design around

- **`parse_status = 'duplicate'` uploads are excluded from every extract page and carry no
  blocks.** If any of these turn files was uploaded before, its `raw_uploads` row will be marked
  duplicate and the file will be unreachable through the normal path. The recovery page needs a
  route that works from an existing upload, not only from a fresh one.
- **`extract_playbyplay.php` writes only `plays`** — no `team_game_stats`, no
  `standings_weekly` — so none of the `lessons.md` §22 cascade traps apply and the blast radius
  is small. That is a good reason to keep this page play-by-play only rather than letting it
  grow into a general re-parser.

---

## 6. Open questions, in the order they need answering

1. **Do the turn files exist for these weeks?** Nothing in the database can answer this. The
   archive spans 2003–2029; the 2034 re-parse had both Alan's and Gordon's files. Without a
   file, a game is not a re-parse candidate at all, only a documented gap.
2. ~~**Who uploaded the NCAA5 2003 games?**~~ **Answered (Alan, Aug 2026): Gordon coaches the
   NCAA5 Ohio State Buckeyes** (inactive). The ten fixtures alternate Ohio State and Pittsburgh,
   so they are Gordon's and Alan's turns respectively — both in the family, both expected to be
   available. Ohio State is simply absent from `legacy_relevant_teams`, which tracks PE/PI/MV
   only; that is a gap in the relevance list, not evidence of a third coach. Games 181
   (Ohio State v Air Force) and 311 (West Virginia v Ohio State) are Gordon's too.
3. **Has the parser been validated against 2003-era file format?** `findings-legacy_playbyplay_backfill.md`
   §8 scoped the 2034 re-parse to a single season precisely because "earlier seasons carry format
   changes the parser has not been validated against, and the return diminishes the further back
   you go". These candidates sit at the far end of that. Validate on one game before planning a
   batch.
4. **Is category A a parse failure or an upload gap?** 9114 stopping at 502s and 2440 at 924s
   look like truncated source, in which case re-parsing reproduces the same 22 and 38 rows.
   Cheap to test on one file.
5. **Should category C be re-parsed at all?** The rows are structurally sound; only the play
   text is missing, and Feature 14 can render them honestly as text-less. Lower value than A or
   D, where the data is absent or wrong rather than merely thin.
6. **Does anything want doing about the 629 `NFLA` rows?** Same ingest fault, but a defunct
   league with no `games` rows and no UI path. Recommendation: no.

---

## 7. Not a candidate: game 9621

NFLAR 2033 wk15, Philadelphia Eagles v Los Angeles Rams, 141 plays. Held in the **cleaned-tail
ingest format** — `result_text` carries only the prose (`"QB hurried, pass thrown away,
incomplete"`) rather than the whole line with its clock/team/down prefix. 286 rows across two
games are stored this way, `play_log_id` 997907–998192, one contiguous ingest run; the other 145
were a redundant third copy of game 9602 and were removed by Feature 15.

The game is complete and internally consistent — clock strictly advancing, no repeated
`(time, down, distance, offense)` tuple — so there is nothing to fix. It is recorded here for
two reasons: it has at least one merged row (`play_log_id` 998192 carries two plays' text), and
**any duplicate-detection or play-count check based on `result_text` uniqueness gives a false
positive on it.** Feature 15 came within one query of deleting 43 legitimate plays from it. See
`lessons.md` for the full account.

# Gameplan PBM League Database — Schema Redesign Proposal

**rev 003 · 16 Aug 2026 · role: reference · authority: derived (below `new_schema.sql`)**
*rev 003 (H15): §12's `franchise_identities` subsection is corrected — the coverage horizon has
moved, the 120-game gap is closed, and the New Orleans team code is `NS`, not `NO`. §12's unused-
code table gains its population caveat and its "seven" count is fixed to eight. A new subsection
records the unordered-`LIMIT 1` defect that put the wrong code into 642 rows. §13's "Still to
build" line is retired. Nothing outside the header, §12 and §13 is touched — an earlier attempt
at this revision rewrote the whole document and silently compressed measured figures out of §9,
§14 and §15.*
*rev 001 was unnumbered — this document predates WoW §7's self-naming rule.*

**Purpose:** eliminate the Excel/CSV/GPVCon/GPAnalyst pipeline entirely. Turn files upload
directly into the app, which parses the raw text itself, block by block, straight into
normalized tables — no external desktop tools in the pipeline at all.

**Companion files:**
- `new_schema.sql` — runnable DDL implementing everything described here.
- `migration_data.sql` — real historical data: leagues, game types, seasons, weeks,
  franchises, coaches, coach tenures, rivalries, honors, and the full 1989–2038 game log
  (9,969 games, 19,938 team-game stat lines) generated from `fc_franchises.sql`,
  `fp_franchises.sql`, `f_gametypes.sql`, and `f_games.sql`. See §9.

**A caveat about this document itself:** this is a narrative summary, not the schema —
`new_schema.sql` is authoritative, and its own inline DDL comments can carry real design
decisions that this document's prose never repeated, or has since fallen behind on. Confirmed
directly, twice, in later work built on top of this one: a real design decision (linking a
website login to a coaching identity, via `coaches.id_user`) existed as a `new_schema.sql`
comment well before either of those sessions, and was never carried into this document — leading
to a redundant column being designed and built elsewhere before the existing one was found (see
`lessons.md` §11–§12 for the full account, both instances). For anything beyond "does this
table/column exist" — genuine design *reasoning* — check `new_schema.sql`'s own comments
directly; don't treat this document as a complete substitute for them.

---

## 1. What the source files actually contain

Every turn (`NFLAR-PE_*.txt`, `NCAA5-PI_*.txt`) is a single email whose body is split into
`<BK.section name>` blocks. (The `-PE`/`-PI` suffix in the filename is the *coach's own*
franchise abbreviation baked into that turnsheet — PE = Philadelphia Eagles, PI = Pittsburgh
Panthers — not the league code; the league itself is identified as `NFLAR` / `NCAA5` in the
report headers and in `fc_franchises.league` / `fp_franchises.league`.) The blocks that
matter for this project, and the parser each will need:

| Block | Parser | Contents | Scope |
|---|---|---|---|
| `Team Report` | — | Uploading coach's own gameplan config, training | Own franchise only |
| `Roster` | — | Full player roster with age/value/strengths/status | Own franchise only |
| `1st–4th Quarter` | **play parser** | Play-by-play: time, side, field pos, down/dist, off call, def call, result, running score — **one row per play** | Own franchise's game only |
| `League Report` | **results/standings parser** | One box-score paragraph per game, for **every game in the league that week** (FG, EP, punts, 3rd/4th down, passing, rushing, returns, play-calls) | Whole league |
| `Standings` | **results/standings parser** | Conference/division table: W-L-T, PF/PA, division record, streak | Whole league |
| *(within League Report)* | **transactions parser** | Free-text transaction lines: waives, coach recruiting picks, position/depth changes, FA signings | Whole league |
| `Draft Report` / `Draftsheet` | **transactions parser** | Annual rookie draft order and picks | Whole league |
| `Scouting Report - Game Summary` | **drive parser** | Drive-by-drive summary (not play-by-play) for the *next opponent's* most recent game — **one row per drive** | One other franchise's game |
| `Roundup` | — | End-of-season narrative summaries | Whole league |
| `Turnsheet` | — | Blank input form for the next turn | Not data — ignore on ingest |

**Quarter boundaries within the `1st–4th Quarter` blocks, confirmed directly, not derived —
by minutes:** Q1 0–14 / Q2 15–29 / Q3 30–44 / Q4 45–59 / OT 60–75.

**Two structurally different play-level blocks — do not conflate them:**
- The coach's own `1st–4th Quarter` blocks are true **per-play** detail: one row per snap,
  with down/distance, the specific off/def call, and the free-text result of that one play.
- `Scouting Report - Game Summary` (the next opponent's last game) is **per-drive** summary
  only: how the drive started, a "N plays for N yds" summary, the single longest play in the
  drive, and how the drive ended. No individual-play detail is given for it at all.

These map to two separate tables, `plays` and `drives` (§4), not one shared table — a drive
summary is not a coarser version of a play row, it's a genuinely different shape of record
(e.g. it has no `down`/`yards_to_go` at all, but has fields like `play_count` and
`longest_play_text` that `plays` doesn't).

**Key implication:** play-by-play (either form) is only ever available for two games per
turn (self + next opponent), never the full league. `plays` and `drives` will both be sparse
by design relative to `games` — that's expected, not a parsing failure.

Both leagues (`NFLAR`, pro; `NCAA5`, college) share this exact block structure, differing
only in whether "division" is populated (pro has conference+division, college has conference
only) and in what postseason events exist (bowls/CIC for college vs conference
championship/wildcard for pro — confirmed against real data, see §9).

---

## 2. Problems with the current schema this redesign fixes

1. **Games stored twice per row, wide.** `f_games`/`fc_vgames`/`fp_vgames` hold one row per
   team-perspective with ~90 `opp_*` mirror columns. → **Fixed**: `games` (one row per game)
   + `team_game_stats` (one row per team per game, long/normalized).
2. **History as delimited strings.** `WinnerYears`, `CottonYears`, `RivalryYears`, etc. are
   `varchar(1024)` comma-lists on `fc_franchises`; ~20 separate win-count columns alongside
   them. → **Fixed**: `franchise_honors` (one row per honor per season) + `honor_types` lookup.
3. **Nine near-identical bowl tables** (`fc_rosegames`, `fc_cottongames`, `fc_orangegames`,
   `fc_hawaiigames`, `fc_motorgames`, `fc_ncgames`, `fc_confgames`, `fc_cicgames`,
   `fc_rivalrygames`, plus `fp_bowlgames`). → **Fixed**: `games` with `game_type_id` (FK to a
   proper `game_types` lookup, loaded from the real `f_gametypes` table — see §9) +
   `franchise_honors` — the same handful of tables now serve every postseason event in both
   leagues.
4. **No structured play-by-play at all** — under the old pipeline this apparently never made
   it past the CSV stage. → **Fixed**: `plays` (own-game, per-play) and `drives` (opponent
   scouting, per-drive) — see §1 for why these are two tables, not one.
5. **Raw turn storage with no structure.** `g_turnsfull` is flat numbered lines
   (`up_id`, `tf_seq`, `tf_line`) with no idea which `<BK.>` section a line belongs to, and
   `g_turnsummary` carries `batstat1-8`/`pitstat1-8` fields that don't apply to football at
   all (baseball leftovers). → **Fixed**: `raw_uploads` (the file itself) +
   `raw_upload_blocks` (one row per `<BK.>` section, correctly typed).
6. **Two separate franchise ID spaces — now confirmed safe to merge.** `fc_franchises.franchise`
   and `fp_franchises.franchise` are independent `smallint` PKs in separate tables. With the
   real data supplied, pro franchises run 2001–2024 and college franchises run 5001–5012 —
   no overlap, across all 36 rows. The single unified `franchises` table (§4) is confirmed
   safe; this was Open Question 1 in the previous draft and is now resolved.
7. **Coaching changes as prose only.** Currently nowhere in the schema — League Report line
   items like "Jets waive no 32" have no home. → **Fixed**: `transactions` table with a typed
   `transaction_type` plus a `detail_text` fallback so nothing is lost even when the parser
   can't fully decompose a line.
8. **Two per-game flags with no column at all.** The League Report marks a team with `S`
   when they conceded a safety that game, and with `UP` when they played up (fielding at full
   strength — automatic in the postseason, optional in the regular season at the cost of not
   accumulating Form). Neither survived into the old schema. → **Fixed**: `team_game_stats
   .safety_conceded` and `team_game_stats.played_up`, both captured as reported rather than
   inferred, since `played_up` isn't strictly determined by `game_type` (it's a real choice in
   the regular season).

---

## 3. Design principles

- **One row per real-world fact.** A game is one row; a team's performance in that game is
  one row; a play is one row; a franchise honor is one row. No mirrored `opp_*` columns, no
  delimited lists.
- **Keep the raw text.** Every parsed row should be traceable back to the `raw_uploads` row
  (and ideally the `raw_upload_blocks` row) it came from, and any line the parser can't
  fully structure still gets captured verbatim in a `detail_text`/`result_text` column rather
  than dropped. Parsing is done incrementally and safely — partial understanding of the
  turn format shouldn't mean data loss.
- **Retain franchise IDs.** `franchises.franchise_id` is not auto-increment; it's populated
  from whatever numbering the current/legacy system already uses, so historical references
  (and anyone's muscle memory of "Eagles = 24") keep working.
- **Derive, don't duplicate, where cheap.** Season-cumulative stats, rankings, etc. shown in
  the "Scouting Report" season-stats tables are computable from `team_game_stats` via a VIEW
  — no need to store them separately. Standings are the one exception (see §5) because the
  "streak" and "division record" values are non-trivial to recompute and the source already
  gives you the correct, authoritative answer each week.
- **Design for Dadabik's grain, not against it.** File upload fields, master/detail
  (subform) views, VIEWS-as-pre-filtered-grids, hooks, and formula fields are all first-class
  Dadabik features — the schema is shaped so each of those maps onto a real table
  relationship rather than requiring custom code to fake it (see §7).

---

## 4. Entity overview

### Structural / reference
- **leagues** — one row per PBM league (`NFLAR`, `NCAA5`, ...), sport type, GM name.
- **seasons** — one row per league-year.
- **weeks** — one row per league-season-week; carries both `week_number` and `turn_number`
  (usually equal, but playoff turns can process a week that doesn't match the regular-season
  numbering — e.g. Turn 17 of a 16-week season). No `phase` column — see §5.
- **game_types** — lookup table, one row per distinct kind of game (Regular Season, Rose Bowl,
  Superbowl, ...), loaded verbatim from the legacy `f_gametypes` table with IDs retained,
  plus a `phase` (preseason/regular/postseason) column added for reporting. Replaces the
  guessed `game_type` ENUM + free-text `bowl_name` from earlier drafts — see §10.

### Franchises & people

> **Read §12 before writing any query that maps a team name or code to a franchise.** A
> franchise is a *slot*; the real-life identity attached to it changes as coaches join and
> leave, sometimes mid-season. `franchises.label` is a present-day snapshot and is wrong for
> anything historical.

- **franchises** — merged replacement for `fc_franchises` + `fp_franchises`. Identity fields
  only (city, nickname, conference, division, abbr); all the derived win-counts and Years
  lists are gone, replaced by queries against `games`/`franchise_honors`.
- **coaches** — the human league participants (distinct from the league's GM/commissioner,
  which is just a text field on `leagues`).
- **franchise_coach_tenures** — replaces the flattened `team` text column on
  `fc_coaches`/`fp_coaches`; one row per coaching stint, so franchise history (relocations,
  coaching changes) is queryable instead of string-parsed. Backfilled from real per-week coach
  data in `f_games` — see §10 (this was flagged as impossible in an earlier draft; it wasn't,
  the franchise snapshot just didn't have what was needed).

### Ingestion (the new part — no legacy equivalent)
- **raw_uploads** — one row per uploaded turn file. This is the table the Dadabik upload
  form targets directly.
- **raw_upload_blocks** — one row per `<BK.>` section within an upload, correctly typed
  (`Team Report`, `League Report`, `1st Quarter`, ...). Structured intermediate storage that
  `g_turnsfull` never had; makes debugging a bad parse trivial (open the block, not the whole
  email) and is a natural Dadabik master/detail view under `raw_uploads`.

### Games & stats
- **games** — one row per game (not per team). `game_type_id` (FK to `game_types`) covers every
  postseason event, replacing the nine bowl tables.
- **team_game_stats** — one row per team per game; every box-score figure from the League
  Report line (FG, EP, 3rd/4th down, passing, rushing, returns, play-calls), plus
  `safety_conceded` and `played_up`.
- **plays** — one row per play, populated only from the uploading coach's own quarter blocks
  (see §1). `result_text` always keeps the original free-text result.
- **drives** — one row per drive, populated only from the "Scouting Report - Game Summary"
  block (the next opponent's last game). Deliberately a separate table from `plays`, not a
  rolled-up view of it — the source data itself is drive-grain, not play-grain, for these
  games (see §1).

### Standings & history
- **standings_weekly** — one row per franchise per week, captured exactly as the Standings
  block printed it (wins/losses/PF/PA/division record/streak). See §5 for why this is
  captured rather than computed.
- **franchise_season_records** — one row per franchise per season: final wins/losses/ties/
  points-for/points-against. Usually `derived` (aggregated from `games`/`team_game_stats`,
  excluding preseason games — matching how the league's own Standings block only ever starts
  at Week 1). For a documented set of very old seasons, real per-game data doesn't survive at
  all and this table holds the legacy season-total instead (`source = 'legacy_rollup'`) — see
  §10.
- **honor_types** / **franchise_honors** — replaces the nine bowl tables' historical columns
  and the Years-list strings on `fc_franchises`/`fp_franchises`. One row per franchise per
  season per honor (league winner, conference champion, each named bowl winner, rivalry win,
  perfect season, ...). See §9 for the confirmed code list, decoded from the real data.
- **franchise_legacy_stat_counts** — a staging table for legacy career-total fields with no
  accompanying year list, for whatever future field might need this treatment. Currently
  empty: every field that started here during migration (§9) turned out to be derivable from
  real game data once the right query was found, and got moved into `franchise_honors`
  properly instead. Kept in the schema as a safety net, not because anything needs it today.
- **rivalries** — simple franchise-pair lookup, replacing `fc_rivalries`/`fc_rivalrygames`.
  Confirmed against the real data: `fc_franchises.Rivalry` is a shared grouping ID, and every
  group in the supplied data has exactly two members (e.g. Air Force/Army both carry `1000`).
  Rivalry *record* (head-to-head W-L) is a VIEW over `games` + `rivalries`, not a stored
  table — unlike weekly standings, it has no streak/division-record complexity, so it's safe
  to derive live (`v_rivalry_records` in the DDL).

### Transactions
- **transactions** — one row per roster/coaching move (waive, sign, draft pick, position
  change, retirement...), typed where the parser is confident, always keeping `detail_text`
  verbatim.

---

## 5. Standings: captured, not recomputed

Wins/losses/points-for/points-against **could** be a VIEW aggregating `team_game_stats`.
Division record and win/loss streak are much harder to get right in SQL (streak needs
ordered, stateful logic; division record needs to know each franchise's division at the time
of the game, which can change on relocation). Since the Standings block already prints these
correctly every week, `standings_weekly` **stores that snapshot directly** rather than
re-deriving it. This avoids a whole class of "the computed standings don't match what was
actually reported" bugs. Basic PF/PA/W-L are also stored here for convenience even though
they're technically re-derivable, so the whole weekly standings table is one clean read with
no joins.

A related fix from the same evidence: `weeks` originally had its own `phase` column
(preseason/regular/postseason). Real `f_games` data disproves that a week has one phase — 60
weeks (several NFLAR seasons' weeks 19–20) contain both postseason games for teams still alive
in the playoffs *and* preseason games for already-eliminated teams starting next season early,
in the same week. Phase is a per-game fact only (`game_types.phase` via `games.game_type_id`);
`weeks.phase` has been removed rather than left in as something that's sometimes wrong.

---

## 6. Multi-coach ingestion: every parsed table is upsert-safe

Every coach in a league uploads their own turn, and each turn independently contains the
*entire* league's shared facts for that week — the full League Report (every game, not just
theirs), the Standings block, and every transaction line — alongside two coach-specific
things: their own game's play-by-play, and their next opponent's drive-by-drive summary. So
with N coaches uploading, the shared data arrives N times over; the design goal is that
re-ingesting it is a no-op, never a duplicate row, while genuinely coach-specific data still
lands correctly.

This works out cleaner than it sounds, because it splits along a line the schema already
respects: **shared league-wide facts key on the fact itself** (a game, a franchise's weekly
standing, a transaction), and **the two coach-specific tables key on the actual game they
describe**, which — this is the part worth spelling out — is *also* shared more often than it
first appears:

- **`games`** — `UNIQUE KEY (week_id, home_franchise_id, away_franchise_id)`. Whichever of the
  two participating coaches' uploads gets parsed first creates the row; the second is an
  upsert against the same key.
- **`team_game_stats`** — `UNIQUE KEY (game_id, franchise_id)`. Same logic, one row per team
  per game regardless of which upload supplied it.
- **`standings_weekly`** — `UNIQUE KEY (week_id, franchise_id)`. Every coach's Standings block
  covers the whole league, so this table fills in from whichever upload gets parsed first
  each week; added `source_upload_id` for provenance, not for the dedup itself.
- **`transactions`** — free text has no natural key, so the parser computes a SHA-256 of
  `detail_text` into `detail_hash`, and `UNIQUE KEY (week_id, franchise_id, detail_hash)`
  does the deduplication. (Hashing sidesteps InnoDB's composite-key length limit that a raw
  `VARCHAR(255)` in the key would risk.)
- **`plays`** — this is the one that isn't obvious at first: a coach's own play-by-play is
  for a game that has *two* participants, and **both of them** will report play-by-play for
  it in their respective turns (Team A's "own game" this week is the exact same game as Team
  A's Week-N opponent's "own game"). So `plays` needs the same idempotency as `games` does:
  `UNIQUE KEY (game_id, quarter, play_seq)`.
- **`drives`** — similarly non-obvious: the "next opponent's last game" being scouted can
  recur across *multiple different coaches' turns over multiple weeks* — if Team Z has a bye,
  every team scheduled to face Z stays pointed at the same historical game until Z plays
  again. `UNIQUE KEY (game_id, quarter, drive_seq)` makes repeat coverage idempotent the same
  way.

None of this needs application-level "have I seen this before" logic — every parsed insert is
a plain `INSERT ... ON DUPLICATE KEY UPDATE` (or the Custom Code API equivalent) against a key
that's already unique for the right reason, so a second upload confirming the same fact is
cheap and harmless rather than something the parser has to detect and special-case.

---

## 7. How this maps onto Dadabik

- **`raw_uploads.file_path`** is a `generic_file` field — the actual upload control. A
  **hook** (insert/update hook, §10.8 of the Dadabik manual) fires the PHP parser after a
  file lands, which populates `raw_upload_blocks`, then `games`/`team_game_stats`/
  `plays`/`drives`/`standings_weekly`/`transactions`, and finally updates
  `raw_uploads.parse_status`.
- **`raw_uploads` → `raw_upload_blocks`** is a natural **master/detail (subform)**: open an
  upload, see its parsed sections inline, useful for QA-ing a parse without downloading the
  original file.
- **`games` → `team_game_stats`**, **`games` → `plays`** and **`games` → `drives`** are all
  master/detail relationships too — a game's detail page can show both teams' box scores
  plus whichever of the play log or drive summary is available for that particular game.
- **`v_current_standings`** (a VIEW filtering `standings_weekly` to each franchise's latest
  week) is exactly the "pre-filtered results grid using VIEWS" pattern the manual describes
  — gives coaches a standings page with zero custom code.
- **Season-total stat pages** (the "season, offence"/"season, defence" tables shown in the
  Scouting Report) are VIEWS aggregating `team_game_stats`, not stored tables — formula
  fields or advanced SQL reports can produce the ranked versions directly from the grid.
- **`franchises.franchise_id`** should be a `select_single` lookup wherever it's a foreign
  key, so users pick "Philadelphia Eagles" and the numeric ID is stored underneath —
  consistent with how the manual recommends handling lookups generally.

---

## 8. Out of scope for this pass (flagged, not designed)

- **Player-level roster tracking.** The `Roster` block has full per-player detail (age,
  value, strengths, status) but wasn't named as a parsing target. The schema doesn't
  preclude adding a `players` table later (`transactions.player_name` is free text for now,
  not a FK) but building it out is a separate phase.
- **Weekly free-agent pool.** ~170-row list of available players per turn; it's a live
  draft board, not history — probably doesn't belong in the database at all. Flagging rather
  than deciding.
- **Turn credits / billing.** `turn credits =26.0` appears in the Team Report header; this is
  an account-balance concern, not a results concern, and probably belongs in whatever system
  handles payments rather than this schema. Noted, not designed.

---

## 9. Validated against real data

### From `fc_franchises.sql` / `fp_franchises.sql` (36 rows, not just structure)

- **Franchise ID ranges don't collide.** Pro franchises run 2001–2024 (24 rows), college
  franchises run 5001–5012 (12 rows). The unified `franchises` table is safe as designed.
- **The `Rivalry` grouping column is exactly pairwise.** Every distinct value of
  `fc_franchises.Rivalry` (1000–1005 in this data) is shared by exactly two franchises — e.g.
  Air Force and Army both carry `1000`. `rivalries(franchise_a_id, franchise_b_id)` is a
  direct, lossless translation of this.
- **The G/GC/S/SC/B/BC bowl-tier codes decode cleanly** — since confirmed directly against the
  real `f_gametypes` table rather than left as an inference (see below).
- **Some fields are pure derived redundancy and were dropped**, not migrated: `team`/`ori_team`
  are exactly `city + nickname` concatenations; `ConfWins` equals `COUNT(ConferenceYears)`;
  `Perfect` equals `COUNT(PerfectYears)`; `RivalryWins` equals `COUNT(RivalryYears)`.
- **One legacy data-quality issue found and designed around:** `PerfectYears` sometimes
  contains raw HTML (e.g. `' <em>2027</em>'`), apparently used to style an in-progress
  streak differently from a completed one in the old front-end. The new schema stores just
  the year in `franchise_honors`; "is this streak still active" becomes a property of season
  status (`seasons.status`), not markup baked into a data column.

### From `f_gametypes.sql` (20 rows)

This fully resolved the bowl-tier guess: `GC_Winner`→Rose Bowl, `S_Winner`→Cotton Bowl,
`SC_Winner`→Orange Bowl, `B_Winner`→Hawaii Bowl. One correction to the earlier inference:
`BC_Winner` (from `fc_franchises.MotorYears`) is actually the **Music City Bowl**, not "Motor
[City] Bowl" as the legacy column name implied — `honor_types.MUSIC_CITY_BOWL_WINNER` uses the
name from the authoritative lookup table, not the franchise table's column name. `CIC` is
confirmed as the Commander-in-Chief's Trophy (Army/Navy/Air Force) — see §10 for the one
structural nuance worth flagging there.

It also justified replacing the guessed `game_type` ENUM entirely: `game_type_id` is now a
proper FK into a `game_types` lookup table loaded verbatim from `f_gametypes`, with a `phase`
column added for reporting. Confirmed safe as a single ID space the same way franchise IDs
were — every one of the 21 codes belongs to exactly one league across all 20,601 `f_games`
rows, no exceptions.

### From `f_games.sql` (20,601 rows — the full historical game log for both leagues)

This is what let me go from "designed and plausible" to "loaded and cross-checked against the
turn files." Concretely, `migration_data.sql` now also contains:

| Table | Rows | Source |
|---|---|---|
| `weeks` | 1,059 | every distinct (league, season, week) with real game data |
| `games` | 9,969 | paired `f_games` rows, deduplicated on (week, {franchise, opponent}) |
| `team_game_stats` | 19,938 | 2 per game, every box-score column mapped 1:1 |
| `franchise_season_records` | 1,488 | 1,158 derived (aggregated using the exact legacy formula — see below) + 330 `legacy_rollup` |
| `coaches` | 177 | up from 68 — real per-week coach names, not just current/original |
| `franchise_coach_tenures` | 169 | backfilled by detecting coach changes across each franchise's chronological game log |

### From the actual legacy PHP (`fp_updaterecords.php`, `fp_updateseasons.php`, `fc_updaterecords.php`, `fc_updateseasons.php`)

This is the actual source code that computes every field this whole `franchise_legacy_stat_counts`
conversation has been about — ground truth rather than statistical inference. It confirmed some
things, corrected one thing I'd gotten wrong, and closed out nearly everything that was still open.

**`ChampionshipL` — I was right the second time, and now I know exactly why.** The PHP does
precisely `WHERE gametype=35 AND week=19 AND win=0` (pro), matching what your query pointed at
last time — confirmed, not just re-derived independently.

**`ChampionshipW`/`ConferenceYears` — simpler than I made it sound.** Both get set from
Superbowl participation, and I described that as if it were a separate mechanism from "winning
the week-19 game" — it isn't. Winning week 19 *is* how a team reaches the Superbowl; "Superbowl
participant" and "won the conference championship game" are the same set of teams by
construction, not two derivations that happen to agree. Nothing to fix in the data — `CONFERENCE_
CHAMPION` was already correct — just a needlessly roundabout way of describing something
straightforward.

**`MaxWins`/`MaxLosses`/`MaxScored`/`MaxConceded` — fully solved, both leagues, and the earlier
"narrowed down but not solved" verdict on `MaxScored`/`MaxConceded` was based on a bug in my
own aggregation, not a genuine gap.** The exact source queries: pro is
`WHERE week NOT IN (0,17,18,19,20)` (i.e. strictly the 16-game regular season), college is
`WHERE gametype=1` exclusively (an 11-game regular season). Both replicate the legacy figures
with **0 mismatches**, all four stats, all 24 pro and all 12 college franchises. My own
`franchise_season_records` had a real bug: it excluded only preseason, not the full postseason
too, so for the seasons where per-game detail exists *only* as a playoff-round row (no regular-
season games at all, or vice versa) it was aggregating the wrong set of games entirely — a
handful of early seasons had off-by-orders-of-magnitude records as a result (e.g. Bills 1994
showed as 0-1 with 13 points instead of the real 13-3 with 444). Rebuilt `franchise_season_records`
from the exact legacy queries; all four `Max*` legacy rows deleted for both leagues.

**College bowl runner-up counts — I got this one wrong, and it wasn't a subtle mistake.** I'd
concluded these were structurally unrecoverable because the PHP's "Find Rose Bowl losses" block
(and the Cotton/Orange/Hawaii/Music-City equivalents) increments `GC_Runnerup` etc. with no
accompanying year-list update — and took "the legacy table never stored a year list" to mean
"the dated facts can't be reconstructed." That's wrong: I'd already used exactly this query
shape for the *winner* side (`gametype=X AND win=1`) to build dated `franchise_honors` rows
directly from `f_games`, without needing `fc_franchises` to have pre-stored anything. The
loser side needed nothing more than flipping `win=1` to `win=0` — I just didn't think to do
it. 0 mismatches, all five bowls, all 12 college franchises, all at week 13 (same week as the
corresponding wins). Fixed the same way as everything else: five new honor types
(`ROSE_BOWL_RUNNERUP` and siblings), 155 dated `franchise_honors` rows, legacy rows deleted.

**Two smaller confirmations:** `PerfectYears`'s `<em>` tags are applied unconditionally by the
PHP every time (`CONCAT(PerfectYears,' <em>$season</em>')`, always, no branch) — not an
"in-progress vs. completed" distinction as I'd guessed, just a permanent styling choice in the
old front-end. And a perfect season specifically requires **11-0** (college's fixed regular-
season length) *and* winning the national championship that year, not just going undefeated —
matches what I'd already stored (sourced directly from `PerfectYears`, not re-derived), just
useful to know precisely what it means.

### Was `franchise_legacy_stat_counts` too pessimistic? Yes, three times — it's empty now.

You pushed back three times on treating fields as permanently opaque, and every time you were
right. The third time wasn't even a case of finding a better signal — I had the right query
pattern already, from the winner side, and just didn't apply it to the loser side. Summary of
where everything landed:

| Field | Status | Fix |
|---|---|---|
| `Runnerup` (pro) | Fully derivable | New `LEAGUE_RUNNERUP` honor type, 45 dated rows |
| `ChampionshipL` (pro) | Fully derivable (confirmed by source) | New `CONFERENCE_RUNNERUP` honor type, 90 dated rows |
| `MaxWins`/`MaxLosses`/`MaxScored`/`MaxConceded` (pro + college) | Fully derivable (confirmed by source) | `franchise_season_records` rebuilt correctly; all rows deleted |
| Bowl runner-up counts (college, 5 bowls) | Fully derivable — I just hadn't tried | 5 new honor types, 155 dated rows |

`franchise_legacy_stat_counts` went from 252 rows to **0**. Every field that started in there
turned out to be derivable; none of it needed to be a permanent legacy staging table. It stays
in the schema as a safety net for whatever comes up next, not because anything currently needs
it.

A few other things this validated or fixed along the way:

- **Spot-checked against the turn files directly**: Eagles vs. Packers, Week 1, season 2032 —
  `f_games` gives 42–21, exactly matching the League Report text from the original sample.
- **`weeks.phase` was wrong and has been removed** (see §5) — real data disproves that a week
  has one phase.
- **`safety_conceded` needed to be a count, not a boolean** — `f_games.safe` is a run of
  repeated letters (`S`, `SS`, `SSS`, up to `SSSS` once). Renamed to `safeties_conceded`.
- **The `qb` flag is confirmed: starting QB benched during the game** (your answer) —
  `team_game_stats.starting_qb_benched`, no longer a guessed/held-verbatim field. This also now
  explains the earlier data-driven observation (186 pass yds / 11.8 pts average vs. 234 / 32.4
  otherwise) — a benched starter is usually a symptom of a team already losing badly, not a
  cause of worse stats on its own.
- **Two documented exclusions**, not silently dropped: 423 rows where `week` is a sentinel
  (`95`, `98`, or `99`) marking a season-end rollup with no real per-game detail — these feed
  `franchise_season_records` with `source='legacy_rollup'` instead of `games`. And 240 rows
  (NFLAR seasons 1989–2003, weeks 17–19) that record only a win/loss outcome for a playoff
  round with no opponent identified and no box score — these are **not** in `games` at all,
  since there's nothing genuine to attach to a game row. The final season records for those
  years are still complete and correct (via the rollup rows), but individual playoff-game
  detail for that specific window isn't recoverable from `f_games`.
- **`ret_type`/`ret_num`/`ret_yds`/`ret_td` on `team_game_stats`** (fumble/interception/
  defensive-TD returns) are left NULL for every migrated row: `f_games` has no columns for
  these at all, only kick and punt returns. This isn't a migration gap, it's a genuine hole in
  what the legacy table ever captured — going forward, the turn-text parser can fill this in
  from the League Report line (`FumR`/`IntR`/`DefR` do appear there), just not retroactively.

---

## 10. Open questions

1. ~~Single-uploader vs multi-coach ingestion~~ **Resolved: multi-coach, and it's handled.**
   Every parsed table now has a dedup key suited to how re-ingestion actually happens — see §6
   for the full breakdown (`games`, `team_game_stats`, `standings_weekly`, `transactions`,
   `plays`, and `drives` all upsert cleanly regardless of which coach's turn supplied a given
   fact, including the non-obvious cases where two *different* coaches' turns describe the
   exact same game).
2. ~~`played_up` isn't in the historical data~~ **Resolved: it was never recorded, and that's
   fine.** Confirmed — it wasn't captured historically, only submitted in orders, which are out
   of scope here. `team_game_stats.played_up` stays in the schema exactly as designed: it will
   simply start at 0/unknown for all migrated legacy rows and populate correctly from parsed
   turn text going forward. No further action needed.
3. **CIC's three-way structure isn't modeled, just its outcome.** Confirmed as the
   Commander-in-Chief's Trophy (Army/Navy/Air Force round robin), and a genuine structural
   check bears this out: Air Force and Army share pairwise `rivalries` group `1000`, but Navy
   doesn't belong to that group at all (paired with Maryland instead) — proving `Rivalry` and
   `CICWins`/`CICYears` track two different things. `honor_types.CIC_WINNER` correctly
   captures "who won the trophy that year"; it doesn't model the round robin itself. Only
   worth extending if you want in-progress three-way standings, not just the annual winner.
4. ~~Runner-up-only and Max-only fields~~ **Fully resolved.** `Runnerup`, `ChampionshipL`,
   `MaxWins`, `MaxLosses`, `MaxScored`, `MaxConceded`, and the college bowl runner-up counts
   are all exactly derivable, both leagues, backed by real source queries rather than
   inference — see §9. `franchise_legacy_stat_counts` is empty; every field that started there
   turned out to be recoverable.

---

## 11. Suggested build order

Largely overtaken by events — most of this is now loaded and validated rather than planned:

1. ~~`leagues`, `seasons`, `franchises`, `coaches`, `rivalries`, `honor_types`,
   `franchise_honors`, `franchise_legacy_stat_counts`~~ **Done.**
2. ~~`weeks`, `game_types`, `games`, `team_game_stats`, `franchise_season_records`,
   `franchise_coach_tenures`~~ **Done** — all loaded and cross-checked against the turn files
   in `migration_data.sql` (see §9).
3. `raw_uploads`, `raw_upload_blocks` — still the right place to start on the *live* ingestion
   side: get file upload + block-splitting working end to end before any parsing logic exists,
   so every future turn is captured even before its parser is finished.
4. The results/standings parser (`standings_weekly`, and new rows into `games`/
   `team_game_stats`/`franchise_season_records` going forward) — regular enough (fixed-format
   lines) to parse reliably first among the *new* parsing work.
5. `transactions` — same League Report source, free-text lines; lower confidence parsing, so
   build after the box-score parser is proven and keep `detail_text` as the safety net.
6. `plays` — the coach's own quarter blocks; structurally regular but highest volume.
7. `drives` — the "Scouting Report - Game Summary" block; same priority tier as `plays` but
   worth keeping as an explicitly separate parser given the different grain (§1).

---

## 12. Franchises, team identities and team codes

*Added Aug 2026. This was obvious to Alan and repeatedly non-obvious to Claude during the
`legacy_play_log.game_id` backfill — several wrong turns traced back to it not being written
down. Every claim below is confirmed against live data; the confirming check is noted.*

---

### The model

**A franchise is a slot in a league, not a team.**

- Each league has a fixed number of franchise slots: **12 for college (`NCAA%`), 24 for pro
  (`NFL%`)**. Stable since 1989 — no expansion, no contraction.
  *(Confirmed: `franchises` holds 12 NCAA5 and 24 NFLAR rows.)*
- Each slot has a permanent, unique `franchise_id`.
- Each slot is **occupied by a real-life team identity** — a name and a matching team code —
  drawn from a much larger pool: the 32 current NFL teams plus historical variants
  (`OA` Oakland Raiders, `WR` Washington Redskins, `WT` Washington Team), and the
  corresponding college pool.
  *(Confirmed: `team_codes` holds 78 codes against 36 franchise slots.)*

**A franchise's identity changes as coaches join and leave** — and can change **mid-season**,
sometimes more than once. When a coach leaves a slot and another arrives, the slot may take a
different real-life identity.

Franchise 2014's history, as an example:

| From | Identity |
|---|---|
| 1989 | Dallas Cowboys |
| 2006 wk 4 | New York Giants |
| 2008 wk 5 | Washington Commanders |
| 2013 wk 0 | Dallas Cowboys |
| **2014 wk 13** | Washington Commanders |
| 2021 wk 11 | Dallas Cowboys |
| **2024 wk 5** | Washington Commanders |
| 2031 wk 9 | Dallas Cowboys |

*(Confirmed: 9 franchise-seasons across NFLAR carry two identities within real weeks.)*

⚠ **That 9 was measured on the 2,588-record identity map. Against the built `franchise_identities`
table it measures 21** ✓ 15-Aug-2026 · A4 chat 1, 20,156 rows. Almost certainly a superset rather
than a contradiction — the table covers far more franchise-weeks than the map did — but the two
numbers have never been reconciled, so **do not quote either as "the" figure without saying which
population it came from.** `label_L1_identities.sql`'s gate L1.3g still hardcodes the three
franchises and eight seasons the 9 was drawn from; if the real number is 21, that gate checks a
subset and passes for the wrong reason.

⚠ **Sharpened 16-Aug-2026 — and the two numbers may never have been comparable quantities.** The
21 counts **franchises** whose identity ever differs from their current `franchises.label` — 16
NFLAR and 5 NCAA5. The 9 counts **franchise-seasons** carrying two identities inside one season.
Those measure different things, so "21 against 9" was never a like-for-like discrepancy and
reconciling them as if it were would have produced a false answer. The figure that actually
describes the scale of the problem is neither: **4,969 franchise-weeks** diverge from the current
label — 4,401 NFLAR across 43 seasons, 568 NCAA5 across 27 ✓ 16-Aug-2026 · whole population.
L1.3g's narrowness stands as recorded regardless.

---

### In-league season is NOT a calendar year

A turn is processed **every two real weeks** — occasionally three, around Christmas, Easter and
similar breaks. So an in-league season takes a broadly predictable amount of real time, and
in-league years run **ahead** of calendar years:

| League | Turns per season | Real time per season | In-league seasons per real year |
|---|---:|---:|---:|
| **NFLAR** (pro) | 21 (weeks 0–20) | 42 weeks | ~1.24 |
| **NCAA5** (college) | 14 (weeks 0–13) | 28 weeks | ~1.86 |

Confirmed against turn-file headers, which carry both the in-league season and the real date:

| League | In-league season | Real date |
|---|---|---|
| NFLAR | 2005 | 8 Jan 2003 |
| NCAA5 | 2000 | 27 Aug 2003 |
| NFLAR | 2013 | 2 Jul 2009 |
| NFLAR | 2021 | 28 Dec 2015 |
| NFLAR | 2024 | 3 Feb 2018 |
| NFLAR | 2032 | 1 Jun 2024 |
| NFLAR | 2033 | 20 Sep 2025 |
| NCAA5 | 2038 | 18 Dec 2025 |

NFLAR 2005→2033 is 28 in-league seasons across 22.7 real years — 42.2 weeks per season against
a nominal 42. NCAA5 2000→2038 gives 30.6 against a nominal 28. Both run slightly long, which is
the holiday breaks accumulating; NCAA5 picks up proportionally more of them because it fits more
seasons into the same real time.

**The mapping is therefore approximate, not a formula.** The breaks are irregular, so a real
date cannot be converted to an in-league season (or the reverse) by arithmetic. The figures
above are for orientation only — anything needing the actual season must read it, never
calculate it.

**Never derive an in-league season from the system clock.** This is the root of the
`games.label` bug in `lessons.md` §9: `date('Y')` was used for the label's season, which assumes
in-league year equals calendar year. It never has. The season is always available from the FK
chain `games.week_id → weeks → seasons.year`.

---

### Two eras of data granularity — by design, not a gap

**Play-by-email began around calendar 2003–2004.** Before that the league ran without per-turn
electronic records, so the historical data has two fundamentally different granularities:

| Era | What exists | Grain |
|---|---|---|
| **Pre-email** — NFLAR in-league 1989–2003 (calendar 1989–2003) | Season records only, carried by the **sentinel rows** (weeks 95/98/99) | **Season** |
| **Email era** — NFLAR in-league 2004+ (calendar 2003+) | Per-week fixture rows, plus play-by-play | **Week** |

The sentinel weeks are therefore not a defect or a rollup artefact to be filtered out — for
NFLAR's first fifteen in-league seasons they **are** the record. Filtering
`week NOT IN (95,98,99)` as "cleanup" silently discards the entire pre-email history.

**NCAA5 has no pre-email era.** It was created at the transition — its first season, in-league
2000, is calendar August 2003 — so it has per-week coverage from the very beginning and its
sentinel rows are supplementary rather than primary.

Corroborated by the data: `legacy_play_log` starts at exactly **NFLAR in-league 2004** and
**NCAA5 in-league 2000**, the first seasons of each league to be played by email.

### What this means in practice

- **Identity is week-exact only in the email era.** For NFLAR pre-2004 it is season-exact,
  because that is all that was ever recorded. A pre-2004 mid-season identity change cannot be
  recovered — not because an extract lost it, but because it was never written down.
- **Never assume week granularity across the whole history.** A query that works on NFLAR 2024
  may return nothing for NFLAR 1995.
- **Derived data should record which era it came from.** `franchise_identities.derived_from`
  does this: `'week'` where `f_games` recorded the identity for that exact week,
  `'season_rollup'` where it came from the season sentinel. NCAA5 rows should be almost
  entirely `'week'`; NFLAR pre-2004 almost entirely `'season_rollup'`.
- **Absence of pre-email per-week data is expected**, in the same way that a code missing from
  the play-by-play tables reflects upload coverage rather than league history.

---

### Confirmed engine behaviour: the quarter buckets

Given directly, not derived — no query could have produced this. `legacy_play_log` stores only
`time_gone_seconds` and has no `quarter` column, so quarter must be **inferred** from the clock:

```
Q1:  0–14 minutes
Q2: 15–29 minutes
Q3: 30–44 minutes
Q4: 45–59 minutes
OT: 60–75 minutes    (= quarter 5, matching plays.quarter's own convention of 1-4, 5 = OT)
```

Write this as an explicit `CASE` on `FLOOR(time_gone_seconds / 60)`, **not** as a formula. The
rule does not map cleanly to one — OT's bucket is 16 minutes wide, not 15 — so
`FLOOR(seconds/900)+1` happens to fit the first four buckets and then silently breaks. Match the
stated boundaries literally.

Corroborated in passing during the backfill: NFLAR 2005 wk 13 MV v SF runs to 70:54 in the turn
file, inside the OT bucket, and the two 2034 overtime games both come back with
`COUNT(DISTINCT quarter) = 5` from the live parser.

---

### The uniqueness rule

**`(league, season, week, team name)` and `(league, season, week, team code)` are unique.**
Identity is unique at **week** grain — not season, not franchise.

Tested against the full identity map (2,588 records), both directions:

| Check | Result |
|---|---:|
| One team name → more than one franchise in a week | **0** |
| One franchise → more than one team name in a week | **0** |

✓ **Re-confirmed against the built table 16-Aug-2026**, whole population, 20,396 rows: both
directions still **0**. `franchise_identities`' `uk_franchise_week` now makes the second
direction **structural** rather than merely tested — the database refuses it rather than a query
reporting it absent.

This is the single most important structural fact for any query joining play-by-play or
historical records to franchises.

---

### Code and name: which direction is guaranteed

This distinction matters and is easy to get backwards.

**`code → name` is unique and permanent.** A code identifies exactly one real-life team
identity, always, in every league and every era. `DC` is always Dallas Cowboys. This is the
direction every resolver joins on, and the one that must hold.
*(Confirmed: grouping `team_codes` by `code` returns no code with more than one name.)*

**`name → code` is NOT unique, and never needed to be.** One team can have several codes, of
which typically one is in active use:

| Team | Codes | In use |
|---|---|---|
| Green Bay Packers | `GB`, `GP` | `GB` |
| New Orleans Saints | `NS`, `NO` | `NS` |
| Tennessee Titans | `TN`, `TT` | `TN` |

Nothing resolves name → code, so the duplicates are harmless. Do not "fix" them by deleting
rows; they are superseded entries, not errors.

⚠ **"Nothing resolves name → code" was true of the application and false of the build scripts,
and the difference cost 642 rows.** A resolver that reads this table by name and takes the first
row it finds is choosing at random between the two valid codes, and the choice is invisible
afterwards. See "The unordered `LIMIT 1`" below.

---

### Unused codes are normal — two legitimate kinds

A code appearing in no data is not by itself a defect. As of Aug 2026 there are eight, and all
eight are expected:

| Kind | Codes | Why |
|---|---|---|
| **Superseded duplicate** | `TT`, `NO`, `GP` | Another code for the same team is the one in use |
| **No uploaded game** | `OK` Oklahoma Sooners, `RU` Rutgers Scarlet Knights, `TA` Texas A&M Aggies, `TV` Tennessee Volunteers, `LV` Las Vegas Raiders | A real team in the pool that no coach has ever uploaded a game involving |

⚠ **The count above read "seven" while the table listed eight.** Corrected 16-Aug-2026 by fixing
the number, not by trimming the list — all eight entries are real.

⚠ **Which codes are unused depends on which populations you test, and that was never stated.** A
check run 16-Aug-2026 across `franchise_identities` + `legacy_play_log` + `plays` returns **nine**
— `GP`, `NO`, `OK`, `RU`, `TA`, `TT`, `TV`, `WR`, `WT` — differing from the table above in two
ways. `LV` drops off, because `franchise_identities` now carries Las Vegas Raiders rows even
though no game was ever uploaded under that code. `WR` and `WT` appear, which the original check
did not report. **Neither list is wrong; they answer different questions**, and the original's
population included `gplan_main.n_playbyplay`, which is no longer reachable from `gplan_pbm`.
Treat the table above as the documented set and re-derive against a *named* population before
acting on it (`lessons.md` §27).

The second kind says nothing about whether a slot has *held* the identity — only that no
play-by-play reached the archive. `LV` is the clearest case: franchise 2011 genuinely is the
Las Vegas Raiders from 2029 in `f_games`, with 101 fixtures on record, but neither Alan nor
Gordon has played them under that name, so no uploaded game carries the code. Absence from
`legacy_play_log` / `plays` / `n_playbyplay` reflects **upload coverage**, not league history.

The check that *does* indicate a defect is the reverse one — a code **in the data** with no
`team_codes` row. Run across `legacy_play_log` (`offense_team_code`, `defense_team_code`,
`possession_team_code`), `plays.field_side`, and `gplan_main.n_playbyplay` (`a_off`, `a_def`,
`a_poss`, `a_team1`, `a_team2`), this currently returns **zero rows**.

---

### Rename vs. slot change

Two different things change a franchise's identity, and they need different handling:

**A slot change** — a coach leaves, another arrives, the slot takes a different real-life
identity. Each identity already has its own code. Franchise 2014 alternates between `DC` and
`WC`; franchise 2007 between `PS` and `TN`. Nothing in `team_codes` changes.

**A rename** — the same real-life team is renamed. The new name gets its **own new code**, not
a second name on the existing one. Precedent: the Raiders. `OA` = Oakland Raiders (franchise
2011, 2004–2029); when the team became the Las Vegas Raiders (2029–2034), **`LV` was added as
a separate code** rather than adding a second name to `OA`. Confirmed against the league
announcement text.

So `code → name` holds with **no exceptions**. If a code appears to need two names, the answer
is a new code.

---

### What follows from this

**Join at week grain.** Any resolution from a team name or code to a franchise **must**
include season *and* week. Resolving at season grain is wrong nine times over in NFLAR alone,
and silently — it returns a real franchise, just not the right one.

**`franchises.label` is a snapshot, not history.** It is a `STORED GENERATED` column
(`city` + `nickname`), so it always reads as the slot's *current* identity. Franchise 2014
reads "Dallas Cowboys" across its entire 1996–2034 history, including 394 fixtures actually
played as Washington Commanders. **Never use `franchises.label` to describe a historical game,
season or record.** Use `franchise_identities`, which is recorded at week grain and lives in this
database. (`f_games.team` is the original source and says the same thing, but it is frozen, in
`gplan_main`, and will not exist on gpbm.uk — see §13.)

⚠ **One narrow, proven exception exists**, recorded in `findings/parse-as-identity-evidence.md`.
Where a `games` row exists, the turn file demonstrably carried the name the label holds — because
all three parsers match by exact string equality and drop the game on failure, so the row could
not exist otherwise. That warrant covers only franchise-weeks with a successful parse, and **it
expires the moment A4 package 3 adds a fallback path**. It is not a general licence, and A4's
backfill is the only thing that has used it.

*Known live defect:* `games.label` was built this way. **3,487 NFLAR games across 39 seasons
and 516 NCAA5 across 27** carry a label naming an identity the franchise was not playing under
that week — e.g. NFLAR 2014 wks 13–20 and all of 2021 labelled "Dallas Cowboys" for games
played as Washington Commanders. Same shape as the `games.label` calendar-year bug
(`lessons.md`). Affects `game.php`, `team.php`, `coach.php`. It is a rebuild from
`f_games.team`, not a patch. Logged as a backlog item with its own task doc.

**`team_codes` is an identity map, not a franchise map.** `DC` and `WC` both point at
franchise 2014 in different weeks of the same season. That is correct and expected. A code
that resolves to "the wrong franchise" is nearly always a code carrying the wrong *name*.

**Two codes in one season is normal, in one week is impossible.** `DC` and `WC` may both
appear in a single season for one franchise — they are both valid NFC East identities. They
can never both apply in the same week.

---

### Corrections made (backfill session, Aug 2026)

`team_codes` carried four wrong names. All four were confirmed from multiple independent
directions before being changed, and all four were `UPDATE`s — no code gained a second name.

| Code | Was | Is | How confirmed |
|---|---|---|---|
| `WC` | Dallas Cowboys | Washington Commanders | Resolved from both offence and defence sides independently; franchise 2014 shows 394 fixtures as Washington in `f_games`; `DC` already covers Dallas |
| `TN` | Tennessee Volunteers | Tennessee Titans | `n_playbyplay` shows `TN` only in pro leagues (NFLAR, NFLC, NFLA), never NCAA; `NFLAR-PE_s2021_w12_vs_Titans.txt` carries code and name together — play lines `0:00  TN  80  1st and 10`, scoreline `10-21 TN`, in a game headed *Philadelphia Eagles vs Tennessee Titans* |
| `OA` | Las Vegas Raiders | Oakland Raiders | All `OA` rows fall in 2005–2027, entirely the Oakland era; `LV` added for the Las Vegas identity |
| `GE` | Georgia Tech Yellow Jackets | Georgia Bulldogs | NCAA5 slot 5003 was Bulldogs 2000–2010, Yellow Jackets 2011–2038; `GT` already covers Tech |

Two codes added: **`LV`** (Las Vegas Raiders) and **`TV`** (Tennessee Volunteers) — the latter
freeing `TN` for its actual meaning without losing the Volunteers from the pool.

Effect: legacy play-by-play rows resolving to a real fixture rose from **188,560 to 203,617**.

**The check that would have caught all four years earlier:** for every code, compare the name
`team_codes` holds against the name `f_games` recorded for that team in that week. All four sat
at **0.0% agreement** over thousands of rows. Worth running after any change to `team_codes`.

---

### Why this caused trouble

The backfill resolves `legacy_play_log` → `games` through team codes, so four wrong names meant
four wrong identities and therefore four wrong franchises. Diagnosing it took far longer than
it should have because:

- `franchises.label` showed current identities, so franchise 2014's Washington fixtures
  appeared as Dallas. Correcting `WC` moved that franchise from 27 to 77 games, which read as
  the *fix* stealing another team's history rather than restoring the slot's own.
- A zero-row collision check (`one name → two franchises`) was taken as proving the codes
  sound. It proves no code is **reused**; it says nothing about a code that is real but
  **mislabelled**. Same lesson as the earlier `AF`/"Carolina Panthers" case.
- Unused codes and duplicate names both look like defects without the two sections above, and
  chasing them wastes time that belongs on the codes that are actually wrong.

---

### `franchise_identities` — built, carried forward, and where it stops

**The table exists.** Built by `label_L1_identities.sql` as a one-off extract from
`gplan_main.f_games`, and extended by `A4_backfill_identities.sql` (16 Aug 2026) to cover the
weeks played since. Read it, not `franchises.label`, for any historical identity.

**Live state ✓ 16-Aug-2026**, whole population:

| | |
|---|---:|
| Rows | **20,396** |
| `derived_from = 'week'` | 20,058 |
| `derived_from = 'season_rollup'` | 338 — NFLAR pre-2004 only |
| `team_code IS NULL` | 27 |
| Franchises covered | NFLAR 24/24, NCAA5 12/12 |
| `(week, team_name)` → >1 franchise | **0** |
| `(week, team_code)` → >1 franchise | **0** |
| `team_code` disagreeing with `team_codes.team_name` | **0** |

NCAA5 is 100% `'week'`. The live `SHOW CREATE TABLE` is identical to the script's `L1.1` DDL
✓ 15-Aug-2026. Of the 338 rollup rows, 330 were `f_games`' pre-2004 sentinels and 8 were
`'rollup'`-tagged NFLAR 2004+ rows.

**27 rows carry `team_code IS NULL`:** Houston Oilers (12), LA Raiders (8), Phoenix Cardinals (7),
all NFLAR 1989–2000. Pre-email identities that predate the code pool. Expected, not a defect.

#### The horizon — closed once, and it reopens

`f_games` is frozen while the league keeps playing, so the original extract stopped short of the
present. ✓ measured 15-Aug-2026, re-measured 16-Aug:

| League | Extract horizon | Last week with games | After the A4 backfill |
|---|---|---|---|
| NFLAR | 2034 wk 9 | 2034 wk 16 | **2034 wk 16** ✓ |
| NCAA5 | 2038 wk 11 | 2039 wk 3 | **2039 wk 3** ✓ |

That is `f_games`' horizon exactly — `lessons.md` §22 records it as *"roughly"* those weeks; it is
precisely them. At that horizon **120 games had no identity**, every one falling in a week with
*zero* identity rows: 13 whole weeks (NFLAR 2034 wk 10–16, NCAA5 2038 wk 12–13 and 2039 wk 0–3).
This was never a defect in the extract. It was the extract working exactly as designed against a
frozen source, while the league kept playing.

✓ **Closed 16-Aug-2026.** `A4_backfill_identities.sql` inserted **240 franchise-weeks** (NFLAR 168
= 7 weeks × 24, NCAA5 72 = 6 weeks × 12; no byes in any gap week), and the figure is now **0** —
verified by re-running `label_L1_identities.sql`'s own gate L1.3d unmodified: **10,029 games, 0
missing**.

The backfill used `franchises.label` as its source, which §12 otherwise forbids. The warrant is
the narrow exception above, and it was **tested rather than assumed**: 240/240 franchise-weeks
also had their label string present in that week's `League Report` block, and that check was shown
to discriminate — run against all 75 distinct `team_codes.team_name` values on NFLAR 2034 wk10 and
wk12 it found exactly 24, all of them the current slate, with **zero** non-slate hits and 51 names
failing to match.

⚠ **The gap will reopen, and this is the important sentence in this subsection.** Nothing writes
identity rows during a parse. The backfill is a one-off catch-up, so the moment a new turn is
uploaded the newest week has games and no identities again — and any query joining to
`franchise_identities` silently returns nothing for it, which is the week a reader is most likely
to look at. **A4 packages 2 and 3** — both parsers writing an identity row per franchise-week from
the turn file's own team names, and the three lookups resolving at week grain — are the only
durable fix. Until they land, re-running `A4_backfill_identities.sql` is the supported stopgap: it
upserts on `uk_franchise_week` and is safe to repeat.

#### The unordered `LIMIT 1` — 642 rows, and no gate could see it

✓ **Found and corrected 16-Aug-2026.** Both of `label_L1_identities.sql`'s population passes
resolve the team code with:

    (SELECT tc.code FROM team_codes tc WHERE tc.team_name = fg.team LIMIT 1)

**No `ORDER BY`.** For the three names carrying two codes, the server returned whichever row it
reached first. It landed on `GB` and `TN` — both correct — and on **`NO` for New Orleans Saints,
which is wrong**. Every other holder of that code disagreed:

| Source | `NS` | `NO` |
|---|---:|---:|
| `gplan_main.n_playbyplay.a_off` | 4,775 | **no row at all** |
| `legacy_play_log.offense_team_code` | 4,698 | 0 |
| `legacy_play_log.defense_team_code` | 4,559 | 0 |
| `plays.field_side` | 29 | 0 |
| `franchise_identities.team_code` | 0 | **642** ← the outlier |

Corrected by `A4_fix_neworleans_code.sql` — 642 rows `NO` → `NS`, one franchise, backup
`bak_a4_no_ns` retained as the rollback path. `team_codes` was **not** modified: `NO` remains a
superseded entry, which is what superseded codes are.

**Two things make this worth reading rather than just recording.**

*No existing gate could detect it.* Both `NO` and `NS` are legitimate `team_codes` rows for that
name, so the code/name agreement check — the one established above after four mislabelled codes —
passes by construction. So does the week-grain uniqueness check: one franchise still held one code
per week. This is neither the "code carrying the wrong name" shape nor the "inference written down
as fact" shape. It is a resolver *permitted to choose*, choosing silently, and the choice hardening
into data that later work then has to stay consistent with. By the time it surfaced, correcting it
had become a 642-row question rather than a one-line one.

*It was right two thirds of the time.* Green Bay and Tennessee came out correct by the same coin
toss — their alternates `GP` and `TT` have zero rows anywhere. A defect with that hit rate is not
one anyone goes looking for, which is why it survived from the original build.

**The rule.** A `LIMIT 1` with no `ORDER BY` is only safe where the result set is provably one row
— and where it is, the `LIMIT 1` is doing nothing and the proof belongs in a gate instead.
Anywhere else: order it deterministically and say why that order is right, or refuse to resolve and
write NULL. Writing NULL is recoverable; a plausible wrong value is not.

### The three parser lookups — what they actually do ✓ 15-Aug-2026 · code read

All three resolve a turn file's team name by exact match against `franchises.label`, scoped to the
league. Confirmed by reading the files, not inferred from prose:

| File | Line | On no match |
|---|---|---|
| `extract_games.php` | 223–225 | **Whole game skipped** — no `games` row, no `team_game_stats` rows. Names listed in a red panel on screen. |
| `extract_standings.php` | 177–179 | That team's standings row skipped. Names listed in a red panel on screen. |
| `operational_hooks.php` | 196 | `raw_uploads.franchise_id` left null; a warning string appended to `parse_notes`. `parse_status` still `'partial'` — the ordinary success state. |

⚠ **Earlier documents describe this as "dropped with no error" and "silently SKIP". That is wrong
for the first two.** Both render the unresolved names prominently at extraction time. The defect is
real — the row *is* dropped — but the accurate statement is **"dropped with an on-screen warning
nobody is required to read, and no queryable record afterwards."** Only `operational_hooks.php` is
genuinely quiet, and it is the one whose failure looks like success in the database.

The distinction matters because the severity argument for repointing these at
`franchise_identities` was partly built on the word "silently". The case stands on the third file
and on the absence of any persisted trace in the first two — not on total silence.

⚠ **`label_L1_identities.sql`'s header comment cites lines 214 / 220 / 188 for these three.**
Those are stale; the table above carries the code-read figures ✓ 15-Aug-2026. Correct the script
comment when someone next touches that file.

⚠ **This table describes the parsers as they stand today.** A4 package 3 repoints all three at
`franchise_identities` at week grain, keeping a `franchises.label` fallback that **logs when it
fires** — a silent fallback would rebuild the exact defect the repoint exists to remove. Update
this table when that lands, and note that the fallback is also what expires
`findings/parse-as-identity-evidence.md`.

---

## 13. Changes since the original redesign (Aug 2026)

**`legacy_play_log.game_id`** — new nullable column linking the historical archive to `games`
for the first time. 199,577 of 250,169 rows populated, 98.6% of the 202,439 achievable. No
foreign key, deliberately: cascade would let one game deletion destroy rows in a frozen
archive, and `RESTRICT` would block the delete-and-reupload procedure. Full accounting of the
remaining `NULL`s is in the column comment and in
`findings-legacy_playbyplay_backfill.md`.

**`team_codes`** — four names corrected (`WC`, `TN`, `OA`, `GE`), two codes added (`LV`, `TV`).
See §12 and the DDL comments in `new_schema.sql`.

**`migration_fgames_map`** — narrow one-off extract from `f_games`, renamed from
`tmp_fgames_map`. ~~Interim; review once `franchise_identities` exists.~~ **`franchise_identities`
now exists, so H6's review is unblocked — but the answer is keep, not retire** (A4 §5): `gplan_main`
will not exist on gpbm.uk, so this table becomes the only surviving trace of `f_games`. A10 must
exclude it from the deployment dump rather than shipping it.

**NFLAR 2034 re-parsed** through the live pipeline: 204 games, 384 standings rows, ~300 plays
per week. That season's play-by-play now lives in `plays`, not `legacy_play_log`.

**Known defects, documented not fixed:**

- `games.label` names present-day identities rather than the identity the franchise played
  under that week — 3,487 NFLAR and 516 NCAA5 games. Affects `game.php`, `team.php`,
  `coach.php`. Rebuild from `f_games.team`; see `task-games_label_rebuild.md`.
- `franchise_season_records` has no writer at all — migration-populated, read by `team.php`,
  demonstrably stale.
- `v_current_standings` takes `MAX(week_id)` per franchise rather than per league, and assumes
  id order matches chronological order.
- Three parsers resolve franchises by matching turn-file team names against `franchises.label`,
  which skips a game whose identity differs from the current label — with an on-screen warning in
  two of the three files and nothing queryable afterwards in any of them. Not triggering today;
  will recur at the next identity change. Backlog A4 packages 2+3.

**`franchise_identities` — built, verified, and carried forward to the present** ✓ 16-Aug-2026.
The week-grain identity record `gplan_pbm` never had, and the thing every defect above works
around. 20,396 rows covering every week that has games. See §12 — including why the coverage
reopens with the next uploaded turn until A4 packages 2 and 3 land.

---

## 14. Changes from Feature 15 — duplicate removal (Aug 2026)

**`legacy_play_log` shrank from 250,169 rows to 247,241.** 2,928 duplicate rows removed across
22 fixtures, in two passes with separate backups:

| Pass | Population | Fixtures | Rows | Backup |
|---|---|---:|---:|---|
| Linked | games with a `game_id` | 16 | 2,051 | `bak_f15_legacy_dupes` |
| Unlinked | matchup-weeks with no `game_id` | 6 (all NFLA 2009-2010) | 877 | `bak_f15_legacy_dupes_unlinked` |

Post-deletion: 197,526 linked rows, 49,715 unlinked, 1,325 distinct games — unchanged, since no
fixture lost every copy. Every matchup-week in the archive now sits in 100-200 plays except nine
short ones listed in `task-20`.

**Mechanism.** Most are Alan-v-Gordon games where both coaches' turn files were ingested and the
play text is byte-identical (`lessons.md` §5). The NFLA cases are the same shape with different
coaches — all six involve `NJ`. Three were not that: game 9602 held three copies, and games
310/311 held a second copy whose `result_text` was entirely NULL.

**`result_text` has two storage formats.** 245,812 rows are clock-prefixed
(`0:22  PE  56  1st and 10   …`); **286 rows hold only the cleaned tail**, the same shape as
`plays.result_text`, in one contiguous ingest run at `play_log_id` 997907-998192 covering games
9602 and 9621. Any duplicate detection or play-count check based on `result_text` uniqueness
gives a false positive on those rows. See `lessons.md` §25 — this corrects §23.

**Overtime is absent from the whole archive**, and always was. No row exceeds
`time_gone_seconds` 3599, while 15 NFLAR games (2005-2029) carry `games.went_to_ot = 1`. The
source table `gplan_main.n_playbyplay` has no rows above `a_minutes` 59 either, and the
migration was row-complete (253,450 → 253,450), so this was lost at the original parse rather
than in the migration. The live parser handles OT correctly — `plays` holds 55 rows at
`quarter = 5`. Recovery requires the turn files; see `task-20`. Full evidence in `lessons.md`
§29.

**`gplan_main.n_playbyplay.a_id` does NOT map to `legacy_play_log.play_log_id`.** `a_id` spans
107,181-29,005,625 across 253,450 rows and the migration renumbered. Any join between the two
must key on `(a_league, a_season, a_week, a_off, a_def, a_minutes*60 + a_seconds)`.

**4,071 rows hold a NULL `result_text`** with every other column populated — one ingest fault at
`play_log_id` 799296-803366 spanning NCAA5 2003 wks 1-6, NFLAR 2008 wks 8-13 and NFLA 2008
wks 16-18. The source holds the empty string for exactly the same 4,071, so the text was never
captured. After Feature 15 removed the 278 belonging to games 310/311's duplicate copies, 3,793
remain across 21 linked games plus the inactive-league rows.

**Playcall views are unaffected in substance.** The `v_playcall_*_all` views apply no league and
no `game_id` filter, so all nine leagues feed them — which is intended, since inactive leagues
carry valid play-call information. Removing the duplicates moved pro from 138,713 rows / 5.449
avg yards to 136,950 / 5.452, and college from 67,193 / 6.845 to 66,580 / 6.851. Counts were
overstated by roughly 1%; the averages were barely biased, duplicated plays being a near-random
sample.

**Also confirmed while investigating:** basic and advanced rulesets do not produce different
outcomes (college basic 6.835 vs college advanced 6.845), so grouping the views on `sport_type`
alone is correct. Pro versus college does differ, and the views already separate them.


---

## 15. Changes from Feature 21 — the `leagues` table (Aug 2026)

**`leagues` went from 2 rows to 9.** `legacy_play_log` carries nine distinct `league_code`
values; only NFLAR and NCAA5 have a coach from this project in them, but every archive row now
resolves to a league row.

| id | code | sport | level | `legacy_play_log` rows | in-league seasons | `active` |
|---:|---|---|---|---:|---|---:|
| 1 | NFLAR | pro | advanced | 134,141 | 2004–2033 | 1 |
| 2 | NCAA5 | college | advanced | 67,297 | 2000–2038 | 1 |
| 3 | NFLA | pro | advanced | 16,537 | 2008–2012 | 0 |
| 4 | NFLBC | pro | advanced | 14,681 | 2004–2009 | 0 |
| 5 | NFLC | pro | advanced | 1,504 | 2013 | 0 |
| 6 | NFLI | pro | advanced | 494 | 2004 | 0 |
| 7 | NCAA6 | college | **basic** | 928 | 2015 | 0 |
| 8 | NCAA7 | college | **basic** | 9,478 | 2001–2013 | 0 |
| 9 | NCAA8 | college | **basic** | 2,181 | 2001 | 0 |

`league_id` was assigned explicitly rather than left to `AUTO_INCREMENT`, so the ids match
between MountZion and gpbm.uk when the deployment dump is taken.

**Call leagues 3–9 *inactive*, not defunct** (Alan, 14 Aug 2026). Some are still running with
nobody from this project in them, and which ones is not determinable from any data held here.
"Inactive" states what the record says — `active = 0` — rather than making a claim about the
world. See `lessons.md` §32: an early draft of the `notes` said "Defunct" and had to be
corrected. Note this makes defining `leagues.active` more pressing, not less: the prose word and
the column name are now the same word, and the column's meaning is still undecided.

### `level` is populated, and it is a league-creation fact

`leagues.level` was NULL on both live rows. It is now `ENUM('basic','advanced') NOT NULL` on all
nine, converted from `VARCHAR(20) NULL`.

**The ruleset level is set by the GM when the league is created and is immutable thereafter**
(Alan, 14 Aug 2026). It sits at league grain and has **no season dimension** — worth stating
because §12 established that team *identity* needs week grain, which makes "should this be
per-season?" a reasonable question to ask of any league-level attribute. For `level` the answer
is no, and it is answered.

**The values were read from the archive, not assumed.** `legacy_play_log.ruleset_level` is
`ENUM('basic','advanced') NOT NULL` across all 247,241 rows, and no league carries two values —
verified, along with the same test on `sport_type`. Alan confirmed the same independently, so two
sources agree without one deriving from the other. The task doc's original evidence was six turn
file headers; the archive is four orders of magnitude larger and was sitting there.

**Keep `level` and `sport_type` as two columns.** Across the two live leagues `level` does not
vary at all — both are `advanced` — so it reads as a droppable column, with `sport_type` looking
sufficient on its own. NCAA6/7/8 are basic + college, so college appears with **both** levels and
neither column derives from the other. The task doc argued this hypothetically from NCAA7 being
"not yet in the data"; it is in the data, 12,587 rows across the three.

Since all nine leagues now have rows, the counter-case is visible in the data rather than only in
prose — `SELECT DISTINCT sport_type, level FROM leagues` shows it. **But a query filtered to
`active = 1` sees the constant again**, which is why the argument stays recorded here and in the
`leagues.level` column comment.

**`plays` deliberately has no ruleset column.** It is derivable through
`plays.game_id → games → weeks → seasons → leagues`, the chain `v_plays_normalized` already uses
for `sport_type`, and basic vs advanced does not change play-call outcomes (college 6.835 vs
6.845 average yards, measured Aug 2026). The point of populating `leagues.level` was narrow: stop
the ruleset fact living exclusively in `legacy_play_log.ruleset_level`, where it dies when the
legacy table is retired.

### `NOT NULL` does not make it mandatory — deferred to the backlog

The intent was that a new league could not be created without choosing a level. **It can.** An
`ENUM NOT NULL` column takes the first member of its list as an implicit default, so an omitted
`level` arrives as `basic` rather than erroring — confirmed by probe under active
`STRICT_TRANS_TABLES`. Five other live columns share the shape. Full detail and the measured
comparison of alternatives are in `lessons.md` §30; the fix is a `todo.md` item spanning all six
columns rather than a `leagues`-only patch.

### `leagues.active` — defined 14 Aug 2026, and audited

**`1` = new data for this league is actively arriving into the application on a regular basis.
`0` = we hold historical data for it but nothing new is coming in** (Alan). The definition is
deliberately about *this database*, not about the world: whether a league marked `0` is still
being run by the GM is **undeterminable from anything held here**, and `0` must not be read as
"this league has ended". The word for it is *inactive*, not defunct (`lessons.md` §32).

It is the first version of this column that can be **checked**, and it was:

| | `active` | `raw_uploads` rows | last upload |
|---|---:|---:|---|
| NFLAR | 1 | 33 | 3 days ago |
| NCAA5 | 1 | 6 | 11 days ago |
| the seven | 0 | 0 | — |

Flag and evidence agree on all nine. **But the flag is set by hand and no process maintains it**,
so a league that quietly stops will keep `active = 1` until somebody notices. The column comment
says so and points at `MAX(raw_uploads.uploaded_at)` per league as the audit. Treat the column as
a cached assertion, not a live fact.

### The seven inactive leagues are archive-only — measured, not assumed

Zero rows in **`seasons`, `franchises`, `coaches`, `rivalries`, `raw_uploads`, `game_types` and
`franchise_honors`** for `league_id` 3-9. They exist solely as `legacy_play_log` rows. This was
asserted in an early draft of the `notes` text, removed for lack of evidence, and only written
back once counted (Feature 21 stage 2c, Q.1).

Note `franchise_honors` has **no `league_id`** — it reaches `leagues` through `franchise_id` and
`season_id`. A query written from `new_schema.sql`'s FK list rather than the implemented schema
gets this wrong and aborts (`lessons.md` §11).

### View baseline as at 14 Aug 2026

Captured before the `leagues` change and unchanged through every stage of Feature 21. Recorded
here because the snapshot table that held it has been dropped, and this is a known-good baseline
for the next feature that touches a view:

| View | group rows | total calls |
|---|---:|---:|
| `v_playcall_formation` | 1,367 | 203,530 |
| `v_playcall_formation_all` | 1,368 | 207,986 |
| `v_playcall_matchup` | 2,575 | 203,530 |
| `v_playcall_matchup_all` | 2,577 | 207,986 |
| `v_playcall_matchup_formation` | 15,438 | 203,530 |
| `v_playcall_matchup_formation_all` | 15,510 | 207,986 |
| `v_relevant_offense_current` | 44 | 2,688 |
| `v_relevant_offense_all` | 2,036 | 80,331 |
| `v_relevant_offense_formation_current` | 84 | 2,688 |
| `v_relevant_offense_formation_all` | 3,507 | 80,331 |
| `v_relevant_defense_current` | 32 | 2,935 |
| `v_relevant_defense_all` | 1,295 | 88,907 |
| `v_relevant_defense_formation_current` | 230 | 2,935 |
| `v_relevant_defense_formation_all` | 7,222 | 88,907 |

The `total_calls` figure is identical across the three `v_playcall_*` views and across the three
`_all` ones, as it must be — they differ only in grouping. The `_all` views exceed the
legacy-only ones by exactly 4,456, the `plays` contribution.

### What did not change

No view moved. `v_plays_normalized` reaches `leagues` only through `seasons.league_id`, and none
of the seven new leagues has a `seasons` row — predicted, then verified against a stored
before/after snapshot of all 14 filtered playcall and relevant views, every one unchanged.
`legacy_play_log` was never written to and still reads 247,241 rows.

---

## 16. Changes from A3 — `v_current_standings` (15 Aug 2026)

Two correctness defects in `v_current_standings`, both **latent** — neither was producing wrong
output on the data as it stood. They were demonstrated by simulation rather than observation;
see `findings/latent-defect-simulation.md` for the method.

### What was wrong

Both defects lived in one subquery: `SELECT franchise_id, MAX(week_id) ... GROUP BY franchise_id`.

1. **The latest week was resolved per franchise, not per league.** Each franchise independently
   contributed its own latest row, so a franchise missing a row for the newest week appeared at
   an **older** week alongside everyone else's current figures — one table, mixed weeks, nothing
   on screen to say so. The recorded live instance: the NFLAR 2034 re-parse deleted all 384 of
   that season's `standings_weekly` rows before re-adding them week by week, and until it
   finished the page showed 2033 for NFLAR, correctly by its own logic.
2. **`MAX(week_id)` assumed id order matches chronological order.** `week_id` is assigned by
   `dadabik_resolve_or_create_week()` when a week is first seen, so backfilling an older week
   after a newer one gives the older week a **higher** id.

### What replaced it

A helper view, **`v_current_standings_week`** — one row per league, resolving the current week on
`(seasons.year, weeks.week_number)`, restricted by `EXISTS` to weeks that actually carry
standings rows. `v_current_standings` joins to it on `week_id` **and** `league_id`.

`season_year` and `week_number` are appended to `v_current_standings` (positions 20 and 21, after
`sort_key`) so `current_standings.php` can state which week the rows come from, read off the rows
themselves rather than from a second "what is the newest week" query. Appending leaves every
pre-existing column at its original ordinal position for `SELECT *` consumers, and a DaDaBIK
re-sync **does** act on added fields — unlike the type/nullability changes it cannot see (A1).

### Measured, 15 Aug 2026

- `standings_weekly`: **15,564 rows** — 3,876 NCAA5 + 11,688 NFLAR; 810 distinct weeks;
  36 franchises; 420 `parsed`, 15,144 `derived`. No franchise has zero standings rows.
- **810 of 1,067 weeks carry standings rows.** The other 257 have games but no Standings block,
  which is why the helper view's `EXISTS` clause is load-bearing rather than decorative.
- **0 inversions** between `week_id` order and `(year, week_number)` across all 1,067 weeks
  ✓ verified 15-Aug-2026 · full pairwise scan. Defect 2 was latent, not absent.
- **0 rows** where a standings row's league via `week → season → league` disagrees with its
  league via `franchise → league`, across all 15,564 rows. The view joins on both regardless, so
  a future divergence drops the row rather than filing it under the wrong league.
- Current weeks at that date: NCAA5 **2039 wk 3** (12 rows), NFLAR **2034 wk 16** (24 rows).
- The change is a no-op on this data: 36 rows before, 36 after, 0 added, 0 dropped.

### Consumers ✓ 15-Aug-2026 · `grep -rn "v_current_standings" --include=*.php`

`current_standings.php` (`SELECT *`, filtered on `league_code`, ordered by `sort_key`) and
`home.php` (`fact_longest_win_streak` — no league filter, orders across both leagues). No view
depends on it. Both were unaffected by the change; `home.php` returns the same row before and
after.

⚠ **`home.php` line 82 resolves each league's "Latest Week" with `MAX(w.week_id)`** — the same
chronology assumption as defect 2, on the front page. It groups per league so it cannot mix
weeks, but it can name the wrong one. Out of scope for A3, logged on the tracker.

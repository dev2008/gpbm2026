# B2 task brief — Team Roster (page 1) parser + schema

**rev 001 · 25 Aug 2026 · role: task brief · authority: derived**

Paste this to open the next session. It's self-contained but references project docs the next
session should read first.

## Read first

- `claude/B1-lessons-2026-08-25.md` — what B1 got wrong and right; the schema-design and
  device-bridge-permissions lessons apply directly here.
- `claude/B2-baseball-roadmap-2026-08-25.md` — where this fits in the rest of baseball.
- `schema.md`, `style-guide.md`, `security.md` — standing project conventions.
- Legacy reference (spec, not code to reuse): `bb_rostercompare.php`, `bb_rostercompare.php2`,
  `bb_rosterupdate.php` (all three previously uploaded to this project's session — ask Alan to
  re-attach if not present as project docs). `bb_rosterupdate.php` in particular shows the
  legacy `bb_myteam`/`bb_players`/`bb_transactions` write pattern to replicate against new-schema
  tables — including things to explicitly NOT carry forward: raw string-interpolated SQL for
  `$_cp_turnid`, the ~10 hardcoded legacy name-correction UPDATEs, and the stubbed-out "update"
  branch.

## What's already true (don't re-derive)

- B1's draft pipeline (parser, `bb_draftees`, `bb_draft_picks`, `bb_draftee_scouting`,
  `v_bb_draft_board`) is complete and closed out. MLB6, MLB8, MLB21 drafts are all
  `status = 'complete'`.
- Ratings (`asm_rating`, `gpm_rating`) are stored ×10 (smallint, divide by 10 to display) —
  established convention, follow it for any new rating/value column here unless there's a reason
  not to.
- `mcp__remote-devices__mysql__query` is READ-ONLY. Every DDL/DML deliverable is a self-verifying
  `.rNNN.sql` file with numbered GATEs and a SUMMARY gate, pre-verified live via a
  `WITH ... AS (...)` CTE wrapping the planned logic before being handed to Alan — not just
  reasoned through.
- The device bridge changes file permissions/ownership on push (`device_commit_files`). Any PHP
  file pushed to the live server needs its ownership/mode verified against the project standard
  (`alandev:www-data`, mode `640`) afterward, explicitly — not by eyeballing a sibling file. A
  clean end-to-end run through the pushed file (no `require_once` Permission denied) counts as
  valid confirmation on its own; a separate `ls -la` isn't always needed. `bb_draft_parser.php`'s
  permissions after its last (ratings) push are already confirmed this way — the turn-21 forced
  re-run processed successfully through that file afterward.

## The actual task

Parse the "Team Report" roster section of a baseball turn file (Ab Initio Games MLB format) into
a normalized schema, and build the DaDaBIK page(s) to browse it — the new-schema replacement for
legacy `bb_myteam`/`bb_players`.

Confirmed turn-text shape (see `MLB6ABs35w21.txt`, `MLB6ABs35w20.txt`, `MLB6ABs35w18.txt` —
previously uploaded, ask Alan to re-attach if needed):

- One roster block per team per turn, header row `Sh Name Type Ability Exp Pot Trd Fat Frm Tot
  Inj Value Wages` shared by pitchers and batters, then a type-specific 4-column skill block
  (pitchers: `Acc Con Qui Sta`; batters: `Hit Pow Spd Fld`), then `Sqd` (squad status: `Act`,
  `Res`, `Drf` seen in sample data), then a type-specific extra block (pitchers: `MxB MxH MxE FIn
  MnB`; batters: `Pos vRH Plt Pin`).
- `Ability` combines a letter code (position-relevant, e.g. `ACC`/`CON`/`QUI`/`STA` for pitchers,
  `HIT`/`POW`/`SPD`/`FLD` for batters) with a rating number — this looks like the player's "best
  skill" callout, worth confirming against B1's `draft_value`/`best_skill` handling since it may
  be the same concept post-draft.
- Rookie/drafted players show an `R` in the Exp column and a `*` suffix on Wages (seen for
  drafted-this-season players, e.g. `21 LP*`) with a note in the turn footer: "Draft squad players
  are paid half wages... until week 16" — decide whether this needs its own flag column or can be
  derived from draft data already in `bb_draft_picks`.

## Design questions to settle before writing schema

1. **Natural key**: shirt number alone is NOT stable across turns (numbers get reused as rosters
   turn over) — key roster rows by team + season + week (or turn/upload id) + shirt number, or by
   a resolved player identity if one can be established. Decide which, and whether "player
   identity" needs its own table (a player persists across turns; a roster row is a snapshot).
2. **Upsert vs. append-per-turn**: is Team Roster a full history (one row per team per turn,
   append-only) or a current-state table (upsert, overwritten each turn)? B4 (Roster Comparison,
   next after this) needs turn-vs-turn diffing, which argues for append-per-turn / one row per
   (team, turn, shirt) rather than upsert-only — confirm with Alan before committing to a shape,
   since it's expensive to change later.
3. **Pitcher/batter split**: B1 used `bb_draftees` with nullable type-specific columns rather than
   separate pitcher/batter tables — apply the same pattern here for consistency, or reconsider if
   the extra-block columns don't overlap cleanly enough to share one table comfortably.
4. **Schema location**: this needs its own migration script(s), same `B2_*.rNNN.sql` convention
   as B1's `B1_*.rNNN.sql` files, delivered as new files per revision (never edited in place).

## Deliverables for this task

- Migration SQL creating the new roster table(s) (or view, if built on existing tables), with
  numbered GATEs, live-pre-verified.
- Parser code (extending or alongside `bb_draft_parser.php`, or a new
  `bb_roster_parser.php` — decide based on how much the extraction logic actually shares with the
  draft parser) that reads a turn upload and upserts/inserts Team Roster rows.
- A DaDaBIK page (or table config) to browse Team Roster, following B1's pattern: explicit unique
  field set after install, DB Synchro run if columns were added, single sortable computed column
  if a multi-field sort is needed (check with Alan before assuming one is — B1 needed it because
  of a specific 3-column report layout quirk; Team Roster's default browse order might not need
  the same workaround).
- Confirm live against the database (not just the DaDaBIK on-screen report) that parsing at least
  one full turn (recommend MLB6 week 21, since it's already been read this session and its
  content is known) produces the expected roster rows before calling it done.

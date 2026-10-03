# Gameplan PBM — Live Parser Build Notes

**Purpose:** companion to `schema_design_proposal.md`, which covers schema design and the
historical migration (`f_games`/`fc_franchises`/etc. → `games`/`franchises`/`franchise_honors`/
etc., 9,969 games, and the 253,450-row `legacy_play_log` migration). That document stops once
the schema exists and historical data is loaded. This one picks up from there: building the
**live, staged extraction pages** that turn newly-uploaded turn files into rows in that schema,
plus every real bug, structural surprise, and design decision made along the way. If starting a
fresh conversation, load all three files — this one is meaningless without the schema context
in the first, and open/upcoming work now lives separately in `todo.md`, not here.

---

## 1. Current state — what's built and working

**Ingestion (automatic, via Dadabik after-insert hook on `raw_uploads`):**
- `operational_hooks.php` — populates `original_filename`, computes `content_hash`, identifies
  league/season/week/franchise via a multi-source fallback chain (League Report header for
  week, Team Report for league+season, Draft Report for bye-week fallback), splits the turn
  into `raw_upload_blocks` (one row per `<BK.>` marker; `Turnsheet` excluded as a blank form,
  `Draftsheet` kept as real data).

**Staged, manually-triggered extraction pages (deliberately NOT automated hooks — see §3):**
- `extract_standings.php` — parses the `Standings` sub-block of `League Report` into
  `standings_weekly`.
- `extract_games.php` — parses the game-results portion of `League Report` (every game
  league-wide that week) into `games` + `team_game_stats`.
- `extract_playbyplay.php` — parses the `1st Quarter`–`4th Quarter` blocks (the receiving
  franchise's own game only) into `plays`. **Live-tested and working** as of this writing —
  multiple games across both NFLAR and NCAA5 processed successfully, 1070 total rows, a
  thorough set of invariant-checking queries run directly against the live data all coming
  back clean (see §5 for the full validation detail, and the three real bugs this first live
  testing round found and fixed).

**Supporting schema additions (beyond the original migration):**
- `play_text_patterns` — extensible lookup table for classifying play `result_text` into
  boolean flags (turnover, fumble, penalty offense/defense, sack, hurry, blitz pickup/no-pickup,
  safety, incomplete, touchdown). A real table, not hardcoded PHP, specifically so a newly
  discovered text variant is a row insert, not a code change. 27 patterns seeded, every one
  cross-validated against real, current turn files (see §4 for why that mattered).
- `team_codes` — flat `(code, team_name)` lookup for the 2-4 letter side codes used in play-by-
  play and League Report text (`PE`, `GB`, etc.). Deliberately has no `franchise_id` or
  `league_id` column — see §3 for why.
- `plays` gained several columns after the initial design that weren't in the original
  migration-era schema: `is_fumble`, `is_interception`, `is_penalty`/`is_penalty_offense`/
  `is_penalty_defense`, `is_sack`, `is_hurry`, `is_blitz_pickup`, `is_blitz_no_pickup`,
  `is_safety`, `is_incomplete`, `is_first_down`. All confirmed necessary against real data or
  explicit instruction (see §4). `is_interception` was the last of these added, well after the
  others — see §8 for the full story of that gap.
- `franchises.abbr` was **dropped entirely**, replaced by `team_codes` (see §3).
- `v_playcall_formation_all` / `v_playcall_matchup_all` / `v_playcall_matchup_formation_all` /
  `v_relevant_offense_all` / `v_relevant_offense_formation_all` / `v_relevant_defense_all` /
  `v_relevant_defense_formation_all` — Feature 1 complete, both stages, all seven union views
  live and verified; see §8. The original eight legacy-only views are untouched throughout.
- `extract_games.php`'s `games.label` — fixed and backfilled; was silently using today's
  real-world date instead of the turn's actual season on every live-created game. Found while
  verifying Feature 1 stage 2, unrelated to Feature 1 itself; see §9.

**Front-end pages:** `current_standings.php`, `team.php`, `home.php`, `bowl_records.php` — all
built earlier, standings page recently given a second logo for College (matching Pro's
conference-banner logo, placed after the league selector per explicit correction — see §6 for
why the first attempt was wrong).

**Live database:** fully loaded with historical data per the migration document; `raw_uploads`/
`raw_upload_blocks` populated automatically as new turns arrive; `standings_weekly` and `games`/
`team_game_stats` now have live-parsed rows from real 2034 NFLAR/NCAA5 turns in addition to the
historical migration data. `plays` still empty pending the first successful live test.

---

## 2. Architecture decisions specific to the live-parser phase

- **Staged, manually-triggered pages, not Dadabik hooks.** Each parser (Standings, Games,
  Play-by-Play) is a separate custom page the user runs deliberately, picking the upload from a
  dropdown. Chosen over automatic hook-based parsing for visibility and control while the
  parsers are still being hardened — a bad automatic parse on every upload would be much worse
  than a manual step that can be re-run once a bug is found.
- **Upsert semantics differ by table, and this is deliberate, not inconsistent:**
  - `games`: no-op upsert (`ON DUPLICATE KEY UPDATE game_id=game_id`) — the schema comment says
    `source_upload_id` is "first upload this result was captured from." A game's final score is
    a fixed historical fact once played; re-parsing a later upload of the same week shouldn't
    overwrite it.
  - `team_game_stats`, `standings_weekly`, `plays`: full upsert, every column updated on
    conflict — these are league-wide-per-week or per-play facts that should always reflect the
    latest parse, and re-parsing is expected to be idempotent (same input → same output), not
    something to protect a "first" value from.
- **`play_text_patterns` as a real table, not hardcoded regex/PHP arrays.** One master table
  with a boolean column per flag (not a single category enum), so one pattern row can set
  multiple flags at once (e.g. sacked *and* fumbled). Substring match, longest `pattern_text`
  wins on overlap, OR-ing together every flag from every pattern that matches a given
  `result_text` — a play can accumulate flags from more than one matching row.
- **`team_codes` deliberately has no `franchise_id` or `league_id` column.** Confirmed directly
  (not assumed): codes are globally consistent, not league-scoped — the same code means the
  same team everywhere it's used, across every league. `CI` (Cincinnati Bengals) was checked
  directly against real NFLAR games spanning 2010-2028 and a real NFLC game, and is the same
  code both places. Given that, there's no per-league ambiguity to design around in the first
  place, and no `league_id` column is needed. (An earlier version of this reasoning leaned on
  a "cross-league collision" example — the same code supposedly meaning different teams in
  different leagues — but that example was built on data that later turned out to be simply
  wrong, not a real collision; see §3's note on `AF`. Worth having corrected here rather than
  leaving the disproven version standing.)
  Some codes never have a current franchise to attach to at all, though — confirmed directly:
  `VT` (Virginia Tech Hokies) is a real, historically-used NCAA5 code with no current
  `franchises` row, since the league's own 12-team membership changed at some point (Iowa State
  Cyclones occupies that slot now). `BA` (Baltimore Ravens) and `VI` (Virginia Cavaliers) are
  the same situation for different reasons — both real, both confirmed against actual games,
  neither belonging to a currently-active league.
  A `UNIQUE KEY (code, team_name)` constraint makes future franchise relocations safely
  upsertable (`INSERT ... ON DUPLICATE KEY UPDATE code_id=code_id`) without needing to check for
  an existing row first — confirmed the *common* case for a relocation is landing on a
  combination some other franchise used earlier (24 franchises drawn from a 32-team real-world
  pool), not a genuinely new pairing.
  Also deliberately does NOT try to normalize different eras of the same real-world franchise
  into one canonical name — confirmed necessary via a genuinely messy real case: `WR`
  ("Washington Redskins"), `WT` ("Washington Team"), and the current name (not yet needed under
  any code) are three different eras of the same franchise, each with its own code at the time.
  `team_codes`' job is only "what was this code called when it was used," not "what's the
  current canonical name" — that reconciliation already belongs to `franchises`/
  `franchise_name_history`, and trying to duplicate it here would just create a second,
  competing source of truth for something already handled elsewhere.
- **Historical `legacy_play_log` and live `plays` are staying separate tables**, not being
  merged or backfilled into one. `legacy_play_log` can't cleanly gain real `game_id` links for
  most of its 253,450 rows (most belong to leagues/seasons never fully migrated into `games`).
  Plan for offense/defense matchup views going forward: build new views that UNION both tables,
  rather than retrofitting the existing eight `v_playcall_matchup`-style views (which currently
  only query `legacy_play_log` and won't see anything `plays` picks up going forward) — **not
  yet built**, tracked as an open backlog item in `todo.md`.

---

## 3. Hard-won lessons — worth internalizing before continuing this work

**Governing principle, stated explicitly by the user after the `CB` case below: when the user
asserts something, the default response should be a query to validate that assertion, where
one is possible — not accepting it and moving on.** This isn't about distrust; it's that
confidence and correctness turned out to be independent in this project often enough that the
gap is worth checking by default rather than as an exception. Several of the lessons below are
specific instances of this same rule playing out — `CB` is the clearest one (a direct,
confident correction that turned out to be wrong, precisely because it was never checked the
way the systematically-found errors were), but the "0 start" and `AF` cases below are really
the same principle applied to *this assistant's own* assumptions and inherited data, not just
the user's. The rule cuts both ways and applies regardless of source.

**Reusable testing methodology, proven during the Games build: test every parser against the
full, agreed matrix of week types for both leagues, not just a couple of "normal" samples.**
The specific regime settled on: NCAA5 weeks 0, 1, 11, 12, 13 (pre-season, a regular week, the
*last* regular week specifically — confirmed to have its own oddities, distinct from any other
regular week — playoffs, bowls) and NFLAR weeks 0, 1, 16, 17, 18, 19, 20 (the same shape, plus
the extra playoff/bowl rounds NFLAR has that NCAA5 doesn't). This wasn't a formality — running
`extract_games.php` against all 12 cases directly caught multiple real bugs that a couple of
"looks fine" spot checks never would have: the DOTALL/line-ending issue (one file used plain
spaces where every other sample used real newlines), the header-detection fragility (tag
ordering varied between files), the blank-call-field and hyphen-placeholder merging bugs (both
silently combined two adjacent games into one corrupted match, only visible by checking an
unusual match length), and the "Consolation Gold" mismatch (later found to be more nuanced than
first recorded — see §3 below for the correction). None of these were reachable from NFLAR
week 1 alone, which is exactly why the fuller matrix mattered. Worth explicitly re-running the
same regime — or a league-appropriate equivalent — against any *future* parser built against
this same turn-file
format (the still-backlogged `drives`/Scouting-Report parser is the obvious next candidate),
rather than assuming a couple of successful spot checks are representative. Note also that this
methodology itself was never separately captured in this document until asked about directly —
worth remembering that a valuable, proven process can go undocumented even when its individual
*results* (the bugs it caught) are written up in detail; the process itself deserves its own
explicit record, not just its outputs.

These aren't just bug notes; several represent real, generalizable failure modes that recurred
more than once across this build.

### Legacy/historical data can genuinely diverge from what the current game engine prints —
### always cross-check against real, current turn files, not just the legacy source.
The clearest example: a `play_text_patterns` seed was built from a comprehensive SQL export of
`n_playbyplay.a_text` (every row with `a_peno=1`, confirmed by the user to be authoritative for
what patterns *exist*), which showed `"0 start against offence"` as far more common than
`"false start against offence"` for the same underlying event. Presented as "genuinely what the
game prints" — turned out to be wrong. The user grepped every real, current turn file they had
and found zero instances of `"0 start"`, only `"false start"`. Root cause: the legacy export
reflects however `gplan_main.n_playbyplay` was originally populated, which is a different, less
authoritative process than "the current game engine's own text output" — conflating "came from a
database table" with "therefore reliable, unparsed ground truth" was the actual error. Every
other pattern derived from that same legacy export was then re-validated independently against
real turn files before being trusted (all 19 others held up).

### A falsy-zero bug fix needs tracing through every consumer of the value, not just its source.
`lookup_game_type()` in `extract_games.php` used `return $stmt->fetchColumn() ?: null;` — since
NCAA5's current "Pre Season" game type is literally `game_type_id = 0`, and PHP treats `0` as
falsy, this silently discarded a valid result. Fixed with an explicit `!== false` check. The
*same* bug immediately resurfaced one call site further up the chain: the code consuming
`resolve_game_type_id()`'s return value used `if (!$game_type_id)`, which has the identical
falsy-zero problem — fixing the source function's return value didn't help until the *consumer*
was also fixed. Lesson: search the whole file for every place a value is checked, don't assume
fixing one spot closes the issue.

### Match-count validation can hide "genuinely wrong" matches that happen to be the right count.
Extract Games' offset arithmetic (mapping capture groups to named fields) had two independent
errors — a returns-line group miscounted (8 vs. actual 10) that cascaded into every field after
it. This was invisible to "does the regex match N games" testing, since the regex itself was
matching the correct span of text throughout; the bug was purely in how that match was sliced
apart afterward. Caught only by validating actual field *values* against raw text by hand, not
by trusting a passing match count.

### A field text pattern that's "always present" in every sample you've checked might not be
### universal — optional/blank fields cause regexes to silently over-match into the next record.
Two separate real bugs, same root cause: (1) a team with zero pass attempts has a completely
*blank* Pass field in its "Calls" line (`"Pass      , Def..."`), and (2) a literal `-` character
sometimes appears as a call-code placeholder (`"Fm S -,"`). Both broke a regex that assumed
exactly two word-characters were always present. Because the surrounding pattern used non-greedy
`.*?` segments, the failure didn't just skip the malformed record — it backtracked and matched
the *next* record's Calls line instead, silently merging two games into one corrupted match
(diagnosable by an unusually long match length, roughly double the norm). Fixed by allowing
each call-code slot to be `[\w-]*` (zero-or-more word characters or a hyphen) instead of
requiring `\w+`.

### Correction to the lesson below, found later: the source itself uses two different forms
### for the same name, depending on context — not simply "shorthand vs. literal text."
Originally recorded as a straightforward case of using an abbreviated outline term (`"Cons
Gold"`) as if it were literal source text, when the actual game-result section header reads
`"Consolation Gold"`. That fix was correct, but the earlier framing wasn't the full picture:
directly comparing a Week 12 file (showing the upcoming bowl pairings, before they're played)
against the following Week 13 file (showing the actual results for those same games) confirmed
the game engine genuinely prints **both** forms — the abbreviated `"Cons Gold"`/`"Cons
Silver"`/`"Cons Bronze"` in the schedule-preview section at the end of League Report, and the
full `"Consolation Gold"` etc. in the actual game-result section header the following week.
The original outline wasn't careless or fabricated — it was accurately drawn from the preview
section, just a different context than the one the parser actually needed to match against.
Confirmed directly (not assumed) that this poses no risk to `extract_games.php` as it
currently stands, for two independent reasons: the schedule-preview text always falls after
the last real game match's end position, already excluded by the existing header-detection
boundary regardless of which form appears there, and `KNOWN_HEADERS` no longer contains the
abbreviated form at all following the original fix, so it wouldn't match even if that boundary
were ever removed. Lesson, restated more precisely: a discrepancy between two readings of "the
same" text doesn't always mean one reading was wrong — sometimes the source itself genuinely
varies by context, and that's worth checking directly (a real, matched-pair comparison, not
just re-reading the same excerpt more carefully) before concluding a mistake was made on one
side or the other, on the assumption that only one framing could ever be correct.

### Don't treat your own abbreviated summary of something as if it were the literal source text.
A game-type mapping used `"Cons Gold"` as a header-matching key, taken from an earlier
*shorthand outline* rather than verified against real turn text. The actual game-*result*
section header text is `"Consolation Gold"` (confirmed directly: `"<B>Consolation
Gold<L.45.1>"`). The shorthand had never been checked against a real file before being used as
a literal pattern. (See the correction directly above this entry — the fix itself was right,
but the outline's form turned out to be independently real too, just from a different part of
the source than the one being matched against.)

### Regex `.` doesn't match newlines by default — files can genuinely differ in whether they use
### real line breaks or plain spaces between logical segments.
One real turn file had League Report content using plain spaces where every previously-tested
file used `\r\n`. A pattern relying on `.*?\n` to skip between stat lines silently failed to
match *any* games in that file (0 of 6) until switched to DOTALL mode, where `.*?` doesn't care
which separator a given file actually used.

### Down/distance progression is not always a valid cross-check for yardage — first downs reset
### the distance-to-go independent of exact yardage gained beyond the minimum needed.
When validating the play-by-play yardage-extraction rule (see §5) against consecutive plays'
down/distance, one compound-play case appeared to fail (predicted 15 yards gained, but distance
went 3rd-and-6 → 1st-and-10, an apparent -4). Turned out the discrepancy was in the *validation
method*, not the extraction rule: a first down resets distance to 10 regardless of yardage
beyond what was needed, breaking a same-drive distance-delta check. Field-position delta (not
affected by first-down resets) confirmed the extraction rule was correct all along. General
lesson: prefer field-position delta over down/distance delta for yardage cross-checks, and don't
conclude a rule is wrong before checking whether the *validation method itself* has blind spots.

### `field_position`'s direction was initially assumed backwards.
Assumed (without confirming) that higher `field_position` meant closer to the *offense's own*
goal (yards already traveled). It's the opposite: `field_position` is yards *remaining* to the
opponent's goal line (1-99), so a gain *decreases* it. Caught via the same yardage cross-
validation work above — a compound play's predicted +15-yard gain matched a field-position
*decrease* of exactly 15, not an increase.

### A zero-row check only catches a code that was never used — it can't catch one that's real
### but mislabeled, which is a genuinely more dangerous failure mode.
While resolving `team_codes`' remaining unknowns, `SELECT COUNT(*) FROM n_playbyplay WHERE
a_off='JJ'` (and later `'AT'`, `'CP'`) came back at zero, confirming those codes from the
original `franchises.abbr` data were simply never used — safe to drop. `'AF'` looked the same
kind of case at first, but came back with 4426 real rows — under the old, inherited label
"Carolina Panthers." Checking the actual games it appeared in showed it's really Atlanta
Falcons. A zero-row check would never have caught this on its own, since the code itself is
genuinely real and heavily used; only cross-referencing the specific games it appeared in
exposed the mislabeling. Worth remembering this means `franchises.abbr` may have carried other
silent mislabelings beyond the ones already found — a present, non-zero row count is necessary
but not sufficient evidence that a code's *name* is correct, only that the code itself is real.

### Resolving an unknown code by joining directly against `f_games` only works if a `game_id`-
### style link actually exists for that data — some codes belong to leagues that never got one.
An early attempt to resolve remaining unknown codes joined `n_playbyplay` to `f_games` on
league/season/week and returned nothing for `'BA'`, even with no other join conditions
narrowing the result. Root cause: `BA` belongs entirely to `NFLBC`, one of the 8 inactive
leagues whose raw play-by-play text was migrated into `n_playbyplay` without ever getting
matching game-level rows in `f_games` (consistent with the original migration notes: "most
leagues have no leagues/franchises/games rows"). No join against `f_games` was ever going to
resolve it, regardless of how it was constructed — confirmed directly by finding a real turn
file for that league (`NFLBC-PS Turn 14, "Pittsburgh Steelers vs Baltimore Ravens"`), not by
refining the query further. When a cross-reference against migrated data returns nothing at
all rather than an ambiguous result, worth checking whether the target table has any coverage
for that data's source *at all* before assuming the join logic itself is wrong.

### "Complete" against one source column doesn't mean complete against the full picture —
### a sparse column can hide codes that only ever appear in the columns that are always filled.
`team_codes` was declared fully resolved (75 codes, zero `TBD`s) based on covering every
distinct value from `n_playbyplay.a_poss` — but `a_poss` is the *sparse* possession column,
populated only when possession is shown explicitly. `legacy_play_log`'s `offense_team_code`/
`defense_team_code` columns are always populated, by contrast, and a direct coverage check
against all three of `legacy_play_log`'s code columns (not just the one `team_codes` was
originally built from) turned up two more real, heavily-used codes `a_poss` alone had never
surfaced: `CI` (2323 rows) and `US` (304 rows). Both resolved the same way as everything else
— `US` is USC Trojans (NCAA6), `CI` is Cincinnati Bengals — confirmed directly to be the same
code across both NFLC and real NFLAR games (2010-2028), not a separate NFLAR-specific code as
first assumed (see the next lesson below for what that original assumption actually was).
Lesson: "confirmed against every value in column X" is a claim about column X
specifically, not about the underlying concept in general — worth checking whether a more
complete, always-populated source exists before calling something fully resolved.

### A resolution the user provided directly still needs the same validation as one found by
### systematic checking — being confident isn't the same as having actually checked.
`CB` was used early on to resolve a genuine duplicate-name collision (`CB`/`CH` both showing
"Cincinnati Bengals"/"Chicago Bears" ambiguously) via direct correction, and from that point
on was treated as settled — unlike `JJ`/`AT`/`CP`, which were caught by the same systematic
`SELECT COUNT(*) FROM n_playbyplay WHERE a_off=...` check applied across the board. `CB` never
actually got that check, precisely because it had already been "resolved." When it finally was
checked, it came back with zero rows — the same signature as the three already-known unused
codes, meaning it was never real either. Cincinnati Bengals' actual code was `CI` all along
(confirmed directly against real NFLAR games across 2010-2028, not assumed), which was already
sitting in the table under an *apparently* different, NFLC-specific meaning at the time —
itself a second, compounding error: assuming two same-name entries under different codes must
mean two different situations (one per league), rather than checking whether they were simply
the same underlying fact recorded twice. Lesson: a code's provenance (systematically checked
vs. directly asserted vs. inherited from old data) doesn't change how much validation it
actually needs — every code deserves the same zero-row check regardless of how it entered the
table, and a plausible-sounding explanation for why two entries differ (like "these must be
league-specific") is itself worth checking before being written down as settled fact.

### A value can be extracted correctly and still never reach storage — parsing-logic
### validation alone can't catch a field the schema never had a column for.
`formation` was computed correctly by the parser the entire time the play-by-play work was
underway — including the `'X'` kickoff-synthesis rule — but `plays` never actually had a
`formation` column, and the computed value was silently dropped every time, all the way
through to the first live test. Every earlier validation pass (see §5) checked whether
*extraction* was correct against real text, never whether every extracted field actually had
somewhere to go in the final output — a gap invisible to Python-replica testing against raw
files, only found by looking at real rows in the actual database. Lesson: validating that a
parser extracts the right values is a different claim from validating that those values
survive all the way to storage — the second needs checking against the live schema and real
output specifically, not just the parsing logic in isolation.

### `NULL` should mean "unknown," not "the value is zero" — and the two get confused easily
### when a value is genuinely absent from the source text rather than present-but-unparsed.
`yards_gained` came back `NULL` for incomplete passes and for the `"no gain"` phrasing, in both
cases because no "gain/loss of N yards" phrase existed in the text for the extraction regex to
find anything to parse — but the real answer in both cases is unambiguously known (0), not
missing. The `"no gain"` case was also structurally different enough (`"for no gain"`, with no
`"of"`/`"yards"` at all) that it needed its own regex branch, not just a value-substitution
fix — and a compound play combining an `"at no gain"` position marker with a later `"for gain
of N"` increment was silently dropping the first segment entirely before the fix, arriving at
the correct total only by coincidence in the one case checked (0+3 and just-3 both equal 3).
Lesson: absence of a match in extraction isn't automatically "unknown" — worth checking
whether the specific play type has a definite, known value regardless of whether the text
states a number explicitly, and treating a coincidentally-correct compound-play result as
confirmation that the underlying logic is right is exactly the trap the "0 start"/`AF` lessons
already warned about, just recurring in a new form.

### A hook only ever runs once, at insert time — data ingested before a hook gets a piece of
### logic (or before a bug in that logic is fixed) has no way to pick that fix up retroactively.
All 6 NCAA5 uploads had `franchise_id = NULL`, silently excluding every one of them from Extract
Play-by-Play's dropdown. The hook's franchise-resolution logic (step 3b) was present and
structurally sound at the time this was investigated — not a live, currently-active bug — the
real cause was that these specific uploads were ingested before that step existed in the hook
(or before a fix to it), and a hook has no mechanism to reach back and reprocess records that
already exist. Fixed with a one-off backfill page reusing the hook's exact logic, rather than
waiting to notice this again the next time a hook gains new resolution logic. Worth remembering
as a general category: any time hook logic changes, existing records may need an explicit,
one-off backfill pass — the hook firing correctly from that point forward doesn't retroactively
fix anything already sitting in the table.

### The same "computed but never stored" failure shape recurred a second time, in a place the
### first occurrence's own fix should have made easier to catch, not harder.
Found while scoping Feature 1 (union views for `legacy_play_log` + `plays`): `plays` had no
`is_interception` column at all, only a combined `is_turnover` plus a separate `is_fumble` —
even though `play_text_patterns.sets_interception` had existed the whole time, and
`apply_play_text_patterns()` in `extract_playbyplay.php` was already computing it correctly
into the `$flags` array on every single call. The value simply never got read back out of that
array into the upsert — the exact same shape of bug as the missing `formation` column above,
right down to being invisible to parsing-logic validation for the identical reason (extraction
was correct; storage was the gap). Worth being honest about the recurrence rather than treating
it as a one-off: the first occurrence's fix added the missing `formation` column and, per its
own lesson, should have prompted a check of every other computed-value-to-storage path at the
time — it didn't, and this is what slipped through. Fixed properly rather than worked around:
added `plays.is_interception`, backfilled the 1070 pre-existing rows by joining
`play_text_patterns` the same way the parser itself does (substring match on `sets_interception
= 1` rows), patched both places `extract_playbyplay.php` needed it (the upsert's column list /
`VALUES` / `ON DUPLICATE KEY UPDATE`, and the bound-params array feeding it), and updated
`new_schema.sql` to match. See §8 for the full build and live verification. A cheap general
check worth running any time a new `is_*`/`sets_*` pattern flag is added in the future: grep
the parser for every place `$flags[...]` is read, not just where it's written — the write side
working doesn't confirm the read side does too.

### An unverified assumption pattern-matched against nearby context and got stated as fact —
### the correction came from the user re-checking, not from re-deriving it independently.
While walking through Feature 1 stage 2's live verification, a claim was made that "the live
turns you've been parsing are from season 2032" — stated with full confidence, based purely on
the project workspace's sample turn files happening to be named `s2032`. No query was ever run
to check what season the actual live `plays` rows belonged to; the sample filenames were the
only "evidence," and they aren't the same thing as the real uploads that produced the real
data. The user's own query (joining `plays` to `games.label`) surfaced "2026" instead, which
didn't match that guess either — and turned out to be a third, unrelated thing entirely: a real
bug in `extract_games.php`'s label construction (see §9), while the turns' actual seasons were
2034/2038/2039, confirmed only once someone actually checked. The governing principle already
established in this document — when someone asserts something, the default is a query to
validate it, not acceptance — applies exactly as much to Claude's own inferences drawn from
surrounding context as it does to the user's assertions or Claude's own prior work. This is the
same category of mistake as the "0 start"/`AF` cases earlier in this section, just committed by
a different party within this exact conversation. Worth remembering: pattern-matching to the
closest available reference material sitting in view (here, sample files in the project
workspace) is not the same thing as checking the actual data, even when the match feels obvious.

---

## 4. Confirmed text-parsing rules (play-by-play specific)

All of the following were confirmed against real turn files, explicit user instruction, or both
— not assumed. See `play_text_patterns_seed.sql` and `extract_playbyplay.php` for the
implementations.

- **Turnover/fumble classification** (explicit rules, not inferred from possession changes):
  - `"intercepted"` anywhere in the text → always a turnover. `yards_gained` is always `0` on
    an interception, regardless of any yardage number in the text (that number describes how
    far the pass traveled, not an offensive gain).
  - Fumble, but **not** a turnover: `"fumbled and recovered"`, `"fumble recovered by offence"`,
    `"...yards and recovered"` (the fumbled-snap case — only the "recovered" form was ever
    confirmed to exist for this specific phrasing).
  - Fumble **and** a turnover: `"fumbled and not recovered"`, `"fumble recovered by defence"`.
  - Every fumble (recovered or not) still sets `is_fumble` independently of `is_turnover` —
    these are deliberately separate columns, restored after being found missing from the new
    schema despite `legacy_play_log` already having had `is_fumble` (a real gap that had been
    silently dropped when this table was designed separately from the migration schema).
- **Penalty classification**: `is_penalty_offense`/`is_penalty_defense` are genuinely separate,
  not derivable from one generic flag — confirmed via the original legacy source columns
  `a_peno`/`a_pend`, which were two distinct columns. `pattern_text` includes the full
  `"...against offence"`/`"...against defence"` suffix, not just the penalty-type name, since
  several types (holding, pass interference) occur on both sides and the bare type name alone
  would collide against the pattern table's unique constraint. Only combinations actually
  observed in real data were seeded — real asymmetry exists (personal foul/offside only ever
  appeared on defense; delay of game/false start/illegal procedure/illegal shift/ineligible
  player downfield only ever appeared on offense in the samples checked).
- **`yards_gained` extraction**: `"AT gain/loss of N yards"` is a cumulative position marker —
  the *last* such mention in a play's text wins, overriding any earlier one. A trailing
  `"FOR gain/loss of N yards"` is an *additional* increment on top of the last AT position, not
  a replacement. Validated two independent ways against real plays: down/distance progression
  (with the first-down caveat above) and field-position delta on the following play (only valid
  when the same team retains possession).
- **`is_first_down`**: computed directly (`yards_gained >= yards_to_go` on the same play row),
  not text-pattern-matched — deliberately has no `sets_first_down` counterpart in
  `play_text_patterns`. Left false for goal-line plays (`yards_to_go` is `NULL` there —
  `"1st & Goal"` has no explicit number — and "first down" doesn't apply the same way when the
  object of the play is reaching the endzone, which `is_touchdown` already captures).
- **Structural special cases in the quarter blocks** (all confirmed against real examples):
  - **Overtime** lives *within* the `4th Quarter` block, under a `<B>Overtime<C>` heading, not
    its own `<BK.>` marker. Plays after that heading get `quarter=5`; the cumulative game clock
    keeps counting past `60:00` rather than resetting.
  - **Blank `side` column** = same possession as the previous play, **except** the very first
    play after any non-play marker line (two-minute warning, quarter-end stat summary), which
    always has an explicit side. Detected structurally, not by enumerating every possible
    marker type: a real play row always starts with a digit (the time); a marker/summary line
    never does.
  - **Kickoff/onsides-kick rows** print no formation letter or field position at all —
    formation is synthesized as `'X'` per explicit instruction (matching the legacy migration's
    own convention for the same situation). The "side" column ambiguity this creates (a blank
    side directly followed by `"KO"`/`"ON"` looks structurally identical to "side is KO/ON") is
    resolved with a negative lookahead excluding those two literal strings from ever matching as
    a side code.
  - **Multi-line scoring plays**: the first physical line ends in its own `<L>`; a continuation
    line (no time/side, just further prose) carries the rest of the description plus the
    `<T>score<C>` suffix. Merged into one play by only accepting an `<L>` as a genuine play
    boundary when it is *not* immediately followed by a lowercase-starting continuation line.
  - **QB benching/replacement announcements** (`"<Z>[Player] benched, and replaced by [Player]
    <C>"`) consume that row's time+side entirely — the real play that follows has no time value
    of its own. Confirmed directly: borrow the time+side from the announcement line and attach
    it to the following line's real play data (field position, down/distance, formation,
    off/def calls, result); the announcement text itself is discarded. Implemented as a
    pre-processing text substitution before the main play regex runs, not folded into that
    regex directly.
  - **`"quarterback flop"` rows** (a clock-killing knee-down at the end of a half/game) have
    field position and down/distance but no formation/off/def columns at all — confirmed these
    should be dropped entirely, not treated as a real play with (necessarily garbage) stats.
  - Confirmed by the user as the complete set of "varying degrees of weird" in this block —
    flops, benchings, touchdowns, safeties — after these two were found. No further special
    cases known to exist as of this writing, though the same caution from §3 (legacy data can
    diverge from current output) applies to any future additions.

---

## 5. Extract Play-by-Play — validation status in detail

Everything below was validated against real files before being trusted; see §3 for why that
discipline matters here specifically.

**Fully validated, parsing-logic level (Python replica of the PHP, tested against real files):**
- Core play-row regex: 143/143 correct on a full real NFLAR game (`NFLAR-PE_s2032_w01_vs_
  Packers.txt`), correctly excluding the one genuine `quarterback flop` line in that file.
- Overtime detection: exact 41/13 regular-quarter/overtime split on a real OT game.
- QB-replacement time-borrowing merge: all three real plays in the test case correctly
  recovered, including the merged one with the borrowed time+side.
- Multi-line touchdown handling: field position, down/distance, cleaned result text,
  `score_after`, and `yards_gained` all independently confirmed correct.
- Yardage extraction rule and the interception-always-0 special case (see §4).
- Kickoff formation synthesis to `'X'`.
- `score_after` extraction and `result_text` cleanup (stripping `<Z>`/`<T>...<C>`/embedded
  `<L>` formatting debris while preserving the actual prose).

**Live database interaction — now fully tested and confirmed working, after three real bugs
were found and fixed during the process (all detailed below and in §3):**
- `team_codes` lookup resolution, `play_text_patterns` loading/matching, `game_id` resolution,
  and the upsert itself all confirmed working correctly against real uploads.
- Multiple games processed successfully across both NFLAR and NCAA5 (once the NCAA5-specific
  `franchise_id` gap below was fixed), reaching 1070 total rows in `plays`.
- A thorough set of invariant-checking queries run directly against the live data, all
  confirming clean: `is_first_down=1` never paired with `yards_gained < yards_to_go` (the
  computed comparison holds with zero exceptions); `is_touchdown`/`is_fumble`/`is_sack`/
  `is_hurry`/`is_blitz_pickup`/`is_blitz_no_pickup`/`is_safety` never set without the
  corresponding keyword actually present in `result_text`; and `is_turnover=1` never occurring
  without either `is_fumble=1` or an interception mentioned in the text (the more precise
  version of this last check — `is_turnover=1 AND is_fumble=0 AND result_text NOT LIKE
  '%inter%'` — is the one that actually matters, since turnovers legitimately come from two
  independent sources; a plain `NOT LIKE '%inter%'` check alone will show real, expected rows
  for fumble-turnovers and shouldn't be read as a failure).

**Three real bugs found during this first live testing round, all now fixed:**
1. **`formation` was computed correctly but never stored anywhere at all** — not a parsing
   bug, a genuine schema gap: `plays` never had a `formation` column in the first place,
   despite the parser extracting it (including the `'X'` kickoff synthesis) since the
   play-by-play work began. The value was computed, then silently dropped every single time,
   invisible to all the earlier parsing-logic validation because that validation only checked
   whether extraction was correct, never whether every extracted field had somewhere to go in
   the final output. Column added; parser fixed to actually include it in the returned array
   and the upsert (see §3 for the general lesson this represents).
2. **`yards_gained` was `NULL` for plays where it should have been the known value `0`** —
   confirmed for incomplete passes (no "gain/loss of N yards" phrase appears in text like
   `"pass thrown away, incomplete"`, but the real answer is unambiguously 0, not unknown) and
   separately for `"no gain"` phrasing (`"HB run for no gain"`), which uses no `"of"`/`"yards"`
   at all and so was never matched by the extraction regex, returning `NULL` outright. Worse,
   a compound play combining `"at no gain"` with a later `"for gain of N"` mention silently
   dropped the first segment entirely, only arriving at the correct final total by coincidence
   in the one example checked before the fix — a genuinely different, more dangerous failure
   than the simple missing-value case, since it could have produced a *wrong* non-null number
   for some other compound play, not just a missing one. See §3 for the general NULL-vs-0
   lesson this represents.
3. **All 6 NCAA5 uploads had `franchise_id = NULL`**, which is why none of them appeared in
   the dropdown at all (it requires `franchise_id IS NOT NULL`). The ingestion hook's own
   franchise-resolution logic (step 3b) is present and structurally sound — not a live bug in
   current code — but hooks only ever fire once, at insert time, so any upload ingested before
   that step existed (or before a later fix to it) is left permanently stuck with no way to
   retroactively trigger the hook again. Fixed with a one-off backfill page
   (`backfill_franchise_id.php`) that reuses the hook's exact same regex/lookup logic against
   any upload still missing `franchise_id`, rather than reimplementing it slightly differently.

**Confirmed since: both sides of the same game report byte-for-byte identical play-by-play
text.** Directly verified against a real matched pair — one coach's own `.txt` turn and the
other participant's `.eml` turn, same game (NFLAR, season 2031, week 11, Eagles vs Vikings).
All four quarter blocks matched exactly, character for character, between both files. This
confirms the multi-coach dedup mechanism works as designed for exactly the reason assumed: the
dropdown-exclusion query keys off `game_id` (via `games`, not `upload_id`/`franchise_id`
directly), so once either participant's turn has been processed for a given game, the other
participant's turn for that same game simply never appears in the dropdown — and even if it
somehow did get processed anyway, `plays`' `UNIQUE KEY (game_id, quarter, play_seq)` would
upsert cleanly rather than duplicate, since both sides' texts produce identical play sequences.

**Also confirmed in the same investigation: `.eml` files are handled correctly by the existing
pipeline with no changes needed.** A real `.eml` turn report (forwarded email, full SMTP
routing/authentication headers, `multipart/mixed` MIME structure) was checked directly. Two
things make it work already: the actual turn content is a `text/plain; charset="us-ascii"` part
with `Content-Transfer-Encoding: 7bit` — meaning no base64/quoted-printable decoding is needed,
it's genuinely plain ASCII text sitting behind MIME boundary markers and a large block of
routing headers. And critically, the file already contains the `<STARTREP>` marker the
ingestion hook already trims at — everything before it (however much header/routing noise
precedes it) gets stripped the same way regardless of whether that's ordinary Gmail chrome or a
full raw SMTP header block. Confirmed directly: trimming at `<STARTREP>` on the real `.eml` file
produces output identical in shape to a normal `.txt` upload, with all expected `<BK.>` blocks
present.

**A fourth real bug, found later — not part of the first live testing round above, surfaced
instead while scoping Feature 1's union views:** `plays` was missing `is_interception`
entirely, the same "computed but never stored" shape as bug 1 (`formation`) above. Full detail,
fix, and live verification in §3 and §8 — noted here separately, and dated after the fact, to
keep this section's own timeline honest rather than folding it into the "three bugs" list above
as if it had been caught in that same round.

---

## 6. Other recent fixes worth remembering

- **`current_standings.php`'s second College logo**: first attempt placed it next to the main
  league logo, ignoring an explicit instruction that it should sit *after the league selector*
  (matching where Pro's conference-banner logo naturally appears, later in the page). Corrected
  after direct pushback — a reminder to actually re-check placement instructions against what
  gets built, not just against general visual judgment.
- **`extract_standings.php`'s pre-season detection** (`LIKE '%Week%Schedule%'`) was originally
  unanchored to position within the block, and turned out to match *every* week's Standings
  block, not just genuine pre-season ones — because every turn's Standings block ends with
  *next* week's schedule, not just pre-season turns specifically (a correction to an earlier,
  wrong assumption that only pre-season turns show a schedule at all). Fixed by checking only
  the first ~300 characters of the block, since the real distinguishing signal is *position*
  (a genuine pre-season block has the schedule heading immediately after the header, ~113
  characters in; a normal week has it only after a full table, 2000+ characters in), not mere
  presence of the phrase.
- **Every turn shows that same week's own result and standings, plus *next* week's schedule** —
  not a "previous game" reported one turn late, which was an earlier, incorrect framing that
  needed correcting.

---

## 7. Live output file inventory (this phase)

All in `/mnt/user-data/outputs/` as of this writing:

- `extract_standings.php`, `extract_games.php`, `extract_playbyplay.php` — the three staged
  parser pages.
- `play_text_patterns_seed.sql` — schema + 27 seeded, cross-validated text patterns.
- `team_codes_seed.sql` — schema + 76 fully-resolved codes (22 NFLAR, 12 NCAA5 — complete —,
  and 42 historical/other, covering both currently-active franchises and confirmed-real
  entries with no current franchise at all). No `TBD` placeholders remaining as of this
  writing — every code from the original 70-code legacy list has been either resolved or
  confirmed never actually used and dropped, AND separately verified complete against all
  three of `legacy_play_log`'s code columns (not just the sparse one the original list came
  from — see §3, this check is what surfaced `CI`/`US`). `CB` (originally used to resolve a
  duplicate-name collision, later found to have zero real rows) was removed after the fact —
  see §3 for why it slipped past the same validation the other unused codes got. See §3 also
  for the two distinct failure modes (unused-and-safe-to-drop vs. real-but-mislabeled) caught
  while resolving these.
- `plays_missing_flags.sql` — live-DB `ALTER TABLE` statements for the seven `is_*` columns
  found missing from `plays` partway through this phase.
- `add_formation_column.sql` — live-DB `ALTER TABLE` statement for the `formation` column
  found missing entirely (see §3) during the first live test of `extract_playbyplay.php`.
- `backfill_franchise_id.php` — one-off admin page, reuses the ingestion hook's exact
  franchise-resolution logic against any upload still missing `franchise_id` (see §3 for why
  this can happen — a hook only ever runs once, at insert time).
- `new_schema.sql` — kept in sync with every live-DB change made this phase (should always
  match the actual database state exactly; treat any divergence as a bug to fix, not a
  reason to trust the live DB over this file or vice versa without checking). Updated again
  in the Feature 1 phase to add `is_interception` — see below and §8.
- `current_standings.php`, `team.php`, `home.php`, `bowl_records.php`, `operational_hooks.php`
  — carried over from earlier phases, `current_standings.php` updated this phase (§6).

**Feature 1 (stage 1) additions, added later than the rest of this inventory — see §8:**
- `add_is_interception_column.sql` — live-DB `ALTER TABLE` adding `plays.is_interception`,
  plus the backfill for the 1070 pre-existing rows and its verification queries.
- `union_views_playcall.sql` — the three `v_playcall_*_all` union views plus their shared
  `v_plays_normalized` helper view, and verification/test queries.
- `extract_playbyplay.php` — updated in place (not a new file) to also write
  `is_interception` going forward; the version in this inventory's first entry above is now
  stale.

**Feature 1 (stage 2) additions — see §8:**
- `check_relevant_teams_franchise_mapping.sql` — diagnostic confirming all three tracked
  teams (PE/MV/PI) round-trip cleanly between `legacy_relevant_teams.team_code` and current
  `franchise_id` before the no-schema-change join was used.
- `union_views_relevant.sql` — extends `v_plays_normalized` (additive), adds the
  `v_relevant_teams_franchise` helper, the four `v_relevant_*_all` union views, and
  verification/test queries.

**`extract_games.php` `games.label` bug fix — see §9:**
- `check_live_play_season_resolution.sql` — first diagnostic, ruled out the "plays matched
  onto pre-existing historical games" hypothesis.
- `check_games_label_bug.sql` — confirmed the bug was cosmetic-only (48/48 rows agreed between
  the FK-derived season and `raw_uploads`' independently-resolved one).
- `extract_games.php` — updated in place to resolve the label's season from `seasons.year`
  instead of `date('Y')`; the version in this inventory's first entry above is now stale.
- `backfill_games_label.sql` — corrects the label on all 48 already-affected games.

---

## 8. Feature 1 — union views for `legacy_play_log` + `plays` (both stages)

### Stage 1: the three `v_playcall_*` views

**Scope:** the three `v_playcall_*`-style aggregate views only (`v_playcall_formation`,
`v_playcall_matchup`, `v_playcall_matchup_formation`). The four `v_relevant_*` views and their
`v_relevant_current_season` helper are a deliberately separate stage 2, covered below.

**Design decisions, confirmed with the user before building:**
- **Special-teams filtering on the `plays` side uses formation only**
  (`formation NOT IN ('P','X','F')`) — `plays` has no `play_category` column at all, unlike
  `legacy_play_log`. The formation encoding, including the synthesized `'X'` for kickoffs, was
  directly confirmed to match between the two tables by reading real turn files, not assumed.
- **Interception filtering required a real parser fix, not a proxy.** The obvious shortcuts —
  filtering on the broader `is_turnover`, or on `is_turnover=1 AND is_fumble=0` as an
  interception stand-in — were both rejected once it became clear `is_interception` should
  simply exist and didn't, purely because of a storage gap (see §3, §5). The proper fix was
  small enough (one column, one backfill, a two-line parser patch) that there was no good
  reason to ship an approximation instead.
- **`sport_type` for `plays` rows is resolved via `game_id → games.week_id → weeks.season_id →
  seasons.league_id → leagues.sport_type`**, not via the nullable `plays.offense_franchise_id`
  — every column in that join chain is `NOT NULL`, so this path can't produce a silent NULL
  `sport_type` the way the franchise-based path could.
- **New views use an `_all` suffix** (`v_playcall_formation_all`, etc.). The original three
  legacy-only views are untouched, per the explicit requirement that they keep querying
  `legacy_play_log` exclusively.
- **A shared helper view, `v_plays_normalized`**, carries the join chain above plus
  legacy-style column aliases (`formation` → `formation_code`, `off_call` →
  `offense_call_code`, `def_call` → `defense_call_code`) so the three target views don't each
  repeat the same four-table join — the same composition pattern already used elsewhere
  (`v_current_coach` → `v_current_standings`; `v_relevant_current_season` → the four
  `v_relevant_*` views).
- **Raw rows are unioned before aggregating, not two pre-aggregated `avg_yards` values
  averaged against each other** — the latter would be an unweighted mean of means, wrong as
  soon as `plays` carries any real weight of its own.

**The `is_interception` gap (see §3 for the general lesson):** scoping this feature surfaced
that `plays` had no `is_interception` column — only a combined `is_turnover` plus a separate
`is_fumble`, with no way to isolate "interception" the way `legacy_play_log` does. Root cause:
`play_text_patterns.sets_interception` had always existed and `extract_playbyplay.php` was
already computing it correctly on every call, but the computed value was never read back out
into the upsert — the same failure shape as the earlier missing `formation` column. Fixed
properly: `add_is_interception_column.sql` adds the column and backfills the 1070 existing
rows via the same pattern-table join the parser itself uses, then cross-checks the result two
independent ways (against the literal `"intercepted"` substring rule, and against the
already-validated `is_turnover=1 AND is_fumble=0` invariant) — both checks came back clean,
zero mismatches. `extract_playbyplay.php` was patched at both places that needed it (the
upsert's column list / `VALUES` / `ON DUPLICATE KEY UPDATE`, and the bound-params array), so
every future extraction now writes `is_interception` correctly. `new_schema.sql` updated to
match.

**Live verification results (run in this order — column fix, then views):**
1. `add_is_interception_column.sql`'s two cross-checks both returned all zeros — the backfill
   and the parser fix agree with each other and with the independently-confirmed text rule.
2. `v_playcall_formation_all` vs. the original `v_playcall_formation`, same `formation_code =
   'W' AND offense_call_code = 'CW'` example from the task brief: `pro` unchanged at 52/4.17
   (no live pro data matches that key yet — expected, still early data), `college` moved from
   1035/5.07 (legacy-only) to 1048/5.01 (combined) — 13 real plays from `plays` correctly
   folded into the aggregate, with `avg_yards` recalculated across the full unioned set rather
   than averaged against the old figure.
3. 842 of the 1070 rows in `plays` survive the filters in `v_plays_normalized` — the right
   ballpark once special teams, penalties, interceptions, and fumbles are all excluded, same
   as `legacy_play_log`.
4. `sport_type` resolves for every single row in `v_plays_normalized` — 155 pro + 915 college
   = 1070, exactly matching the known total, confirming the join chain never produces a silent
   NULL bucket.
5. Grain check on all three `_all` views (`GROUP BY` the view's own key, `HAVING COUNT(*) > 1`)
   — all three came back empty, confirming one row per matchup as required.

### Stage 2: the four `v_relevant_*` views

**Scope:** `v_relevant_defense_current`, `v_relevant_defense_formation_current`,
`v_relevant_offense_current`, `v_relevant_offense_formation_current`, and their
`v_relevant_current_season` helper — all four left completely untouched, per explicit decision
below, despite a real defect found in them.

**A genuine design defect found in the existing views before any building started.** The user
supplied the original legacy `gplan_main` structure — `n_s_pe_off`/`n_s_pe_def` (+ `_f`
formation variants), and the same for `pi`/`mv` (owner codes for the three tracked
owner/franchise pairs: PE = Philadelphia Eagles, PI = Pittsburgh Panthers, MV = Minnesota
Vikings). Every one of those tables carries `season` as a plain data column across full
multi-season history, with no restriction at the table level at all — filtering by year was
always an end-user, UI-level concern. The current `v_relevant_current_season` helper instead
computes `MAX(season)` per team and every `_current` view hard-joins on equality against it —
meaning these views can only ever surface each team's single most recent season, with no way to
reach any other one. Confirmed directly by reading the actual view SQL, not assumed. This is a
real regression relative to the original design, not a simplification.

**Decision: leave the old views and their defect alone; build stage 2 correctly from scratch.**
The "untouched eight" requirement was originally about not retrofitting the union onto them,
but touching them to fix this separate, real defect was raised as an option anyway — the user's
call was to leave them exactly as they are and get stage 2 right instead, rather than change
behavior on views that may already be depended on elsewhere. New views expose `season` as a
plain, unrestricted, filterable column, matching the legacy `n_s_*` design exactly — no
"current" restriction, and no need for an equivalent to `v_relevant_current_season` at all,
since relevance is about which *teams* are tracked, not which *season*.

**The `plays`-side relevance-matching problem, and how it was resolved.** `legacy_relevant_teams`
identifies relevant teams by text `team_code` (matching `legacy_play_log` natively); `plays`
identifies teams by `franchise_id`, a completely different identity system, with no direct link
between the two. The only bridge available is `franchises.label → team_codes.team_name → code`
— a text match on the franchise's *current* name, which is fragile in general (the project's own
`team_codes` build already surfaced a real case, the Washington Redskins/Washington Team
franchise, where the current name had no code seeded for it at all — a silent, zero-match
failure were it to be hit here). Rather than assume this fragility didn't apply to the three
specific teams actually in play, it was checked directly: a round-trip diagnostic
(`check_relevant_teams_franchise_mapping.sql`) confirmed all three relevant teams
(`PE`→Philadelphia Eagles/franchise_id 2015, `MV`→Minnesota Vikings/franchise_id 2019,
`PI`→Pittsburgh Panthers/franchise_id 5008) resolve cleanly 1:1 in both directions, no `NULL`s,
no fan-out. Given that clean result, the no-schema-change join was used rather than adding a
`legacy_relevant_teams.franchise_id` column — the schema-change option remains the right call if
a future relevant team's current name ever lacks a `team_codes` entry, and is worth re-checking
any time a new row is added to `legacy_relevant_teams`.

**Build:** `v_plays_normalized` (stage 1's helper) extended additively with `league_code`,
`season`, `offense_franchise_id`, and a derived `defense_franchise_id` (whichever of
`games.home_franchise_id`/`away_franchise_id` isn't the offense side, explicitly `NULL` rather
than guessed when `offense_franchise_id` itself is `NULL`) — purely additive, so stage 1's three
views needed no re-verification. A new small helper, `v_relevant_teams_franchise`, resolves each
`legacy_relevant_teams` row to its currently-resolvable `franchise_id` via the validated join
above. The four `_all` views union `legacy_play_log` (filtered via `legacy_relevant_teams`
directly, no season restriction) with `v_plays_normalized` (filtered via
`v_relevant_teams_franchise`), grouped by `league_code, team_code, season[, formation_code],
play_call` — one row per key, `season` now a real dimension instead of a single forced value.

**Live verification results, and a real, concrete illustration of the defect this stage fixed:**
1. `season_count` per team in the new `v_relevant_offense_all`: PI 33 distinct seasons
   (2000–2039), MV 31 (2004–2034), PE 30 (2005–2034) — the actual multi-season history now
   genuinely reachable, versus one row per team through the old views.
2. Regression check: the legacy-only portion matches the old `_current` views exactly, row for
   row, for every `play_call` on the one season the old views can reach (spot-checked on
   MV/2034 across all 85 rows returned).
3. `v_relevant_teams_franchise` returns exactly the same 3 rows as the earlier standalone
   diagnostic — franchise resolution is stable.
4. 477 offense rows and 593 defense rows in `plays` belong to one of the three tracked teams —
   real data is flowing through the plays-side join, not silently filtered to zero.
5. Grain check clean on all four views.

Point 4 combined with point 2 initially looked like a contradiction worth chasing — real
matching plays exist, yet the regression check showed no difference from the old view. It
wasn't a contradiction: the live turns actually parsed so far are from NFLAR/NCAA5 seasons
2034/2038/2039, none of which is the single season (2032, at the time) the old `_current` view
happened to expose for those teams. All ~1070 rows of live play data were sitting in seasons the
old view could never reach at all — concrete, current evidence the season restriction wasn't a
theoretical problem, it was actively hiding real data the moment this stage's diagnostic ran.
(Chasing down exactly *which* season those live rows belonged to also surfaced an unrelated bug
in a different, already-"completed" parser — see §9, and §3 for the mistaken assumption that
led there.)

---

## 9. `extract_games.php` bug: `games.label` used today's date, not the turn's season

Found while verifying Feature 1 stage 2 — a real, separate bug in a different, already-
"completed" parser, unrelated to the union views themselves. See §3 for the mistaken assumption
that kicked off the investigation (a claim that the live turns were from season 2032, based on
nothing more than the project workspace's sample filenames, never actually checked).

**Symptom:** every game created via live extraction carried a label like `"NFLAR 2026 Wk 15:
..."`, regardless of what season the turn was actually for — all 48 affected games showed 2026,
the same across turns whose filenames (and, it turned out, correctly-resolved `season_id`)
said 2034, 2038, and 2039.

**First hypothesis, checked and ruled out:** that live plays had been mismatched onto
pre-existing historical games left over from the original migration (i.e. a `week_id`
resolution bug landing on the wrong, already-populated week). Ruled out directly:
`games.source_upload_id` matched `plays.source_upload_id` exactly for every affected game —
these were genuinely new games, created fresh by this same live-extraction round, with the
wrong label baked in from the start, not old ones being reused.

**Root cause**, found by reading `extract_games.php` directly rather than continuing to guess:
```php
$label = "{$_cp_league_code} " . date('Y') . " Wk {$_cp_week_number}: {$game['home_team']} vs {$game['away_team']}";
```
`date('Y')` returns today's real-world wall-clock year — not the turn's actual in-league
season, which was already sitting resolved and correct in `$_cp_upload['season_id']` the whole
time, just never read for the label.

**Confirmed cosmetic-only before fixing or backfilling anything** — the same discipline as
everywhere else in this project: don't assume a bug's blast radius, check it. A query
cross-referenced `games.week_id → weeks → seasons` (what the union views and everything else
downstream actually reads) against `raw_uploads`' independently-resolved season (set by
`operational_hooks.php`, before `extract_games.php` ever ran) for all 48 affected games. All 48
rows agreed exactly with each other — 2038/2039/2034, matching the turn filenames — while every
one disagreed with the label's "2026." Conclusive: the actual `week_id` FK, and therefore every
one of Feature 1's union views (both stages), was correct throughout. Only the display text was
ever wrong — Feature 1's live verification results above stand as reported, no correction
needed there.

**Fixed:** `extract_games.php` now resolves `season_year` from `seasons.year` via the upload's
own `season_id` (same pattern already used for `league_code`/`week_number` two lines above it in
the same file), with a `season_id` `NULL` guard added alongside the existing `league_id`/
`week_id` check. `backfill_games_label.sql` corrects the label on all 48 already-affected games
by reconstructing it from the same FK chain (`games.week_id → weeks → seasons → leagues`, plus
`franchises.label` for the two team names), restricted to `source_upload_id IS NOT NULL` so
migration-era games are left untouched. Also worth noting for next time this kind of bug shows
up: `extract_games.php`'s own upload dropdown excludes any `week_id` that already has `games`
rows, so simply re-running the parser against these same uploads would never have picked these
48 rows up again — the backfill was the only way to correct them, the same "a hook/parser only
ever runs once" shape as the NCAA5 `franchise_id` gap in §5.

---

> **CORRECTION (Aug 2026) — this fix did not fully land, and this section recorded it as though
> it had.** Verified against the live file at
> `/var/www/gpbm/public_html/dadabik/include/custom_php_files/extract_games.php`:
>
> ```
> 61:  l.code AS league_code, s.year AS season_year, w.week_number
> 84:  ? "{$u['league_code']} {$u['season_year']} Wk {$u['week_number']}"
> 262: $label = "{$_cp_league_code} " . date('Y') . " Wk {$_cp_week_number}: ..."
> ```
>
> The `season_year` work reached the **upload dropdown** (lines 61 and 84) but not the label
> line, and the `season_id` guard was never added either. So every game this page created
> between that entry being written and August 2026 still carried the calendar year.
>
> Now genuinely fixed: `season_id` added to the guard at line 103, `$_cp_season_year` resolved
> alongside `league_code`/`week_number`, and line 262 rebuilt from it. The confirmation echo
> also shows the season now, so the value is visible before any label is written.
>
> **The lesson underneath this is worse than the bug.** A fix was described here in detail,
> in the right file, with the right reasoning — and one of the two edits was never made.
> Nothing checked. Writing "fixed" in `lessons.md` is not evidence that anything was fixed;
> only the file is. Any entry claiming a code fix should name the file, the line, and what a
> passing check looks like — see §21.

---

## 10. Deleting an uploaded game: `games.source_upload_id` is many-to-one, not one-to-one

**One upload's `games` rows are one-to-many, not one-to-one — same shape as `standings_weekly`.**
A turn's Results/League Report block reports on every game played league-wide that week, not
just the uploader's own — confirmed directly while testing a full delete-and-reupload cycle: a
single NFLAR upload (`upload_id 12`) produced 12 `games` rows, one per game across all 24
franchises that week, not one. `games.source_upload_id` is genuinely many-to-one: many game rows
share one upload's ID as their "first upload this result was captured from" source, exactly per
the schema comment already on that column (§2) — just not previously written down as a
many-per-upload fact, only as a "which upload wins on conflict" fact.

**Consequence: deleting an uploaded game means deleting by `source_upload_id`, not by a single
`game_id`.** Targeting only the one `game_id` of interest leaves the other ~11 games from that
same upload still referencing it. Since `games.source_upload_id → raw_uploads.upload_id` has no
`ON DELETE CASCADE` (confirmed directly against `new_schema.sql`), attempting to delete the
`raw_uploads` row afterward fails with a foreign key error (`#1451`) until every game from that
upload is gone, not just the first one attempted — found directly, live, mid-test.

**Correct procedure, in order:**
```sql
-- 1. Find every game this upload touched (there will likely be more than one)
SELECT game_id, label, week_id FROM games WHERE source_upload_id = {upload_id};

-- 2. Safety check -- franchise_honors.game_id also has no cascade
SELECT * FROM franchise_honors
WHERE game_id IN (SELECT game_id FROM games WHERE source_upload_id = {upload_id});

-- 3. Delete all of them -- cascades to team_game_stats/plays/drives automatically
DELETE FROM games WHERE source_upload_id = {upload_id};

-- 4. That week's standings will regenerate identically on re-processing, safe to
--    wipe entirely rather than trying to selectively unlink
DELETE FROM standings_weekly WHERE week_id = {week_id};

-- 5. Now safe -- cascades to raw_upload_blocks automatically
DELETE FROM raw_uploads WHERE upload_id = {upload_id};
```

**Cascade map worth having alongside this, confirmed directly against `new_schema.sql`'s actual
constraints rather than assumed:** `team_game_stats`, `plays`, `drives` → `games`, and
`raw_upload_blocks` → `raw_uploads`, all cascade automatically. `games.source_upload_id`,
`standings_weekly.source_upload_id`, and `franchise_honors.game_id` do not — those are the ones
that block a delete if handled out of order.

---

## 11. Schema-check discipline: DESCRIBE the whole table before adding to it

**Two redundant columns added this same session, both from the identical mistake.**
`franchises.coach_user_id` (`coaches.id_user` already existed for exactly that purpose) and
`raw_uploads.id_user` (`uploaded_by` already existed, already configured as DaDaBIK field type
`ID_user`, already working). Both times, a new column was designed by reasoning about the
feature being built, without first checking the table's actual, complete definition. The first
case at least checked `schema.md`'s narrative summary — just not `new_schema.sql`'s own DDL,
where the real answer was sitting in a comment. The second case checked neither, only whatever
custom PHP code happened to already be visible.

**Standing habit going forward: run a full `DESCRIBE` (or view the complete `CREATE TABLE`) on
any table before adding a column to it — every time, not just when something feels like it
might already exist.** Checking only the specific thing in mind, rather than the table's actual
complete definition, is what let both of these slip through undetected until they were live and
populated.

**One live operational detail surfaced while investigating the second case, confirmed by an
actual live insert, not just theorized:** DaDaBIK's `ID_user` field-type auto-population only
populates one field when a table has two configured at once — `raw_uploads` briefly had both
`uploaded_by` (pre-existing) and `id_user` (freshly configured) set to type `ID_user`
simultaneously, and a real upload inserted during that window came back with `uploaded_by =
'AlanM'`, `id_user = NULL`. `uploaded_by` sits earlier in the column order; `id_user`, added
later via `ALTER TABLE`, sits at position 16 (last) — column position as the tie-break is a
clean fit for this result, though confirmed from one instance, not proven as a hard rule for
every case.

---

## 12. Trust hierarchy: `new_schema.sql`'s inline DDL comments over `schema.md`'s prose

Related to §11's habit (run `DESCRIBE` before adding a column) but a distinct point — this one
is about which *document* to trust when they disagree, not just whether to check the live
schema at all.

**Confirmed twice, not once — same root cause both times.** Both `coach_user_id`/
`coaches.id_user` and `raw_uploads.id_user`/`uploaded_by` (§11) trace back to the same failure
shape: a real, already-considered design decision existed, recorded as an inline comment in
`new_schema.sql`'s own DDL, but never carried forward into `schema.md`'s narrative summary. In
the first case, `schema.md`'s prose *was* checked before building the redundant column — it
just didn't contain the answer, because the answer was only ever written in `new_schema.sql`'s
comment, not repeated here. In the second case, neither document was checked, only whatever
custom PHP happened to already be visible.

**Standing rule: for a question about a genuine past design decision — not just "does this
column exist," which §11 already covers — grep `new_schema.sql` directly, don't rely on
`schema.md`'s summary of it.** Not a claim that `schema.md` is unreliable in general — a claim
that it isn't a substitute for the source it summarizes, specifically for *reasoning*, which can
survive perfectly well in a DDL comment while never making it into the prose written from it.

---

## 13. A zero-row collision check proves nothing about a *mislabelled* code

`team_codes.code` is deliberately not unique, so the standing check has been "does one team name
ever map to two franchises in a season?" It returns zero rows, and that was taken as proof the
codes were sound.

It isn't. That check proves no code is **reused**. It says nothing about a code that is real,
heavily used, and carrying the **wrong name**. Four were, found during the
`legacy_play_log.game_id` backfill:

| Code | Held | Actually | Rows | Agreement |
|---|---|---|---:|---:|
| `WC` | Dallas Cowboys | Washington Commanders | 4,510 | 0.0% |
| `TN` | Tennessee Volunteers | Tennessee Titans | 1,419 | 0.0% |
| `OA` | Las Vegas Raiders | Oakland Raiders | 887 | 0.0% |
| `GE` | Georgia Tech Yellow Jackets | Georgia Bulldogs | 746 | 0.0% |

This is the **third** appearance of this failure shape, after `AF`/"Carolina Panthers" (§3).
Correcting the four raised rows resolving to a real fixture from 188,560 to 203,617. (Both
figures pre-date the NFLAR 2034 re-parse, which later removed 3,281 rows from the table — see
`findings-legacy_playbyplay_backfill.md` §8.)

Each was confirmed from three independent directions before being changed — resolved from the
defence side, resolved from the offence side (which does not use the mapping under suspicion),
and tested at franchise level via `f_games`. `TN` was additionally proved from a turn file:
`NFLAR-PE_s2021_w12_vs_Titans.txt` carries the code and the name in the same document.

**The check that catches it**, and that should run after any `team_codes` change: for every code,
compare the name `team_codes` holds against the name `f_games` recorded for that team **in that
week**. Anything below 100% agreement wants investigating.

Two codes were also added: `LV` (Las Vegas Raiders) and `TV` (Tennessee Volunteers), the latter
freeing `TN` for its real meaning. See §14 for why a rename gets a new code rather than a second
name on an existing one.

---

## 14. Franchise identity is week-scoped — read the model before writing any team-name query

A franchise is a **slot** in a league, not a team. The real-life identity attached to it changes
as coaches join and leave, sometimes **mid-season** — nine NFLAR franchise-seasons carry two
identities. Franchise 2014 alone has been Dallas, New York, and Washington at various points,
switching at 2014 wk 13, 2021 wk 11 and 2024 wk 5.

Full model: **`schema.md`, "Franchises, team identities and team codes."** Read it before writing
anything that maps a team name or code to a franchise. The three operational consequences:

**Join at week grain.** `(league, season, week, team name)` is unique; `(league, season, team
name)` is **not**. Resolving at season grain returns a real franchise, just not the right one —
silently.

**`franchises.label` is a snapshot, not history.** It is a `STORED GENERATED` column
(`city` + `nickname`) holding the slot's *current* identity. Franchise 2014 reads "Dallas
Cowboys" across its entire 1996–2034 history, including 394 fixtures played as Washington
Commanders. Never use it to describe a historical game, season or record — use `f_games.team`,
which is recorded at week grain.

**`code → name` is guaranteed; `name → code` is not.** A code identifies exactly one identity,
always. But one team can have several codes (`GB`/`GP`, `NS`/`NO`, `TN`/`TT`), of which typically
one is in use. The unused ones are superseded entries, not errors — do not "clean" them up. A
code appearing in no data is also normal: it means no coach has ever uploaded a game involving
that team, which is upload coverage, not league history.

**Live defect this caused:** `games.label` was built from `franchises.label`, so **3,487 NFLAR
and 516 NCAA5 games** name an identity the franchise was not playing under that week. Affects
`game.php`, `team.php`, `coach.php`. It is a rebuild from `f_games.team`, not a patch — see
`task-games_label_rebuild.md`.

---

## 15. In-league season is NOT a calendar year, and cannot be calculated from a date

A turn is processed every **two real weeks** — occasionally three, around Christmas and Easter.
NFLAR runs 21 turns per season (42 real weeks), NCAA5 runs 14 (28). So in-league years advance
**ahead** of calendar years, at roughly 1.24 seasons/year for NFLAR and 1.86 for NCAA5.

Confirmed from turn-file headers, which carry both:

| League | In-league season | Real date |
|---|---|---|
| NFLAR | 2005 | 8 Jan 2003 |
| NCAA5 | 2000 | 27 Aug 2003 |
| NFLAR | 2024 | 3 Feb 2018 |
| NFLAR | 2033 | 20 Sep 2025 |
| NCAA5 | 2038 | 18 Dec 2025 |

Because the holiday breaks are irregular, **the mapping is approximate, not a formula**. A real
date cannot be converted to an in-league season by arithmetic in either direction. The season can
only ever be **read** — from `games.week_id → weeks → seasons.year`, or from
`raw_uploads.season_id`, both of which are always correct.

This is the root of §9's bug, and the reason a "smarter" fix deriving the season from the upload
date using the cadence would also have been wrong — just less visibly, and drifting further with
every break.

---

## 16. Two eras of data granularity — the sentinel rows ARE the record

**Play-by-email began around calendar 2003–2004.** Before that the league ran without per-turn
electronic records:

| Era | What exists | Grain |
|---|---|---|
| Pre-email — NFLAR in-league 1989–2003 | Season records only, in the **sentinel rows** (weeks 95/98/99) | Season |
| Email era — NFLAR 2004+, all of NCAA5 | Per-week fixture rows, plus play-by-play | Week |

The sentinel weeks are **not** a rollup artefact to filter out. For NFLAR's first fifteen
in-league seasons they are the only record there is. Filtering `week NOT IN (95,98,99)` as
"cleanup" silently discards the entire pre-email history.

**NCAA5 has no pre-email era** — it was created at the transition (its first season, in-league
2000, is calendar August 2003), so it has per-week coverage from the very beginning.

Corroborated independently: `legacy_play_log` starts at exactly **NFLAR 2004** and **NCAA5
2000** — the first email-era season of each league. That was never designed as a coverage
boundary; it falls out of when each league started being played this way.

**Consequence:** identity, results and play data are week-exact only in the email era. A pre-2004
mid-season change cannot be recovered — not because an extract lost it, but because it was never
written down. Derived data should record which era it came from (see
`franchise_identities.derived_from`).

---

## 17. Count the matchup-week, not the week

A week can legitimately hold several fixtures when more than one coached team played. Aggregating
per week conflates *"two coached teams both played"* with *"one game recorded twice"* — and that
confusion cost three rounds of increasingly elaborate analysis during the `game_id` backfill
before anyone simply asked which matchups a week contained.

The unit for any play-by-play sanity check is the **matchup-week**:
`(league, season, week, LEAST(off,def), GREATEST(off,def))`.

At that unit, **97.8% of the archive's 1,670 fixtures fall in 120–180 plays**, which makes
anomalies obvious rather than ambiguous.

---

## 18. Row counts can't distinguish more coverage from wrong coverage

Two resolver strategies were compared for the `game_id` backfill:

| Strategy | Rows resolved | Games in the normal 120–180 band |
|---|---:|---:|
| offence side only | 198,714 | **88.3%** |
| both sides pinned | 188,560 | **97.8%** |

The offence-only join resolved ~10,000 **more** rows and looked strictly better. It was wrong: it
manufactured **117 half-filled junk games**, because a mislabelled code on the offence side sends
only that team's offensive snaps to the wrong franchise's fixture. `WC` alone accounted for 1,012
rows that the stricter join rejected outright.

**A count going up is not evidence of correctness. Check the shape, not the total.** This is §3's
"match-count validation can hide genuinely wrong matches" arriving from the opposite direction —
and it was nearly missed a second time, because the higher number was briefly argued for.

---

## 19. Multi-table `UPDATE` does not error on fan-out — stage it

If a row joins to several candidates, MySQL/MariaDB applies one **arbitrarily** and reports
success. On a 253,450-row table that failure is invisible afterwards.

**Pattern for any resolver-driven backfill:**

1. Resolve into a staging table with **no unique key** on the target row id, so ambiguity is
   countable rather than silently collapsed
2. Verify: `GROUP BY row_id HAVING COUNT(DISTINCT target_id) > 1` must return zero
3. `UPDATE` by joining off the staging table, restricted to `HAVING COUNT(*) = 1`
4. Confirm the written count equals the staged count **exactly**

Used for `legacy_play_log.game_id`; the discrepancy came back 0 and the orphan count 0. The
staging tables were kept until the documentation was written, so the result stayed re-derivable.

---

## 20. Ingest artefacts: check the archive matches the season it claims

`legacy_play_log` held **2,100 rows labelled NFLAR 2024 that are actually NFLAR 2025 weeks
4–18** — imported in the right sequence position but stamped with the wrong season, then
re-imported correctly later without the bad rows being removed. NFLAR 2025 holds a complete
correct copy, so they are redundant as well as wrong.

Nine of the fourteen weeks fail to resolve on their own. **Five coincidentally matched 2024's
real fixture** and would have received wrong `game_id`s, passing every structural check —
because both team codes genuinely matched a real fixture, just not the right one. The
`team_codes` fix in §13 made this *worse*, not better: correcting `OA` made week 6 newly match.

**Detector:** a matchup-week over ~180 rows where `COUNT(*) / COUNT(DISTINCT result_text)` is
near **1.0**. A ratio near 2.0 or 3.0 is duplicate copies of the same game (benign — the
`game_id` is still right). A ratio near 1.0 with inflated volume is *different games merged*.
Re-run after any bulk import.

**This detector works on `legacy_play_log` ONLY — see §23.** `legacy_play_log.result_text`
stores the whole line including the time prefix, so it is near-unique per play. `plays`
stores the cleaned tail, which repeats naturally, and the same ratio there means nothing.
The equivalent check on `plays` is `COUNT(*)` versus `COUNT(DISTINCT play_seq)`.

---

## 21. A found bug gets fixed or logged — never just mentioned

**Working practice, adopted Aug 2026.** Noticing a defect and moving on is how §9 stayed broken
for months while being documented as fixed.

- Fix it now if it is a blocker
- Otherwise write a task doc, and link it from `todo.md`
- Never leave it as a remark in a conversation or a comment in passing

"Blocker" is deliberately narrower than "small and in scope", which this originally said. Small
and in scope invites judgement, and judgement drifts: the Aug 2026 session set out to backfill
one column and ended up re-parsing a season, correcting four team codes and rewriting three
parser pages. Each step was individually defensible; the aggregate was several features' work in
one sitting, which is exactly what one-chat-one-feature is meant to prevent.

The test is whether the current feature can finish without it. The NFLAR 2034 re-parse genuinely
blocked the label rebuild — `f_games` was missing five weeks the rebuild needed. The extract-page
dropdown ordering did not block anything; it should have been a task doc.

And when recording a code fix here: **name the file, the line, and what a passing check looks
like.** §9 described its fix in detail and half of it was never applied. This document is not
evidence that anything was fixed; only the file is.

Bugs found this session, and where each went:

| Found | Disposition |
|---|---|
| `extract_games.php` still used `date('Y')` for the label | Fixed |
| `extract_games.php` guard omitted `season_id` | Fixed |
| `operational_hooks.php` refreshed only `league_id` in `$upload` after identification | Fixed |
| Four `team_codes` names wrong | Fixed |
| `games.label` names present-day identities | `todo.md` Feature 12 |
| 2,100 mislabelled rows in `legacy_play_log` | `todo.md` Feature 17 |
| 13 duplicated fixtures | `todo.md` Feature 15 |
| `franchise_season_records` has no writer | `todo.md` Feature 13 |

---

## 22. Know what a delete cascades to — and what it doesn't

Extending §10. When removing a season's data, FK cascade covers less than it looks like:

| Table | Keyed on | Cascades from a `games` delete? |
|---|---|---|
| `team_game_stats` | `game_id` | **Yes** |
| `plays`, `drives` | `game_id` | **Yes** |
| `legacy_play_log` | `game_id`, **no FK** | **No** — orphans silently (deliberate: an FK would let one game deletion destroy archive rows) |
| `standings_weekly` | **`week_id`** | **No** — survives entirely |
| `franchise_season_records` | `season_id` | **No** |

`standings_weekly` surviving is the trap: `extract_standings.php` excludes any upload whose week
already has standings rows, so leaving those rows in place after deleting a season's games means
**no upload for that season will ever appear in the standings list again**. Delete both.

**`franchise_season_records` is worse — nothing writes it at all.** It is migration-populated,
read by `team.php` for season records and "most wins in a season", and was already unmaintained
for the parser-created NFLAR 2034 weeks 15–16. Any re-parse leaves it stale with nothing to
regenerate it. Needs its own task doc.

Related: derived tables built from `gplan_main.f_games` cannot be pure one-off extracts, because
`f_games` is frozen while the league keeps playing. It stops at roughly NFLAR 2034 wk 9 /
NCAA5 2038 wk 11. Anything derived from it needs a maintenance story — for
`franchise_identities`, that should be `extract_games.php` writing an identity row per
franchise-week from the turn file's own team names, which it already parses for the label.

---

## 23. A validation metric is only meaningful given what the column actually stores

`COUNT(*) / COUNT(DISTINCT result_text)` was a good duplicate detector on `legacy_play_log` and
was reused unchanged on `plays` during the NFLAR 2034 re-parse verification. On `plays` it is
meaningless, and it produced ratios of 1.26–1.61 across every game that briefly read as
widespread duplication.

The two columns hold different things:

| Column | Contents | Distinctness |
|---|---|---|
| `legacy_play_log.result_text` | The whole line — `"0:22  PE  56  1st and 10   S RO ND    HB run for gain of 5 yards"` | Near-unique per play, so ratio 2.00 really is duplication |
| `plays.result_text` | The cleaned tail only — `"pass out of bounds, incomplete"` | Repeats naturally within one game, so a ratio above 1.0 is normal |

Nothing was wrong with the data or the parser. The metric was wrong for the table.

**The check that does work on `plays`:** `play_seq` is assigned 1..N as the parser walks the
block, so
```sql
GROUP BY game_id HAVING COUNT(*) <> COUNT(DISTINCT play_seq)
```
returns nothing when no game has been ingested twice. Direct, not inferential.

**The general lesson:** when carrying a check from one table to another, confirm the columns
mean the same thing first. A metric that transfers *syntactically* can still be nonsense
*semantically*, and it fails loudly enough to look like a real defect — which wastes exactly
the attention a real defect would need.

**Correction, Aug 2026 (Feature 15): the claim above that `legacy_play_log.result_text` holds
the whole line is true for 245,812 rows and false for 286. See §25.** The exception is exactly
where the detector produces a false positive, and it nearly cost a complete game.

---

## 24. A `tmp_` prefix on anything that outlives its session is a latent bug

`tmp_fgames_map` and `tmp_lpl_resolution` were both created as scratch during the
`legacy_play_log.game_id` backfill. When the tidy-up came, dropping both looked obviously
correct — same prefix, same session, same purpose.

Only one was actually spent. `tmp_lpl_resolution` had done its job and is regenerable from the
resolver query. `tmp_fgames_map` is the **only copy of f_games' fixture data inside
gplan_pbm**, and `label_L1_identities.sql` needs it to build `franchise_identities`. Dropping it
would have meant another cross-database read of a frozen table the whole redesign is trying to
stop depending on.

Renamed to **`migration_fgames_map`**, with a table comment carrying the three things a reader
needs before trusting it: that it is a one-off frozen extract, where f_games stops, that it is
not served to the app, and what still depends on it.

**The lesson:** a name is a claim about lifetime, and `tmp_` claims "safe to delete." The moment
something with that prefix survives the session that created it, the name is lying, and the next
person to tidy up will act on the name rather than checking. Rename it when its lifetime
changes, not when someone nearly deletes it.

**Related:** when a scratch table is promoted, say so in `new_schema.sql` too. A table that
exists only in a running database and in one script's assumptions is invisible to anyone reading
the schema — the same "known only to the person who built it" shape as §12's trust hierarchy.

**And say whether it is permanent.** `migration_fgames_map` is interim: once
`franchise_identities` exists it carries the identity data at week grain, which is what actually
gets used, and the only thing the map holds beyond that is the fixture pairing. Its comment says
to review it at that point rather than silently promoting it to permanent schema.


---

## 25. `legacy_play_log.result_text` has TWO storage formats, and §23's detector is unsafe on one

Found during Feature 15, by dumping one game in full rather than trusting a ratio.

| Format | Example | Rows | Where |
|---|---|---:|---|
| **Clock-prefixed** (the one §23 describes) | `0:22  PE  56  1st and 10   S RO ND    HB run for gain of 5 yards` | 245,812 | everywhere else |
| **Cleaned tail** (what `plays.result_text` holds) | `QB hurried, pass thrown away, incomplete` | **286** | `play_log_id` 997907–998192 |

The cleaned-tail rows are one contiguous ingest run covering two games: a redundant third copy
of game 9602 (145 rows) and the whole of game 9621 (141 rows). Both NFLAR 2033, adjacent weeks,
high ids — a late ingest done with a different tool.

**Why it matters.** §23's duplicate detector, `COUNT(*) / COUNT(DISTINCT result_text)`, depends
on the clock prefix making each line near-unique. On cleaned-tail rows the prose repeats
naturally — `"QB hurried, pass thrown away, incomplete"` appears ten times in one game — so
game 9621 scored 141 rows against 98 distinct texts and read as a duplicate at ratio 1.44. It
is not. Its clock advances monotonically 0→3583, it repeats no `(time, down, distance, offense)`
tuple, and it is a complete 141-play game.

**The rule that would have deleted 43 legitimate plays passed its own simulation**, because the
simulation was built on the same assumption as the rule. What caught it was reading the rows.

**Any detector touching `result_text` must be format-aware:**
```sql
result_text REGEXP '^[0-9]+:[0-9][0-9]'   -- clock-prefixed; text identity means duplication
```
The cleaned-tail rows also carry at least one merged play (`play_log_id` 998192 holds two plays'
text in one row), so they are lower fidelity as well as differently shaped.

---

## 26. The checks need checking — three defects in my own verification, one feature

None of these was a data problem. All three were defects in the things meant to *detect* data
problems, which is worse: a broken gate is trusted by definition.

| Gate | Defect | Symptom |
|---|---|---|
| `U9` | Restated stage F's `V5` from memory and dropped its `result_text REGEXP` filter | **FAIL: 38** on correct data — it was counting NULL-text games and cleaned-tail repeats |
| `K2` | `GROUP BY … HAVING COUNT(*) <> 2` emits no row when everything matches | **Vanished from the output entirely.** A passing gate and a gate that never ran look identical |
| `W9` | Hardcoded post-delete league counts worked out by hand | Two of nine were wrong (133,414 for 134,141; 66,506 for 67,297). Would have failed on correct data |

Three distinct rules:

- **When restating a check in a new context, diff it against the original.** Do not retype it
  from memory. `U9` and `V5` differed by one `AND` and reached opposite conclusions.
- **A gate's PASS must never depend on a row existing.** Wrap the aggregate so the label is
  always emitted: `SELECT 'K2 …', CASE WHEN (SELECT COUNT(*) FROM (…) x) = 0 THEN 'PASS' …`.
  Otherwise silence is ambiguous between success and the statement never running.
- **Derive expected values from the data, never from arithmetic in your head.** A verification
  step whose expectation comes from the same reasoning it is meant to check inherits that
  reasoning's errors. `W9` was rewritten to snapshot per-league counts before the delete and
  reconcile to *snapshot minus backup*, so no constant is supplied at all. Constants belong only
  where they are genuinely external — e.g. a total traced from an earlier stage's printed output.

**Related, and version-specific:** `ROWS` became a **reserved word** in MariaDB 10.6 (window
frames, `FETCH … ROWS`). `AS rows` aborted a diagnostic batch mid-run. It was legal in older
MariaDB, so it survives in copied-forward scripts and fails on upgrade. Also worth knowing:
`mysql` stops at the first error, so `--force` is right for read-only diagnostic batches and
wrong for anything that writes.

---

## 27. A filter inherited from a task doc is still an assumption

`task-15`'s own detection query joined `games`, so it could only ever see rows with a
`game_id`. Every detector in Feature 15's first four stages carried `WHERE game_id IS NOT NULL`
because I inherited that framing without questioning it.

**That excluded 50,592 rows — 46,680 of them in seven inactive leagues.** And the
`v_playcall_*_all` views apply **no league filter and no `game_id` filter**, so every one of
those rows feeds the aggregates. Six NFLA matchup-weeks held 877 duplicate rows that were
inflating `times_called` while the feature reported itself complete.

It surfaced only because Alan asked whether the data flowed through to the views.

**The rule:** a task doc's query encodes the scope its author assumed. Before adopting it, ask
what population it *cannot* see, and check that population is genuinely out of scope rather
than merely invisible. The cheap version: run a `COUNT(*)` with and without the filter, and if
the difference is large, justify it explicitly.

**Where there is no `game_id`, the unit is the matchup-week** (§17):
`(league_code, season, week, LEAST(off,def), GREATEST(off,def))`.

---

## 28. Two independent rules agreeing beats one rule passing its own gate

Feature 15 removed duplicates two ways, and the second population allowed both to run:

| Rule | Signal | Deletes |
|---|---|---:|
| **Island** — decompose into runs of consecutive `play_log_id`, keep the best block | id adjacency | 877 |
| **Occurrence** — keep the first instance of each identical line | text identity | 877 |

Agreement was exact: 877 in both, zero in either alone. Those signals share no assumption, so
concurrence is real evidence — unlike §25's simulation, which agreed with its rule because it
was built from it.

**Keep-rule priority, in order** (each clause earned by a real case):
1. **Most non-NULL `result_text` rows.** Games 310/311 hold an all-NULL copy the *same size* as
   the good one, sitting *first* in id order — size and position both pick the wrong copy.
2. **Most rows.** Game 9602's cleaned-tail copy has 145 against its siblings' 154, having merged
   plays together.
3. **Lowest `min(play_log_id)`.** Arbitrary tie-break between byte-identical copies.

---

## 29. Overtime is absent from the entire legacy archive — and always was

`legacy_play_log` holds **no row above `time_gone_seconds` 3599** in 247,241 rows, while 15
NFLAR games carry `games.went_to_ot = 1`. Established four ways, after the first attempt rested
on a single negative observation and was rightly pushed back on:

1. **The source has none either.** `gplan_main.n_playbyplay.a_minutes` runs 0–59 across all
   253,450 rows. The migration was row-complete (253,450 → 253,450), so this was lost at the
   **original parse**, not in the migration to `gplan_pbm`.
2. **The live parser captures it.** `plays` holds 55 rows at `quarter = 5`, `time_gone_seconds`
   3600–4498, from the same source format.
3. **The format carries it.** §4 records that overtime sits inside the `4th Quarter` block under
   a `<B>Overtime<C>` heading with the clock continuing past 60:00; a real turn file's drive
   summary shows `60:00`, `63:02`, `65:36`.
4. **The clock-reset alternative is refuted.** OT stored with a reset clock would make time run
   backwards mid-game; across the whole archive that occurred exactly once, and it was a
   duplicate-row artefact.

The minute distribution corroborates it: two-minute-drill spikes at 28/29 and 58/59 against a
~3,937 median, then a hard stop. A complete regulation game with the OT period never captured —
most likely a parser that stopped at the heading, or an `mm:ss` pattern rejecting minutes above
59. Not a storage limit: `time_gone_seconds` is `SMALLINT UNSIGNED`.

**Consequences.** These games have been wrong since the day each was played, up to 21 years.
Only the turn files can recover them (`task-20`). And since `went_to_ot` is populated and
reliable, Feature 14 should render an explicit *"overtime not recorded"* marker rather than
silently ending at 59:59.

**A method note that cost real time:** the score-continuity detector built to test this — read
the last embedded running score, compare to `games` — was gated on reproducing known finals
first. It managed 370 of 1,142, so it was discarded. Gating a novel check against known-good
data before trusting it is what stopped a 32%-accurate instrument from being cited as proof.
It still supported a population-level claim (8 of 14 OT games end tied against 44 of 1,142
non-OT, a 15x enrichment) — a noisy instrument can carry a claim about a population while being
useless on any single case, provided that distinction is stated.


---

## 30. An `ENUM NOT NULL` column is *not* mandatory — the type supplies a default

Feature 21 set out to make `leagues.level` impossible to skip. `ENUM('basic','advanced') NOT NULL`
with no `DEFAULT` was chosen over `VARCHAR(20) NOT NULL` partly on the grounds that it would
force a choice at insert. **It does not.** Measured on this server, three shapes, one `INSERT`
each omitting the column:

| Shape | Value omitted | Bad value (`'Advanced'`) |
|---|---|---|
| `ENUM('basic','advanced') NOT NULL` | **accepted**, silently stored as `basic` | normalised to `advanced` |
| `VARCHAR(20) NOT NULL` + `CHECK (c IN (...))` | rejected, `ERROR 1364` | **accepted and stored with the capital** |
| `VARCHAR(20) NOT NULL` + `CHECK (BINARY c IN (...))` | rejected, `ERROR 1364` | rejected, `ERROR 4025` |
| `ENUM('unset','basic','advanced') NOT NULL` + `CHECK (c <> 'unset')` | rejected, `ERROR 4025` | normalised to `advanced` |

An `ENUM` column declared `NOT NULL` takes **the first member of its value list** as its
implicit default. `information_schema.COLUMN_DEFAULT` reports NULL, but the storage engine has
one, so an omitted column is filled rather than refused. `STRICT_TRANS_TABLES` does not change
this — strict mode was confirmed active, globally and in-session, throughout.

**This is worse than the gap it was meant to close.** A missing `level` used to arrive NULL,
which is visibly absent. It now arrives as `basic`, which looks like an answer.

**Six live columns carry the same silent default** (17 matches, but five are views and three are
`bak_` tables):

| Column | Silently becomes |
|---|---|
| `transactions.transaction_type` | `waive` |
| `game_types.phase` | `preseason` |
| `leagues.level` | `basic` |
| `leagues.sport_type` | `pro` |
| `legacy_play_log.ruleset_level` | `basic` |
| `legacy_play_log.sport_type` | `pro` |

`transactions.transaction_type` is the one to worry about: an ingest that omits the type does not
fail, it files a waiver.

**And a second lesson inside the first — a definition check is not a constraint test.** The
Feature 21 gate `P.2` confirmed the column read `enum('basic','advanced')`, `NOT NULL`, no
default, and passed. The column was skippable anyway. The gate that caught it, `P.4`, was the one
that *attempted the violation* and expected an error. A constraint nobody has tried to break has
not been verified, however carefully its definition has been read. Cf. §25: the rule that would
have deleted 43 legitimate plays also passed its own simulation.

**Method note.** The first explanation offered for `P.4` — that strict mode must be off — was
wrong, and was stated confidently before `@@sql_mode` was read. The comparative probe above is
what settled it. Reasoning from documentation about server behaviour is a hypothesis; on this
project it gets a scratch table and an `INSERT` before it gets written down.


---

## 31. A backup step must FAIL on its second run

Feature 21's write script opened with the obvious idiom:

```sql
DROP TABLE IF EXISTS bak_f21_leagues;
CREATE TABLE bak_f21_leagues AS SELECT * FROM leagues;
```

Run once, that is a backup. **Run twice, it is a data-loss event wearing a backup's name** — the
second run rebuilds it from the already-changed table, leaving something still named, commented
and dated as a rollback path, holding the post-change state. Nothing errors, and nothing about
the object looks wrong afterwards.

This came within one command of happening. The script was believed not to have run (a wrong
password on a *later* script was attributed to it), and the natural response — re-run it — would
have destroyed the only copy of the pre-change rows.

**The fix is to remove the `DROP`.** A bare `CREATE TABLE ... AS SELECT` errors on the second
run, and with no `--force` the script stops there. Fail loudly beats overwrite silently.

**The guard fired, and was stepped around.** Stage 2e of Feature 21 carried the bare `CREATE`
described above. It also died partway, on an unrelated error. The backup table was afterwards
found holding the *post*-change text — meaning it had been recreated from the already-updated
table, which the bare `CREATE` was specifically designed to prevent. **A `DROP` to clear the
"table already exists" error undoes the whole protection**, and that error is exactly what a
person sees when re-running a script that half-worked.

So the guard is necessary and not sufficient. What actually caught it was a **verification gate
that asserted how many rows should differ from the backup** — expected 7, got 0, failed. Without
that gate the worthless backup would have sat there wearing a correct-looking name, a plausible
comment and a plausible timestamp. If a script takes a backup, something later must *use* it and
assert against it; a backup nothing compares to is a backup nobody has checked.

**Two habits this earned:**

- **A state check before re-running anything.** Ask the database what exists, rather than
  reasoning from recollection. `bak_f21_leagues` holding 2 rows with NULL `level` proves it is a
  valid rollback path; 9 rows would have proved it worthless. That distinction is one query and
  is invisible from the outside.
- **Output that contradicts your recollection is evidence, not noise.** The results file that
  "could not exist" was genuine, and chasing the discrepancy instead of dismissing it is what
  prevented the re-run. Related: §18, row counts cannot distinguish more coverage from wrong
  coverage.


---

## 32. A comment that states an inference as fact is worse than no comment

The seven archive-only leagues added in Feature 21 were given `notes` beginning **"Defunct."**
Nothing in the data said so. `active = 0` was supplied; "defunct" was inferred from it and then
written into seven rows as a settled fact.

It is wrong on the facts — some of those leagues are still running, with nobody from this project
in them, and **which ones is not determinable from anything held here**. But the shape of the
error matters more than the instance. A reader who found `active = 0` alone might have gone and
checked. A reader who finds a note saying "Defunct" will not. **Documentation displaces
investigation, which is what makes a confident wrong note more expensive than an absent one.**

The same inference was available from the column, so the text fix alone was not enough:
`leagues.active` now carries a comment saying its meaning is undefined and that `0` must **not**
be read as "this league has ended".

**The rule:** write what was measured, and say explicitly when something is unknown. "Whether the
league is still running is UNKNOWN and cannot be determined from any data held here" is a useful
sentence. "Defunct" is a guess in the costume of a finding.

**The agreed replacement is "inactive"** (Alan, 14 Aug 2026), applied across `schema.md`,
`new_schema.sql`, `todo.md` and the `leagues.notes` text. It is the right word for the reason the
wrong one failed: *inactive* reports what the record holds — `active = 0` — while *defunct*
asserts something about the world that the record does not contain. Prefer the word that names
the evidence over the word that names the conclusion.

**One consequence to watch.** The prose word is now the column name, so `leagues.active` having
no defined meaning matters more than it did. "Inactive" is only honest while nobody reads it as
"has ended" — which is the same trap one level down. (`active` was given a definition later the
same day; see `schema.md` §15.)

---

**The lesson recurred within hours of being written, which is the part worth keeping.** A gate
reported that a backup table held rows identical to the live ones. From that alone — *without
opening the table* — I concluded it had been captured after the change rather than before, and
wrote **"MISLABELLED BY ITS OWN NAME"** into its `TABLE_COMMENT`.

The conclusion turned out to be correct. That is not the point. The evidence available at the
time was consistent with a second explanation — a defective comparison in my own gate, which had
happened three times already in Feature 15 (§26) — and the query that distinguished them took one
line and was run *afterwards*. Had it landed the other way, a false statement would have been
permanently attached to a database object, by exactly the route this section describes.

**Being right by luck and being right by evidence produce identical text.** The difference is
invisible in the artefact and total in the method. Before writing a claim into a comment, name
the other explanation that fits the same evidence, and run the query that separates them — even
when, especially when, the first explanation feels obvious.

**Where the word came from is the postscript.** It was carried out of `new_schema.sql`'s
`game_id` backfill comment — "46,680 rows in 7 defunct leagues" — without checking whether that
comment had established it or merely repeated it. Inherited prose gets the same scrutiny as an
inherited filter (§27).


---

## 33. A reconciliation joined from one side is blind to extras on the other

Feature 21's whole-population gate reconciled every league in the archive against `leagues`:

```sql
FROM ( SELECT league_code, ... FROM legacy_play_log GROUP BY league_code ) a
LEFT JOIN leagues l ON l.code = a.league_code
```

It reads PASS on all nine leagues — correctly. It also read PASS while `leagues` held a **tenth**
row, a stray `ZZTEST` inserted by a constraint probe, and reported `leagues_checked: 9` while the
table held 10. Driving from the archive side, a `leagues` row with no archive rows is simply not
in the result set. The gate can catch a wrong value and a missing row; it cannot catch a
**spurious** one.

**Any reconciliation between two populations needs both directions**, or an explicit row-count
assertion on each table alongside it:

```sql
-- the join proves the overlap agrees; this proves there is nothing outside the overlap
SELECT COUNT(*) FROM leagues;        -- expect exactly the number reconciled
```

Feature 21's `M.6` did assert the `leagues` count and would have caught it — but at a different
stage, so the two never ran together. **A gate is only as scoped as the query under it**, and
"whole population" has to be true of the population you named.


---

## 34. One script per turn — and a script that names itself

Feature 21 was delivered as a chain of numbered SQL files. Twice, two were handed over together,
and both times it cost more than the round trip it saved.

**Sending 2b and 2c together.** A wrong password hit one of them. The failure was attributed to
an *earlier* script, 2a, which had in fact completed. The obvious next move — re-run 2a — would
have destroyed the only copy of the pre-change rows (§31). What prevented it was a read-only
state check that asked the database what existed, rather than reasoning from recollection.

**Sending 2c and 2d together.** 2c was run from the copy already on disk, which predated a
wording revision. Its own verification gate passed, because that version of the gate had been
written before the revision it was meant to check. With 2d executing immediately afterwards,
nothing surfaced the mistake until the affected rows happened to be pasted into the chat several
exchanges later.

**The rule:** one script, run it, read the results, verify, then the next. Even when the scripts
are independent and the order does not matter — independence is about the *database*, not about
whether a human can tell which one produced which output.

**Two mechanics that make it work**, because the rule alone does not:

- **A revised script gets a new filename.** Never the same name with different contents. Two
  files called `f21_stage2c_notes_correction.sql` existed, differing by one wording change, and
  nothing in the output distinguished them.
- **Every script's first statement prints its own identity** — filename, revision, date:

  ```sql
  SELECT 'f21_stage2c_notes_correction.sql  rev 2  14 Aug 2026' AS script,
         DATABASE() AS db, @@hostname AS host, NOW() AS run_at;
  ```

  This is the standing rule *diagnostic output must name its own source* applied to scripts
  rather than to pages. By the time a results file is read, the command that produced it has
  scrolled away. The stale run above would have been visible in the first line of output instead
  of several exchanges later.

**The general shape.** A verification gate can only test what it knew about when it was written.
Running scripts serially is what puts a human between one gate's blind spot and the next script's
assumptions.

## 35. A DaDaBIK re-sync does not detect a column type or nullability change

A re-sync in the DEV Area acts on fields that have been **added, renamed or deleted**. A column
altered in place is invisible to it. ✓ 14-Aug-2026 · Alan, from the DEV Area's operation, with the
stale row as its own corroboration.

Feature 21 converted `leagues.level` from `VARCHAR(20) NULL` to `ENUM('basic','advanced') NOT NULL`.
Nothing was added, renamed or deleted, so the re-sync reported nothing to do — correctly, by its own
rules — and `zpbm_forms` went on holding the pre-change definition: `type_field=text`,
`required_field=0`. The edit form offered a free-text box for a column that had become a
two-member ENUM. The field had to be configured by hand afterwards (task A1).

**The failure mode is the quiet one.** The re-sync does not warn, does not list the column, and
does not fail. It returns a clean result, which reads as confirmation that the form and the
database agree. It is not that: it is confirmation that no field was added, renamed or deleted.
Those are different claims, and the second one is true whenever the first is.

**Rule.** After any `ALTER TABLE` that changes a column's type or nullability, configure the
affected `zpbm_forms` field by hand and confirm on the form itself. A re-sync reporting nothing to
do is not evidence the field is aligned.

**Where this bites next.** Backlog item **P3** alters six live columns in place. The re-sync will
stay quiet about all six, and each will need the same hand pass. Budget it rather than discover it.

**Related, and worth not conflating.** Making a field required in `zpbm_forms` does not make the
column mandatory — an `ENUM NOT NULL` still takes its first member as an implicit default under
`STRICT_TRANS_TABLES` (§30). A1 fixed the form; the column is exactly as permissive as it was.
Two separate layers, two separate fixes.

# `lessons.md` §36 — paste-in block (H19)

**Where it goes:** at the very end of `lessons.md`, after §35's closing line
*"Two separate layers, two separate fixes."*

**Numbering:** §35 is currently the last section, so this is **§36**. Check that before pasting —
if another session has added one since, renumber.

**Route:** download `lessons.md`, paste at the end locally, upload. Do not have a chat rewrite the
file — at ~115KB it cannot be reproduced reliably, which is what P14's split is for.

Everything below the line is the block. Paste it verbatim, including the leading `---`.

---

---

## 36. An unordered `LIMIT 1` is a coin toss, and the result looks like a decision

`label_L1_identities.sql` resolved each identity's team code with:

```sql
(SELECT tc.code FROM team_codes tc WHERE tc.team_name = fg.team LIMIT 1)
```

**No `ORDER BY`.** `team_codes` holds two rows for three of the names in the pool — Green Bay
(`GB`/`GP`), New Orleans (`NS`/`NO`), Tennessee (`TN`/`TT`) — so for those three the server
returned whichever row it reached first. That arbitrary pick became 642 rows of `NO` for New
Orleans Saints in `franchise_identities`, against `NS` in every other place the code appears.

The evidence, measured 16 Aug 2026:

| Source | `NS` | `NO` |
|---|---:|---:|
| `gplan_main.n_playbyplay.a_off` | 4,775 | **no row returned at all** |
| `legacy_play_log.offense_team_code` | 4,698 | 0 |
| `legacy_play_log.defense_team_code` | 4,559 | 0 |
| `plays.field_side` | 29 | 0 |
| `franchise_identities.team_code` | 0 | **642** |

`schema.md` §12 independently listed `NS` as the code in use and `NO` among the superseded
duplicates. Corrected by `A4_fix_neworleans_code.sql`, 642 rows, backup `bak_a4_no_ns`.

### Why no gate caught it, and why none could have

Both `NO` and `NS` are legitimate `team_codes` rows for that name. So:

- **The code/name agreement check passes by construction.** That is the check §13 established
  after four mislabelled codes — compare the name a code holds against the name recorded for that
  team in that week. It catches a code carrying the *wrong name*. `NO`'s name was right.
- **The week-grain uniqueness checks pass too.** One franchise still held exactly one code per
  week, and no code mapped to two franchises. Nothing about the shape of the data was wrong.

This is a **third failure shape**, distinct from the two already recorded here. §13 is a code
carrying the wrong name. §32 is an inference written down as fact. This one is a **resolver
permitted to choose, choosing silently, and the choice hardening into data** that later work then
has to stay consistent with. By the time it surfaced, correcting it had stopped being a one-line
fix and become a 642-row question — and A4's backfill had to deliberately propagate the wrong code
into 7 new rows first, because writing the right one would have fabricated a code change at the
2034 wk9/wk10 boundary, indistinguishable to every consumer from a real mid-season identity change.

### The part that explains why it survived

**It was right two thirds of the time.** Green Bay came out `GB` and Tennessee came out `TN`, both
correct, by exactly the same coin toss — their alternates `GP` and `TT` have zero rows anywhere.
A defect with that hit rate produces no symptom anyone would chase. It is not that the check was
skipped; it is that two of the three instances were indistinguishable from correct work.

This is the same reason §30's `ENUM NOT NULL` gap survived: the wrong behaviour and the right
behaviour produce identical-looking artefacts, so only a test that *attempts the failure* separates
them. A definition check is not a constraint test; an output that looks right is not a resolver
that works.

### The rule

**A `LIMIT 1` with no `ORDER BY` is only safe where the result set is provably a single row — and
where it is provably a single row, the `LIMIT 1` is doing nothing and the proof belongs in a gate
instead.** Anywhere else, one of two things:

- **Order it deterministically, and say in a comment why that order is the right one.** "First by
  insertion" is not a reason; "the code in current use, which is the one every play-level table
  carries" is.
- **Or refuse to resolve and write `NULL`.** A `NULL` is visibly absent and someone will ask. A
  plausible wrong value is not, and nobody will.

`A4_backfill_identities.sql` took the second option: it resolves a code only where exactly one
candidate exists and writes `NULL` otherwise, with gate `A4.3d` reporting which branch every row
took. That gate would have made this defect visible on the day it was created.

### Where to look for more of it

Any resolver that reads a lookup **by name** rather than by key. `name → code` is not unique in
this schema and never needed to be (`schema.md` §12) — the duplicates are superseded entries, not
errors, and deleting them would be the wrong fix. What has to change is any query that reads them
as though a choice were unnecessary.

Worth a grep for `LIMIT 1` across the parser pages and the migration scripts, checking each for an
accompanying `ORDER BY` and for whether the result set is genuinely single-row.

### And one about how this was found

The defect surfaced because A4's backfill had to resolve the same codes and hit the same ambiguity
— so it asked which code to write, rather than picking one. The instruction it was working from
(`team_code` resolved from `team_codes` by name) was underspecified in exactly the way the original
script's `LIMIT 1` had papered over. **An instruction that cannot be followed deterministically is
a defect report about the thing it was copied from.** Stopping to say so is cheaper than
discovering it 642 rows later.

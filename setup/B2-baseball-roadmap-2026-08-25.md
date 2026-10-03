# Baseball roadmap after B1 — Team Roster through League Stats

**rev 001 · 25 Aug 2026 · role: roadmap · authority: derived**

B1 (draft parser + draft board) is done. This is the outline for what Alan asked for next, in the
order he gave it: Team Roster (page 1) next, then Team Stats (page 2), Roster Comparison, League
Roster, League Stats — with two things called out explicitly: player aging/skill/value changes
happen at week 21, and League Roster/League Stats differ depending on whether they were produced
by a user action (Special Actions: ROUNDUP, PLAYERS) or a game-generated week (week 21 specifically
mentioned). Grounded against three real turn files Alan supplied (MLB6ABs35 weeks 18, 20, 21) and
three legacy reference scripts (`bb_rostercompare.php`, `bb_rostercompare.php2`,
`bb_rosterupdate.php`) — legacy PHP here is a behavioral spec to replicate against the new B-series
schema, not code to reuse directly (per project convention: it uses legacy tables `bb_myteam`,
`bb_players`, `bb_playerstemp`, `bb_transactions`, `g_turnsummary`, raw string-interpolated SQL in
places, and an unimplemented "update" branch — none of that should carry forward).

Confirmed in the turn text (MLB6ABs35w21.txt, "Team Report" section, lines ~15-57): each team's
roster block is exactly the legacy `bb_myteam` shape — `Sh Name Type Ability Exp Pot Trd Fat Frm
Tot Inj Value Wages` common to both pitchers and batters, then a type-specific skill block
(pitchers: `Acc Con Qui Sta`; batters: `Hit Pow Spd Fld`), then `Sqd` (Act/Res/Drf), then a
type-specific extra block (pitchers: `MxB MxH MxE FIn MnB`; batters: `Pos vRH Plt Pin`). "Team
Stats" (Individual Pitching / Individual Batting, lines ~92+) is a separate section in the same
turn file, per-player season cumulative stats. `Special Actions:` lines (e.g. `SCOUT 105: OK,
ex-SM, ...`) confirm the turn text also carries the user-initiated action codes referenced below
(the code list — ACCEPT, ACTIVATE, ..., PLAYERS, ..., ROUNDUP, ..., SCOUT — appears in the turn
file's key/legend section).

## B2 — Team Roster (page 1) — next

Per-team, per-turn parse of the "Team Report" roster block into a normalized table (new-schema
equivalent of legacy `bb_myteam`), keyed by team + season + week (or turn/upload id) + shirt
number, not just shirt number alone (shirt numbers get reused across turns as rosters change).
Needs both the shared fields and the two type-specific skill/extra blocks — most naturally two
child tables or a nullable-columns single table keyed by player type, matching the draftee-side
precedent (`bb_draftees` + type-specific columns) already established in B1. This is explicitly
the first write-state (not just parse-and-display) feature outside the draft pipeline, so it needs
its own upsert-by-natural-key logic, same pattern as the B1 scouting-schema fix (upsert, not
append-and-dedupe-later).

## B3 — Team Stats (page 2)

Per-player cumulative season stats (Individual Pitching / Individual Batting sections). Likely a
straightforward parse once B2's player-identity resolution (team + shirt number + turn -> which
roster row) exists, since stats rows key off the same Sh/Name pairs as the roster block in the
same turn file.

## B4 — Roster Comparison

Turn-vs-turn diff of a team's roster, matching what `bb_rostercompare.php`/`.php2` do against
legacy `bb_myteam`: level change (up/down), potential loss >1 flagged as a double-loss case, the
four skill columns diffed via the Po/Fa/Av/Go/Ex/WC ranking, value-tier flags, and a distinct
render path for players who appear in the later turn but not the earlier one (no "→" diff, just
their current values). **Alan's explicit note: player aging and skill/value updates happen at week
21** — so B4 needs a turn-selector (both scripts already do this; `.php2`'s version lists every
turn, the other restricts to each league's latest season only — worth deciding which behavior is
actually wanted rather than copying either by default) and should be validated specifically against
a week-20-vs-week-21 comparison once B2/B3 land, since that's the one turn boundary known to
contain real aging changes rather than just week-to-week performance drift.

## B5 — League Roster

**Correction (25 Aug, caught by Alan before this was built on): this is NOT B2's per-team data
shown for every team, and it's not a separate report either — it's fog-of-war by design.**
A real section of the MLB6 week-21 turn text — a side-by-side "Atlanta Braves (AB) | Philadelphia
Phillies (PH)" block — carries a materially *narrower* column set than the Team Roster block B2
parses: `Sh Name Type Ability Exp Pot Trd Value` only, no `Fat Frm Tot Inj Wages`, no skill block,
no `Sqd`, no extra block. **Confirmed by Alan: you get detailed reports about your own players,
but the league-wide view of every other team is deliberately reduced — same shape as an
*unscouted* draftee in B1's draft list** (Ability/Exp/Pot/Trd/Value, no skill breakdown, because
nothing has scouted the detail in yet). So League Roster isn't "call B2's parser for every team's
own upload" — a coach's turn file never contains any other team's full data to parse in the first
place. It's a two-tier model, same shape B1 already solved once:
- **Own team**: full detail, from B2's Team Roster parse (this team's own turn upload).
- **Every other team**: the reduced fields only, sourced from wherever this narrower league-wide
  block actually lives in the turn text (still need to confirm: is it per-opponent as shown here,
  tied to the schedule, or a full-league table elsewhere — e.g. under a ROUNDUP/PLAYERS action) —
  and, per the B1 precedent, this reduced data might itself be upgradable to full detail later if
  something like `SCOUT` applies to rostered players the way it does to draftees (worth asking
  Alan rather than assuming either way).

League Roster's schema is therefore two tables/paths, not one: an "own team, full" path reusing
B2's shape, and an "other teams, reduced" path with its own (narrower) shape matching the
unscouted-draftee column set already established in B1's `bb_draftees`.

**Confirmed (25 Aug, against `MLB6ABs35w20.txt` and `MLB6ABs35w21.txt`, both previously
uploaded): the "other teams, reduced" path has a real, distinct source — the `<BK.Roundup>`
section.** It's not per-opponent, it's league-wide: one block per division (AL East, AL Central,
AL West, NL East, NL Central, NL West — all six present in w20's `ROUNDUP`-ordered output),
two teams per row-pair, every team's full roster, columns exactly `Sh Name Type Ability Exp Pot
Trd Value` as suspected, with fielding position folded into the name for batters/catchers
(`Corey Seager (SS)`) rather than a separate Pos column. **This section appears in BOTH turn
files** — w20 under `Special Actions: ROUNDUP : OK, roundup ordered...` (user-initiated), and w21
with no ROUNDUP action requested at all (that week's only Special Action was `SCOUT`) — titled
"Preseason Roundup" instead of "Week 21 Roundup", i.e. it's generated automatically at
season-boundary weeks regardless of whether it was ordered. **Same column shape both times** —
the two paths Alan mentioned ("user initiated ROUNDUP vs. game generated") look like the same
data under a different trigger/heading, not a structural difference; worth a quick confirm with
Alan but B5 can likely use one parser for both, keying off the section being present at all rather
than needing separate logic per trigger.

**Confirmed by Alan: the reduced data IS upgradable to full detail — the ROUNDUP baseline is a
floor, not the ceiling.** Two scenarios put more-than-ROUNDUP information about an opponent's
player into a coach's own data:
1. **You scouted an opponent player.** `SCOUT` isn't draft-pool-only (as B1 assumed) — it can
   target a rostered opponent's player too, and presumably returns the same kind of skill-level
   detail B1's scouting already captures for draftees.
2. **You released a player and another team signed them.** The releasing team retains whatever
   full detail they had on that player from when they owned them (B2 Team Roster data), even
   though the player is now on another team's roster and would otherwise only show up at
   ROUNDUP's reduced level.

Alan's own caveat on both: this enhanced data is time-boxed in a real sense — a player can be
coached or trained into a new position or skill level the following week, so a stale scouted/
ex-owned snapshot can drift from reality. But within the season it's still valuable and
Alan wants it captured, not discarded once the ROUNDUP baseline exists.

**Open design decision — explicitly deferred, not for this task**: does League Roster's
"more than ROUNDUP knows" data live in the *same* table as the ROUNDUP baseline (one row per
team-visible-player, upgraded in place when better data arrives, with a provenance/as-of marker
for how it was learned and when), or in a *separate* overlay table (ROUNDUP baseline stays
untouched; a second table holds scouted/ex-owned snapshots keyed by observer-team + player +
turn, joined against the baseline for display)? The one-table shape is closer to B1's precedent
(`bb_draftee_scouting` overlays `bb_draftees`) but B1's scouting was single-payer (one family,
one scouted value per draftee) — this is genuinely multi-observer (every team can independently
know more than ROUNDUP about a given opponent player, and different teams can know different
things), which the B1 shape doesn't handle. Revisit when B5 is actually built; don't decide now.

## B6 — League Stats

**Confirmed (25 Aug, against `MLB6ABs35w20.txt`): also a real, distinct source, but NOT
fog-of-war-reduced like B5 — full stat detail, same columns as Team Stats.** The
`<BK.Batting Leaders>`/`<BK.Pitching Leaders>` sections (triggered by the `PLAYERS` special
action: `PLAYERS : OK, AL & NL hitters and pitchers stats listings ordered, cost 0.5 credits`)
are league-wide leaderboards, AL/NL split, further split into ranked blocks of ~66 players each,
sorted by performance (batting: descending `BAvg`), with team code + position folded into the name
(`Josh Breaux (NY, c)`). Same stat columns as Team Stats' Individual Pitching/Batting sections —
stats are public performance, not scouted-ratings, so no reduction the way B5's roster data is
reduced. **Unlike B5, this did NOT appear automatically in w21** (no `PLAYERS` action that week,
and no Batting/Pitching Leaders section present). **Confirmed by Alan: League Stats is
user-request-only** — no game-generated equivalent the way League Roster's `<BK.Roundup>` shows
up automatically at season boundaries. So B6's parser only ever has data for turns where `PLAYERS`
was ordered; no need to design for a game-generated variant here the way B5 needed to check for
one.

## Sequencing note

B4 (Roster Comparison) depends on B2 and B3 both existing (it diffs roster fields but the legacy
version also reports stat deltas in its summary panel). B5 depends on B2 for the own-team path
and has a confirmed source (`<BK.Roundup>`) for the other-teams-reduced path — its own parser,
schema matching B1's unscouted-draftee shape, not an extension of B2/B3. B6 has a confirmed
source too (`<BK.Batting Leaders>`/`<BK.Pitching Leaders>`) with full (unreduced) stat columns —
also its own parser, not built on B3, though its column shape happens to match B3's.

# Task: build `franchise_identities`

**Status:** Designed, partly scripted, not built. Two of three passes written and tested against
live data; the third is an open design question, not a coding problem.
**Blocks:** Feature 12 (`games.label` rebuild).
**Fixes, permanently:** three separate defects that currently work around the same missing fact.

---

## 1. The missing fact

`gplan_pbm` has **no record of which real-life identity a franchise held in a given week.**

A franchise is a *slot*; the identity attached to it changes as coaches join and leave,
sometimes mid-season — nine NFLAR franchise-seasons carry two identities. Full model in
`schema.md` §12.

That fact exists only in `gplan_main.f_games`, which is frozen. So every consumer improvises
with `franchises.label` — a `STORED GENERATED` column holding the slot's **present-day**
identity — and is therefore wrong for anything historical:

| Consumer | Consequence |
|---|---|
| `games.label` | 3,487 NFLAR + 516 NCAA5 games name the wrong team (Feature 12) |
| `extract_games.php:214`, `extract_standings.php:220`, `operational_hooks.php:188` | Franchise lookups match turn-file team names against `franchises.label`; a game whose identity differs from the current label is **silently skipped** |
| `team.php` game lists | Historical games display current identities |

The parser lookups are not triggering today — no franchise in a season still being played has a
mismatch — but they will at the next identity change, and the failure is silent.

## 2. Design

Table keyed on `(franchise_id, week_id)`, with a `derived_from` column recording provenance
because the three sources are not equally precise. DDL is in `label_L1_identities.sql`.

**Pass 1 — per-week rows from `f_games`.** `derived_from = 'week'`. Covers the email era.
Tested: 14,910 NFLAR and 4,908 NCAA5 rows.

**Pass 2 — season sentinels (weeks 95/98/99) where pass 1 found nothing.**
`derived_from = 'season_rollup'`. Covers NFLAR in-league 1989–2003, the pre-email era, where
season records are all that was ever kept (`schema.md` §12, `lessons.md` §16). Tested: 338 rows,
all NFLAR, all 1989–2004. **NCAA5 returns zero** — it was created at the transition and has no
pre-email era, which is a real confirmation of the model rather than a coincidence.

Verified on the tested passes: franchise 2014 reads `DC` through 2014 wk 12 and `WC` from wk 13,
`WC` through 2021 wk 10 and `DC` from wk 11 — matching the known identity changes exactly. No
known mid-season change is rollup-derived (all nine fall in 2005+, where per-week rows exist).

**Pass 3 — the open question.** `f_games` stops at **NFLAR 2034 wk 9 / NCAA5 2038 wk 11** and
the league has kept playing. After the 2034 re-parse, roughly 204 games sit past that horizon
with no identity source.

## 3. Pass 3 — decide this before building

Three options, in ascending order of how well they hold:

**(a) Carry `franchises.label` forward past the horizon.** One `INSERT`, tagged
`derived_from = 'current_label'`. Correct *today* — identity only ever diverges from the current
label going backwards in time — but it is the same present-day-snapshot assumption that caused
every defect in §1, and it goes stale at the next identity change.

*This was written and then deliberately reverted during the backfill session.* Coding around the
gap hides it; the gap is the thing worth fixing.

**(b) `extract_games.php` writes an identity row per franchise-week.** The turn file's League
Report names every team as it was that week, and the parser **already extracts those strings** —
`$game['home_team']` and `$game['away_team']` — using them for the label and then discarding
them. Writing them to `franchise_identities` closes the loop permanently: no frozen table, no
snapshot assumption, and the parsers can then resolve franchises *through* this table rather
than through `franchises.label`, fixing the silent-skip defect at the same time.

**(c) A manual entry page** for identity changes, since they are rare (nine in NFLAR's history)
and a coach change is a known event at the time it happens.

**Recommendation: (b), with (a) as a one-off backfill for the ~204 games already past the
horizon.** (b) handles everything from the next turn onward; (a) closes the historical gap once,
and the `derived_from` tag keeps its weaker provenance visible.

## 4. Build order

1. Decide pass 3
2. Run `label_L1_identities.sql` (passes 1 and 2 as written, plus whatever pass 3 becomes)
3. **Gate `L1.3d`: every `games` row must resolve both franchises to an identity.** Expect
   ~204 missing before pass 3 exists — NFLAR 2034's games were all recreated during the
   re-parse and `f_games` never covered weeks 10–16. This is expected, not a regression
4. **Gate `L1.3g`: no known mid-season change may be rollup-derived.** Must return nothing
5. Then Feature 12 — `label_L2_rebuild.sql` is written and gated, and inner-joins this table on
   both sides so any game still lacking an identity is skipped rather than mislabelled
6. Separately: point the three parser franchise lookups at this table

## 5. Notes

- `migration_fgames_map` holds the `f_games` extract, but `label_L1_identities.sql` reads
  `gplan_main.f_games` directly and does not need it. That table is interim — review whether it
  is still needed once this exists (`lessons.md` §24)
- Provenance matters downstream: `'week'` is week-exact, `'season_rollup'` is season-exact and
  cannot represent a mid-season change that was never recorded. Anything built on this should
  carry the distinction rather than flattening it
- Once this table exists, `franchises.label` should be used for **nothing** except displaying a
  franchise's present-day identity

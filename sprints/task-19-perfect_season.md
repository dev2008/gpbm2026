# Task: Add Perfect Season honors for NFLAR (pro)

**Status:** Confirmed as a real, genuine gap (not a display bug), rule given directly. Nothing
built. This document exists so a fresh conversation can pick this up without re-deriving the
background from `lessons.md`/conversation history first.

---

## 1. The gap, confirmed two ways

`PERFECT_SEASON` (`honor_types`) currently only ever applies to NCAA5 (college). Confirmed
directly, not assumed:

- `new_schema.sql`'s own comment on `honor_types.code`, verified against the original migration
  data: *"12/12 college rows, 24/24 pro rows"* for winner-honor counts — the legacy source
  (`f_games`/`fc_franchises`) simply never recorded a perfect-season concept for pro teams at
  all.
- Alan checked the legacy game-engine code directly and confirmed `PerfectYears` (the source
  field this honor type was derived from during migration — `schema.md` §9) is genuinely only
  ever referenced for college there too.

**Not a `coach.php` display bug.** `honor_types.league_id` is `NOT NULL` — the honor type is
scoped to NCAA5's league at the schema level. There's nothing to surface even if any query
changed; the row that would represent an NFLAR perfect season doesn't exist yet, for any
franchise, ever.

## 2. The rule, given directly

**Win the Superbowl after a 16-0 regular season.** Exact wins/losses/ties — 16-0-0, not merely
"undefeated" (a 16-0-1 tie-included season would not qualify under this reading; confirm this
interpretation before implementing if there's any doubt).

Worth checking, not assuming: does *every* historical NFLAR season actually use a 16-game
regular season, or did the game length vary by era (the way real NFL history did)? The rule as
given doesn't qualify by era — confirm the historical data doesn't have a season where a
different win threshold would represent "regular season complete" before assuming 16-0 is the
right test for every year in the archive.

College's own equivalent, for comparison/context (already correctly implemented, not something
to change): **11-0** (college's fixed regular-season length) *and* winning the national
championship that year — confirmed and documented in `schema.md` §9. Pro's rule is structurally
the same shape (perfect regular season + winning it all), just a different game count and a
different honor code (`LEAGUE_WINNER` either way, since that's already league-agnostic).

## 3. Three real pieces, not one

### Piece 1 — a new `honor_types` row for NFLAR
```sql
-- Confirm the exact league_id for NFLAR and the exact code/name conventions already in use
-- for the existing PERFECT_SEASON row before writing this -- match the pattern, don't guess it.
SELECT * FROM honor_types WHERE code = 'PERFECT_SEASON';
```
Then insert the NFLAR equivalent, matching whatever pattern that query reveals (same `code`
value scoped to a different `league_id`, most likely — `honor_types` doesn't force a code to be
globally unique across leagues, given `LEAGUE_WINNER`/`CONFERENCE_CHAMPION`/etc. already exist
per-league; confirm this rather than assume it during implementation).

### Piece 2 — a one-time historical backfill

Draft query identifying every qualifying (franchise, season) pair — not yet run or tested:
```sql
SELECT fsr.franchise_id, fsr.season_id
FROM franchise_season_records fsr
JOIN seasons s ON s.season_id = fsr.season_id
JOIN leagues l ON l.league_id = s.league_id
JOIN franchise_honors fh ON fh.franchise_id = fsr.franchise_id AND fh.season_id = fsr.season_id
JOIN honor_types ht ON ht.honor_type_id = fh.honor_type_id AND ht.code = 'LEAGUE_WINNER'
WHERE l.code = 'NFLAR' AND fsr.wins = 16 AND fsr.losses = 0 AND fsr.ties = 0;
```
Each resulting row needs a new `franchise_honors` insert with the NFLAR `PERFECT_SEASON`
`honor_type_id` from Piece 1, same `franchise_id`/`season_id`.

### Piece 3 — open question, needs resolving before Piece 2's scope is even fully known

**Is `franchise_honors` populated only by the original historical migration, or is there a live
process adding honor rows as each season completes?** Not investigated. Strong circumstantial
signal, not confirmed: none of the three live extraction pages (`extract_standings.php`/
`extract_games.php`/`extract_playbyplay.php`) write to `franchise_honors` anywhere — check this
directly (`grep -n "franchise_honors" extract_*.php`) rather than trust this document's
paraphrase of it. If confirmed migration-only, Piece 2's one-time backfill is the *whole*
solution going forward too — but if some other mechanism does exist and update
`franchise_honors` live, this task needs to also make sure that mechanism knows about the new
NFLAR perfect-season rule, or every *future* qualifying season will silently need its own manual
backfill forever.

---

## 4. Non-goals

- Not re-litigating college's existing Perfect Season logic — it's already correct, already
  documented (`schema.md` §9), not touched by this task.
- Not building any new UI — `coach.php`'s `honor_display_labels()` already has a `'PERFECT_SEASON'
  => 'Perfect Seasons'` row that renders automatically the moment a qualifying `franchise_honors`
  row exists for an NFLAR coach. No front-end change needed once the data exists.

---

## 5. Suggested first steps for whoever picks this up

- [ ] Run the Piece 3 grep — confirms whether this is a pure one-time backfill or needs an
      ongoing mechanism too, before committing to a scope for the rest of the task
- [ ] Confirm the 16-game-every-season assumption against the actual historical data (§2)
- [ ] Confirm the exact `honor_types` insert shape by examining the existing college row first
      (§3, Piece 1) — don't guess the column values
- [ ] Run the Piece 2 query as a `SELECT` first, sanity-check the resulting list (how many
      qualifying seasons? does it look plausible for the number of NFLAR franchises across
      1989–2038?) before converting it to an `INSERT`

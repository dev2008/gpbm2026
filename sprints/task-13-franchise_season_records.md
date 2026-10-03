# Task: `franchise_season_records` has no writer

**Status:** Not started. Identified Aug 2026 during the NFLAR 2034 re-parse.
**Priority:** Medium — the data is live, user-visible, and currently wrong.

---

## 1. The problem

`franchise_season_records` holds per-franchise, per-season win/loss/tie and points totals. It is
**read** by `team.php` — the season records table, plus the "most wins in a season", "most
losses" and "most points" derived stats — and **written by nothing in the extract pipeline**.

```
$ grep -l "franchise_season_records" *.php
team.php
```

It was populated by the original migration and has not moved since. Every season played through
the live parser has left it untouched.

## 2. Demonstrated stale, not merely unmaintained

Measured for NFLAR 2034 immediately after the season's 16 played weeks were re-parsed:

```
record_rows  total_wins  total_losses  total_ties
         24         166           166           4
```

166 + 166 + 4 = **336 team-records = 168 games = 14 weeks × 12**. The season has **16** played
weeks (204 games including pre-season). So the table has been frozen since before weeks 15 and 16
were ever processed — and those were parser-created back in early August, well before this
re-parse.

`team.php` is therefore showing 2034 records that are two weeks short, with nothing on screen to
indicate it.

## 3. What needs deciding

**Should it exist at all, or be a view?** Everything in it is derivable from `games` — wins,
losses, ties, points for and against per franchise per season. A view (or a materialised
refresh) cannot go stale. Given the same "computed but never refreshed" shape has now appeared
several times in this project (`lessons.md` §3 on "computed but never stored", §9 on a fix that
only half landed), deriving rather than storing is worth serious consideration.

Against: `team.php` runs `MAX(column)` and `COUNT(*)` queries across it for the career-records
panel, which are cheap on a stored table and less so on a view over ~10,000 games. Measure
before assuming it matters — the table is only ~24 rows per league-season.

**If it stays a stored table, what refreshes it?** Options:
- `extract_games.php` recomputing the affected franchise-season after each week — simple, but
  runs 24 recomputations per week for data that only changes at the margin
- A separate rebuild page under Process Turns, run at season end
- An `operational_hooks.php` step

**Whichever is chosen, it needs a backfill** for every season already affected — at minimum
NFLAR 2034, and worth checking whether earlier seasons drifted too.

## 4. Scoping queries

```sql
-- Which league-seasons disagree with what games actually holds?
SELECT   l.code AS league, s.year AS season,
         fsr.total_results AS recorded_results,
         g.actual_results,
         g.actual_results - fsr.total_results AS shortfall
FROM (
    SELECT s.season_id, SUM(f.wins + f.losses + f.ties) AS total_results
    FROM   franchise_season_records f
    JOIN   seasons s ON s.season_id = f.season_id
    GROUP BY s.season_id
) fsr
JOIN (
    SELECT w.season_id, COUNT(*) * 2 AS actual_results
    FROM   games g JOIN weeks w ON w.week_id = g.week_id
    WHERE  g.home_score IS NOT NULL
    GROUP BY w.season_id
) g ON g.season_id = fsr.season_id
JOIN     seasons s ON s.season_id = fsr.season_id
JOIN     leagues l ON l.league_id = s.league_id
WHERE    fsr.total_results <> g.actual_results
ORDER BY l.code, s.year;
```

```sql
-- Does team.php's "most wins in a season" currently name the right franchise?
-- Compare the stored record against one computed live from games, for one franchise.
```

## 5. Related

- `lessons.md` §22 records this alongside what a `games` delete does and does not cascade to
- The same question applies to any other table populated by migration and read by the app but
  never written by the pipeline. Worth a sweep: for each table, which code writes it? If the
  answer is "only the original migration", it is stale by construction the moment the live
  pipeline creates anything.

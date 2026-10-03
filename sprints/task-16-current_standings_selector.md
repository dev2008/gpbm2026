# Task: season/week selector for `current_standings.php`

**Status:** Not started. Raised Aug 2026 while re-parsing NFLAR 2034 — the page gives no
indication of which season it is showing.
**Priority:** Medium. Section 3 below is arguably higher, and is a data-correctness issue rather
than a display one.

---

## 1. The request

`current_standings.php` renders a standings table with **no season or week indicator anywhere**.
A reader cannot tell whether they are looking at 2034 week 16 or 2029 week 3.

This matters more here than in most leagues because the in-league season is not the calendar year
(`lessons.md` §15) — in-league 2034 was played across calendar 2025–26 — so a reader cannot infer
the season from context either.

Wanted: a **season (and probably week) selector**, with the current selection shown.

## 2. This reverses a deliberate decision

The page carries an explicit comment:

> `// Current Standings -- always shows the latest week on record for whichever league is`
> `// selected. No season/week picker here on purpose: that's what the separate Historical`
> `// Standings page is for.`

So before building, decide the split:

- **Does the Historical Standings page still exist, and what does it do?** If it already offers
  season/week selection, this may be a navigation problem rather than a missing feature — the
  answer might be a prominent link plus a season heading here.
- **If a selector is added here, what is Historical Standings for?** Two pages doing the same
  thing with different names is worse than one.
- **Minimum viable change:** even if no selector is added, the page should *state* the season and
  week it is showing. That is a one-line fix and removes the ambiguity entirely.

`team.php` already has a working season selector and is the model to follow for markup and
parameter handling.

## 3. Two defects in `v_current_standings`, found while scoping this

### 3a. `MAX(week_id)` is taken per franchise, not per league

```sql
join (select franchise_id, max(week_id) as max_week_id
      from standings_weekly group by franchise_id) latest
  on sw.franchise_id = latest.franchise_id and sw.week_id = latest.max_week_id
```

Each franchise independently contributes its own latest row. If one franchise is missing a row
for the newest week, it silently appears **at an older week** alongside everyone else's current
figures — one table, mixed weeks, no indication.

This is the same failure shape as the `MAX(season)` defect already corrected in the
`v_relevant_*_current` views. It should resolve the latest week **once for the league**, then
select every franchise at that week.

**Live demonstration, right now:** the NFLAR 2034 re-parse deleted all 384 of that season's
`standings_weekly` rows before re-adding them week by week. Until the parse completes, this page
is showing **2033** for NFLAR — correctly, by its own logic — with nothing on screen to say so.
That is exactly the ambiguity in section 1, arriving from a direction nobody planned for.

### 3b. `MAX(week_id)` assumes id order matches chronological order

`week_id` is an auto-increment assigned by `dadabik_resolve_or_create_week()` **when a week is
first seen**. Backfilling an older week after a newer one gives the older week a **higher**
`week_id`, and `MAX(week_id)` would then pick the older week as "latest".

Ordering should be on `seasons.year, weeks.week_number`, which is the actual chronology, not on
a surrogate key that only usually correlates with it.

Not currently biting — weeks have so far been created in order — but it is a latent trap of the
same shape as `date('Y')` in `lessons.md` §9: a value that happens to be right today because of
how the data arrived, not because anything guarantees it.

## 4. Suggested scope

1. Fix 3a and 3b in `v_current_standings` — these are correctness, and independent of any UI work
2. Add a season/week heading to `current_standings.php` — small, and resolves the reported issue
3. Decide the Current vs Historical split before building a selector
4. Build the selector, following `team.php`'s pattern

Steps 1 and 2 are worth doing regardless of what is decided in step 3.

## 5. Verification

- With a franchise's newest-week row deliberately absent, the page should either omit that
  franchise or show the whole league at the previous complete week — **not** mix weeks silently
- The heading must match the week the rows actually come from, not the newest week in the table
- Check both leagues: NCAA5 has no conference grouping and the view suppresses it, so the heading
  and selector must work without that column

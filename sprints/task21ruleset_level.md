# Task: record the ruleset level; settle the NULL-filter behaviour in the playcall views

**Status:** Not started. Two small correctness items, neither urgent, spawned by Feature 15
(Aug 2026). Specified inline in `todo.md` until now; this doc exists so **Sprint A item A1** has
the same shape as every other item in the sprint.
**Priority:** Low impact, low risk. Neither part is visible to a reader — this is *trimmable*
from Sprint A if go-live needs to come forward a week.

> **A note on scope.** `todo.md` originally said this was *"better folded into whatever feature
> next touches `leagues` or the `v_playcall_*` views than given its own chat."* That still holds
> in principle — but nothing in Sprint A touches either object (A3 touches
> `v_current_standings`, a different view), so there is nothing to fold it into. Standalone or
> deferred are the only real choices.

---

## 1. Part 1 — `leagues.level` is NULL

`leagues.level` is NULL for both live leagues. **Intended action is one `UPDATE`.**


### The invariant, stated directly (Alan, 14 Aug 2026)

**A league's ruleset level and sport type are fixed for the life of the league.** They do not
vary by season, and they never change:

| League | `level` | `sport_type` | Status |
|---|---|---|---|
| NFLAR | Advanced | Pro | live |
| NCAA5 | Advanced | College | live |
| NCAA7 | **Basic** | College | not yet in the data |

Three things follow, and both are worth more than the `UPDATE` itself.

**(a) `level` belongs at league grain — there is no season dimension to it.** Recorded here so
nobody re-derives it. Team *identity* turned out to need week grain (`schema.md` §12), which
makes "should this be per-season?" a reasonable question to ask of any league-level attribute.
For `level` the answer is no, and it is answered.

**(b) NCAA7 proves `level` and `sport_type` must stay two columns.** With only NFLAR and NCAA5
live they are perfectly correlated — collapse them into one column and nothing breaks until a
third league arrives. NCAA7 (Basic + College) is the case that separates them. Worth knowing
before anyone "simplifies" the pair.

**(c) Level should be a required field** 

### The evidence for 'advanced', from the turn file itself

Every turn file surveyed for Feature 20 carries the ruleset in its own header, across the whole
archive span and both leagues:

```
<P.70><B>GAMEPLAN (Advanced)   League NCAA5   Season 2003   Week 6   29/6/05<L.54.1>
<P.70><B>GAMEPLAN (Advanced)   League NFLAR   Season 2032   Week 1   1/6/24<L.54.1>
```

and again on the turnsheet:

```
<C> <B>GAMEPLAN   VERSION 2.17 ADVANCED   29/6/05   TURNSHEET   <X>NCAA5-PI<L.54.1>
```

Six files spanning in-league NCAA5 2003 to NFLAR 2008, plus the NFLAR 2032 reference — **all
Advanced, both leagues, every era**. See `findings-f20_format_survey.md`. So `'advanced'` is read
from the source, not inferred from the current state of play.

### The fix

```sql
-- Confirm the current state first
SELECT league_id, code, level FROM leagues;

-- Name the leagues explicitly rather than updating every NULL row -- defunct or
-- placeholder league rows should not be swept up by a blanket WHERE level IS NULL.
UPDATE leagues SET level = 'advanced' WHERE code IN ('NFLAR','NCAA5');
```

Two rows on a reference table. Trivially reversible.



### Check the whole `leagues` row, not just `level`

`level` is the known gap, but it is unlikely to be the only one — nothing forced values into this
table at insert, which is why `level` is NULL in the first place. `schema.md` §4 describes
`leagues` as carrying sport type and GM name alongside the code; `v_plays_normalized` already
depends on `sport_type` resolving correctly.

```sql
SELECT * FROM leagues;   -- the whole row, both leagues
```

`lessons.md` §11 is precisely this habit: read the table's complete definition and contents, not
only the column you came for. Two redundant columns were added to this schema by checking the
specific thing in mind rather than the whole picture. Anything else NULL here is either a second
one-line fix or a finding worth its own note.

### Explicitly NOT in scope: a ruleset column on `plays`

**Do not add one.** It is derivable through `plays.game_id → games → weeks → seasons → leagues`
— the same join chain `v_plays_normalized` already uses for `sport_type` — so a stored column
would denormalise a distinction that is **confirmed not to change outcomes**: college `basic`
6.835 vs `advanced` 6.845 average yards, measured Aug 2026.

Adding a column by reasoning about a feature rather than establishing that it is needed is the
`lessons.md` §11 mistake, which has already cost this project two redundant columns
(`franchises.coach_user_id`, `raw_uploads.id_user`).

**The point of Part 1 is narrow:** stop the ruleset fact living exclusively in
`legacy_play_log.ruleset_level`, where it dies when the legacy table is eventually retired.

---

## 2. Part 2 — rows disappearing from the playcall views through three-valued logic

The `v_playcall_*` views filter on:

```sql
play_category   <> 'ST'
formation_code  NOT IN ('P','X','F')
```

Both evaluate to **NULL, not TRUE**, when the column is NULL — so any row with a NULL in either
column silently drops out of the aggregates without having been filtered on purpose.

**10 rows today: 5 NFLAR, 5 NCAA5.**

**Intended action is a decision, and possibly no code.**

| If the rows are… | Then |
|---|---|
| Junk | Exclusion is the right behaviour. **No view change** — add a DDL comment recording that it is deliberate |
| Real plays | The filters need `IFNULL(...)`, which means **editing views signed off under Feature 1** |

### Read the view definition before querying its source

`lessons.md` §27 records Feature 15 losing time twice to filters inherited without being read.
The same applies here: **confirm which columns the live view actually filters on, and which
source the NULLs sit in**, before writing the identifying query. The 10 rows may be on the
`legacy_play_log` side, the `plays` side, or both — `v_plays_normalized` normalises across them.

Starting point, to be corrected against the real definitions:

```sql
-- Adjust the source once the view definition has been read
SELECT *
FROM   v_plays_normalized
WHERE  play_category IS NULL
   OR  formation_code IS NULL;
-- expect 10 rows, 5 per league
```

### Two constraints if a view change is needed

1. **The original eight legacy-only views must stay untouched** — an explicit requirement carried
   throughout Feature 1. Any `IFNULL` goes in the `_all` views only.
2. **Any view edit must be reflected in `new_schema.sql`**, which is kept in sync with the live
   database by standing convention — treat divergence as a bug, not a preference.

Worth doing before A9's deployment dump either way, since views are what that dump has trouble
with (`findings-gpbm_uk_host_probe.md` §4). A1 precedes A9, so the ordering already holds.

---

## 3. Consider making the column impossible to skip

`leagues.level` is NULL today because nothing forced a value at insert time. **Populating the two
live rows does not stop the next league being created the same way** — add NCAA7 and it arrives
NULL unless something prevents it.

Worth deciding as part of this task:

- `ALTER TABLE leagues MODIFY level ... NOT NULL` — the strongest version, and cheap on a
  two-row table once both rows are populated. Check the column's current definition in
  `new_schema.sql` first (`lessons.md` §11: read the whole definition, not the part you have in
  mind).
- Or, if NOT NULL is unwelcome for some reason, at minimum a DDL comment recording that a new
  league row must carry a `level`, and what the three known values are.

This is the part that survives. The `UPDATE` fixes today; the constraint fixes the next time.

## 4. Done means

- [ ] `leagues.level` populated for both live leagues
- [ ] A decision recorded on whether the column becomes `NOT NULL`
- [ ] A **recorded decision** on the 10 rows — either a view change, or a DDL comment explaining
      why not. Silence is not an outcome (`lessons.md` §21)

## 5. Verification

```sql
-- Part 1
SELECT code, level FROM leagues WHERE code IN ('NFLAR','NCAA5');
-- expect 'advanced' on both, no NULLs

-- Part 2, only if the views were changed: the aggregates should move by exactly the
-- rows deliberately admitted, and by nothing else. Capture counts before and after.
```

If the views are changed, re-run whatever row-count check Feature 1 used for its grain
verification — a total moving is not evidence of correctness, only that something moved
(`HANDOVER-2026-08-11.md` §6: check the shape, not the total).

## 6. Suggested first steps

- [ ] `SELECT * FROM leagues;` — the whole row for both leagues, not just `level`. See what is
      actually there before changing anything
- [ ] Run the `UPDATE`, then decide on `NOT NULL` (§3). Part 1 is finished at that point
- [ ] Read the live definition of one `v_playcall_*` view — do not trust this document's
      paraphrase of its filters
- [ ] Identify the 10 rows and **look at them** before deciding anything
- [ ] Record the decision either way

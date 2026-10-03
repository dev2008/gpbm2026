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

# Findings — Feature 20 turn-file format survey

**Run:** 12 Aug 2026, against six turn files supplied by Alan spanning in-league NCAA5 2003 to
NFLAR 2008, plus `NFLAR-PE_s2032_w01_vs_Packers.txt` from the project as the modern reference.

**Purpose:** answer the open question in `task-20-legacy_reparse_candidates.md` §6 Q3 — "has the
parser been validated against 2003-era file format?" — and size the parser work in Feature 20.

**Headline:** the play-by-play format has **not changed** between 2003 and 2032. The obstacle is
somewhere else entirely, it is smaller than expected, and it is a single well-defined piece of
work.

---

## 1. The sample

| | Turn | In-league | Wk | Engine | Play lines | Q1/Q2/Q3/Q4/OT | Fixture |
|---|---|---|---:|---|---:|---|---|
| A | NCAA5-PI | 2003 | 6 | 2.17 | 140 | 38/37/28/37/0 | Pittsburgh Panthers vs Michigan Wolverines |
| B | NCAA5-OH | 2003 | 2 | 2.17 | 148 | 35/42/36/35/0 | Ohio State Buckeyes vs USC Trojans |
| C | NFLAR-PE | 2005 | 2 | **2.15** | 161 | 38/45/39/39/0 | Washington Redskins at Philadelphia Eagles |
| D | NFLAR-PE | 2008 | 3 | 2.17 | 155 | 38/45/39/33/0 | Chicago Bears vs Philadelphia Eagles |
| E | NFLAR-MV | 2008 | 12 | 2.17 | 162 | 40/45/37/40/0 | Seattle Seahawks vs Minnesota Vikings |
| F | NFLAR-MV | 2005 | 18 (playoff) | 2.17 | 157 | 39/47/36/35/0 | Washington Redskins vs Minnesota Vikings |
| — | NFLAR-PE (reference) | 2032 | 1 | 2.17 | 143 | — | Philadelphia Eagles vs Green Bay Packers |

Play counts of 140–162 sit right alongside the modern reference's 143. Nothing here is thin.

**Two of the six are live Feature 20 targets.** File B is NCAA5 2003 wk2 — game **287**, one of
the 21 category-C games, and one of the 16 identity-blocked ones (playing as USC Trojans;
`franchises.label` reads Texas Longhorns today). File E is NFLAR 2008 wk12, the week holding
games **3383** and **3388**.

---

## 2. The play-by-play format is byte-identical to 2032

Every check came back the same:

- **Column header.** All six files and the 2032 reference carry the identical string:
  `<Z> time side fld down/yards   off  def   result<T> score<C><L.54.1>`
- **Column offsets.** The `formation / off-call / def-call` triple sits at character offset
  **28** in all six files and at offset 28 in the 2032 reference. Not "similar" — the same.
- **Line terminator.** `<L>` on every play line, both eras.
- **Multi-line touchdowns.** The continuation line (39 spaces, then `is touchdown …`) is present
  and identically shaped in all six — 2 to 11 per file.
- **Score markup.** `<Z>` … `<T>score<C>` on scoring plays, both eras.
- **Kickoff lines** carry no formation/down and are shaped identically in both eras.

`lessons.md` §4/§5 records the QB-replacement merge, multi-line touchdowns and kickoff formation
synthesis as hard-won parser behaviour. **All of it applies unchanged to 2003 files.**

### Engine versions: two, and only one boundary

Only **2.15** and **2.17** appear. Version 2.17 runs from Feb 2005 (file D's print date) through
June 2024 (the 2032 reference) — effectively the entire archive. Only file C is 2.15, printed
July 2002.

Note this is real-world date, not in-league season: files C and F are both in-league NFLAR
season 2005 but sit either side of the version change, C printed 31/7/02 and F 19/3/03. **A
season can straddle the boundary**, so era must be read from the file's own version string, not
inferred from the season.

**What 2.15 lacks, relative to 2.17:** the `NFLAR-PE, Turn N` line, and the whole Turnsheet
block. The play-by-play block, League Report header and Standings block are unchanged.

This is the finding that most changes the estimate. The roadmap assumed 1–3 distinct format eras
each needing its own handler. In practice there is **one format** with a trivial variant.

---

## 3. The actual blocker: no `<BK.>` markers

**None of the six files contains a single `<BK.` marker.** The 2032 reference has eleven.

`operational_hooks.php` splits an upload into `raw_upload_blocks` with one row per `<BK.>`
marker. On these files it produces **zero blocks** — and every extraction page reads blocks, not
raw text. So all three extract pages find nothing to work with, and they fail *silently*, in the
same shape as every other silent-skip defect in this project.

**This is an ingestion-layer problem, not a parser problem.** The parser was never the obstacle.

### Block synthesis is feasible, and was tested

Splitting on the page-break markers (`<P>` / `<P.70>`) and classifying each chunk by its own
heading reproduced the modern block set exactly on all three regular-season files tested:

```
Team Report · Roster · 1st Quarter · 2nd Quarter · 3rd Quarter · 4th Quarter
· League Report · Standings · Scouting Report · Scouting Report - Game Summary · Turnsheet
```

Classification anchors used, all stable across both eras:

| Block | Anchor |
|---|---|
| Team Report | `turn credits =` |
| Roster | `<U>Current Roster<UC>` |
| *N*th Quarter | `<B>1st Quarter` … `<B>4th Quarter` |
| League Report | `League Report  Gameplan` |
| Standings | `Week \d+ Standings` |
| Scouting Report | `<U>Scouting Report<UC>` |
| Game Summary | `Game Summary` |
| Turnsheet | `TURNSHEET` |

**Two refinements needed before this is production-ready:**

1. **The box score is its own chunk under this rule but belongs to the 4th Quarter block.** In
   the modern file a `<P>` precedes `<E><ST.66>` but no `<BK.>` does, so the box score falls
   inside `4th Quarter`. The synthesiser must merge it, or `extract_games.php` loses the
   own-game box score.
2. **Playoff weeks are shaped differently.** File F produced two `League Report` chunks, no
   `Standings` block at all (correct — a playoff week has none), and a merged final chunk
   carrying both Team Report and Game Summary anchors. Regular-season weeks were clean; playoff
   weeks need a specific pass.

---

## 4. Overtime — encouraging, not proven

None of the six own-games went to overtime, so **the own-game OT block format remains
unconfirmed**. This sample cannot close category D's central question.

One positive signal: file C's *Game Summary* block (drive grain, the next opponent's last game)
does contain an `Overtime` section, and its first drive is stamped **`60:00`** — the clock
continues past 59:59 rather than resetting. That matches how `plays` already stores live OT
(`time_gone_seconds` 3600–4498) and is consistent with `lessons.md` §29's conclusion that OT was
lost at ingest rather than never printed.

**Still worth doing exactly what `task-20` says:** validate on one real category-D file before
committing to the other fourteen. Ask Alan for one of the 15 — 2005 wk4, 2005 wk13, 2013 wk1 or
similar — specifically because it went to overtime.

---

## 5. Coverage: play-by-play exists only for coached games

`extract_playbyplay.php` parses **only the receiving coach's own game**, not every game that
week. File E is the NFLAR 2008 wk12 turn and holds play-by-play for Seattle v Minnesota only —
even though its League Report covers the whole league that week.

**This is the general rule, not a parser limitation** (Alan, 12 Aug 2026): *play-by-play will
only ever exist for games the family coached in.* It has always been true, it applies to the
legacy archive exactly as it applies to the live pipeline, and it is a permanent property of the
data rather than a gap to be closed.

**Two consequences.**

**(a) It resolves the Feature 20 coverage question by construction.** All 43 targets already
hold rows in `legacy_play_log`. Rows only ever came from a turn file, and turn files only ever
exist for coached games — so all 43 are family games and a file existed for every one of them.
No coverage query is needed. What remains open is only whether Alan or Gordon still *has* each
file, which is `task-20` §6 Q1 and unchanged.

**(b) It reframes Feature 14, and this matters more.** `legacy_play_log` covers **1,325 distinct
games** against roughly 9,969 in the migration — about **13%**. That is not an archive with
holes in it; it is a complete record of a deliberately narrow slice.

So in `game.php` and `replay.php`, *"no play-by-play for this game"* is the **normal state for
roughly seven games in eight**, not an error condition. The UI should treat it as ordinary and
expected — no "missing data" framing, no apology, no implication that something failed. Feature
14's data-quality tier marker needs to distinguish three states, not two:

| State | Meaning |
|---|---|
| `plays` rows | live parser, full fidelity — `play_seq`, `quarter`, `score_after` |
| `legacy_play_log` rows | coached game, older ingest, lower fidelity |
| no rows at all | **not a coached game — expected, ~87% of games** |

Collapsing the third state into "missing" would misrepresent the archive on the majority of
pages the feature touches.

---

## 6. Minor mechanical notes

- All six files are **CRLF**. Four carry a trailing `0x1A` (DOS EOF); two also carry a `0x00`.
  Harmless, but they make `file(1)` report "data" rather than "text" and want stripping on
  ingest.
- Email headers vary in shape — some `Subject:` / `From:` / `Date:` on separate lines, others
  `From: <addr>` inline. Anchor identification on `<STARTREP>` onward rather than on the header.
- File C (2.15) has **no `Turn N` line**. `operational_hooks.php`'s identification chain reads
  week from the League Report header and league/season from the Team Report header, both of
  which are present and unchanged — so this should not break identification. Confirm it directly
  rather than assuming.

---

## 7. What this does to the Feature 20 estimate

| Piece | Before survey | After survey |
|---|---|---|
| Format survey | 2–4h | **done** |
| Build recovery page + worklist | 8–12h | 8–12h |
| Format handling | 4–10h **× 1–3 eras** = 4–30h | **one block-synthesis shim, 4–8h** |
| Category D (15 games) | 5–8h | 5–8h |
| Category A (7 games) | 2–4h | 2–4h |
| Category C (21 games) | 6–10h | 6–10h |
| **Total** | **27–68h** | **25–42h** |

The top end falls by about 26 hours, almost all of it from establishing that there is one format
rather than several.

**The shape of the work also changed.** It was scoped as "extend the parser for old play
formats". It is actually "synthesise `<BK.>` blocks for files that predate the marker" — a
pre-ingest normalisation step that leaves `extract_playbyplay.php` completely untouched, which
is exactly the design `task-20` §5a point 1 asked for.

That shim is also **reusable beyond Feature 20**: any pre-marker turn file anywhere in the
archive becomes ingestible through the normal pipeline once it exists.

---

## 8. Open questions this survey did *not* close

1. **Own-game overtime block format** — no OT game in the sample. Needs one category-D file.
2. **Do turn files exist for all 43?** Six files answer six weeks. `task-20` §6 Q1 stands.
3. **Per-game family-franchise coverage** — §5 above, answerable in SQL today.
4. **Anything older than 2003 / between 2008 and 2032** — the sample has a gap from in-league
   2008 to 2032. Category D reaches 2029, which is inside that gap. Engine 2.17 spans it on both
   sides, so the risk is low, but it is untested.

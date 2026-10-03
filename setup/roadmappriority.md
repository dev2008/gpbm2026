# Gameplan PBM — Go-Live Plan & Effort Estimates

**Written:** 12 Aug 2026. Supersedes the phase-ordered draft of the same date.
Covers the eleven open `task-*.md` docs plus the un-scoped backlog items in `todo.md`.

---

## 0. Strategy, as decided 12 Aug 2026

| Decision | |
|---|---|
| **Go live at the end of Phase 1** | The legacy pipeline is broken. The new site wins by existing; the functionality gap gets closed on a live system rather than before one. |
| **F20 moves to the very end** | Missing play-by-play from very old turns costs almost nothing. It was over half of Phase 1 by effort and nothing depends on it. |
| **Audience: all league coaches, read-only** | They browse; they don't log in to upload. |
| **Capacity: front-load, then drop off** | ~16h/week to the go-live gate, then minimal while Civ takes priority. SBLPA not started. |

Ordering principle unchanged: correcting missing or wrong data first, then enhancements to
existing features, then new features — with a go-live gate inserted at the end of the first
group.

**What the estimates mean.** Elapsed hours of Alan working a chat, including prompting, reading
output, reviewing SQL before it runs, running it, testing, deploying to MountZion, and writing
up `lessons.md`. One feature per chat; a chat up to ~6h is a day, longer runs over two.

---

## 1. Sprint A — to go-live

| # | Item | Est. | Go-live critical? |
|---:|---|---|---|
| 1 | **F21** — `leagues.level` + playcall NULL filters | 2–3h | No — trimmable |
| 2 | **Upload hygiene** + confirm turn files aren't web-reachable | 2–3h | **Yes** |
| 3 | **F16a** — the two `v_current_standings` defects | 3–4h | **Yes** |
| 4 | **F11** — build `franchise_identities` (3 chats) | 10–15h | **Yes** |
| 5 | **F12** — audit, then rebuild `games.label` | 2–7h | **Yes** |
| 6 | **F13** — `franchise_season_records` writer + sweep | 7–11h | **Yes** |
| 7 | **F19** — Perfect Season honors for NFLAR | 4–6h | No — trimmable |
| 8 | **F17** — remove block B | 2–3h | No — trimmable |
| 9 | **Go-live checks** — see §2 (folds into item 2) | 1–2h | **Yes** |
| | **Sprint A total** | **33–54h** | |
| | *Lean version — items 1, 7, 8 deferred* | **25–42h** | |

At 16h/week: **2.1–3.4 weeks → go live 1–10 Sep 2026.**
Lean version: **1.6–2.6 weeks → go live 28 Aug – 4 Sep 2026.**

Items 1, 7 and 8 are trimmable because none is visible to a reader. F21 sets a NULL column and
resolves ten rows; F19 adds an honor that is currently absent rather than wrong; F17 removes
rows already unreachable from the app. Deferring all three buys about a week and loses nothing
a coach would see.

---

## 2. The go-live gate — small, and smaller than an earlier draft claimed

`security.md` §1 already answers the audience question: **"a player-owner who isn't family is
just an ordinary member of the public"**, GM status included. So "all league coaches, read-only"
is exactly what the existing design serves. They are group 3. **F18 is not a go-live
prerequisite and stays in the post-go-live backlog.**

### ⚠️ `security.md` §4 is stale — correct it

§4 currently says:

> **Not yet tested against a real login** — worth confirming
> `$current_user_is_administrator`/`$current_id_group` actually behave as expected for both an
> Administrator account and a guest before relying on this.

**This is out of date. The gates are tested and confirmed working** (Alan, 12 Aug 2026) — he
routinely starts sessions logged out, so the guest path has been exercised repeatedly in a real
browser, and it is standard DaDaBIK functionality besides. The caveat describes the state of the
document, not the state of the code.

An earlier draft of this plan read that line as current and built a 4–7h go-live gate around
re-testing it. That was wrong, and it is worth naming why: **it is the exact inverse of the F12
error in §3.** There, a to-be-checked became a fact by repetition. Here, a confirmed fact stayed
recorded as to-be-checked. Same underlying failure — the document drifting from the code — and
`lessons.md` §21's rule cuts both ways: a doc is not evidence that something is broken any more
than it is evidence something was fixed.

**Correcting that line in `security.md` is the real action item here**, and it is five minutes.
Left alone it will cost the next session the same detour it cost this one.

### What genuinely remains — partly tested 12 Aug 2026

Alan ran the webserver checks against `gpbm.local`. Results:

| Test | Result |
|---|---|
| `…/custom_php_files/extract_standings.php` direct | ✅ Blank — `if(!defined('custom_page_from_inclusion')) { die(); }` |
| `…/custom_php_files/` directory | ⚠️ **Lists every file** with sizes and dates |
| `…/dadabik/uploads` directory | ✅ 403 — but see below |
| Legacy `gameplan.org.uk` equivalent | ✅ 403 |

**Good news worth documenting.** The inclusion guard means custom pages cannot be executed
directly at all, independent of DaDaBIK's page permissions. §4's "there's currently no fallback
that protects a page which simply forgets the check" is accurate about the *content* gate but
not about direct execution — `security.md` should record the distinction.

**Directory indexing on `custom_php_files/`** is low severity by itself: Apache executes the
PHP rather than serving source, so nothing leaks but the inventory. It matters for what it
revealed — **`team_diag.php`, 22K, undocumented**, appearing in neither `security.md` §3 nor
`style-guide.md` nor `todo.md`. A diagnostic page is the shape that dumps data without
ceremony. Establish whether it is registered and gated, then gate, unregister or delete it.
`example.php` is the DaDaBIK sample and should go.

**The uploads test is not finished, and it is the one that matters.** A 403 on the *directory*
proves listing is denied; it does not prove a *file inside* is unreadable — different Apache
behaviours, and the 403-rather-than-404 confirms the directory sits inside the docroot. The
filenames are also highly guessable: `task-20-upload_hygiene.md` records
`dadabik_tmp_file_Game Report from Ab Initio Games NFLAR-MV, Turn 15_148.txt`, which is
league-team plus turn number plus a small integer. Nobody needs a listing to find one.

Take a real filename from `raw_uploads.original_filename` and request it directly. A turn file
is a coach's full gameplan — the same competitive information §3's page gates protect — so if it
serves, it routes around all of them. Preferred fix in that case is moving uploads outside the
docroot, not relying on indexes-off plus unguessable names.

**And repeat all of it on the actual deploy target.** `gpbm.local`'s config is demonstrably not
production's, and production's is not necessarily the new vhost's.

**Estimate still 1–2h**, folded into Sprint A item 2 — which is where
`task-20-upload_hygiene.md` §4 put it in the first place.

`migration_add_id_user.sql` is *not* needed for go-live — it exists for group-2 owner
permissions, which read-only coaches don't require. It stays with F18.

---

## 3. Reader-visible correctness, and why items 3–6 are the go-live-critical ones

`security.md` §3 lists what the public sees: **game results and scores, standings, summary
rosters.** Everything in Sprint A items 3–6 lands inside that set:

| Item | What a reader sees today |
|---|---|
| F16a | Standings can silently mix weeks — one franchise shown at an older week alongside everyone else's current figures, with nothing on screen to say so |
| F13 | `team.php`'s season records and "most wins in a season" are frozen at the original migration — demonstrably two weeks short for NFLAR 2034 |
| F11 / F12 | Historical games may name present-day identities — see the audit caveat below |

Read-only external readers raise the stakes on all three. A wrong standings table or a stale
season record stops being a family annoyance and becomes the site being wrong in public.

### ⚠️ F12's scope is still not settled — audit before building

An earlier draft called F12 "the largest user-visible defect", ~4,000 games naming the wrong
team on every page. **That is not supported by anything anyone has checked** (Alan, 12 Aug
2026). It came from `schema.md` §13 and `HANDOVER-2026-08-11.md` §4, both stating "Affects
`game.php`, `team.php`, `coach.php`" as settled — but both trace back to `task-12` §5, which
says those pages **may** derive names independently and that this should be audited *before*
assuming a `games.label` fix is sufficient. A to-be-checked became a fact by repetition.
`lessons.md` §21: this document is not evidence that anything is true; only the code is.

Evidence that `games.label` is not reader-facing:

- `new_schema.sql`'s comment on `seasons.label` says it "exists purely so this table has an
  unambiguous single-column display for Dadabik lookups", explicitly grouping `games.label` and
  `weeks.label` under the same pattern — an admin record-display field, not front-end output.
- The `date('Y')` episode. That bug stamped every live-parsed game "NFLAR 2026" regardless of
  season and survived long enough to be recorded in `lessons.md` §9 as fixed when half of it
  never was. It was found sideways, verifying union views. Forty-eight games showing a visibly
  wrong year on the front end would not have hidden that long.

**The defect splits in two:**

| | What | Confirmed? | Who sees it |
|---|---|---|---|
| **(i)** | `games.label` holds present-day identities — 3,487 NFLAR + 516 NCAA5 | Yes, at data level | Possibly DaDaBIK admin views only |
| **(ii)** | Pages resolve names via `franchises.label` — `task-11` lists "`team.php` game lists → historical games display current identities" | Asserted, not audited | Readers, if true |

F12 as scoped fixes (i). If (ii) is where the visible problem lives, rebuilding the stored
column changes nothing a reader sees. **So step 1 is the audit** — grep `game.php`, `team.php`,
`coach.php`, `replay.php` for `games.label` and `franchises.label`, ~1h. It decides whether item
5 is a 5–7h reader-visible fix, a 2h admin-only tidy-up, or differently-shaped work aimed at the
pages. With external readers arriving, (ii) is the one that matters.

**F11 is unaffected either way.** It fixes the silent-skip defect in three parser franchise
lookups (`extract_games.php:214`, `extract_standings.php:220`, `operational_hooks.php:188`),
where a game whose week-grain identity differs from the current label is dropped with no error.
That is latent corruption of future uploads, and it is reason enough on its own.

### F19 must follow F13 — a dependency the task docs don't record

`task-19`'s backfill query finds qualifying seasons with
`fsr.wins = 16 AND fsr.losses = 0 AND fsr.ties = 0` against `franchise_season_records` — exactly
the table F13 shows to be stale. Run it first and it silently misses any season the table hasn't
caught up with. Happier corollary: F13's writer sweep ("for every table, which code writes it?")
answers F19's open Piece 3 question as a by-product.

---

## 4. After go-live — reduced capacity

Nothing below is a blocker for anything. Order is by value once the site is live.

| Order | Item | Est. |
|---:|---|---|
| 1 | **F14** — legacy play-by-play in `game.php` / `replay.php` (2 chats) | 8–12h |
| 2 | **Historical navigation** — F16b + F6 + F10 scoped as one piece | 8–16h |
| 3 | Trimmed Sprint A items, if deferred — F21, F19, F17 | 8–12h |
| 4 | **F18** — `coaches.id_user` auto-population | 6–10h |
| 5 | **F4 / F5** — Stats and Historical Stats pages | 12–24h |
| 6 | **F2 / F3** — Scouting Report parsers | 20–38h |
| 7 | **F7 / F8** — Roster pages | 20–40h |
| 8 | **F20** — recover the 43 damaged legacy fixtures (5 chats) | 25–42h |
| | **Total remaining** | **~107–194h** |

**Sensitivity to capacity**, since this is the part that competes with Civ:

| Weekly average | Time to clear the post-go-live backlog |
|---|---|
| 4h | 27–49 weeks (~6–11 months) |
| 8h | 13–24 weeks (~3–6 months) |
| 16h | 7–12 weeks |

### Notes on the post-go-live items

**F14 first** — it is the payoff for the whole backfill, 202,858 linked plays currently
invisible, and Feature 15 removed most of its unknowns. But note it is **family-only content**
(`security.md` §3), so it adds nothing for the new read-only audience. Historical navigation
(order 2) is the one that improves what *they* see.

**F14's scope changed 12 Aug 2026.** Play-by-play only ever exists for games the family coached
in. `legacy_play_log` covers 1,325 distinct games against ~9,969 — about **13%**. That is not an
archive with holes; it is a complete record of a narrow slice. So *"no play-by-play for this
game"* is the normal state for roughly seven games in eight, and F14 needs three display states,
not two: live `plays` rows, legacy rows, and **no rows because it was never a coached game** —
the last presented as ordinary and expected. Collapsing that into "missing" would misrepresent
the archive on most pages the feature touches.

**Historical navigation folds three backlog items.** F16's selector, F6 (Historical Standings)
and F10 (historical season results) are three views of one problem — there is no path to
anything that isn't the current season. `task-16` §2 flags the F16/F6 overlap and `todo.md` F10
flags the F10/F6 overlap. Separately: 3–6h + 4–8h + 4–8h = 11–22h. Together: 8–16h. The first
hour is a decision, not code — what does Current show, what does Historical show — and it is
worth making as a standalone conversation.

**F7 / F8 are the least reliable numbers here.** Rosters imply a `players` entity, and nothing
in `schema.md`'s entity overview suggests one exists — so this is schema design, plus a parser,
plus free-agent processing, which is the first user action in the system that *writes* state
rather than parsing a turn file. `security.md` §5 also flags that the detailed roster needs the
same `$current_user_is_administrator` gate `game.php` uses. Treat these as placeholders until
scoped.

**F9 (Game page)** is still open in `todo.md`, but `game.php` and `replay.php` both exist and
F10's note says the gap was "surfaced while testing Feature 9". Verify and close rather than
estimate.

### F20, when it eventually comes round

The format survey is **done** — see `findings-f20_format_survey.md`. The play-by-play format is
byte-identical to 2032; the blocker is that pre-2032 files carry no `<BK.>` markers, so
`operational_hooks.php` produces zero blocks and every extract page silently finds nothing. The
fix is one pre-ingest shim, 4–8h, leaving `extract_playbyplay.php` untouched. Coverage is
resolved by construction: all 43 hold legacy rows, rows only ever came from turn files, turn
files only exist for coached games.

| Piece | Scope | Est. |
|---|---|---|
| 20b | Recovery page + `migration_f20_recovery_targets` worklist | 8–12h |
| 20c | `<BK.>` block-synthesis shim + playoff-week pass | 4–8h |
| 20d | Category D — 15 games missing overtime. **Validate on one OT file first** | 5–8h |
| 20e | Category A — 7 short fixtures | 2–4h |
| 20f | Category C — 21 games with no `result_text` | 6–10h |

Still open: no file in the survey sample went to overtime, so the own-game OT block format is
unconfirmed. One category-D turn file closes it.

---

## 5. Summary

| | Hours | At 16h/wk |
|---|---|---|
| **Sprint A — to go-live** | 33–54h (lean: 25–42h) | **2.1–3.4 weeks → 1–10 Sep 2026** |
| Post-go-live backlog | 107–194h | 7–12 weeks at 16h; 3–6 months at 8h |
| **Total** | **140–248h** | |

**The three things that decide whether go-live is safe:**

1. **Can a turn file be fetched by URL?** Partly tested — the uploads *directory* returns 403,
   but no *file* inside it has been requested yet, and the filenames are guessable. This is the
   one exposure DaDaBIK's page permissions don't cover, and a turn file is a full gameplan.
   Minutes to settle.
2. **`team_diag.php`** — an undocumented 22K diagnostic page the directory listing exposed.
   Registered? Gated? Neither is currently known.
3. **F12's audit** — one hour that decides whether ~4,000 historical games display the wrong
   team name to an audience that now includes the coaches those teams belong to.

All three are on the deploy target, not `gpbm.local`.

The access gates themselves are **not** on this list: tested, confirmed working, and the
`security.md` line saying otherwise is what needs fixing, not the code.

**The thing most likely to be underestimated:** nothing in Sprint A, on current evidence. The
larger risk is the post-go-live backlog quietly stalling at 4h/week while Civ takes priority —
which is fine if it's a decision, and a problem if it's a drift.

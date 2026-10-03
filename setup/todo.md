# Gameplan PBM — Roadmap & Sprint Tracker

## 🎯 Current High-Level Goal
Migrate Gameplan PBM's legacy MySQL schema and manual tooling to a normalized MariaDB schema
(`gplan_pbm`) with a Dadabik 13.5 front end, plus a set of staged, manually-triggered parser
pages that turn newly-arriving turn files into rows in that schema.

**Deploy target: https://gpbm.uk/** (hosting provider placeholder as at 12 Aug 2026).
`gpbm.local` / MountZion is the internal build box, not the public site.

**Strategy decided 12 Aug 2026: go live at the end of Sprint A.** The legacy pipeline is broken,
so the new site wins by existing; the functionality gap gets closed on a live system rather than
before one. Audience at go-live is **all league coaches, read-only** — which `security.md` §1
already covers, since a non-family player-owner is just an ordinary member of the public.
Capacity is front-loaded: ~16h/week to the go-live gate, then reduced while Civ takes priority.

## 💾 Project Reference Documents
* `schema.md` (schema design & the historical migration; **§12 is the franchise / team-identity
  / team-code model — read it before writing any query that maps a team name or code to a
  franchise**)
* `lessons.md` (the live-parser build phase: Standings/Games/Play-by-Play parsers,
  `play_text_patterns`, `team_codes`, and every hard-won lesson from building them)
* `security.md` (access control — what's public vs family-only, and how it's gated).
  **§4's "Not yet tested against a real login" caveat is STALE** — the gates are tested and
  confirmed working (Alan starts sessions logged out routinely). Correct that line.
* `roadmap-priority.md` (**the go-live plan** — ordering, estimates, dependencies, capacity
  sensitivity. Start here for "what next and how long".)
* `findings-f20_format_survey.md` (turn-file format survey, Aug 2026: the play-by-play format is
  unchanged since 2003; the blocker is absent `<BK.>` markers)
* `findings-legacy_playbyplay_backfill.md` (the `legacy_play_log.game_id` backfill)
* `HANDOVER-2026-08-13.md` (**start here if picking this up cold**) — the go-live strategy
  decisions, the two real deployment problems, what will surprise you, and the doc-drift pattern
  worth knowing about before trusting any of these documents.
* `findings-gpbm_uk_host_probe.md` (the target host, fully characterised: grants, limits, paths,
  CDN, protection mechanisms)
* `HANDOVER-2026-08-11.md` (state at the close of the backfill session). **§3's claim that
  `new_schema.sql` and `schema.md` were never updated in the repo is stale — that merge has been
  done.** §4's ordering is superseded by `roadmap-priority.md`.
* `task-20-legacy_reparse_candidates.md` (the 43 damaged games)

---

## 🏃‍♂️ Active Sprint — **Sprint A: to go-live**

Target: **3–13 Sep 2026** at ~16h/week (31 Aug – 8 Sep if the trimmable items are deferred).
Total 36–61h, including the newly-added A9 deployment item. Take these in order; each is its
own chat.

* [x] **A1. Feature 21** — `leagues.level` + playcall NULL filters · **DONE 14 Aug 2026**
  * See `task-21-ruleset_level.md`. Full write-up in `schema.md` §15.
  * **Part 1 complete and verified.** `leagues` went 2 rows → **9** — all nine `league_code`
    values in `legacy_play_log` now have a row, not just the two live leagues. `level` populated
    and converted to `ENUM('basic','advanced') NOT NULL`. Values read from the archive
    (`ruleset_level`, 247,241 rows, no league carrying two values) and confirmed independently by
    Alan: **set by the GM at league creation, immutable thereafter**. NCAA6/7/8 are `basic`.
  * Ran as `f21_stage2a_write.sql` (backup + snapshot + insert + update) and
    `f21_stage2b_constraint.sql` (the `ALTER`). All 14 filtered views verified unchanged against
    a stored before/after snapshot. **Artefacts to drop on close — five:** `bak_f21_leagues`,
    `bak_f21_leagues_notes`, `bak_f21_leagues_notes_pre2e`, `migration_f21_view_snapshot`,
    `migration_f21_coldef_snapshot`. Note `bak_f21_leagues_notes_pre2e` is **not** a valid
    rollback path despite its name — it was recreated after the change it was meant to precede,
    and its `TABLE_COMMENT` says so. `bak_f21_leagues_notes` is the valid one.
  * **Part 2 DECIDED 14 Aug 2026 — no view change.** All ten rows that vanish from the playcall
    and relevant aggregates through three-valued logic are the *same play*: `"quarterback flop,
    downs the ball to run out the clock"` at 59:22-59:53, the last snap of regulation, with
    `play_category`, `formation_code`, `offense_call_code` and `defense_call_code` NULL together.
    Real plays carrying **no play call** — exactly what a play-call tendency aggregate must not
    contain, so the exclusion is correct. An `IFNULL` would admit a NULL/NULL group row into all
    seven `_all` views. Recorded as a column `COMMENT` on `legacy_play_log.play_category` and
    `.formation_code` (`f21_stage2d_part2_comment.sql`) and in `new_schema.sql`, so the next
    person to notice the three-valued logic does not helpfully repair it.
    *If it is ever revisited, the change surface is **7** `_all` views, not the 3 the task doc
    names: the `v_relevant_*_all` views carry identical filters and all ten rows involve a
    tracked team.*
  * **`leagues.active` now has a definition** (stage 2g): `1` = new data actively arriving,
    `0` = historical data only, nothing new coming in. Explicitly *not* a claim about whether the
    league still runs. Audited against `raw_uploads` and the flag agreed on all nine — NFLAR 33
    uploads / 3 days, NCAA5 6 / 11 days, the seven 0 and NULL. **Set by hand, nothing maintains
    it**; audit rather than trust.
  * The seven inactive leagues are **archive-only, measured**: zero rows in `seasons`,
    `franchises`, `coaches`, `rivalries`, `raw_uploads`, `game_types`, `franchise_honors`.
  * **Still open — one thing, and it is not SQL:**
    1. **DaDaBIK field re-sync for `leagues`.** `zpbm_forms` still holds `level` as
       `type_field=text`, `required_field=0`. After the `ALTER`, the edit form offers a free-text
       box the database rejects. Re-sync in the DEV Area; compare against `leagues.sport_type`,
       already `select_single_radio` / required.
  * **`leagues.active` has no defined meaning** — `1` on the two live leagues, `0` on the other
    seven. "Tracked by this project" or "currently running"? The column comment records that it
    is undecided. **Do not filter on it for anything user-visible until it is.**
* [ ] **A2. Upload hygiene + web-exposure checks** · 2–3h + 1–2h · **go-live critical**
  * `dadabik_tmp_file_*` to `.gitignore`; test DaDaBIK delete on a known-duplicate `raw_uploads`
    row; decide whether rejected duplicates keep their file (the row stays regardless).

  **Tested 12 Aug 2026 on `gpbm.local` (dev box — NOT the deploy target):**

  | Test | Result | |
  |---|---|---|
  | `…/custom_php_files/extract_standings.php` direct | Blank — killed by `if(!defined('custom_page_from_inclusion')) { die(); }` | ✅ |
  | `…/custom_php_files/` directory | **Lists every file**, with sizes and dates | ⚠️ |
  | `…/dadabik/uploads` directory | 403 Forbidden | ✅ *(partial — see below)* |
  | Same path on legacy `gameplan.org.uk` | 403 Forbidden | ✅ |

  * ✅ **The inclusion guard is a real second layer.** Custom pages cannot be executed directly,
    independent of DaDaBIK page permissions. `security.md` §4's "no fallback that protects a
    page which simply forgets the check" is true of the *content* gate but not of this — record
    the distinction there.
  * ⚠️ **Directory indexing is on for `custom_php_files/`.** Low severity by itself (PHP is
    executed, not served as source) but it discloses the full page inventory. Production already
    returns 403, so this is a dev-box config gap — **verify on the deploy target regardless**,
    different vhost. Fix is `Options -Indexes`.
  * ✅ **Turn files are NOT servable.** A real upload requested by its exact URL
    (`…/uploads/Game Report from Ab Initio Games NFLAR-MV, Turn 15_152.txt`) returns 403. So the
    protection is a directory-level deny, not merely indexes-off, and the guessable filename
    pattern doesn't matter. **This was the single biggest go-live exposure and it is closed.**
  * ✅ **`team_diag.php` and `example.php` deleted** (12 Aug 2026). `team_diag.php` carried the
    same inclusion guard, but was a self-described *"TEMPORARY diagnostic wrapper"* that set
    `ini_set('display_errors', '1')` — fine while diagnosing, a path/SQL leak if it were ever
    reachable through the normal inclusion path. Gone is better than audited.
  * [ ] `Options -Indexes` on `custom_php_files/` — cosmetic now that the files can neither be
    read nor run, but production already does it and dev should match.

  **Remaining, and it is about the target host, not this one.** `gpbm.local` is MountZion —
  internal, `.local`, unreachable by league coaches. The public target is **https://gpbm.uk/**,
  currently showing the hosting provider's placeholder.

  * ✅ **Source of the 403 established:** `/dadabik/uploads/.htaccess` containing `Deny from all`,
    honoured because `gpbm.conf` sets `AllowOverride All`. It's a `.htaccess`, so it **travels
    with the files**.
  * ✅ **Host probe run on gpbm.uk (12 Aug 2026)** — see `findings-gpbm_uk_host_probe.md`.
    `.htaccess` is honoured, directory indexing is off by default, HTTPS is enforced, all
    required PHP extensions present, MariaDB 10.11.18 with working `STORED GENERATED` columns.
  * [ ] ⚠️ **Rewrite the uploads `.htaccess` in portable form.** `Deny from all` is Apache 2.2
    syntax and only works on MountZion because `mod_access_compat` is loaded. On a 2.4 host
    without it, that directive 500s the whole directory — fail-closed, but broken and hard to
    diagnose. Use the `<IfModule mod_authz_core.c>Require all denied</IfModule>` form (proven to
    work on gpbm.uk by the probe). Change it on MountZion so both boxes match.
* [ ] **A3. Feature 16a** — the two `v_current_standings` defects only · 3–4h · **go-live
  critical**
  * `MAX(week_id)` taken per franchise rather than per league (silently mixes weeks in one
    table); and it assumes id order matches chronological order. Both are correctness and
    independent of any UI work. Standings are public — this is wrong data in the most visible
    place on the site.
* [ ] **A4. Feature 11** — build `franchise_identities` · 10–15h · 3 chats · **go-live critical**
  * ⚠️ **Pass 3 is decided: option (b)** — `extract_games.php` writes an identity row per
    franchise-week from the turn file's own team names. **`gplan_main` / `f_games` is not to
    live on** (Alan, 12 Aug 2026: "it was never my intention for f_games to live on"), and it
    will not exist on gpbm.uk at all. So `label_L1_identities.sql`'s direct read of
    `gplan_main.f_games` is a **build-time-only** step on MountZion, and pass 3 is the only
    identity path that survives deployment.
  * Consequence: **keep `migration_fgames_map`** rather than retiring it per `lessons.md` §24 —
    once `gplan_main` is gone it is the only surviving trace of `f_games`.
  * (a) decide pass 3, build table with passes 1–2 + gates L1.3d / L1.3g · 4–6h
  * (b) `extract_games.php` writes an identity row per franchise-week + historical backfill · 3–5h
  * (c) repoint the three parser franchise lookups at the new table and regression-test · 3–4h
  * Its case does **not** rest on `games.label`: it closes a silent-skip defect in
    `extract_games.php:214`, `extract_standings.php:220`, `operational_hooks.php:188` where a
    game whose week-grain identity differs from the current label is dropped with no error.
* [ ] **A5. Feature 12** — **audit first**, then rebuild `games.label` · 2–7h · **go-live
  critical**
  * ⚠️ **Step 1 is an audit, not the rebuild** (~1h). Grep `game.php`, `team.php`, `coach.php`,
    `replay.php` for `games.label` and `franchises.label`. The claim that this affects those
    three pages is **unverified** — it traces to `task-12` §5's "may … audit before assuming",
    which `schema.md` §13 and `HANDOVER` §4 then restated as settled fact.
  * Evidence suggests `games.label` is a DaDaBIK record-display field, not front-end output
    (`new_schema.sql`'s `seasons.label` comment; and the `date('Y')` bug stamped every game
    "NFLAR 2026" without anyone noticing on screen).
  * If the pages resolve names via `franchises.label` instead, **that** is the reader-visible
    defect and the rebuild fixes nothing they see. With external readers arriving, it matters.
* [ ] **A6. Feature 13** — `franchise_season_records` writer + sweep · 7–11h · **go-live
  critical**
  * Read by `team.php` (season records, "most wins in a season"), written by nothing. Frozen
    since the original migration — two weeks short for NFLAR 2034. Decide view vs stored table,
    build, backfill every affected season.
  * The **writer sweep** ("for every table, which code writes it?") is worth its own chat and
    answers Feature 19's open question as a by-product.
* [ ] **A7. Feature 19** — Perfect Season honors for NFLAR · 4–6h · *trimmable*
  * ⚠️ **Blocked on A6.** Its backfill query filters `franchise_season_records` on
    `wins = 16 AND losses = 0 AND ties = 0` — exactly the table A6 shows to be stale. Run it
    first and it silently misses any season the table hasn't caught up with.
* [ ] **A8. Feature 17** — remove block B · 2–3h · *trimmable*

* [ ] **A9. Deploy to gpbm.uk** · 4–8h · **go-live critical — this item did not previously
  exist, and go-live cannot happen without it**
  * Host probe done — `findings-gpbm_uk_host_probe.md`. Three things break a naive deploy:
  * ⚠️ **No `SUPER` grant → views with `DEFINER` clauses will not import.** At least nine views
    are affected (Feature 1's seven `_all` views plus `v_plays_normalized`,
    `v_relevant_teams_franchise`, `v_current_standings`, and the eight original legacy-only
    ones). Strip on the way out and verify:
    ```bash
    mysqldump --single-transaction --routines --triggers=FALSE gplan_pbm \
      | sed -E 's/DEFINER=`[^`]*`@`[^`]*` ?//g;
                s/SQL SECURITY DEFINER/SQL SECURITY INVOKER/g;
                s/`gplan_pbm`\.//g' \
      > gplan_pbm_portable.sql
    grep -cE 'DEFINER|gplan_pbm' gplan_pbm_portable.sql   # must be 0
    ```
  * ⚠️ **Schema is `gpbmuk-313333c5cd`, not `gplan_pbm`.** Irrelevant to the application —
    everything goes through `$conn`, which already has the database selected, so no custom page
    qualifies a table name. It bites in exactly one place: **MySQL qualifies table references
    inside stored view definitions**, so a view written `FROM plays` dumps as
    ``FROM `gplan_pbm`.`plays` `` and points at a non-existent database after import. The `sed`
    above strips it in the same pass as the DEFINER — same statements, one verification.
  * ⚠️ **`gplan_main` will not exist on the host**, so all data work happens on MountZion and
    go-live is a database migration, not a file copy. Rehearse it.
  * ⚠️ **No `FILE` grant** → no `LOAD DATA INFILE`. Grep the migration/stage scripts for
    `INFILE` first.
  * ✅ **No `TRIGGER` grant** — already anticipated by `new_schema.sql`'s design note. No action.
  * [ ] Establish whether SSH / CLI `mysql` access exists. `legacy_play_log` is 247,241 rows and
    a full dump plausibly exceeds the 128M upload cap and the 300s execution cap via phpMyAdmin.
  * ✅ **PHP switched from EOL 8.0.30 to 8.4** (12 Aug 2026).
  * ✅ **DaDaBIK 13.5 + custom pages on 8.4 is proven, not assumed** — MountZion runs 8.4.24 and
    has been running the whole app on it throughout. Civ's part-build is on 8.4 too. No need to
    fall back to 8.3.
  * ✅ **Version parity between build box and host already holds** — both 8.4.
  * ✅ **gpbm.uk confirmed: PHP 8.4.24, all extensions intact, HTTPS at the app layer,
    `upload_max` 128M / `post_max` 128M / `max_exec` 300 — limits unchanged by the version
    switch.** No import problem. See `findings-gpbm_uk_host_probe.md` §12 for the two corrections
    this went through, and why an invented-but-plausible mechanism briefly displaced a correct
    conclusion (`lessons.md` §25, same shape as the game 9621 false positive).
  * ✅ **StackCDN is in front, and it is NOT caching authenticated responses.** Tested on the
    legacy DaDaBIK site behind the same CDN: logged in shows 186 rows; logging out elsewhere and
    refreshing, or requesting the same URL in incognito, both redirect to login rather than
    serving a cached 200. Mechanism is PHP's `session_cache_limiter` (default `nocache`), which
    DaDaBIK inherits globally. **This was the highest-consequence risk in the deployment and it
    is substantially retired.**
  * [ ] **Residual CDN check, after deployment.** The test above was a whole-page redirect.
    `game.php` is a different shape — a 200 where public and family-only sections share one
    body. Load a game page with play-by-play as Administrator, request the identical URL logged
    out on the real host, confirm the play-by-play is absent. Repeat for `replay.php`.
    Remaining failure mode is benign (a cached public response shown to family = missing your own
    play-by-play, confusing but not a leak).
  * ✅ **`whoami.php` deployed and working** on MountZion and the legacy 20i site (13 Aug 2026).
    Environment matrix recorded in `findings-gpbm_uk_host_probe.md` §14.
  * ✅ **`probe/info.php` merged into `whoami.php`** (13 Aug 2026). `info.php` did not name the
    host it was served from — the very gap that let a stale clipboard copy pass for a real
    result. One file now, two modes: **normal** (included by DaDaBIK, Administrator-only, shows
    the database) and **standalone** (`whoami.php?t=<token>`, for probing a host before DaDaBIK
    exists, no database — credentials in a web-readable file is the `team_diag.php` mistake in
    another costume). **Standalone is off unless `$_cp_whoami_token` is set**, so a deployed copy
    fails closed on the ordinary inclusion guard. Also removes the risk of two copies of the
    required-extension list drifting apart.
  * ✅ **Extensions confirmed on gpbm.uk under 8.4.24** (13 Aug 2026, `whoami.php` standalone):
    all nine present, limits 128M/128M/300s intact, docroot
    `/home/sites/7a/9/917dc0194c/public_html/`, HTTPS reaches the app, `X-Forwarded-For`
    populated. **The environment is now fully characterised on the actual target.**
  * ✅ **`$_cp_whoami_token` set back to `''` on the gpbm.uk copy** (13 Aug 2026). Standalone
    disabled; the web-root copy now fails closed on the inclusion guard like every other page.
  * [ ] **When DaDaBIK is installed, delete the web-root `whoami.php`.** Its permanent home is
    `dadabik/include/custom_php_files/whoami.php`, where the inclusion guard and admin gate
    apply. Two copies with different protection is how `team_diag.php` happened.
  * [ ] ⚠️ **PHP runs as `fpm-fcgi` on both platforms — `php_value`/`php_flag` in `.htaccess`
    will 500 the directory.** Those are mod_php directives. If an ini value needs changing on the
    host, use a `.user.ini` in the directory or the control panel. Same failure shape as the
    Apache 2.2 `Deny from all` risk.
  * ✅ **`X-Forwarded-Proto` concern closed** — 20i's proxy sets `HTTPS` correctly for the app and
    forwards the client IP. Note `REMOTE_ADDR` will be the CDN, so any future IP-based throttling
    or audit logging must read `X-Forwarded-For`.
  * Keep `whoami.php` deployed permanently — host, database, PHP version/SAPI, ini limits and
    required extensions in one click. Answers "which box am I looking at" for as long as two
    environments serve the same content, and re-checks the extension list after any PHP version
    change. Unlike `team_diag.php`, it is meant to persist — hence the admin gate and the
    fail-closed default.
  * [ ] Watch whether the CDN serves stale standings/team pages after a turn is parsed — a
    correctness annoyance, but it would look exactly like a parser failure.
  * ✅ **`probe/` removed from MountZion** — confirmed 13 Aug 2026,
    `ls /var/www/gpbm/public_html/probe/` returns "No such file or directory".
  * ✅ **`probe/` removed from gpbm.uk** — confirmed 13 Aug 2026, `https://gpbm.uk/probe/`
    returns 404. No credential exposure to chase; `db.php` is gone with it.
  * [ ] Add `probe/` to the deploy excludes and `.gitignore` regardless, so a future probe cannot
    ride along.
    It sits in `/var/www/gpbm/public_html/` on MountZion — **the same docroot A9 deploys from** —
    so an rsync or archive would carry `info.php` — now superseded by `whoami.php` — and possibly a
    `db.php` holding live credentials, straight to the public site. A filled-in `db.php` may also still be on gpbm.uk
    from the connection test. Third artefact this session found on disk but in no checklist,
    after `example.php` and `team_diag.php`.
  * **Environment limits differ, in the safe direction** — MountZion `upload_max 2M` /
    `post_max 8M` / `max_exec 30s`, gpbm.uk `128M` / `128M` / `300s`. The build box is the
    tighter one, so anything that runs there will run live. Turn files are 50–80KB, unaffected.
    **Do not rehearse the DB import via a local phpMyAdmin on MountZion** — 2M and 30s will stop
    it long before the data does. Use CLI `mysql`.
  * ✅ **Deploy path known:** gpbm.uk is the **primary domain of its own account**, home
    `/home/sites/7a/9/917dc0194c/`, so it serves from `public_html/` directly — a clean mirror of
    MountZion, not the subdirectory layout the legacy site uses. Separate 20i account from
    gameplan.org.uk, so **no configuration verified there transfers as a guarantee**.
  * [ ] ⚠️ **"Autoscaling Linux" — confirm uploads persist.** The app writes turn files to local
    disk and uses file-based sessions; multi-instance platforms need shared storage for both.
    Upload a turn, then on a later request confirm the file and its `raw_uploads` row are both
    present. If not persistent, turn content would have to move into the database — better known
    now than mid-season.
  * **Post-deploy verification pass — do these three together, ~20 min:**
    * [ ] Request a real turn file by URL, expect **403**
    * [ ] Load a game page with play-by-play as Administrator, then request the identical URL
      logged out — confirm the play-by-play is absent (CDN cache vs authorization; §13's test was
      on the *other* account)
    * [ ] Confirm the family-only gates behave logged out on the live site, as they do on
      MountZion

**Trimmable = not visible to a reader.** A1 sets a NULL column and resolves ten rows; A7 adds an
honor that is currently absent rather than wrong; A8 removes rows already unreachable from the
app. Deferring all three buys about a week and loses nothing a coach would see.

### 🚀 GO LIVE

---

## 📋 Post-go-live backlog

Reduced capacity from here. Nothing below blocks anything else. ~107–194h total — 7–12 weeks at
16h/week, 3–6 months at 8h/week.

* [ ] **Feature 14:** Legacy play-by-play in `game.php` / `replay.php` · 8–12h · 2 chats
  * **UNBLOCKED** — Feature 15 complete. Needs a UNION with `plays` (check whether Feature 1's
    `_all` views extend rather than writing a parallel one — biggest swing factor in the
    estimate), a computed `quarter` as an explicit `CASE`, an ordering key, a decision on the
    absent `score_after`, and a data-tier marker.
  * Three findings from Feature 15 change it: (a) `play_log_id` is a valid ordering key;
    (b) 15 NFLAR games have `went_to_ot = 1` but no OT plays, so they want an explicit
    *"overtime not recorded"* marker; (c) 21 games hold NULL `result_text` on every row and must
    render as text-less rather than blank-looking-broken.
  * ⚠️ **Scope changed 12 Aug 2026.** Play-by-play only ever exists for games the family coached
    in. `legacy_play_log` covers 1,325 games against ~9,969 — about **13%**. So *"no
    play-by-play"* is the normal state for ~7 games in 8, not an error. **Three display states,
    not two:** live `plays` rows / legacy rows / **never a coached game** — the last presented
    as ordinary and expected.
  * Note this is **family-only content** (`security.md` §3), so it adds nothing for the new
    read-only audience.
* [ ] **Six build-time tables would ship in the deployment dump** · 1h · **check before A9**
  * Left in `gplan_pbm` after Feature 21's own five were dropped: `bak_f15_legacy_dupes` (2,142),
    `bak_f15_legacy_dupes_unlinked` (877), `migration_f15_dupe_candidates` (4,418),
    `migration_f15_predelete_snapshot` (9), `migration_f15_unlinked_candidates` (1,755),
    `migration_fgames_map` (19,998). ~29,000 rows of build-time scaffolding.
  * **`migration_fgames_map` is the one that matters.** It is a one-off extract from
    `gplan_main.f_games`, and the standing rule is that `gplan_main` / `f_games` does not deploy
    and anything reading it is build-time-only. Shipping an extract of it to the public host is
    that rule broken by the back door.
  * The three `migration_f15_*` staging tables have served their purpose. The two `bak_f15_*`
    are the record of what Feature 15 deleted and `schema.md` §14 refers to them — decide
    whether they are kept locally, archived to a dump, or dropped.
  * Either drop them or add `--ignore-table` for each to the deployment dump. Not both halves of
    the decision left implicit.
* [ ] **`ENUM NOT NULL` does not make a column mandatory** — 6 live columns · 2–4h
  * Discovered in Feature 21. An `ENUM` column declared `NOT NULL` takes **the first member of
    its list** as an implicit default, so an omitted column is silently filled rather than
    refused. `information_schema.COLUMN_DEFAULT` reports NULL; the storage engine has one.
    `STRICT_TRANS_TABLES` is active and does not change this. Full evidence and the measured
    comparison of alternative shapes: `lessons.md` §30.
  * **Six live columns affected:** `transactions.transaction_type` → `waive`,
    `game_types.phase` → `preseason`, `leagues.level` → `basic`, `leagues.sport_type` → `pro`,
    `legacy_play_log.ruleset_level` → `basic`, `legacy_play_log.sport_type` → `pro`.
  * **`transactions.transaction_type` is the one that matters.** An ingest that omits the type
    does not fail — it files a waiver. The others sit on reference or archive tables that change
    rarely.
  * Two shapes were measured to work: `VARCHAR(20) NOT NULL` + `CHECK (BINARY c IN (...))`, and
    `ENUM('unset',...)` + `CHECK (c <> 'unset')`. **Decide once and apply uniformly** rather than
    patching `leagues` alone — that was the reasoning for deferring it out of A1.
  * Whatever is chosen, DaDaBIK's field config has to follow: an `ENUM` renders as a radio group
    automatically, a `VARCHAR` does not.
* [ ] **`avg_yards` and `times_called` are computed over different populations** · 2–4h
  * In all seven `v_*_all` views, `COUNT(0)` counts every row surviving the filter while
    `AVG(yards_gained)` silently skips the NULLs — so the call count and the average yardage come
    from different row sets, with nothing in the output indicating it.
  * **1,397 of 5,716 `plays` rows have NULL `yards_gained` (24%).** `legacy_play_log` has zero, so
    this arrived with the `plays` union in Feature 1 and has never been visible in any view.
  * **Establish what the NULLs are before touching the views.** If they are incompletions the
    yardage is genuinely 0 rather than unknown, and the fix belongs in the parser, not the
    aggregate (`lessons.md` §3 — NULL means unknown, not zero). Read the rows first.
  * Any view edit goes in the `_all` views only; the original eight legacy-only views stay
    untouched (Feature 1 requirement, `lessons.md` §8).
* [ ] **17 views exist live but only 4 are in `new_schema.sql`** · 2–3h
  * Found in Feature 21. The standing convention is that `new_schema.sql` tracks the live
    database and divergence is a bug — this is a 17-view divergence, including every
    `v_playcall_*`, every `v_relevant_*`, and `v_plays_normalized`.
  * Matters for **A9's deployment dump**, which `findings-gpbm_uk_host_probe.md` §4 already flags
    as having trouble with views.
* [ ] **Historical navigation** — Features 16b + 6 + 10 scoped as **one** piece · 8–16h
  * All three are views of the same problem: no path to anything that isn't the current season.
    `task-16` §2 flags the 16/6 overlap; `todo.md` F10 flags the 10/6 overlap. Separately
    3–6h + 4–8h + 4–8h = 11–22h; together 8–16h.
  * **The first hour is a decision, not code** — what does Current Standings show, what does
    Historical Standings show, how does a team page reach an old game. Make it as a standalone
    conversation before building.
  * This is the post-go-live item that most improves what the *new audience* sees.
* [ ] **Trimmed Sprint A items**, if deferred — Features 21, 19, 17 · 8–12h
* [ ] **Feature 18:** `coaches.id_user` auto-population · 6–10h
  * Fully designed (`security.md` §7), nothing built. **Not needed for read-only coaches** —
    they're group 3. This is for when coaches upload their own turns.
  * Biggest call: hook vs staged page. Then how a mismatch gets surfaced. Never auto-update on
    mismatch. `migration_add_id_user.sql` belongs with this, not with go-live.
* [ ] **Feature 4:** Stats page · 8–16h — *wants a scoping conversation before the number means
  anything*
* [ ] **Feature 5:** Historical Stats page · 4–8h — after Feature 4
* [ ] **Feature 2:** `drives` / "Scouting Report - Game Summary" parser · 10–18h
  * Per-drive not per-play, for a *different* game (next week's opponent's most recent), with
    fields like `play_count` and `longest_play_text` that have no `plays` equivalent. Needs new
    schema as well as a new parser.
* [ ] **Feature 3:** Rest of the Scouting Report parser · 10–20h — after Feature 2
* [ ] **Feature 7:** League Roster pages · 12–25h — ⚠️ *least reliable estimate here*
* [ ] **Feature 8:** Team Roster pages · 8–15h — after Feature 7
  * Rosters imply a `players` entity that does not appear to exist in `schema.md`'s entity
    overview — so this is schema design, plus a parser, plus free-agent processing, which is the
    first user action in the system that **writes** state rather than parsing a turn file.
  * `security.md` §5: the detailed roster needs the same `$current_user_is_administrator` gate
    `game.php` uses; the summary roster is public.
* [ ] **Feature 20:** Recover the 43 damaged legacy fixtures · 25–42h · 5 chats · **LAST**
  * Deliberately last (12 Aug 2026): missing play-by-play from very old turns costs very little,
    and nothing depends on it.
  * ✅ **Format survey done** — `findings-f20_format_survey.md`. The play-by-play format is
    **byte-identical to 2032**; only engine versions 2.15 and 2.17 exist and 2.17 spans Feb 2005
    → Jun 2024. The blocker is that pre-2032 files carry **no `<BK.>` markers**, so
    `operational_hooks.php` produces zero blocks and every extract page silently finds nothing.
  * ✅ **Coverage resolved by construction** — all 43 hold `legacy_play_log` rows, rows only ever
    came from turn files, turn files only exist for coached games.
  * Pieces: 20b recovery page + `migration_f20_recovery_targets` worklist (8–12h) · 20c `<BK.>`
    block-synthesis shim + playoff-week pass (4–8h) · 20d category D, 15 games (5–8h) ·
    20e category A, 7 games (2–4h) · 20f category C, 21 games (6–10h).
  * Still open: **no file in the survey sample went to overtime**, so the own-game OT block
    format is unconfirmed. One category-D turn file closes it.

### Standing rules
* **Always strip `DEFINER` when dumping this database for the host** — every dump, forever, not
  just the first deploy. `sed -E 's/DEFINER=\`[^\`]*\`@\`[^\`]*\` ?//g'`, then
  `grep -c DEFINER` must return 0.
* **`gplan_main` / `f_games` does not deploy.** Anything that reads it is build-time-only.
* **Matching defaults don't prove two boxes are the same; differing values do prove they're
  different.** Stock `php.ini` (2M / 8M / 30s / 128M) matches on any un-customised host. Identify
  a box by response headers (`x-powered-by`, `x-provided-by`) or a marker file you placed.
* **When one piece of evidence contradicts a conclusion, first ask whether the two observations
  reached the same place** — before inventing a mechanism that reconciles them. A plausible
  mechanism will pass its own simulation (`lessons.md` §25).
* **Diagnostic output must name its own source.** Anything that will be pasted into a chat, a
  doc or a ticket should print its own host, database and version — by the time it is read, the
  URL bar is gone. A mislabelled paste cost real time on 12 Aug 2026 and no check aimed at the
  server could have caught it. `whoami.php` is the standing implementation.
* **One SQL script per turn — run it, read the results, verify, then the next.** Even when the
  scripts are independent and the order does not matter: independence is a property of the
  database, not of whether anyone can tell which script produced which output. Two failures in
  Feature 21 came from sending files in pairs (`lessons.md` §34).
* **A revised script gets a NEW filename, and every script names itself in its first line.**
  Never the same filename with different contents. First statement prints filename, revision,
  date, `DATABASE()` and `@@hostname` — the scripts version of *diagnostic output must name its
  own source*, above. A stale copy of a re-issued script ran undetected on 14 Aug 2026 and its
  own gate passed, having been written before the revision it was meant to verify.
* **State environment findings as actions, not observations.** The PHP EOL finding was recorded
  and then softened to "not a blocker if it can't be changed" — `lessons.md` §21 applies to
  environment findings as much as to bugs.

### Housekeeping
* [ ] **Retire the `$_cp_is_admin` alias — use `$current_user_is_administrator == 1` directly.**
  Decided 12 Aug 2026. The alias earned nothing: `grep -rn 'current_user_is_administrator'`
  finds every gated page either way, and *better*, since it catches both styles. Single-point-of-
  change only helps within one page, and a rule change would want applying across all of them.
  Against it: an alias is one more line per page that has to stay correct — the same objection
  `security.md` §4 already raises against naming `id_group` in the check. Keep the explicit
  `== 1` over a bare truthiness test; it states the expected value rather than leaning on
  coercion.
  * `whoami.php` is already written this way. **`game.php` still uses the alias** (four uses —
    Play by Play and Drive Summary, header and data each) and should be brought into line next
    time it is open, so the codebase doesn't carry a mixed convention.
  * If the rule ever grows beyond the global — "admin **or** coaches this franchise", plausible
    with Feature 18 — that wants a shared *function*, not a per-page variable.
  * Update `security.md` §4's example and `style-guide.md` to match, so the documented pattern
    is the one in the code.
* [ ] Correct `security.md` §4 — the "Not yet tested against a real login" caveat is stale.
* [ ] Add to `security.md`: the `custom_page_from_inclusion` guard as a documented second layer
  (§4 currently states there is no fallback — true of the content gate, not of direct
  execution), and a §3 row for `team_diag.php` once its status is established.
* [ ] Correct `schema.md` §13 and `HANDOVER-2026-08-11.md` §4 — both state "Affects `game.php`,
  `team.php`, `coach.php`" for the `games.label` defect as settled fact when it is unaudited.
  Do this once A5's audit has established what's actually true.
* [ ] Verify and close **Feature 9 (Game page)** — `game.php` and `replay.php` both exist, and
  Feature 10's note says its gap was "surfaced while testing Feature 9". Likely already done.
* [ ] Review `migration_fgames_map` once `franchise_identities` exists (`lessons.md` §24).
* [ ] Drop the Feature 15 backup/staging tables **only** once Feature 14 has rendered legacy
  play-by-play successfully — they are the sole rollback path.

---

## ✅ Completed Features (Historical Context)
* [x] Schema design & architecture proposal
* [x] Historical migration (`f_games`/`fc_franchises`/etc. → `games`/`franchises`/etc.,
  9,969 games; 253,450-row `legacy_play_log` migration; NuGameplan audit)
* [x] `extract_standings.php` — Standings parser
* [x] `extract_games.php` — Games/team_game_stats parser
* [x] `play_text_patterns` — 27 cross-validated text-classification patterns
* [x] `team_codes` — 78 fully-resolved team codes (replacing the retired `franchises.abbr`).
  *(Was 76; `LV` and `TV` were added during the backfill session — see `schema.md` §12.)*
* [x] `extract_playbyplay.php` — Play-by-Play parser, fully live-tested: multiple games across
  both NFLAR and NCAA5, 1070 rows in `plays`, a thorough set of invariant-checking queries run
  directly against live data all clean. Three real bugs found and fixed in the process (a
  missing `formation` column, `NULL`-vs-`0` handling for `yards_gained`, and an NCAA5-specific
  `franchise_id` gap) — see `parser_build_notes.md` §3/§5 for full detail.
* [x] `current_standings.php`, `team.php`, `home.php`, `bowl_records.php` front-end pages
* [x] **Feature 1:** Union views for `legacy_play_log` + `plays`, both stages — seven new
  views (`v_playcall_formation_all`, `v_playcall_matchup_all`, `v_playcall_matchup_formation_all`,
  `v_relevant_offense_all`, `v_relevant_offense_formation_all`, `v_relevant_defense_all`,
  `v_relevant_defense_formation_all`) plus two helper views (`v_plays_normalized`,
  `v_relevant_teams_franchise`). The original eight legacy-only views are untouched throughout,
  per explicit requirement. Along the way: a real parser gap fixed (`plays` was missing
  `is_interception` entirely), a genuine design defect found and deliberately left alone in the
  old `v_relevant_*_current` views (hard-restricted to one season each, confirmed wrong against
  the real legacy `n_s_*` batch table structures), and a second, unrelated bug caught while
  verifying stage 2 — `extract_games.php`'s `games.label` used today's real-world date instead
  of the turn's actual season, confirmed cosmetic-only (the real `week_id` FK was always
  correct) via a 48-row cross-check, then fixed and backfilled. See `lessons.md` §3, §8, §9 for
  full detail.
* [x] **`legacy_play_log.game_id` backfill** — the historical archive is linked to `games` for
  the first time: 199,577 of 250,169 rows, 98.6% of the 202,439 achievable, zero ambiguous
  resolutions and zero orphans. Every remaining `NULL` is accounted for. Along the way:
  the task doc's "~663 unresolved" estimate turned out to be an `f_games` row count applied to
  the wrong table (the real floor is ~50,000); the draft resolver would have silently
  mis-assigned ~10,000 rows by joining only the offence side; and **four `team_codes` entries
  held the wrong team name** (`WC`, `TN`, `OA`, `GE`) at 0.0% agreement across 7,562 rows —
  correcting them recovered ~15,000 rows. `LV` and `TV` added. See
  `findings-legacy_playbyplay_backfill.md` and `lessons.md` §13, §17–§20.
* [x] **NFLAR 2034 re-parsed through the live pipeline** — `f_games` was missing weeks 10–14
  entirely (60 games in `games` with full stats, no fixture rows behind them, no play-by-play).
  Re-sourced from the turn files rather than repairing a pipeline being retired: 204 games,
  408 stat rows, 384 standings rows (now `parsed` rather than `derived`), ~300 plays per week.
  Also fixed three duplicated fixtures, recovered MV's missing week 9 game, and retired 3,281
  legacy rows in favour of parser output carrying `play_seq`, `quarter` and `score_after`.
  See `RUNBOOK-reparse_nflar_2034.md`.
* [x] **Feature 15:** Duplicate legacy fixtures — **2,928 rows removed across 22 fixtures**,
  `legacy_play_log` 250,169 → 247,241. Two gated passes, each with its own backup and rollback:
  16 linked fixtures / 2,051 rows (`bak_f15_legacy_dupes`), then 6 NFLA 2009–2010 matchup-weeks
  / 877 rows (`bak_f15_legacy_dupes_unlinked`). All 23 verification checks passed; no fixture
  lost every copy, 1,325 linked games unchanged, and no matchup-week anywhere in the archive
  now repeats a line. The task doc's estimate of "13 fixtures, ~3,939 rows" was wrong three
  ways, and each error is worth knowing:
  * Its detector filtered `COUNT(*) > 180`, a volume filter rather than a duplication one, so
    it missed game 9621 entirely (141 rows) — and 9621 turned out **not** to be a duplicate at
    all. `result_text` has **two storage formats**, and on the 286 cleaned-tail rows the
    uniqueness detector false-positives. The rule would have deleted 43 legitimate plays and
    **passed its own simulation**, because rule and check shared an assumption. `lessons.md`
    §25 corrects §23.
  * Its detector joined `games`, so it could not see rows with no `game_id`. Every early stage
    inherited that filter, blinding the analysis to **50,592 rows** including 46,680 in inactive
    leagues — which do feed the `v_playcall_*_all` views. Six NFLA matchup-weeks were
    duplicating there. `lessons.md` §27.
  * Its proposed fix (`DISTINCT` on `(game_id, time_gone_seconds, result_text)`) could not have
    resolved game 9602, which holds three copies of which only two are identical.
  Also found and logged, not fixed: **overtime is absent from the entire archive and always
  was** (15 NFLAR games, confirmed four independent ways — `lessons.md` §29), 21 games hold no
  `result_text` at all, and 7 fixtures are short. All 43 go to Feature 20. Three defects in
  Claude's own verification gates are recorded in `lessons.md` §26.
* [x] **Parser fixes** — `extract_games.php`'s label used `date('Y')` for the season (the
  in-league season has never equalled the calendar year, and the drift is irregular, so it can
  only be read, never calculated); `season_id` added to the identification guard;
  `operational_hooks.php` refreshed only `league_id` in its in-memory copy after first-time
  identification. **`lessons.md` §9 had recorded the `date('Y')` fix as complete — it was not**,
  the earlier work reached the upload dropdown but never the label line. Corrected there.
* [x] **Extract page usability** — upload dropdowns ordered by season/week rather than
  `upload_id`; uploads with `parse_status = 'duplicate'` excluded (they have no blocks and can
  never be processed, but were rendering as "not yet identified"); extraction now runs buffered
  *before* the selector is built, so the turn just processed disappears immediately rather than
  on the next page load.
* [x] **Turn-file format survey** (12 Aug 2026) — six files spanning NCAA5 2003 to NFLAR 2008
  against the 2032 reference. Play-by-play format byte-identical across the whole range; only
  engine versions 2.15 and 2.17 exist; the real obstacle for old files is the absence of `<BK.>`
  block markers, not the parser. Cut Feature 20's top estimate by ~26h. See
  `findings-f20_format_survey.md`.

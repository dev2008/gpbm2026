# Findings — gpbm.uk host probe

**Run:** 12 Aug 2026, before any deployment. Probe kit uploaded to the web root, six URLs
checked, plus a PDO connection test once credentials existed.

> **An earlier version of this line read "Folder deleted afterwards." That was written before
> anyone had checked — assumed, and stated as fact.** It has since been verified and *is* true:
> `ls /var/www/gpbm/public_html/probe/` returns "No such file or directory" on MountZion, and
> `https://gpbm.uk/probe/` returns 404. Both confirmed 13 Aug 2026.
>
> Kept rather than quietly edited, because being right by luck is not the same as being right by
> evidence — and a fabricated claim in a reference document is exactly what `security.md` §4 and
> `schema.md` §13 already cost this project.

**Purpose:** establish whether the target host can run the app and — critically — whether the
uploads protection that works on MountZion survives the move.

---

## 1. Headline

**The host is fine, and `.htaccess` is honoured.** The uploads protection travels. Two things
will still break a naive deployment — the missing `SUPER` grant (§4) and the schema name /
absent `gplan_main` (§7) — and neither can be configured away at the hosting account.

| | |
|---|---|
| ✅ Environment | Apache, HTTPS enforced, MariaDB 10.11.18. **PHP 8.0.30 at probe time → switched to 8.4** (§8) |
| ✅ Overrides | `.htaccess` honoured — `Require all denied` returned 403 |
| ✅ Indexing | **Off by default** — the `custom_php_files/` listing seen on MountZion does not reproduce here |
| ⚠️ Grants | **No `TRIGGER`, no `SUPER`, no `FILE`** |
| ⚠️ Schema name | `gpbmuk-313333c5cd`, not `gplan_pbm`. Irrelevant through `$conn`; bites only in **view definitions inside the dump** (§7a) |
| ✅ PHP version | 8.0.30 was EOL; **now 8.4**, and MountZion already runs 8.4.24 so parity holds (§8) |

---

## 2. Probe results in full

| Test | Result | Reading |
|---|---|---|
| `probe/info.php` | PHP 8.0.30 *(pre-switch)*, Apache, `https: yes` | All required extensions present: `pdo_mysql`, `mysqli`, `mbstring`, `gd`, `zip`, `curl`, `openssl`, `json`, `fileinfo` |
| `probe/idx/` | 403 Forbidden | **Directory indexing off by default** |
| `probe/idx/hello.txt` | Served | Static files work |
| `probe/deny/secret.txt` | 403 Forbidden | **`.htaccess` IS honoured** — this was the critical test |
| `probe/noidx/` | 403 Forbidden | Ambiguous on its own (indexing is already off), but it did **not** 500, so `Options` overrides are at least tolerated |
| `http://…/probe/idx/hello.txt` | Redirects to HTTPS | Plain HTTP is not served — logins won't go in clear |
| `probe/db.php` | Connected; MariaDB 10.11.18; `STORED GENERATED` columns work | `franchises.label` will build |

**Limits:** `upload_max 128M`, `post_max 128M`, `memory_limit 128M`, `max_execution_time 300`,
`display_errors` empty (off — correct for production).

---

## 3. ⚠️ The uploads `.htaccess` uses Apache 2.2 syntax

MountZion's `/dadabik/uploads/.htaccess` contains exactly:

```
Deny from all
```

That is **Apache 2.2 syntax**. It works on MountZion (Apache 2.4.58, `AllowOverride All` in
`gpbm.conf`) only because `mod_access_compat` happens to be loaded there. On an Apache 2.4 host
without that module, `Deny` is an unrecognised directive and Apache returns **500 for every
request in that directory**.

That fails *closed*, not open — the turn files still would not serve — but the uploads directory
would be broken rather than protected, and a 500 is a much worse signal to debug under pressure
than a 403.

**The probe proves 2.4 syntax works on gpbm.uk.** Replace it with the portable form:

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

Do this on MountZion too, so both boxes carry the same file and there is nothing to remember at
deploy time. Check whether the file is DaDaBIK's own or hand-written — if DaDaBIK ships it, a
future upgrade may overwrite it.

---

## 4. ⚠️ No `SUPER` grant — views with `DEFINER` clauses will fail to import

Granted on `gpbmuk-313333c5cd.*`:

```
SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER,
CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE,
CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE
```

`CREATE VIEW` is present, so views are fine in principle. **But `mysqldump` writes every view as
`CREATE ALGORITHM=… DEFINER=\`someuser\`@\`localhost\` SQL SECURITY DEFINER VIEW …`**, and
restoring a view whose `DEFINER` names a user you are not — and cannot become without `SUPER` —
fails outright.

This project has at least **nine views**: the seven `_all` union views from Feature 1, plus
`v_plays_normalized`, `v_relevant_teams_franchise`, `v_current_standings`, and the eight original
legacy-only `v_relevant_*` / `v_playcall_*` views. Every one of them is affected.

**Strip the DEFINER on the way out:**

```bash
# No --databases: keeps CREATE DATABASE / USE out of the file, so the dump is schema-agnostic
# and imports into whatever database the connection has open.
mysqldump --single-transaction --routines --triggers=FALSE gplan_pbm \
  | sed -E 's/DEFINER=`[^`]*`@`[^`]*` ?//g;
            s/SQL SECURITY DEFINER/SQL SECURITY INVOKER/g;
            s/`gplan_pbm`\.//g' \
  > gplan_pbm_portable.sql

grep -cE 'DEFINER|gplan_pbm' gplan_pbm_portable.sql   # must be 0
```

The third expression handles the schema qualifier MySQL bakes into view definitions — see §7(a).
Both problems live in the same statements, so they are fixed in one pass and verified by one
`grep`.

---

## 5. ⚠️ No `TRIGGER` grant — and the schema already anticipated this

`new_schema.sql`'s own comment on `seasons.label`:

> deliberately NOT a DB trigger — TRIGGER privilege isn't guaranteed on every hosting
> environment this app might run on, e.g. shared-hosting-style MySQL accounts often have it
> disabled by default

**Confirmed correct.** That design decision is now load-bearing rather than theoretical: labels
are populated by application code, so nothing needs a trigger. No action — recorded because it
is the rare case of a defensive choice being vindicated by evidence.

`mysqldump` still emits trigger-related statements by default; `--triggers=FALSE` above avoids
importing anything that would be rejected.

---

## 6. ⚠️ No `FILE` grant — no `LOAD DATA INFILE`

Any migration or bulk-load script using `LOAD DATA INFILE` or `SELECT … INTO OUTFILE` will fail.
`LOAD DATA LOCAL INFILE` is a client-side variant that *may* work, but is disabled by default in
MySQL 8 / MariaDB 10.5+ clients and often server-side too. Assume neither is available and use
batched `INSERT`s.

Worth grepping the existing migration and stage scripts for `INFILE` before deploy day.

---

## 7. ⚠️ Schema name — `gpbmuk-313333c5cd`, not `gplan_pbm`

Two separate consequences.

**(a) The hyphen matters far less than an earlier draft of this document claimed.** Corrected
12 Aug 2026 (Alan): **anything going through DaDaBIK uses `$conn`, which already has the database
selected**, so `SELECT * FROM games` works regardless of what the schema is called. No custom
page needs to qualify a table name, and none does.

It narrows to two places:

1. **CLI at deploy time** — the `mysqldump` / `mysql` commands themselves. The `.sql` build
   scripts all run on MountZion against `gplan_pbm`, so they are unaffected.
2. **View definitions inside the dump — this one is real.** MySQL does not store a view as
   written; it normalises the definition and **qualifies table references with the source
   database**. A view created as `SELECT … FROM plays` dumps as
   ``SELECT … FROM `gplan_pbm`.`plays` ``. Imported into `gpbmuk-313333c5cd`, it points at a
   database that does not exist there.

That compounds with the `DEFINER` problem in §4 — same dump, same nine-plus views, and neither
failure is visible until something reads a view. Both are stripped by the single command in §4.
Run `grep -c gplan_pbm` against a raw dump first; the count will be higher than writing the views
unqualified would suggest.

**(b) `gplan_main` will not exist on the host.** This is the bigger one.

`label_L1_identities.sql` — the script that builds `franchise_identities` in Sprint A item A4 —
reads **`gplan_main.f_games` directly** (`task-11` §5 says so explicitly, and notes it does not
need `migration_fgames_map`). `label_L2_rebuild.sql` is in the same position. The legacy database
is on MountZion and will not be migrated to shared hosting.

**Consequences:**

1. **All Sprint A data work must be done on MountZion, then the finished database migrated.**
   That is probably the intent anyway, but it makes go-live a database migration rather than a
   file copy, and that migration needs its own rehearsal.
2. **`migration_fgames_map` should be kept, not dropped.** `lessons.md` §24 flags it as interim
   and due for review once `franchise_identities` exists. On the target host it becomes the only
   surviving trace of `f_games` — which argues for retaining it, or for accepting that identity
   work is permanently a MountZion-only operation.
3. **Feature 11 pass 3 matters more than it looked.** Having `extract_games.php` write identity
   rows from the turn file's own team names is the only identity path that works on a host with
   no `gplan_main`. It stops being the tidier option and becomes the only durable one.

---

## 8. PHP version — was 8.0.30 (EOL), now switched to 8.4

Security support for PHP 8.0 ended **26 November 2023**. Alan switched the host to **PHP 8.4**
on 12 Aug 2026 via the control panel's version selector (5.3 through 8.5 offered).

**Process note:** this document originally recorded the EOL finding but framed it as "not a
go-live blocker if it can't be changed", which downgraded an action into an observation. Alan
picked up the implication and acted; the document should have stated it. Findings docs need
their actions stated as actions — `lessons.md` §21 applied to environment findings, not just
bugs.

### Three things to verify now that the version has changed

**(a) ⚠️ Re-run `info.php`. Extension sets are per-version on shared hosting.** Control panels
that offer a PHP version selector almost always carry a *separate* extension list per version,
and switching can silently drop extensions that were enabled on the old one. The probe confirmed
`pdo_mysql`, `mysqli`, `mbstring`, `gd`, `zip`, `curl`, `openssl`, `json` and `fileinfo` under
**8.0.30** — none of that carries over automatically. DaDaBIK will not start without
`pdo_mysql`/`mbstring`.

Re-upload just `probe/info.php`, check the list, delete it again. Two minutes, and it also
confirms 8.4 is actually active for this vhost rather than only set in the panel.

**(b) Confirm DaDaBIK 13.5 supports 8.4.** 8.0 → 8.4 is four major-minor steps. Check DaDaBIK's
own requirements/changelog rather than assuming — if 13.5 predates 8.4, **8.3 is the safer
pick**: it has security support to Dec 2027, which is ample, and it resolves the EOL problem
just as completely. (8.2 expires Dec 2026 — too soon to be worth choosing now.)

What typically breaks in this range, in rough order of likelihood for a mature codebase:

- **Implicitly nullable parameters** — `function f(int $x = null)` is deprecated in 8.4
- **Dynamic property creation** — deprecated since 8.2, still warns
- **Passing `null` to non-nullable internal parameters** — deprecated since 8.1, common in older
  string handling

None of those is fatal on its own, but with `display_errors` off they surface as log noise or,
where something *is* fatal, as a blank page or 500 with nothing on screen.

**(c) ✅ MountZion already runs 8.4.24 — parity achieved, and 8.4 support is proven.** The
probe run against `gpbm.local` returned PHP 8.4.24 with every required extension present. Since
the custom pages and DaDaBIK have been running on that box throughout — turns parsed, extract
pages exercised, Feature 15 completed — **DaDaBIK 13.5 and the custom pages on PHP 8.4 is an
empirical result, not a compatibility assumption.** Alan's part-built Civ site is on 8.4 as
well. (b) above is therefore closed too: 8.4 is the right choice, no need to fall back to 8.3.

### The two environments differ on limits — in the safe direction

| | MountZion (build) | gpbm.uk (live) |
|---|---|---|
| `upload_max_filesize` | **2M** | 128M |
| `post_max_size` | **8M** | 128M |
| `max_execution_time` | **30s** | 300s |
| `memory_limit` | 128M | 128M |
| `display_errors` | off | off |

§9 below worried that the host was too constrained for the data import. It is the reverse: the
**build box is the tighter environment**. That is the favourable asymmetry — anything that runs
within MountZion's limits will run on gpbm.uk, so MountZion is a conservative test bed rather
than a flattering one.

Turn files are 50–80KB, so the 2M cap does not affect normal uploading on either box.

**One practical consequence for the deployment rehearsal:** do not attempt the import through a
local phpMyAdmin on MountZion — 2M and 30s will stop it long before the data does. Use CLI
`mysql` there. The generous limits are on the destination, which is where they are needed.

### ⚠️ Delete the probe folder from both boxes — it is inside the deploy tree

`probe/` was uploaded to `/var/www/gpbm/public_html/` on MountZion, which is **the same docroot
A9 deploys from**. An rsync or archive of that tree would carry `info.php` — and potentially a
`db.php` still holding live credentials — straight to the public site. A filled-in `db.php` may
also still exist on gpbm.uk from the connection test.

Remove from both, and add `probe/` to the deploy exclude list and `.gitignore`. This is the
third artefact this session that existed on disk while appearing in no checklist —
`example.php` and `team_diag.php` were the others.

---

## 9. Getting the data there

`legacy_play_log` alone is **247,241 rows**. A full dump is plausibly 60–120MB of SQL — against
`upload_max`/`post_max` of **128M** and `max_execution_time` of **300s**. Importing through
phpMyAdmin is uncomfortably close to both limits.

Establish before deploy day:

- Is there **SSH or CLI `mysql` access**? If so this is a non-issue.
- If not, does the control panel offer a gzip-aware import? A `.sql.gz` of that dump should be
  15–25MB, comfortably inside the limit — but the 300s execution cap still applies to the import
  itself, and a quarter-million-row insert can exceed it.
- Fallback: split the dump, importing `legacy_play_log` separately in chunks.

---

## 10. Actions, in order

| | Action | Where | Status |
|---|---|---|---|
| 1 | ~~Switch off EOL PHP 8.0~~ (§8) | gpbm.uk | ✅ **done — now 8.4** |
| 2 | Re-run `info.php` to confirm extensions survived the version switch (§8a) | gpbm.uk | ⚠️ **still outstanding — the run so far was against MountZion** |
| 3 | ~~Confirm DaDaBIK 13.5 supports 8.4~~ (§8b) | — | ✅ proven — MountZion runs the app on 8.4.24 |
| 4 | ~~Match MountZion's PHP version~~ (§8c) | MountZion | ✅ already 8.4.24 |
| 4b | **Delete `probe/` from MountZion and gpbm.uk; add to deploy excludes** (§8) | both | ⚠️ **inside the deploy tree** |
| 5 | Rewrite the uploads `.htaccess` in portable form (§3) | MountZion, then deploy | |
| 6 | Grep migration/stage scripts for `INFILE` (§6) | MountZion | |
| 7 | Establish SSH/CLI DB access on the host (§9) | gpbm.uk | |
| 8 | Build the DEFINER-stripping dump; verify `grep -c DEFINER` = 0 (§4) | deploy step | |
| 9 | Rehearse the full import into the real schema name (§7) | deploy step | |
| 10 | Re-request a real turn file **after** deployment, expect 403 (§3) | gpbm.uk | |

Item 2 is the one that bites quietly: a dropped `pdo_mysql` or `mbstring` after a version switch
stops DaDaBIK dead, and nothing announces it until something tries to load.

Item 10 matters for the opposite reason: everything above says the uploads protection *should*
travel, but the only proof that counts is requesting a real turn file on the live host.

---

## 11. Standing rules established here

- **Always strip `DEFINER` when dumping this database.** Not a one-off deploy step — any dump
  intended for the host, ever, including future refreshes. Confirmed agreed 12 Aug 2026.
- **`gplan_main` / `f_games` is not to live on** (Alan, 12 Aug 2026 — it was never the intention
  that it should). `label_L1_identities.sql` reading it directly is a **build-time-only**
  convenience on MountZion, not part of the deployed system. Feature 11 pass 3 —
  `extract_games.php` writing identity rows from the turn file's own team names — is the durable
  path, and the only one that works where no legacy database exists.


---

## 12. Two corrections, and what the fingerprint actually proved

**12 Aug 2026.** This section replaces an earlier draft that was itself wrong. Both versions are
recorded because the reasoning error is worth more than the tidy answer.

### The four runs

| | URL as labelled | PHP | `SERVER_SOFTWARE` | `https` | limits | Actually |
|---|---|---|---|---|---|---|
| 1 | gpbm.uk | 8.0.30 | `Apache` | yes | 128M/128M/300 | **host** |
| 2 | gpbm.uk | 8.4.24 | `Apache/2.4.58 (Ubuntu)` | NO | 2M/8M/30 | **MountZion** |
| 3 | gpbm.local | 8.4.24 | `Apache/2.4.58 (Ubuntu)` | NO | 2M/8M/30 | MountZion |
| 4 | gpbm.uk | 8.4.24 | `Apache` | yes | 128M/128M/300 | **host** |

**Run 2's cause, confirmed: a stale clipboard copy.** The browser requested the right URL; the
text pasted into the conversation was the previous run's. DNS, vhosts and hosts files were never
involved — which is why `dig` came back clean inside and out, and why none of the checks aimed at
the *system under test* could have found it. **The corruption was in the observation channel, not
the system.**

### What was right, what was wrong

**Right:** run 2 was not the host. The server string, HTTPS flag and all four limits differ
between the boxes because gpbm.uk is customised and MountZion runs stock defaults. That *is* a
valid discriminator.

**Wrong:** the follow-up conclusion that run 2 *was* the host and that "the PHP version switch
reset the ini limits to defaults." It did not. Run 4 shows **128M / 128M / 300s intact on 8.4**.
There is no import problem to reinstate; §9's original sizing stands.

**How the error happened, because the shape recurs.** A correct conclusion met one piece of
contradicting evidence — `curl -sI` returning `x-powered-by: PHP/8.4.24` from the host — and
instead of noting that curl and the browser might have reached different places, a mechanism was
invented to reconcile them. The mechanism (per-version `php.ini`) is real, plausible and
internally consistent. It was also not what happened.

This is `lessons.md` §25 in a new costume: the explanation and the check shared an assumption, so
the story passed its own simulation. Feature 15's duplicate detector failed the same way on game
9621.

### The refined rule

- **Matching default values do not prove two boxes are the same.** Any un-customised host shows
  2M / 8M / 30s / 128M.
- **Differing values do prove they are different.** That direction is sound, and it was correct
  here.
- **When one piece of evidence contradicts a conclusion, first ask whether the two observations
  reached the same place** — before reaching for a mechanism that reconciles them.
- Prefer an unambiguous identifier: response headers (`x-powered-by`, `x-provided-by`), or a
  marker file you placed yourself.
- **Diagnostic output should name its own source.** `info.php` reported PHP version, server and
  limits — everything except *which host answered*. One line (`$_SERVER['HTTP_HOST']`) would have
  made a stale paste self-evident. Output travels further than the context around it: by the time
  it reaches a chat, a ticket or a doc, the URL bar is gone. `whoami.php` (Aug 2026) exists for
  this reason.

✅ **Confirmed state of gpbm.uk:** PHP 8.4.24, all required extensions present, HTTPS at the
application layer, `upload_max` 128M, `post_max` 128M, `memory_limit` 128M,
`max_execution_time` 300, `display_errors` off, StackCDN in front.

---

## 13. StackCDN and the access gates — tested on a live site

`x-provided-by: StackCDN`. The concern: the entire access model is **per-user rendering of the
same URL**. `game.php` returns play-by-play and drive summary for an Administrator and omits them
for a guest — same path, same query string, different body. A CDN caching an authenticated
response and serving it onward would leak family-only content upstream of the application, where
no amount of correct PHP gating helps.

**Tested against the legacy DaDaBIK site behind the same CDN** (Alan, 12 Aug 2026),
`analysis.gameplan.org.uk/index.php?function=search&tablename=bb_myteam`:

| Test | Result |
|---|---|
| Logged in | 186 items found |
| Log out in another tab, refresh | redirects to login page |
| Same URL in an incognito window | redirects to login page |

**Authenticated responses are not being cached and replayed.** If they were, the incognito
request would have received a cached 200 with the 186 rows.

The mechanism is almost certainly PHP's `session_cache_limiter`, which defaults to `nocache` and
emits `Cache-Control: no-store, no-cache, must-revalidate` on any response with a session
started. DaDaBIK starts sessions globally, so every page inherits it.

**Residual, narrow, worth confirming after deployment.** The test above is a *whole-page
redirect* — login gate versus content. `game.php` is a different shape: a **200 response where
public and family-only sections share one body**. The redirect test proves the caching mechanism
behaves, so this is expected to hold, but the specific confirmation is:

- load a game page with play-by-play as an Administrator
- request the identical URL logged out, on the real host
- confirm the play-by-play section is absent
- repeat for `replay.php`

The remaining failure mode is benign: a cached *public* response served to a logged-in family
member, who would see a game page missing their own play-by-play. Confusing, not a leak.

Also worth watching: whether the CDN caches standings and team pages long enough to serve stale
data after a turn is parsed. A correctness annoyance rather than an exposure — but it would look
exactly like the parser having failed, which is an expensive thing to misdiagnose.

### One consequence for §8

`https: NO` in runs 2 and 3 was MountZion over plain HTTP, not TLS terminating at the CDN. Run 4
shows `https: yes`, so the application *does* see HTTPS on the real host and
`$_SERVER['HTTPS']` is populated. The earlier warning about `X-Forwarded-Proto` was based on the
mislabelled run and does not apply. No action.


---

## 14. Environment matrix — measured via `whoami.php`, 13 Aug 2026

`whoami.php` deployed to MountZion and to the legacy 20i site. The legacy site is not guaranteed
identical to gpbm.uk but is the closest available proxy for the target platform.

| | MountZion (build) | analysis.gameplan.org.uk (20i, proxy) | gpbm.uk (target) |
|---|---|---|---|
| Server software | `Apache/2.4.58 (Ubuntu)` | `Apache` | `Apache` |
| PHP | 8.4.24 **fpm-fcgi** | 8.2.33 **fpm-fcgi** | **8.4.24 fpm-fcgi** |
| HTTPS reaches app | no | **yes** | **yes** |
| `X-Forwarded-For` | (none) | populated | **populated** |
| Document root | `/var/www/gpbm/public_html` | `/home/sites/27a/8/826366cb9c/public_html/analysis/` | **`/home/sites/7a/9/917dc0194c/public_html/`** |
| `upload_max` / `post_max` | 2M / 8M | 128M / 128M | 128M / 128M |
| `memory_limit` | 128M | 128M | 128M |
| `max_execution_time` | 30s | 300s | 300s |
| `display_errors` | off | off | off |
| Database | `gplan_pbm` | `gplan8-35303737a645` | `gpbmuk-313333c5cd` |
| DB server | 10.11.14-MariaDB | 10.11.18-MariaDB-log | 10.11.18-MariaDB |
| Required extensions | all 9 present | all 9 present | **all 9 present (verified on 8.4.24)** |

**Target column measured directly via `whoami.php` standalone mode, 13 Aug 2026.** The
environment is now fully characterised on the actual deploy target rather than inferred from the
legacy account. Two inferences were confirmed rather than merely plausible: the flat
`public_html/` layout (gpbm.uk being a primary domain, unlike the legacy subdomain-in-a-
subdirectory), and the ini limits being untouched by the 8.0 → 8.4 switch.

### What this changes

**⚠️ PHP runs as `fpm-fcgi`, so `php_value` / `php_flag` in `.htaccess` will not work.** Those
directives come from mod_php; without it Apache treats them as unknown and returns **500 for the
whole directory** — the same failure shape as the Apache 2.2 `Deny from all` risk in §3. If a PHP
ini value ever needs changing on the host, the mechanisms are a **`.user.ini`** file in the
directory or the hosting control panel, never `.htaccess`.

**✅ The `X-Forwarded-Proto` concern is dead.** 20i's proxy sets `HTTPS` correctly for the
application *and* forwards the client address in `X-Forwarded-For`. gpbm.uk behaves the same.
Nothing in the app needs to handle protocol detection specially.

*Consequence worth knowing:* `$_SERVER['REMOTE_ADDR']` will hold the proxy's address, not the
visitor's. Nothing currently keys off it, but any future login throttling, rate limiting or audit
logging by IP would record the CDN unless it reads `X-Forwarded-For` instead.

**MariaDB 10.11.14 → 10.11.18** is a patch-level move forward inside the same LTS series. No
feature or syntax difference to plan around.

**Deploy path shape.** 20i puts sites in subdirectories of one account's `public_html`. Expect
gpbm.uk to be `/home/sites/…/public_html/<site>/`, which is where `dadabik/uploads/` and its
`.htaccess` will land. The differing database prefixes (`gplan8-` vs `gpbmuk-`) suggest the two
sites may be separate accounts — confirm when the deploy path is known.

**Retroactive validation of §12.** 20i suppresses the Apache version (`ServerTokens Prod`) across
its estate, exactly as gpbm.uk does. So `Apache` versus MountZion's `Apache/2.4.58 (Ubuntu)` was
a genuine discriminator after all. The fingerprint reasoning was sound; only the clipboard was
not — which is precisely why diagnostic output has to name its own source.


### Accounts and deploy path — confirmed 13 Aug 2026

Two **separate 20i accounts**, both "Linux Unlimited / Autoscaling Linux", London:

| | gameplan.org.uk (legacy) | gpbm.uk (target) |
|---|---|---|
| Home path | `/home/sites/27a/8/826366cb9c/` | `/home/sites/7a/9/917dc0194c/` |
| IPv4 | 185.151.30.197 | **185.151.30.135** |
| IPv6 | 2a07:7800::197 | 2a07:7800::135 |
| Database | `gplan8-35303737a645` | `gpbmuk-313333c5cd` |

The gpbm.uk IP matches the earlier `dig +short gpbm.uk` exactly — independent corroboration that
the run-4 probe reached the real host, from a source that has nothing to do with the probe.

**Deploy path, and it differs in shape from the legacy site.** `analysis.gameplan.org.uk` is a
subdomain in a *subdirectory* (`public_html/analysis/`). **gpbm.uk is the primary domain of its
own account**, so it serves from `/home/sites/7a/9/917dc0194c/public_html/` directly. That gives:

```
/home/sites/7a/9/917dc0194c/public_html/dadabik/
/home/sites/7a/9/917dc0194c/public_html/dadabik/uploads/          <- .htaccess goes here
/home/sites/7a/9/917dc0194c/public_html/dadabik/include/custom_php_files/
```

A clean mirror of MountZion's layout, which makes the deploy simpler than the legacy structure
implied. Confirm on first login rather than assuming.

**Separate accounts means separate configuration.** Nothing verified on the legacy account
transfers to gpbm.uk as a guarantee — PHP version, ini values, `.htaccess` handling and CDN
settings are all per-account. In particular **the cache-vs-authorization test in §13 was run on
the legacy account.** Its mechanism (PHP session headers) is application-level and should carry,
but that is a reason to keep the post-deploy re-test rather than mark it settled.

**⚠️ "Autoscaling Linux" — worth one check.** The application writes uploaded turn files to local
disk and depends on PHP file-based sessions. On a genuinely multi-instance platform both need
shared storage, or a file uploaded via one instance may be invisible to the next request. The
legacy site's login persisting across requests is decent evidence the filesystem is shared, but
it may never exercise uploads.

Cheap confirmation once deployed: upload a turn, then load the extract page in a later request
and confirm both the file on disk and its `raw_uploads` row are present. If uploads prove
non-persistent, the fix is storing turn content in the database rather than on disk — a change
worth knowing about before it is discovered mid-season.

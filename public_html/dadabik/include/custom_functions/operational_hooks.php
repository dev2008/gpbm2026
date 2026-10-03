<?php
// ------------------------------------------------------------------
// After-insert hook on raw_uploads. The registration line and the hook
// function itself live together in this one file -- matching the
// DaDaBIK docs' own example (dadabik_send_notice_after_accounts_insert),
// which shows both side by side with no file split indicated. An
// earlier version of this file assumed the registration line belonged
// in config_custom.php instead, by analogy with where $permissions_template
// lives -- that was a guess extrapolated from an unrelated config
// pattern, not something the hooks documentation actually said, and it
// was wrong. Corrected here.
//
// Chosen as a hook rather than a manual page (unlike every extraction
// stage downstream of this, which are deliberately staged/manual pages,
// not hooks -- see conversation): this step is purely mechanical, with
// no judgment calls or partial-success states worth a human reviewing --
// read a file, hash it, split it on markers. Every upload needs exactly
// this, always, with no case where a human would want to delay or skip
// it. The extraction stages (standings, play-by-play, ...) stay manual
// precisely because they DO involve exactly that kind of judgment
// (unresolved franchise names, malformed blocks, partial data).
//
// Per the docs: hooks run inside the same transaction as the insert
// itself, so an uncaught exception here could disrupt the upload. Every
// entry point below is wrapped in try/catch for that reason -- a
// failure here should degrade to "this upload needs attention"
// (parse_status='error', parse_notes explaining why), never an
// exception propagating out of the hook.
// ------------------------------------------------------------------


// --------------------------------------------------------------
// A4 packages 2 & 3 -- franchise identity at WEEK grain
// --------------------------------------------------------------
// This block is byte-identical in extract_games.php, extract_standings.php,
// extract_playbyplay.php and operational_hooks.php. It is duplicated rather than put in an
// include because these four files are deployed independently (three DaDaBIK static pages
// and the hooks file) and a missing include would fail at exactly the moment an upload is
// being processed.
// The function_exists() guards exist because DaDaBIK loads the hooks file on every
// request, including the request that renders one of the static pages -- without them
// that combination is a fatal redeclare.
//
// Why this exists at all (schema.md §12): franchises.label is a STORED GENERATED column
// (city + nickname), so it always reads as the slot's PRESENT-DAY identity. Franchise
// 2014 reads "Dallas Cowboys" across its whole 1996-2034 history including 394 fixtures
// actually played as Washington Commanders. Resolving a turn file's team name against it
// is right only by accident, and wrong silently. franchise_identities records identity at
// week grain and is the correct source.
// --------------------------------------------------------------

if (!function_exists('a4_week_has_identities')) {
/**
 * How many franchise_identities rows this league already has for this week. Used only to
 * classify a fallback (see a4_resolve_franchise_at_week) -- never to decide a franchise.
 */
function a4_week_has_identities($conn, $league_id, $week_id) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM franchise_identities fi
           JOIN franchises f ON f.franchise_id = fi.franchise_id
          WHERE f.league_id = :league_id AND fi.week_id = :week_id"
    );
    $stmt->execute([':league_id' => $league_id, ':week_id' => $week_id]);
    return (int)$stmt->fetchColumn();
}
}

if (!function_exists('a4_resolve_franchise_at_week')) {
/**
 * Resolves a turn file's team name to a franchise_id AT WEEK GRAIN.
 *
 * (week, team_name) -> one franchise is schema.md §12's uniqueness rule, but that
 * DIRECTION is tested rather than structural -- uk_franchise_week only makes "one
 * franchise -> one name per week" structural. So this selects every match and REFUSES on
 * more than one instead of taking an unordered LIMIT 1. That is the precise shape §12
 * records as 642 rows of silently wrong data ("a resolver permitted to choose, choosing
 * silently"): writing NULL is recoverable, a plausible wrong value is not.
 *
 * Falls back to franchises.label, and the fallback ALWAYS appends to $log. It classifies
 * itself, because two very different situations reach it:
 *   seed -- the week has NO identity rows at all. Expected: the first parse of a newly
 *           uploaded week, and every operational_hooks.php run (the hook fires at upload
 *           time, before any extraction page has written identities).
 *   GAP  -- the week HAS identity rows and this name is not among them. Not expected, and
 *           the only case worth investigating.
 * Without that split the log would be all noise on every first parse and no one would read
 * it, which is the objection A4 §11 raises against a bare fallback.
 *
 * $week_had_identities MATTERS, and is not an optimisation. A caller that WRITES identity
 * rows in a loop must snapshot the count ONCE before that loop and pass it in, because
 * otherwise this function measures state its own caller is midway through changing: the
 * first team of a brand-new week is classified 'seed', its row is written, and every
 * remaining team in the same pass then sees a non-empty week and is misclassified 'GAP'.
 * Observed live on NCAA5 2039 wk4 -- 1 seed line then 11 false GAPs, i.e. exactly the
 * cry-wolf log the classification exists to prevent. Pass null (the default) only where
 * nothing is being written during the same pass, as in operational_hooks.php.
 *
 * Returns int franchise_id, or null (caller reports and skips -- never guesses).
 */
function a4_resolve_franchise_at_week($conn, $league_id, $week_id, $team_name, &$log, $week_had_identities = null) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT fi.franchise_id
           FROM franchise_identities fi
           JOIN franchises f ON f.franchise_id = fi.franchise_id
          WHERE f.league_id = :league_id AND fi.week_id = :week_id AND fi.team_name = :team_name"
    );
    $stmt->execute([':league_id' => $league_id, ':week_id' => $week_id, ':team_name' => $team_name]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) === 1) {
        return (int)$ids[0];
    }
    if (count($ids) > 1) {
        $log[] = "AMBIGUOUS: '$team_name' matches " . count($ids) . " franchises in this week ("
               . implode(', ', $ids) . ") -- refusing to choose; skipped.";
        return null;
    }

    // No week-grain identity. Fall back to the present-day label, exactly as these three
    // lookups did before the repoint -- but say so.
    $stmt = $conn->prepare("SELECT franchise_id FROM franchises WHERE league_id = :league_id AND label = :label");
    $stmt->execute([':league_id' => $league_id, ':label' => $team_name]);
    $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($labels) !== 1) {
        if (count($labels) > 1) {
            $log[] = "AMBIGUOUS: '$team_name' matches " . count($labels)
                   . " franchises by present-day label -- refusing to choose; skipped.";
        }
        return null;
    }

    $had = ($week_had_identities === null)
        ? a4_week_has_identities($conn, $league_id, $week_id)
        : (int)$week_had_identities;
    if ($had > 0) {
        $log[] = "fallback (GAP): this week already has identity rows but '$team_name' is not among "
               . "them -- resolved from franchises.label instead. This one is worth investigating.";
    } else {
        $log[] = "fallback (seed): no franchise_identities rows exist for this week yet -- "
               . "'$team_name' resolved from franchises.label. Expected on a week's first parse.";
    }
    return (int)$labels[0];
}
}

if (!function_exists('a4_resolve_team_code')) {
/**
 * The team code to record alongside a new identity row (A4 D6).
 *
 * Taken from the codes franchise_identities ITSELF already uses for this name, not from
 * team_codes: name -> code is not unique (schema.md §12 -- Green Bay GB/GP, New Orleans
 * NO/NS, Tennessee TN/TT), and a new row's job is to stay consistent with the convention
 * the table already holds, not to re-litigate it. Reading team_codes here would let a
 * parse write NS where the surrounding weeks say NO (or the reverse), which is
 * indistinguishable from a real mid-season identity change.
 *
 * Returns NULL where the table itself is not consistent about this name -- deliberately.
 * Aggregated with COUNT(DISTINCT)/MAX rather than an unordered LIMIT 1, so it cannot
 * silently pick.
 */
function a4_resolve_team_code($conn, $league_id, $team_name) {
    $stmt = $conn->prepare(
        "SELECT CASE WHEN COUNT(DISTINCT fi.team_code) = 1 THEN MAX(fi.team_code) ELSE NULL END
           FROM franchise_identities fi
           JOIN franchises f ON f.franchise_id = fi.franchise_id
          WHERE f.league_id = :league_id AND fi.team_name = :team_name AND fi.team_code IS NOT NULL"
    );
    $stmt->execute([':league_id' => $league_id, ':team_name' => $team_name]);
    $code = $stmt->fetchColumn();
    return ($code === false || $code === null || $code === '') ? null : $code;
}
}

if (!function_exists('a4_write_identity')) {
/**
 * Writes one franchise_identities row for (franchise, week) from the turn file's own team
 * name (A4 D1/D2/D3).
 *
 *   D2 -- INSERT ... ON DUPLICATE KEY UPDATE on uk_franchise_week (franchise_id, week_id),
 *         refreshing team_name. Repeat parses of the same week, and other coaches' turns
 *         covering the same week, converge rather than duplicating.
 *   D3 -- derived_from = 'week': a turn file records the identity for that exact week,
 *         which is the strongest grain this project has.
 *
 * team_code uses COALESCE(VALUES(team_code), team_code) rather than a plain refresh, so a
 * re-parse can never downgrade an already-known code to NULL when a4_resolve_team_code
 * declines to choose. NULL is a legitimate value here (27 pre-email rows carry it) but it
 * should only ever be an initial state, never a regression.
 *
 * Wrapped in try/catch and returns 0 on failure: in extract_*.php a failed identity write
 * must not take the standings/games extraction down with it, and in the hook an exception
 * would escape into the insert's own transaction.
 */
function a4_write_identity($conn, $league_id, $week_id, $franchise_id, $team_name, &$log) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO franchise_identities (franchise_id, week_id, team_name, team_code, derived_from)
             VALUES (:franchise_id, :week_id, :team_name, :team_code, 'week')
             ON DUPLICATE KEY UPDATE
                 team_name = VALUES(team_name),
                 team_code = COALESCE(VALUES(team_code), team_code),
                 derived_from = 'week'"
        );
        $stmt->execute([
            ':franchise_id' => $franchise_id,
            ':week_id'      => $week_id,
            ':team_name'    => $team_name,
            ':team_code'    => a4_resolve_team_code($conn, $league_id, $team_name),
        ]);
        return 1;
    } catch (\Throwable $e) {
        $log[] = "identity write FAILED for '$team_name' (franchise $franchise_id, week $week_id): "
               . $e->getMessage();
        return 0;
    }
}
}

if (!function_exists('a4_resolve_franchise_by_code_at_week')) {
/**
 * Resolves a team CODE to a franchise_id at week grain -- the code-side counterpart of
 * a4_resolve_franchise_at_week, for parsers that read codes rather than names (the
 * play-by-play side column).
 *
 * The code is the more reliable of the two directions: schema.md §12 establishes that
 * code -> name is unique and permanent, while name -> code is neither. What it is NOT is
 * franchise-stable -- the same slot answers to different codes in different eras (DC/WC for
 * franchise 2014), which is exactly why this resolves through franchise_identities at a week
 * rather than through team_codes alone.
 *
 * No league scope parameter: week_id already implies a season and therefore a league, and
 * idx_week_code (week_id, team_code) covers the lookup directly. Refuses on more than one
 * match rather than taking an unordered LIMIT 1, same as its name-side counterpart.
 *
 * Returns int franchise_id, or null when this week has no identity row carrying that code
 * (the caller decides what to do about it -- this function never falls back on its own).
 */
function a4_resolve_franchise_by_code_at_week($conn, $week_id, $team_code, &$log) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT fi.franchise_id
           FROM franchise_identities fi
          WHERE fi.week_id = :week_id AND fi.team_code = :team_code"
    );
    $stmt->execute([':week_id' => $week_id, ':team_code' => $team_code]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) === 1) {
        return (int)$ids[0];
    }
    if (count($ids) > 1) {
        $log[] = "AMBIGUOUS: code '$team_code' matches " . count($ids)
               . " franchises in this week (" . implode(', ', $ids) . ") -- refusing to choose.";
    }
    return null;
}
}

$hooks['raw_uploads']['insert']['after'] = 'dadabik_process_raw_upload';

function dadabik_process_raw_upload($upload_id) {
    global $conn, $upload_directory;

    try {
        $warnings = [];
        $upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $upload_id);

        // -------------------- Step 0: populate original_filename --------------------
        // Not set by Dadabik's generic_file field at insert time -- confirmed empirically
        // (both $_FILES and $parameters_ar were empty/unhelpful at before-insert). Set here,
        // verbatim from file_path, rather than trying to strip Dadabik's added suffix -- a
        // regex guessing at that suffix's exact pattern is one more thing that could be
        // subtly wrong, and the verbatim value is still perfectly identifiable either way.
        if (empty($upload['original_filename'])) {
            ddb_api::update_records('raw_uploads', 'upload_id', $upload_id,
                ['original_filename'], [$upload['file_path']]);
            $upload['original_filename'] = $upload['file_path'];
        }

        // -------------------- Step 1: read the uploaded file into raw_text --------------------
        // Dadabik's generic_file field only stores the file on disk + a reference in file_path --
        // it does not read the file's contents into the database. That's this step's job.
        $full_path = rtrim($upload_directory, '/') . '/' . $upload['file_path'];
        if (!file_exists($full_path)) {
            dadabik_mark_upload_error($upload_id, "Uploaded file not found on disk at $full_path");
            return;
        }
        $raw_text = file_get_contents($full_path);
        if ($raw_text === false) {
            dadabik_mark_upload_error($upload_id, "Could not read uploaded file at $full_path");
            return;
        }

        // -------------------- Step 1b: extract turn_number --------------------
        // MUST happen before the <STARTREP> trim below, not after -- confirmed the "X, Turn N"
        // line (e.g. "NFLAR-PE, Turn 1") sits BEFORE <STARTREP> in every file checked,
        // including genuinely clean ones with no email-client contamination at all. This
        // isn't Gmail boilerplate the way the rest of that leading text is -- it's part of
        // the legitimate email body every turn file has. Previously raw_uploads.turn_number
        // was never actually written by this hook at all (only ever read from it, always
        // NULL, which silently fed (int)NULL = 0 into every weeks.turn_number this hook has
        // created so far -- a real bug, not just a missing feature; see conversation).
        if (preg_match('/,\s*Turn\s+(\d+)/', $raw_text, $m)) {
            ddb_api::update_records('raw_uploads', 'upload_id', $upload_id, ['turn_number'], [(int)$m[1]]);
            $upload['turn_number'] = (int)$m[1];
        } else {
            $warnings[] = 'could not find a "Turn N" line to extract turn_number';
        }

        // Trim anything before <STARTREP> -- confirmed present exactly once at the true start
        // of every real turn file checked, including a genuinely contaminated one. Coaches
        // copy-paste these from their email client rather than downloading a clean file, and
        // that's prone to accidentally selecting extra page content along with it -- confirmed
        // directly: one real upload had Gmail's own web-interface chrome ("Skip to content",
        // "Using Gmail with screen readers", the inbox message list, sender/timestamp) pasted
        // in ahead of the actual report. Harmless for identification (it just searches the
        // whole text regardless), but there's no reason to let it sit in raw_text going
        // forward when it's this easy to normalize away. Non-fatal if the marker's missing --
        // proceeds with the untrimmed text and just notes it, rather than blocking the upload
        // over what block-splitting doesn't actually depend on.
        $startrep_pos = strpos($raw_text, '<STARTREP>');
        if ($startrep_pos !== false && $startrep_pos > 0) {
            $raw_text = substr($raw_text, $startrep_pos);
        } elseif ($startrep_pos === false) {
            $warnings[] = 'no <STARTREP> marker found -- raw_text may contain unexpected leading content';
        }
        ddb_api::update_records('raw_uploads', 'upload_id', $upload_id, ['raw_text'], [$raw_text]);

        // -------------------- Step 2: duplicate check --------------------
        // content_hash is a real MySQL GENERATED column (SHA2 of raw_text), so it's already
        // recomputed automatically by the database the moment raw_text was set above -- just
        // re-fetch to read it, no need to compute it here ourselves.
        $upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $upload_id);
        $content_hash = $upload['content_hash'];

        $stmt = $conn->prepare(
            "SELECT upload_id FROM raw_uploads
             WHERE content_hash = :hash AND upload_id != :uid AND parse_status != 'error'
             LIMIT 1"
        );
        $stmt->bindParam(':hash', $content_hash);
        $stmt->bindParam(':uid', $upload_id);
        $stmt->execute();
        $duplicate_of_id = $stmt->fetchColumn();

        if ($duplicate_of_id) {
            $notes = "Identical content to upload #$duplicate_of_id -- not reprocessed.";
            if ($warnings) {
                $notes .= ' WARNINGS: ' . implode('; ', $warnings);
            }
            ddb_api::update_records('raw_uploads', 'upload_id', $upload_id,
                ['parse_status', 'parse_notes'],
                ['duplicate', $notes]);
            return;
        }

        // -------------------- Step 3: identify league/season/week/phase --------------------
        // Only attempted if not already set. NOT sourced from the Standings block --
        // confirmed empirically (a real bowl/playoff-week upload) that Standings simply
        // doesn't exist for every week: once a season moves past its regular season into
        // bowls/playoffs, there's no running win-loss table to show, so League Report shows
        // bowl results and the next round's schedule instead. Sourced from three places
        // instead, each covering different week-types (confirmed against a normal week, a
        // bye week, and this bowl week specifically -- see dadabik_identify_upload):
        //   - week number: League Report's header ("Week N of total") -- present in every
        //     week-type seen so far, including bye and bowl weeks.
        //   - league code + season year: Team Report's header ("League X Season Y") for any
        //     week that has one (everything except bye weeks); Draft Report's header
        //     ("X ... Y Season" -- note the different word order) as the bye-week fallback.
        // B1, 24 Aug 2026: baseball's header carries all of league/season/phase/week on one
        // combined line and doesn't match any of the three football-shaped patterns above --
        // see dadabik_identify_upload for the added baseball case and the new $phase return
        // value (weeks.phase, added same day: 'preseason' | 'regular' | 'postseason', needed
        // because baseball's "Playoff Week N" and regular "Week N" reuse the same 1-3 digit
        // range within a season -- findings/B1-header-shape-and-week-collision.md).
        if (!$upload['league_id'] || !$upload['season_id'] || !$upload['week_id']) {
            [$league_code, $season_year, $week_number, $phase] = dadabik_identify_upload($raw_text, $upload['turn_number'] ?? null);

            if (!$league_code || !$season_year || $week_number === null) {
                // Explicit === null check, not a plain falsy check (!$week_number) -- week 0
                // (pre-season) is a real, valid value, and PHP treats 0 as falsy same as null,
                // which would otherwise make this incorrectly reject every pre-season upload
                // as a failed identification. $phase is never null (defaults to 'regular' in
                // dadabik_identify_upload), so it doesn't need a matching check here.
                dadabik_mark_upload_error($upload_id,
                    "Could not identify league/season/week (league=" . ($league_code ?: '?')
                    . ", season=" . ($season_year ?: '?') . ", week=" . ($week_number === null ? '?' : $week_number)
                    . ") -- block splitting was NOT attempted.");
                return;
            }

            $stmt = $conn->prepare("SELECT league_id FROM leagues WHERE code = :code");
            $stmt->bindParam(':code', $league_code);
            $stmt->execute();
            $league_id = $stmt->fetchColumn();

            if ($league_id) {
                $week_id = dadabik_resolve_or_create_week(
                    $conn, $league_id, $season_year, $week_number, $phase, (int)$upload['turn_number']
                );
                $season_id = dadabik_get_season_id_for_week($conn, $week_id);
                ddb_api::update_records('raw_uploads', 'upload_id', $upload_id,
                    ['league_id', 'season_id', 'week_id'], [$league_id, $season_id, $week_id]);
                // Refresh ALL THREE in the in-memory copy, not just league_id. The
                // database row gets all three above, so leaving $upload stale here
                // means anything added downstream reads null for season_id/week_id --
                // and only on a first-time identification, which is the worst kind of
                // bug to find later. Nothing currently reads them after this point;
                // this keeps it that way by construction rather than by luck.
                $upload['league_id'] = $league_id;
                $upload['season_id'] = $season_id;
                $upload['week_id']   = $week_id;
            } else {
                dadabik_mark_upload_error($upload_id, "Unrecognized league code '$league_code' -- no matching row in leagues.");
                return;
            }
        }

        // -------------------- Step 3b: resolve franchise_id --------------------
        // Records whose turn this is (the receiving franchise) -- not either game
        // participant. Checked independently of the league/season/week block above, since
        // it's a separate field that was simply never resolved at all until now, regardless
        // of whether the other three needed (re-)identifying. Sourced from Team Report's
        // header, e.g. "Pittsburgh Panthers (Alan Milnes)   turn credits = 8.5" -- the
        // franchise name immediately follows the report's own top header line. Resolved the
        // same way extract_standings.php resolves team names to franchise_id -- which, as of
        // A4 package 3, is from franchise_identities AT WEEK GRAIN (a4_resolve_franchise_at_week
        // below), not by exact match against franchises.label. franchises.label is a STORED
        // GENERATED present-day snapshot (schema.md §12), so matching a turn file against it
        // silently attributes an older turn to whoever holds that name today.
        //
        // This is the lookup schema.md §12 singles out as the genuinely quiet one of the three:
        // on no match it leaves raw_uploads.franchise_id null, appends a warning to parse_notes,
        // and still reports parse_status = 'partial' -- the ordinary success state. Its failure
        // looks like success in the database. Hence the resolver's log lines are folded into
        // $warnings below rather than being rendered somewhere no one will look.
        //
        // Expect the 'seed' fallback here as the normal case, and that is not a defect: this
        // hook fires at UPLOAD time, before either extraction page has run, so the week has no
        // identity rows yet by construction. The line worth acting on is a 'GAP' -- the week has
        // identities and this franchise's name is not among them.
        if (empty($upload['franchise_id']) && !empty($upload['league_id'])) {
            if (preg_match('/<BK\.Team Report>.*?\n.*?\n([^(<]+?)\s*\(([^)]+)\)/s', $raw_text, $m)) {
                $franchise_name = trim($m[1]);
                $identity_log = [];
                $franchise_id = a4_resolve_franchise_at_week(
                    $conn, $upload['league_id'], $upload['week_id'], $franchise_name, $identity_log
                );
                // 'seed' lines are suppressed HERE ONLY, and this is the one place that is
                // right. This hook fires at upload time, before any extraction page has run, so
                // the week is empty by construction and the seed fallback fires on EVERY upload
                // without exception. A warning that cannot not fire carries no information, and
                // it lands in parse_notes alongside the ones that do (no "Turn N" line, no
                // <STARTREP>, a franchise name matching nothing) -- one predictable line per
                // upload is how those come to be skimmed past. GAP and AMBIGUOUS are kept: they
                // are the cases that mean something, and in this hook a GAP is especially worth
                // seeing, since it says the week already had identities yet this coach's own
                // franchise name was not among them.
                //
                // Deliberately NOT suppressed in extract_games.php / extract_standings.php: a
                // seed line there is informative, because those pages run after the hook and a
                // week that is still empty at that point tells you which parse seeded it.
                //
                // To reverse: delete the two lines below marked "seed suppression".
                foreach ($identity_log as $line) {
                    if (strncmp($line, 'fallback (seed)', 15) === 0) { continue; } // seed suppression
                    $warnings[] = "franchise_id resolution: $line";                // seed suppression
                }

                if ($franchise_id) {
                    ddb_api::update_records('raw_uploads', 'upload_id', $upload_id, ['franchise_id'], [$franchise_id]);
                } else {
                    $warnings[] = "franchise name '$franchise_name' from Team Report resolved to no franchise "
                                . "-- no franchise_identities row for it in this week, and no unambiguous "
                                . "franchises.label match either";
                }
            } else {
                $warnings[] = 'could not find a Team Report header to resolve franchise_id (expected for bye weeks, which have no Team Report block)';
            }
        }

        // -------------------- Step 4: split into blocks --------------------
        // uploaded_by, not id_user -- raw_uploads already had a working, DaDaBIK-native "who
        // uploaded this" column (uploaded_by, populated by DaDaBIK itself, confirmed no custom
        // code sets it) before id_user was ever added this session. id_user on raw_uploads has
        // been dropped as redundant -- see migration_drop_raw_uploads_id_user.sql. Every OTHER
        // table's id_user column (raw_upload_blocks, standings_weekly, games, team_game_stats,
        // plays) stays exactly as designed -- only this one source changed, not the shape of
        // what gets propagated downstream.
        $block_count = dadabik_split_into_blocks($conn, $upload_id, $raw_text, $upload['uploaded_by'] ?? null);

        // 'partial', not 'parsed' -- this hook only identifies the upload and splits it into
        // blocks; the domain-table extraction stages (standings, play-by-play, ...) are
        // separate, manually-triggered pages, still pending after this step completes.
        $notes = "$block_count blocks split successfully; awaiting extraction stages.";
        if ($warnings) {
            $notes .= ' WARNINGS: ' . implode('; ', $warnings);
        }
        ddb_api::update_records('raw_uploads', 'upload_id', $upload_id,
            ['parse_status', 'parsed_at', 'parse_notes'],
            ['partial', date('Y-m-d H:i:s'), $notes]);

    } catch (\Throwable $e) {
        dadabik_mark_upload_error($upload_id, 'Exception during processing: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------
// Helpers
// --------------------------------------------------------------

// Identifies league code / season year / week number / phase from raw_text, trying
// multiple sources since no single block is both universally present AND has all four
// pieces. Football's three original patterns (week number, league+season, bye-week
// fallback) are tested directly against real header lines from a normal week, a bye week,
// and a bowl/playoff week -- see conversation. Searches the whole raw_text rather than a
// specific split-out block, since these header phrases are distinctive enough not to
// plausibly appear elsewhere (e.g. in Roster or Scouting Report content), and doing so
// avoids needing block-splitting to happen before identification can run.
//
// B1, 24 Aug 2026: added a baseball-specific pattern, tried FIRST. Confirmed directly
// against all 8 real MLB21 season-35 sample headers (t0, t15-t21 -- findings/
// B1-header-shape-and-week-collision.md) that baseball's header shape is fundamentally
// different from football's -- one combined line carries league, season, phase AND week
// together (e.g. "GAMEPLAN BASEBALL MLB21 Team Report Season 35 Week 15",
// "...Season 35 Preseason", "...Season 35 Playoff Week 2"), and none of it matches any of
// the three existing football patterns: there's no literal word "League", the season
// number is 2 digits not a 4-digit year, and "Preseason" is one solid word, not
// "Pre Season"/"Pre-Season". Deliberately NOT folded into the two independent per-variable
// blocks below (one match sets all four values, or none of them do).
//
// CORRECTED 24 Aug 2026, same day, after the first version of this fix was deployed and
// tested against real uploads: it stored the header's own "Playoff Week N" digit (1-3, a
// ROUND number relative to the postseason phase) as week_number, landing turns 19-21 as
// weeks 1-3 instead of 19-21. Alan caught this directly against the live weeks table:
// "you need to preserve the actual week number 0-21." The fix uses $turn_number (now a
// required second argument, already extracted at Step 1b from the "Turn N" line, strictly
// sequential per season by construction) as week_number for the postseason case instead of
// the matched round digit -- confirmed against real data that turn_number already equals
// the correct absolute week number for every phase (t15-t18's turn_number matches their
// header week digit exactly; t19-t21's turn_number is 19-21 even though their header's own
// round digit reads 1-3). Regular season keeps using the header's own week digit (the two
// already agree in every sample seen, so there's no reason to switch it too and widen the
// blast radius of this fix). If turn_number wasn't itself successfully extracted,
// $week_number is left null here so identification fails outright rather than silently
// falling back to the wrong round-relative number -- see the caller's existing
// === null check.
//
// weeks.phase (ENUM('preseason','regular','postseason'), unique key widened to
// (season_id, phase, week_number), added same day) is UNCHANGED by this correction and
// still worth keeping: week_number is now expected to be globally unique per season on its
// own (0-21, no phase needed to disambiguate), but phase remains a useful, harmless
// descriptive column, and no second schema migration is needed to remove it.
function dadabik_identify_upload($raw_text, $turn_number) {
    $league_code = null;
    $season_year = null;
    $week_number = null;
    $phase = 'regular'; // overwritten to 'preseason' or 'postseason' below when matched;
                         // football's two existing shapes only ever produce 'preseason' or
                         // this default -- neither currently signals a postseason/playoff
                         // week explicitly (see conversation). If one turns up, it needs its
                         // own case added the same way baseball's was below, not silently
                         // miscategorized as 'regular'.

    if (preg_match(
        '/GAMEPLAN\s+BASEBALL\s+(\w+)\s+.*?Season\s+(\d+)\s+(?:(Preseason)|(?:(Playoff)\s+)?Week\s+(\d+))/s',
        $raw_text, $m
    )) {
        // Baseball, one combined match: $m[1] league code, $m[2] season number (2-digit,
        // not a year -- stored in seasons.year regardless, that column is just this
        // league's season identifier, not a calendar-year assumption). $m[3] set only for
        // "Preseason" (no week digit exists in the header at all for this phase -- assigned
        // 0 to match the existing football convention that week 0 IS pre-season, confirmed
        // directly against live NFLAR rows). $m[4] set only when "Playoff " preceded
        // "Week N"; $m[5], the digit after "Week", is a PHASE-RELATIVE round number for
        // postseason (1-3) and is deliberately NOT used as week_number in that case -- see
        // the correction note above.
        $league_code = $m[1];
        $season_year = (int)$m[2];
        if (!empty($m[3])) {
            $week_number = 0;
            $phase = 'preseason';
        } elseif (!empty($m[4])) {
            $week_number = $turn_number; // NOT (int)$m[5] -- see correction note above.
            $phase = 'postseason';
        } else {
            $week_number = (int)$m[5];
            $phase = 'regular';
        }
    } else {
        // Football's existing shapes, unchanged from before this fix.

        // Week number: League Report's header, e.g. "Week 12 of 11" -- present for every
        // numbered week seen so far, including bye and bowl/playoff weeks. Pre-season weeks
        // don't have a numbered week at all though -- confirmed against real pre-season uploads
        // from both leagues, League Report's header says "Pre Season"/"Pre-Season" in place of
        // "Week N of total" entirely (mapped to week_number = 0 -- matching how these leagues'
        // own week numbering works: week 0 IS pre-season, confirmed directly). Both spacing AND
        // hyphenation variants matched deliberately -- confirmed a real upload uses "Pre-Season"
        // (hyphenated) in Team Report's header specifically, different from every earlier sample
        // checked, which all used "Pre Season" (space). Since the two headers aren't guaranteed
        // to agree on which form they use, matching only one risked silently failing
        // identification on real uploads exactly like this one.
        if (preg_match('/Week\s+(\d+)\s+of\s+\d+/', $raw_text, $m)) {
            $week_number = (int)$m[1];
        } elseif (preg_match('/Pre[\s-]Season/', $raw_text)) {
            $week_number = 0;
            $phase = 'preseason';
        }

        // League + season: Team Report's header, e.g. "League NCAA5   Season 2038" -- present
        // for any week that has a Team Report block (everything except bye weeks, which have no
        // game and so no Team Report at all).
        if (preg_match('/League\s+(\w+)\s+Season\s+(\d+)/', $raw_text, $m)) {
            $league_code = $m[1];
            $season_year = (int)$m[2];
        } elseif (preg_match('/GAMEPLAN\s+[\d.]+\s+(\w+)\s+.*?(\d{4})\s+Season/', $raw_text, $m)) {
            // Bye-week fallback: Draft Report's header, e.g. "NFLAR   Annual Draft   Round 2
            // 2032 Season" -- note the year comes BEFORE the word "Season" here, the opposite
            // order from Team Report's header, so this needs its own separate pattern.
            $league_code = $m[1];
            $season_year = (int)$m[2];
        }
    }

    return [$league_code, $season_year, $week_number, $phase];
}

// Splits raw_text on every <BK.NAME> marker into raw_upload_blocks rows -- one row per block,
// content running from just after each marker to just before the next one (or end of text for
// the last block). 'Turnsheet' is deliberately excluded: it's the blank form for the NEXT
// turn, not data to extract. Upsert (ON DUPLICATE KEY UPDATE) rather than plain INSERT, since
// raw_upload_blocks has UNIQUE KEY (upload_id, block_seq) specifically so re-running this
// safely re-splits rather than creating a second, duplicate set of blocks.
function dadabik_split_into_blocks($conn, $upload_id, $raw_text, $id_user) {
    if (!preg_match_all('/<BK\.([^>]+)>/', $raw_text, $matches, PREG_OFFSET_CAPTURE)) {
        return 0;
    }

    $names = $matches[1];       // [ [block_name, offset_of_name], ... ]
    $full_tags = $matches[0];   // [ [full_tag_text, offset_of_tag_start], ... ]
    $count = count($names);
    $seq = 1;
    $inserted = 0;

    // id_user refreshed on conflict, same as block_type/block_text -- consistent with every
    // other real column here. In practice it'll never actually change across re-runs of the
    // same upload (upload_id is part of the unique key, so a re-split always traces back to
    // the same uploader), but there's no reason to special-case it as sticky when nothing else
    // on this table is.
    $stmt = $conn->prepare(
        "INSERT INTO raw_upload_blocks (upload_id, block_seq, block_type, block_text, id_user)
         VALUES (:upload_id, :block_seq, :block_type, :block_text, :id_user)
         ON DUPLICATE KEY UPDATE block_type = VALUES(block_type), block_text = VALUES(block_text), id_user = VALUES(id_user)"
    );

    for ($i = 0; $i < $count; $i++) {
        $block_type = trim($names[$i][0]);
        if ($block_type === 'Turnsheet') {
            continue;
        }

        $content_start = $full_tags[$i][1] + strlen($full_tags[$i][0]);
        $content_end = ($i + 1 < $count) ? $full_tags[$i + 1][1] : strlen($raw_text);
        $block_text = substr($raw_text, $content_start, $content_end - $content_start);

        $stmt->bindValue(':upload_id', $upload_id);
        $stmt->bindValue(':block_seq', $seq);
        $stmt->bindValue(':block_type', $block_type);
        $stmt->bindValue(':block_text', $block_text);
        $stmt->bindValue(':id_user', $id_user);
        $stmt->execute();
        $seq++;
        $inserted++;
    }

    return $inserted;
}

// Find-or-create a week (and its season, if needed) -- same logic as extract_standings.php
// used before this hook existed; now the single place this happens, since every upload is
// identified here before any extraction page ever sees it. New seasons get their initial
// status inferred from phase (preseason vs regular) rather than always assuming 'regular' --
// see the status logic below for why that distinction matters concretely, not just in theory.
//
// B1, 24 Aug 2026: takes a new $phase argument (weeks.phase, ENUM('preseason','regular',
// 'postseason') -- added same day, unique key widened from (season_id, week_number) to
// (season_id, phase, week_number)). Threaded through from dadabik_identify_upload()'s new
// return value. Before this, week_number alone was assumed unique within a season -- true
// for every football shape seen so far, but false for baseball, whose "Playoff Week N" and
// regular "Week N" reuse the same digits (findings/B1-header-shape-and-week-collision.md).
function dadabik_resolve_or_create_week($conn, $league_id, $year, $week_number, $phase, $turn_number) {
    $stmt = $conn->prepare("SELECT season_id FROM seasons WHERE league_id = :league_id AND year = :year");
    $stmt->bindParam(':league_id', $league_id);
    $stmt->bindParam(':year', $year);
    $stmt->execute();
    $season_id = $stmt->fetchColumn();

    if (!$season_id) {
        // Status inferred from whichever week happened to trigger this season's creation --
        // best-effort, not a guarantee (a season could theoretically get created from an
        // out-of-order upload), but more accurate than always assuming 'regular' regardless.
        // Matters concretely: a franchise eliminated early can start receiving pre-season
        // turns for the NEXT season while other franchises in the same league are still
        // finishing the previous one's playoffs/bowls -- confirmed this really happens, not
        // hypothetical -- so a brand new season's first-ever upload being its own pre-season
        // week is a real, expected case, not an edge case worth ignoring. Reads $phase now
        // rather than re-deriving the same fact from ($week_number === 0) -- behaviourally
        // identical for every case seen before this fix (week_number was 0 iff pre-season),
        // but $phase is now the authoritative signal since a postseason week can also carry
        // a low week_number (1-3) without being pre-season.
        $initial_status = ($phase === 'preseason') ? 'preseason' : 'regular';
        $season_id = ddb_api::insert_record('seasons',
            ['league_id', 'year', 'status'], [$league_id, $year, $initial_status]);
        $stmt = $conn->prepare("SELECT code FROM leagues WHERE league_id = :id");
        $stmt->bindParam(':id', $league_id);
        $stmt->execute();
        $league_code = $stmt->fetchColumn();
        ddb_api::update_records('seasons', 'season_id', $season_id, ['label'], ["$league_code $year"]);
    }

    $stmt = $conn->prepare(
        "SELECT week_id FROM weeks WHERE season_id = :season_id AND phase = :phase AND week_number = :week_number"
    );
    $stmt->bindParam(':season_id', $season_id);
    $stmt->bindParam(':phase', $phase);
    $stmt->bindParam(':week_number', $week_number);
    $stmt->execute();
    $week_id = $stmt->fetchColumn();

    if (!$week_id) {
        $season_label = ddb_api::get_record_details('seasons', 'season_id', $season_id)['label'];
        $week_id = ddb_api::insert_record('weeks',
            ['season_id', 'phase', 'week_number', 'turn_number'], [$season_id, $phase, $week_number, $turn_number]);
        ddb_api::update_records('weeks', 'week_id', $week_id, ['label'], ["$season_label Wk $week_number"]);
    }

    return $week_id;
}

function dadabik_get_season_id_for_week($conn, $week_id) {
    $stmt = $conn->prepare("SELECT season_id FROM weeks WHERE week_id = :week_id");
    $stmt->bindParam(':week_id', $week_id);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function dadabik_mark_upload_error($upload_id, $message) {
    ddb_api::update_records('raw_uploads', 'upload_id', $upload_id,
        ['parse_status', 'parse_notes'], ['error', $message]);
}
?>

<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// H26 (18-Aug-2026): no error_handler.php include and no ini_set('display_errors') here.
// DaDaBIK's bootstrap includes error_handler.php before any custom page runs (measured),
// and display_errors is a php.ini setting per environment. See H26.

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

// ------------------------------------------------------------------
// Extract Standings -- first of a planned SERIES of staged, manually
// -triggered extraction pages (not a Dadabik hook -- see conversation:
// deliberately chosen over hooks for visibility/control/timeout-safety
// given how much parsing a single turn file needs). Originally named
// "Extract League Report" -- renamed once it became clear that's a
// misleading description: this page only ever targeted the 'Standings'
// sub-block specifically, not the broader 'League Report' block, which
// ALSO contains per-game results and full team stat lines for every
// game played that week (out of scope here -- that's "Extract Games",
// a separate page, since it's a substantially different extraction
// targeting a different part of the same block, feeding `games` and
// `team_game_stats` rather than `standings_weekly`), plus the week's
// schedule, free agent list, and transaction notices (out of scope
// for either page).
//
// Confirmed by reading real turn files, not assumed:
//   - Pro (NFLAR) standings: divisions paired two-per-line with <U>...<UC>
//     headers, teams paired two-per-line, 7 stat columns (W L T FOR AGN
//     Div SK).
//   - College (NCAA5) standings: NO division headers at all, one flat
//     list, one team per line, only 6 stat columns (W L T FOR AGN SK --
//     no Div record) -- matches the established fact that NCAA5 doesn't
//     use conference/division grouping in standings. Building one parser
//     against only the pro sample would have silently failed (or thrown
//     confusing errors) on every college upload.
//
// Idempotent by design: standings_weekly has UNIQUE KEY (week_id,
// franchise_id), and every coach's turn repeats the SAME league-wide
// standings -- so this page uses INSERT ... ON DUPLICATE KEY UPDATE and
// is safe to re-run on the same upload, or on a DIFFERENT franchise's
// upload for the same week, without creating duplicates or needing any
// "already extracted" tracking.
//
// League/season/week identification (and block-splitting itself) now
// happens once, upstream, in the after-insert hook on raw_uploads
// (dadabik_process_raw_upload, in operational_hooks.php) -- this page
// used to have its own fallback for that, removed once the hook existed
// to do it reliably at upload time instead.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h1>Extract Standings</h1>";

$_cp_upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;

// -------------------- Extraction (buffered) --------------------
// The extraction runs BEFORE the upload selector is queried, and its output is held in a
// buffer until after the selector has been rendered.
//
// Why: the selector excludes uploads whose week is already done. Building it first meant it
// was querying the database BEFORE this request had written anything -- so the turn you had
// just processed was still listed, and only disappeared on the next page load. Harmless
// (re-selecting it would find the week already done) but confusing when working through a
// season one week at a time.
//
// The do/while(false) wrapper exists so the early exits below can stay as `break` instead of
// `return`. In an included file `return` exits the whole file, which would skip the selector
// entirely and leave no way to pick the next turn -- strictly worse than the bug being fixed.
// A function wrapper would also work but would change variable scope for the whole block;
// this keeps scope identical and the diff mechanical.
//
// The single closing </div> now lives at the very end, after the buffer is emitted, rather
// than being repeated before each early exit.
ob_start();
do {


    if (!$_cp_upload_id) {
        break;
    }

    // -------------------- Fetch the Standings block --------------------
    $_cp_sql = "SELECT block_text FROM raw_upload_blocks WHERE upload_id = :uid AND block_type = 'Standings'";
    $_cp_stmt = $conn->prepare($_cp_sql);
    $_cp_stmt->bindParam(':uid', $_cp_upload_id);
    $_cp_stmt->execute();
    $_cp_block_text = $_cp_stmt->fetchColumn();

    if (!$_cp_block_text) {
        echo "<p><em>No 'Standings' block found for this upload. Has it been split into blocks yet?</em></p>";
        break;
    }

    // -------------------- Confirm identification (done by the after-insert hook, not here) --------------------
    // League/season/week identification now happens once, in the after-insert hook on raw_uploads
    // (dadabik_process_raw_upload), at the moment a file is uploaded -- not in this page anymore.
    // Every upload reaching this dropdown should already have it set; if not, something upstream
    // (the hook itself, or block-splitting) didn't complete, and that's what needs investigating,
    // not a fallback re-derivation here.
    $_cp_upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $_cp_upload_id);

    if (!$_cp_upload['league_id'] || !$_cp_upload['season_id'] || !$_cp_upload['week_id']) {
        echo "<p><em>This upload has no league/season/week identified yet. Check its <code>parse_status</code>/<code>parse_notes</code> -- "
           . "the after-insert hook should have identified it automatically when it was uploaded.</em></p>";
        break;
    }

    $_cp_league_id = $_cp_upload['league_id'];
    $_cp_week_id = $_cp_upload['week_id'];

    $_cp_stmt = $conn->prepare("SELECT code FROM leagues WHERE league_id = :id");
    $_cp_stmt->bindParam(':id', $_cp_league_id);
    $_cp_stmt->execute();
    $_cp_league_code = $_cp_stmt->fetchColumn();

    echo "<p>Upload identified as <strong>" . htmlspecialchars($_cp_league_code) . "</strong>, week_id $_cp_week_id.</p>";

    // -------------------- Parse --------------------
    $_cp_is_pro = ($_cp_league_code !== 'NCAA5');
    $_cp_rows = $_cp_is_pro
        ? parse_standings_pro($_cp_block_text)
        : parse_standings_college($_cp_block_text);

    // Same positional check as the dropdown filter above -- only the first 300 characters, not
    // the whole block. Lower-risk here since $_cp_rows is already confirmed empty by the actual
    // parse before this even runs, but still worth being consistent: every block ends with next
    // week's schedule regardless of whether THIS week's own table was real, so an unanchored
    // check could mislabel a genuine parsing failure as "expected, pre-season" if the block
    // happened to contain the word "Schedule" anywhere later on.
    if (empty($_cp_rows) && preg_match('/Week\s+\d+\s+Schedule/', substr($_cp_block_text, 0, 300))) {
        // Pre-season uploads: the Standings block contains next week's schedule instead of a
        // win-loss table -- there's nothing meaningful to show yet since no games have been
        // played. Confirmed directly against a real pre-season upload's actual block content,
        // not assumed. Zero rows here is the CORRECT outcome, not a failure -- this message
        // exists so that's clear rather than looking like a silent, unexplained zero.
        echo "<p><em>This upload's Standings block contains next week's schedule instead of a "
           . "standings table -- expected for a pre-season week, since no games have been played "
           . "yet. 0 rows is the correct outcome here, not a failure.</em></p>";
        break;
    }

    echo "<p>Found " . count($_cp_rows) . " team rows in the Standings block.</p>";

    // -------------------- Resolve franchises + upsert --------------------
    $_cp_resolved = 0;
    $_cp_unresolved = [];

    // uploaded_by, not raw_uploads.id_user -- id_user on raw_uploads turned out to be redundant
    // with a pre-existing, already-working DaDaBIK-native column (uploaded_by), see
    // migration_drop_raw_uploads_id_user.sql. This standings_weekly.id_user column itself is
    // unaffected -- still genuinely new, still worth having -- only its source changed. Refreshed
    // on conflict (id_user = VALUES(id_user) below), matching every other real column on this
    // table -- source_upload_id already updates to reflect whichever upload most recently supplied
    // a (week, franchise) row's numbers, so id_user should track the same "most recent source"
    // rather than staying sticky to the first.
    //
    // Worth flagging plainly regardless: a single Standings block reports on all 24 franchises in
    // the league, not just the uploading coach's own -- so id_user here ends up meaning "whoever
    // most recently uploaded a turn that included this week's standings," not "whose franchise
    // this row is about." It won't usefully support a future "coach sees only their own data" gate
    // the way it will on raw_upload_blocks/games/team_game_stats/plays (each of those stays scoped
    // to the uploader's own game). Adding the column here anyway, for consistency and as an audit
    // trail of source -- standings are public content regardless (security.md), so nothing is
    // actually relying on ownership-based gating here either way.
    $_cp_upload_owner = $_cp_upload['uploaded_by'] ?? null;

    $_cp_upsert_sql = "INSERT INTO standings_weekly
            (week_id, franchise_id, wins, losses, ties, points_for, points_against,
             division_record, streak, conference, division, source, source_upload_id, id_user)
        VALUES (:week_id, :franchise_id, :wins, :losses, :ties, :points_for, :points_against,
             :division_record, :streak, :conference, :division, 'parsed', :upload_id, :id_user)
        ON DUPLICATE KEY UPDATE
            wins = VALUES(wins), losses = VALUES(losses), ties = VALUES(ties),
            points_for = VALUES(points_for), points_against = VALUES(points_against),
            division_record = VALUES(division_record), streak = VALUES(streak),
            conference = VALUES(conference), division = VALUES(division),
            source = 'parsed', source_upload_id = VALUES(source_upload_id), id_user = VALUES(id_user)";
    $_cp_upsert_stmt = $conn->prepare($_cp_upsert_sql);

    // -------------------- A4 package 2, pass 1: write this week's identity rows --------------------
    // Two passes, deliberately (A4 §11's ordering problem). Pass 1 resolves each DISTINCT team
    // name once and writes its franchise_identities row for THIS week; pass 2 (the upsert loop
    // below) then resolves at week grain against a table that already contains this week. A
    // single pass would make the franchises.label fallback fire for every team on every first
    // parse of a week, which destroys the signal value of logging it -- and the log is the whole
    // point of keeping a fallback at all (schema.md §12: "a silent fallback would rebuild the
    // exact defect the repoint exists to remove").
    //
    // Standings is the wider of the two identity sources (D1): it lists every franchise in the
    // league including ones on a bye, which extract_games.php cannot see. It has no block at all
    // in playoff/bowl/bye weeks, which is why both parsers write and neither is enough alone.
    $_cp_identity_log = [];
    $_cp_identity_rows = 0;
    $_cp_franchise_ids = [];

    // Snapshot BEFORE the loop, and pass it in on every call. Pass 1 writes identity rows as it
    // goes, so a resolver left to check the count itself would see team 1's freshly written row
    // when classifying team 2 -- turning one honest 'seed' into eleven false 'GAP's on a brand
    // new week. Observed live, NCAA5 2039 wk4.
    $_cp_week_had_identities = a4_week_has_identities($conn, $_cp_league_id, $_cp_week_id);

    foreach ($_cp_rows as $row) {
        $_cp_name = $row['team_name'];
        if (array_key_exists($_cp_name, $_cp_franchise_ids)) { continue; }
        $_cp_fid = a4_resolve_franchise_at_week($conn, $_cp_league_id, $_cp_week_id, $_cp_name, $_cp_identity_log, $_cp_week_had_identities);
        $_cp_franchise_ids[$_cp_name] = $_cp_fid;
        if ($_cp_fid) {
            $_cp_identity_rows += a4_write_identity($conn, $_cp_league_id, $_cp_week_id, $_cp_fid, $_cp_name, $_cp_identity_log);
        }
    }

    // -------------------- pass 2: resolve at week grain, then upsert --------------------
    foreach ($_cp_rows as $row) {
        $franchise_id = a4_resolve_franchise_at_week($conn, $_cp_league_id, $_cp_week_id, $row['team_name'], $_cp_identity_log);

        if (!$franchise_id) {
            $_cp_unresolved[] = $row['team_name'];
            continue;
        }

        $_cp_upsert_stmt->bindValue(':week_id', $_cp_week_id);
        $_cp_upsert_stmt->bindValue(':franchise_id', $franchise_id);
        $_cp_upsert_stmt->bindValue(':wins', $row['wins']);
        $_cp_upsert_stmt->bindValue(':losses', $row['losses']);
        $_cp_upsert_stmt->bindValue(':ties', $row['ties']);
        $_cp_upsert_stmt->bindValue(':points_for', $row['points_for']);
        $_cp_upsert_stmt->bindValue(':points_against', $row['points_against']);
        $_cp_upsert_stmt->bindValue(':division_record', $row['division_record']);
        $_cp_upsert_stmt->bindValue(':streak', $row['streak']);
        $_cp_upsert_stmt->bindValue(':conference', $row['conference']);
        $_cp_upsert_stmt->bindValue(':division', $row['division']);
        $_cp_upsert_stmt->bindValue(':upload_id', $_cp_upload_id);
        $_cp_upsert_stmt->bindValue(':id_user', $_cp_upload_owner);
        $_cp_upsert_stmt->execute();
        $_cp_resolved++;
    }

    echo "<div class='w3-panel w3-pale-green w3-text-black w3-round-large'>";
    echo "<p><strong>$_cp_resolved</strong> rows written to standings_weekly.</p>";
    echo "<p><strong>$_cp_identity_rows</strong> franchise_identities rows written/refreshed for this week "
       . "(derived_from = 'week').</p>";
    echo "</div>";

    // Fallback/refusal log. Rendered whether or not anything else went wrong: a fallback that
    // fires is not an error, but it IS the thing worth reading. 'seed' lines are expected on a
    // week's first parse; 'GAP' and 'AMBIGUOUS' lines are not.
    if ($_cp_identity_log) {
        echo "<div class='w3-panel w3-pale-yellow w3-text-black w3-round-large'>";
        echo "<p><strong>Identity resolution notes (" . count($_cp_identity_log) . "):</strong></p><ul>";
        foreach ($_cp_identity_log as $line) { echo "<li>" . htmlspecialchars($line) . "</li>"; }
        echo "</ul></div>";
    }

    if ($_cp_unresolved) {
        echo "<div class='w3-panel w3-pale-red w3-text-black w3-round-large'>";
        echo "<p><strong>" . count($_cp_unresolved) . "</strong> team name(s) could not be matched to a franchise "
           . "(no row in <code>franchises</code> with that exact label for this league):</p>";
        echo "<ul>";
        foreach ($_cp_unresolved as $name) {
            echo "<li>" . htmlspecialchars($name) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

} while (false);
$_cp_body = ob_get_clean();

// -------------------- Upload selector --------------------
// Excludes uploads for two distinct, confirmed reasons -- both cases where standings_weekly
// can never get rows for this week, not just "not yet processed":
//   1. No 'Standings' block at all -- playoff/bowl/bye weeks (confirmed directly: no running
//      win-loss table once a season moves into a single-elimination bracket, or once there's
//      no game played at all for a bye).
//   2. A 'Standings' block that DOES exist, but contains next week's schedule instead of a
//      table -- pre-season weeks specifically (confirmed directly: nothing meaningful to
//      show before any games have been played, so the engine substitutes the schedule under
//      the same block marker).
// Checked against only the FIRST 300 characters of block_text, not the whole thing -- a real
// bug caught against a genuine, non-pre-season upload (NFLAR Week 15): EVERY Standings block
// ends with next week's schedule (confirmed: every turn shows that week's own standings AND
// next week's schedule, not just pre-season ones), so checking the whole block for "Week...
// Schedule" anywhere matched this completely normal week too, not just genuine pre-season
// cases -- which would have wrongly excluded nearly every regular-season upload from this
// list. The real distinguishing signal is POSITION, not presence: confirmed directly, a
// genuine pre-season block has "Week N Schedule" ~113 characters in, immediately after the
// header with no real table before it; this normal week had it ~2650+ characters in, after a
// full standings table. 300 gives comfortable margin above the former, well below the latter.
// Not via a dedicated tracking column -- reasonable for two confirmed cases; if a third
// distinct "nothing to extract" pattern turns up later, a more general flag/column approach
// would be worth reconsidering rather than continuing to bolt on more LIKE conditions here.
// Also excludes uploads whose week already has standings_weekly rows -- "already processed".
// Checked via "does standings_weekly have any row for this week_id" rather than a dedicated
// tracking column, since standings are league-wide per week (every franchise's row gets
// written together by the same upsert), not per-upload -- if any row exists for a week,
// that whole week's standings are already in, regardless of which specific upload did it.
// Not-yet-identified/not-yet-split uploads still show, deliberately -- there's no confirmed
// answer yet for those, so excluding them would be a guess, not a fact.
//
// parse_status = 'duplicate' IS excluded, and that one is a fact rather than a guess:
// operational_hooks.php sets it when a file's content is identical to an earlier upload,
// deliberately skipping block-splitting. Such rows have no blocks and no league/season/week,
// so there is nothing here to extract from them -- ever. Before this exclusion they rendered
// as "(not yet identified)", which was actively misleading: it reads like something that
// might resolve later, when in fact the original upload was processed correctly and this is
// a re-upload of the same file.
//
// Also excludes leagues.game_type = 'baseball'. This page's two parsers (parse_standings_pro,
// parse_standings_college) both target football's Standings shape only -- confirmed live
// against a real baseball upload (MLB6 week 15, upload_id 63): baseball's Standings block is
// real and non-empty (division-grouped, exactly like football's), but its columns are Won Lst
// Pct GB Lst9 Str RFor RAgn Home Road LPs Fans Std Mrc Min Wag, nothing like football's W L T
// FOR AGN <division-record> <streak>. Neither existing regex matches a single baseball line,
// so before this exclusion selecting a baseball turn here silently produced "Found 0 team rows
// in the Standings block" with no identity log and no explanation -- indistinguishable from a
// genuinely-processed empty week, the exact silent-zero failure the pre-season-schedule check
// above exists to prevent, just with no equivalent guard for "wrong sport". Confirmed against
// B1-lessons.md and the B2 baseball roadmap: no baseball equivalent of this page is built or
// even scheduled (B2-B6 are Team Roster/Stats/Comparison/League Roster/League Stats, not a
// Standings page), so this isn't a stopgap pending baseball support catching up.
// Gated on leagues.game_type (added by the baseball work) rather than a hardcoded league-code
// list -- confirmed live: 9 football league codes and 9 baseball league codes exist in total,
// most with no uploads yet, so a code list would silently miss every league not yet seen.
// game_type IS NULL is kept IN (not excluded), matching this query's own policy just above for
// not-yet-identified uploads: league_id not yet resolved is "no confirmed answer yet", not a
// confirmed baseball turn, so excluding it here would be the same kind of guess.
$_cp_sql = "SELECT ru.upload_id, ru.original_filename, ru.turn_number,
                   l.code AS league_code, s.year AS season_year, w.week_number
            FROM raw_uploads ru
            LEFT JOIN leagues l ON l.league_id = ru.league_id
            LEFT JOIN seasons s ON s.season_id = ru.season_id
            LEFT JOIN weeks w ON w.week_id = ru.week_id
            WHERE NOT EXISTS (
                SELECT 1 FROM standings_weekly sw WHERE sw.week_id = ru.week_id
            )
            AND (
                ru.parse_status != 'partial'
                OR EXISTS (
                    SELECT 1 FROM raw_upload_blocks rub
                    WHERE rub.upload_id = ru.upload_id AND rub.block_type = 'Standings'
                      AND LEFT(rub.block_text, 300) NOT LIKE '%Week%Schedule%'
                )
            )
            AND ru.parse_status <> 'duplicate'
            AND (l.game_type = 'football' OR l.game_type IS NULL)
            ORDER BY s.year IS NULL, s.year ASC,
                     w.week_number IS NULL, w.week_number ASC,
                     ru.upload_id ASC";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute();
$_cp_uploads = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<form method='get'>";
// Hidden inputs preserving Dadabik's own routing params (function=show_static_page&
// id_static_page=N) -- a GET form with no explicit action submits to the current path but
// replaces the ENTIRE query string with only its own fields, dropping these -- Dadabik then
// doesn't recognize the request as "show this static page" and falls through to its default
// (home) page instead. Same fix already applied to current_standings.php's league toggle and
// team.php's franchise selector -- missed here when this page was first built, even though I
// already knew the pattern by then. Read from the current request rather than hardcoded, so
// it's self-correcting across any future reinstall.
echo "<input type='hidden' name='function' value='" . htmlspecialchars($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . htmlspecialchars($_GET['id_static_page'] ?? '') . "'>";
if (empty($_cp_uploads)) {
    echo "<p><em>Nothing left to process -- every uploaded turn either already has its standings extracted, or genuinely has no Standings block to extract (playoff/bowl weeks).</em></p>";
}
echo "<select name='upload_id' onchange='this.form.submit()' style='width:480px'>";
echo "<option value=''>-- select a turn --</option>";
foreach ($_cp_uploads as $u) {
    $identified = $u['league_code']
        ? "{$u['league_code']} {$u['season_year']} Wk {$u['week_number']}"
        : 'not yet identified';
    $sel = (isset($_GET['upload_id']) && $_GET['upload_id'] == $u['upload_id']) ? 'selected' : '';
    echo "<option value='{$u['upload_id']}' $sel>"
       . htmlspecialchars("{$u['original_filename']} ($identified)") . "</option>";
}
echo "</select>";
echo "</form><br>";

// Emit the extraction output, which is now reporting against a database the selector
// above has already seen the effects of.
echo $_cp_body;
echo "</div>";


// --------------------------------------------------------------
// Helper functions
// --------------------------------------------------------------

// Pro standings: divisions paired two-per-line via <U>Name<UC>, teams paired two-per-line,
// 7 stat columns including a division record. Walks the block line by line, tracking which
// pair of divisions is currently "active" until the next header line replaces it.
function parse_standings_pro($text) {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $rows = [];
    $current_divisions = [null, null];

    // Excludes BOTH '(' and '>' from the name capture -- excluding only '(' was tried first
    // and tested against real data, which caught a real bug: two teams share a line separated
    // by "<T>", and without excluding '>' too, the non-greedy name match can start matching
    // from partway through that preceding tag (e.g. capturing "T>Philadelphia Eagles" instead
    // of "Philadelphia Eagles"), since '>' alone doesn't stop it.
    // Streak group is [WLT], not [WL] -- confirmed a real upload had two teams sitting on a
    // tie streak ("T1"), which the original win/loss-only pattern silently failed to match at
    // all, dropping those rows from the results entirely with no error or warning (they never
    // reached the franchise-resolution step, so didn't even show up as "unresolved").
    $team_pattern = '/([^(<>]+?)\s*\(([^)]+)\)<T>\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+-\d+)\s+([WLT]\d+)/';

    foreach ($lines as $line) {
        if (preg_match_all('/<U>([^<]+)<UC>/', $line, $div_matches)) {
            $current_divisions = [$div_matches[1][0] ?? null, $div_matches[1][1] ?? null];
            continue;
        }
        if (preg_match_all($team_pattern, $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $match) {
                $division = $current_divisions[$i] ?? null;
                $conference = $division ? trim(explode(' ', $division)[0]) : null;
                $rows[] = [
                    'team_name' => trim($match[1]),
                    'wins' => (int)$match[3], 'losses' => (int)$match[4], 'ties' => (int)$match[5],
                    'points_for' => (int)$match[6], 'points_against' => (int)$match[7],
                    'division_record' => $match[8], 'streak' => $match[9],
                    'conference' => $conference, 'division' => $division,
                ];
            }
        }
    }
    return $rows;
}

// College standings: flat list, one team per line, no division headers, 6 stat columns
// (no division record). conference/division are always NULL here -- matches the
// established fact that NCAA5 doesn't use conference/division grouping in standings.
function parse_standings_college($text) {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $rows = [];

    $team_pattern = '/([^(<>]+?)\s*\(([^)]+)\)<T>\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+([WLT]\d+)/';

    foreach ($lines as $line) {
        if (preg_match($team_pattern, $line, $match)) {
            $rows[] = [
                'team_name' => trim($match[1]),
                'wins' => (int)$match[3], 'losses' => (int)$match[4], 'ties' => (int)$match[5],
                'points_for' => (int)$match[6], 'points_against' => (int)$match[7],
                'division_record' => null, 'streak' => $match[8],
                'conference' => null, 'division' => null,
            ];
        }
    }
    return $rows;
}
?>

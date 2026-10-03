<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// H26 (18-Aug-2026): no error_handler.php include and no ini_set('display_errors') here.
// DaDaBIK's bootstrap includes error_handler.php before any custom page runs (measured),
// and display_errors is a php.ini setting per environment. See H26.

// replay.php's registered id_static_page -- was a 0 placeholder, now the real value.
define('REPLAY_PAGE_STATIC_ID', 11);

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
// Extract Play-by-Play -- third staged extraction page (after Standings, Games).
// Targets the '1st Quarter' through '4th Quarter' blocks -- per-play granularity for the
// specific game THIS franchise played that week (unlike League Report, which covers every
// game league-wide). Populates `plays` only -- NOT `drives`, which is structurally distinct
// (populated from "Scouting Report - Game Summary", a different game entirely: next week's
// opponent's most recent one -- see conversation).
//
// Every rule below was confirmed against real turn files before being encoded, not assumed --
// see conversation for the full derivation of each:
//   - Overtime lives WITHIN the 4th Quarter block, under a "<B>Overtime<C>" heading, not its
//     own <BK.> marker. Plays after that heading get quarter=5; the cumulative clock keeps
//     counting past 60:00 rather than resetting.
//   - A blank "side" column means "same possession as the previous play" -- EXCEPT for the
//     very first play after any non-play marker line (two-minute warning, quarter-end
//     summary), which always has an explicit side. Detected structurally: a real play row
//     always starts with a digit; a marker/summary line never does.
//   - Kickoff and onsides-kick rows have no formation letter or field position printed at
//     all -- formation is synthesized as 'X' per explicit instruction, matching the legacy
//     migration's own convention for the same situation.
//   - Scoring plays can span two physical lines: the first ends in its own "<L>", the
//     continuation carries the rest of the description plus the "<T>score<C>" suffix. Merged
//     into one play by only accepting an "<L>" as a true play-ending when it is NOT
//     immediately followed by a lowercase-starting continuation line.
//   - QB benching/replacement announcements ("<Z>X benched, and replaced by Y<C>") consume
//     that row's time+side entirely -- the REAL play that follows has no time of its own.
//     Confirmed directly: borrow the time+side from the announcement line and attach it to
//     the following line's real play data; the announcement text itself is discarded.
//   - "quarterback flop" rows (clock-killing knee-down at a dead clock) have field position
//     and down/distance but no formation/off/def columns at all -- confirmed these should be
//     dropped entirely, not treated as a real play.
//   - yards_gained: "AT gain/loss of N" is a cumulative position marker (the LAST such
//     mention in a play wins); a trailing "FOR gain/loss of N" is an ADDITIONAL increment on
//     top of the last AT position. Validated two independent ways against real plays: down/
//     distance progression, and field-position delta on the following play (only valid when
//     the same team retains possession). Interceptions are a confirmed special case:
//     yards_gained is always 0 regardless of any yardage mentioned in the text (that number
//     describes how far the pass traveled, not an offensive gain). Fumbles use the actual
//     gained yardage up to the point of the fumble, per the AT/FOR rule above.
//   - field_position is yards REMAINING to the opponent's goal line (1-99) -- a gain
//     DECREASES it. Confirmed directly; this was initially assumed backwards.
//   - is_first_down is a computed comparison (yards_gained >= yards_to_go on this same
//     play), not a text pattern -- deliberately has no counterpart in play_text_patterns.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h1>Extract Play-by-Play</h1>";

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

    // -------------------- Resolve upload, game, teams --------------------
    $_cp_upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $_cp_upload_id);

    if (!$_cp_upload['league_id'] || !$_cp_upload['week_id'] || !$_cp_upload['franchise_id']) {
        echo "<p><em>This upload has no league/week/franchise identified yet.</em></p>";
        break;
    }

    $_cp_week_id = $_cp_upload['week_id'];
    $_cp_franchise_id = $_cp_upload['franchise_id'];
    // uploaded_by, not raw_uploads.id_user -- id_user on raw_uploads turned out to be redundant
    // with a pre-existing, already-working DaDaBIK-native column (uploaded_by), see
    // migration_drop_raw_uploads_id_user.sql. plays.id_user below is unaffected -- still genuinely
    // new, still worth having -- only this one source changed.
    $_cp_upload_owner = $_cp_upload['uploaded_by'] ?? null;

    $_cp_stmt = $conn->prepare(
        "SELECT g.game_id, g.home_franchise_id, g.away_franchise_id,
                fh.label AS home_label, fa.label AS away_label
         FROM games g
         JOIN franchises fh ON fh.franchise_id = g.home_franchise_id
         JOIN franchises fa ON fa.franchise_id = g.away_franchise_id
         WHERE g.week_id = :week_id
           AND (g.home_franchise_id = :fid OR g.away_franchise_id = :fid2)"
    );
    $_cp_stmt->execute([':week_id' => $_cp_week_id, ':fid' => $_cp_franchise_id, ':fid2' => $_cp_franchise_id]);
    $_cp_game = $_cp_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$_cp_game) {
        echo "<p><em>No game found for this franchise/week in the `games` table yet -- run Extract Games for this upload's week first.</em></p>";
        break;
    }

    $_cp_game_id = $_cp_game['game_id'];
    // Cast to int: these are now compared with === against the int returned by
    // a4_resolve_franchise_by_code_at_week, and PDO hands back strings by default.
    $_cp_home_id = (int)$_cp_game['home_franchise_id'];
    $_cp_away_id = (int)$_cp_game['away_franchise_id'];
    $_cp_home_label = $_cp_game['home_label'];
    $_cp_away_label = $_cp_game['away_label'];

    // Heading names come from franchise_identities for THIS week where a row exists, falling back
    // to the present-day label. schema.md §12: never use franchises.label to describe a historical
    // game. Display only -- no data depends on it -- but this heading is exactly the sentence §12
    // is about, and it is what a coach reads to confirm the right game was picked.
    $_cp_stmt = $conn->prepare(
        "SELECT franchise_id, team_name FROM franchise_identities WHERE week_id = :week_id AND franchise_id IN (:home, :away)"
    );
    $_cp_stmt->execute([':week_id' => $_cp_week_id, ':home' => $_cp_home_id, ':away' => $_cp_away_id]);
    $_cp_week_names = [];
    foreach ($_cp_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $_cp_week_names[(int)$r['franchise_id']] = $r['team_name'];
    }
    $_cp_home_name = $_cp_week_names[$_cp_home_id] ?? $_cp_home_label;
    $_cp_away_name = $_cp_week_names[$_cp_away_id] ?? $_cp_away_label;

    echo "<p>Game identified: <strong>" . htmlspecialchars($_cp_home_name) . "</strong> vs <strong>"
       . htmlspecialchars($_cp_away_name) . "</strong> (game_id $_cp_game_id).</p>";

    // -------------------- Fetch quarter blocks --------------------
    $_cp_stmt = $conn->prepare(
        "SELECT block_type, block_text FROM raw_upload_blocks
         WHERE upload_id = :uid AND block_type IN ('1st Quarter','2nd Quarter','3rd Quarter','4th Quarter')"
    );
    $_cp_stmt->bindParam(':uid', $_cp_upload_id);
    $_cp_stmt->execute();
    $_cp_quarter_blocks = [];
    foreach ($_cp_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $_cp_quarter_blocks[$row['block_type']] = $row['block_text'];
    }

    if (empty($_cp_quarter_blocks)) {
        echo "<p><em>No quarter blocks found for this upload. Has it been split into blocks yet?</em></p>";
        break;
    }

    // -------------------- Parse each quarter --------------------
    $_cp_all_plays = [];
    $_cp_last_offense = null; // carried forward across quarters -- possession doesn't reset at a quarter boundary

    $_cp_quarter_map = ['1st Quarter' => 1, '2nd Quarter' => 2, '3rd Quarter' => 3, '4th Quarter' => 4];
    foreach (['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'] as $qname) {
        if (!isset($_cp_quarter_blocks[$qname])) { continue; }
        $qnum = $_cp_quarter_map[$qname];
        $plays = parse_quarter_plays($_cp_quarter_blocks[$qname], $qnum);
        foreach ($plays as $p) { $_cp_all_plays[] = $p; }
    }

    echo "<p>Found " . count($_cp_all_plays) . " plays.</p>";

    // -------------------- Resolve team_codes, build final rows, upsert --------------------
    $_cp_team_code_stmt = $conn->prepare("SELECT team_name FROM team_codes WHERE code = :code");

    $_cp_play_upsert = $conn->prepare(
        "INSERT INTO plays (game_id, quarter, play_seq, time_gone_seconds, offense_franchise_id,
             field_side, field_position, down, yards_to_go, formation, off_call, def_call, result_text,
             yards_gained, is_touchdown, is_fumble, is_turnover, is_penalty, is_penalty_offense,
             is_penalty_defense, is_sack, is_hurry, is_blitz_pickup, is_blitz_no_pickup, is_safety,
             is_incomplete, is_first_down, score_after, source_upload_id, id_user)
         VALUES (:game_id, :quarter, :play_seq, :time_gone_seconds, :offense_franchise_id,
             :field_side, :field_position, :down, :yards_to_go, :formation, :off_call, :def_call, :result_text,
             :yards_gained, :is_touchdown, :is_fumble, :is_turnover, :is_penalty, :is_penalty_offense,
             :is_penalty_defense, :is_sack, :is_hurry, :is_blitz_pickup, :is_blitz_no_pickup, :is_safety,
             :is_incomplete, :is_first_down, :score_after, :upload_id, :id_user)
         ON DUPLICATE KEY UPDATE
             time_gone_seconds = VALUES(time_gone_seconds), offense_franchise_id = VALUES(offense_franchise_id),
             field_side = VALUES(field_side), field_position = VALUES(field_position), down = VALUES(down),
             yards_to_go = VALUES(yards_to_go), formation = VALUES(formation), off_call = VALUES(off_call), def_call = VALUES(def_call),
             result_text = VALUES(result_text), yards_gained = VALUES(yards_gained),
             is_touchdown = VALUES(is_touchdown), is_fumble = VALUES(is_fumble), is_turnover = VALUES(is_turnover),
             is_penalty = VALUES(is_penalty), is_penalty_offense = VALUES(is_penalty_offense),
             is_penalty_defense = VALUES(is_penalty_defense), is_sack = VALUES(is_sack), is_hurry = VALUES(is_hurry),
             is_blitz_pickup = VALUES(is_blitz_pickup), is_blitz_no_pickup = VALUES(is_blitz_no_pickup),
             is_safety = VALUES(is_safety), is_incomplete = VALUES(is_incomplete),
             is_first_down = VALUES(is_first_down), score_after = VALUES(score_after), id_user = VALUES(id_user)"
    );

    $_cp_patterns = load_play_text_patterns($conn);

    $_cp_written = 0;
    $_cp_unresolved_sides = [];
    $_cp_play_seq = 0;

    // A4 package 3 addendum -- the fourth lookup.
    // schema.md §12's table lists three parser lookups. This page has a fourth, of a different
    // shape and previously missed: it resolved each play's side code to a name via team_codes,
    // then string-compared that name against franchises.label -- the present-day snapshot. That
    // works only while a franchise's current label equals the identity it held in the week being
    // parsed, and it is wrong for the 4,969 franchise-weeks that diverge.
    //
    // Its failure mode was worse than the other three, which drop a row. Here a failed match left
    // $offense_id at $_cp_last_offense -- the PREVIOUS play's possession -- and the play was still
    // written. So a mismatch produced plays silently attributed to the wrong team, with a red
    // panel naming the unresolved codes but nothing saying possession had been guessed for every
    // play after them.
    //
    // Now resolved through franchise_identities at week grain, by CODE (the reliable direction:
    // §12 establishes code -> name is unique and permanent). Carry-forward on failure is
    // retained -- changing it to NULL would alter this page's write semantics, which is not this
    // task's call -- but it is now LOGGED, so the guess is visible instead of silent.
    //
    // Resolved once per distinct code and memoised: a 139-play game repeated the same two codes
    // dozens of times, and one log line per play would bury the signal it exists to carry.
    $_cp_identity_log = [];
    $_cp_side_cache = [];

    foreach ($_cp_all_plays as $play) {
        $_cp_play_seq++;

        // Blank side -> carry forward. Per the rule confirmed directly: a marker line (two-minute
        // warning, quarter-end) always precedes an explicit side on the next real play, so
        // blank-carry-forward is safe everywhere else.
        $offense_id = $_cp_last_offense;
        if ($play['side'] !== '') {
            $_cp_code = $play['side'];
            if (!array_key_exists($_cp_code, $_cp_side_cache)) {
                $_cp_side_cache[$_cp_code] = pbp_resolve_side_code(
                    $conn, $_cp_week_id, $_cp_code, $_cp_team_code_stmt,
                    $_cp_home_id, $_cp_home_label, $_cp_away_id, $_cp_away_label,
                    $_cp_identity_log
                );
            }
            if ($_cp_side_cache[$_cp_code] !== null) {
                $offense_id = $_cp_side_cache[$_cp_code];
            } else {
                $_cp_unresolved_sides[] = $_cp_code;
            }
            $_cp_last_offense = $offense_id;
        }

        $flags = apply_play_text_patterns($play['result_text'], $_cp_patterns);

        // An incomplete pass genuinely gains 0 yards -- that's a known fact, not an absence of
        // information, so NULL is the wrong representation here even though no "at/for gain/loss
        // of N yards" phrase appears in the text to extract a number from. Reuses the already-
        // computed is_incomplete flag (the same single source of truth play_text_patterns
        // already provides) rather than re-checking the text separately here.
        if ($flags['sets_incomplete'] && $play['yards_gained'] === null) {
            $play['yards_gained'] = 0;
        }

        // is_first_down: computed directly, not pattern-matched -- see file header. Left false
        // for goal-line plays (yards_to_go is null there; "first down" doesn't apply the same
        // way when the object of the play is reaching the endzone, which is_touchdown already
        // captures).
        $is_first_down = ($play['yards_to_go'] !== null && $play['yards_gained'] !== null
                           && $play['yards_gained'] >= $play['yards_to_go']) ? 1 : 0;

        $_cp_play_upsert->execute([
            ':game_id' => $_cp_game_id, ':quarter' => $play['quarter'], ':play_seq' => $_cp_play_seq,
            ':time_gone_seconds' => $play['time_gone_seconds'], ':offense_franchise_id' => $offense_id,
            ':field_side' => $play['side'] !== '' ? $play['side'] : null,
            ':field_position' => $play['field_position'], ':down' => $play['down'],
            ':yards_to_go' => $play['yards_to_go'], ':formation' => $play['formation'],
            ':off_call' => $play['off_call'], ':def_call' => $play['def_call'],
            ':result_text' => $play['result_text'], ':yards_gained' => $play['yards_gained'],
            ':is_touchdown' => $flags['sets_touchdown'], ':is_fumble' => $flags['sets_fumble'],
            ':is_turnover' => $flags['sets_turnover'], ':is_penalty' => ($flags['sets_penalty_offense'] || $flags['sets_penalty_defense']) ? 1 : 0,
            ':is_penalty_offense' => $flags['sets_penalty_offense'], ':is_penalty_defense' => $flags['sets_penalty_defense'],
            ':is_sack' => $flags['sets_sack'], ':is_hurry' => $flags['sets_hurry'],
            ':is_blitz_pickup' => $flags['sets_blitz_pickup'], ':is_blitz_no_pickup' => $flags['sets_blitz_no_pickup'],
            ':is_safety' => $flags['sets_safety'], ':is_incomplete' => $flags['sets_incomplete'],
            ':is_first_down' => $is_first_down, ':score_after' => $play['score_after'],
            ':upload_id' => $_cp_upload_id, ':id_user' => $_cp_upload_owner,
        ]);

        $_cp_written++;
    }

    echo "<div class='w3-panel w3-pale-green w3-text-black w3-round-large'>";
    echo "<p><strong>$_cp_written</strong> plays written to plays.</p>";
    if ($_cp_written > 0) {
        // Deliberately links to the standalone replay page (replay.php), not game.php -- this is
        // the moment right after upload, when the uploading coach is the one person who's about
        // to watch a game whose outcome they already fully know but would still enjoy replaying
        // suspensefully play-by-play. Linking to game.php here would put the final score directly
        // above this link, spoiling the exact experience the link is offering.
        $_cp_replay_link = htmlspecialchars(
            'index.php?function=show_static_page&id_static_page=' . REPLAY_PAGE_STATIC_ID
            . '&game=' . urlencode($_cp_game_id)
        );
        echo "<p><a href='$_cp_replay_link' class='w3-button w3-theme'>Watch the Live Replay</a></p>";
    }
    echo "</div>";

    // Side-code resolution notes. One line per DISTINCT code, not per play. Silence means every
    // side code resolved from franchise_identities at week grain; a 'fallback' or 'carried
    // forward' line means possession came from somewhere less reliable, and says which.
    if ($_cp_identity_log) {
        echo "<div class='w3-panel w3-pale-yellow w3-text-black w3-round-large'>";
        echo "<p><strong>Side-code resolution notes (" . count($_cp_identity_log) . "):</strong></p><ul>";
        foreach ($_cp_identity_log as $line) { echo "<li>" . htmlspecialchars($line) . "</li>"; }
        echo "</ul></div>";
    }

    if ($_cp_unresolved_sides) {
        echo "<div class='w3-panel w3-pale-red w3-text-black w3-round-large'>";
        echo "<p><strong>" . count($_cp_unresolved_sides) . "</strong> play(s) had a side code that could not be resolved to either of this game's two teams "
           . "-- each kept the previous play's possession, which is a GUESS. Codes:</p><ul>";
        foreach (array_unique($_cp_unresolved_sides) as $code) { echo "<li>" . htmlspecialchars($code) . "</li>"; }
        echo "</ul></div>";
    }

} while (false);
$_cp_body = ob_get_clean();

// -------------------- Upload selector --------------------
// Same pattern as extract_games.php: exclude uploads whose game already has plays rows.
// parse_status = 'duplicate' is excluded on a confirmed fact, not a guess:
// operational_hooks.php sets it when a file's content is identical to an earlier upload and
// deliberately skips block-splitting, so these rows have no blocks and no
// league/season/week -- there is nothing to extract from them, ever. Before this exclusion
// they rendered as "(not yet identified)", which reads like something that might resolve
// later; in fact the original upload processed fine and this is a re-upload of the same file.
// (Note the franchise_id IS NOT NULL check below already excluded them here in practice --
// this makes the reason explicit rather than incidental.)
//
// Also excludes leagues.game_type = 'baseball' (same gate as extract_standings.php/
// extract_games.php, added alongside them). This page resolves a game via the `games` table,
// which extract_games.php never populates for baseball turns (see that file's own comment) --
// so a baseball turn already failed safely here too, with an honest "no game found" outcome
// rather than a silent false success. Excluding it from the selector is still correct: there's
// nothing this page could ever extract from a baseball turn (no baseball play-by-play parser is
// built or scheduled -- see B1-lessons.md/B2 roadmap), so offering it just wastes a click.
// game_type IS NULL is kept IN, matching the other two pages' policy: a not-yet-identified
// upload has no confirmed answer yet, so excluding it here would be a guess, not a fact.
$_cp_sql = "SELECT ru.upload_id, ru.original_filename, ru.turn_number,
                   l.code AS league_code, s.year AS season_year, w.week_number
            FROM raw_uploads ru
            LEFT JOIN leagues l ON l.league_id = ru.league_id
            LEFT JOIN seasons s ON s.season_id = ru.season_id
            LEFT JOIN weeks w ON w.week_id = ru.week_id
            WHERE ru.franchise_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM games g
                JOIN plays p ON p.game_id = g.game_id
                WHERE g.week_id = ru.week_id
                  AND (g.home_franchise_id = ru.franchise_id OR g.away_franchise_id = ru.franchise_id)
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
echo "<input type='hidden' name='function' value='" . htmlspecialchars($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . htmlspecialchars($_GET['id_static_page'] ?? '') . "'>";
if (empty($_cp_uploads)) {
    echo "<p><em>Nothing left to process -- every uploaded turn with an identified franchise already has its play-by-play extracted.</em></p>";
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

// Resolves one play's side code to one of THIS GAME's two franchises (A4 package 3 addendum).
//
// Order matters, and is the point of the change:
//   1. franchise_identities at week grain, by code. If it names a franchise that IS one of this
//      game's two, that is the answer -- no name comparison anywhere in the path.
//   2. If it names a franchise that is NOT in this game, refuse. Do not fall through to the
//      label comparison: the identity table has actively said this code belonged to someone else
//      that week, and a name match after that would be overriding evidence with a coincidence.
//   3. Only when the week has no identity row for the code at all does the pre-A4 comparison run
//      (team_codes name vs present-day franchises.label), and it logs when it fires.
//
// Returns int franchise_id, or null -- the caller then carries the previous play's possession
// forward and records the code as unresolved.
function pbp_resolve_side_code($conn, $week_id, $code, $team_code_stmt,
                               $home_id, $home_label, $away_id, $away_label, &$log) {
    $fid = a4_resolve_franchise_by_code_at_week($conn, $week_id, $code, $log);

    if ($fid !== null) {
        if ($fid === $home_id || $fid === $away_id) {
            return $fid;
        }
        $log[] = "side code '$code' resolves at week grain to franchise $fid, which is not one of "
               . "this game's two teams ($home_id / $away_id) -- refusing to use it. Possession "
               . "carried forward for these plays, which is a guess.";
        return null;
    }

    // No identity row carrying this code in this week. Fall back to the pre-A4 path: code ->
    // team_codes.team_name -> compare against the franchise's PRESENT-DAY label. Right only while
    // the slot still answers to the name it held that week.
    $team_code_stmt->execute([':code' => $code]);
    $team_name = $team_code_stmt->fetchColumn();

    if ($team_name === $home_label || $team_name === $away_label) {
        $log[] = "fallback: no franchise_identities row carries code '$code' in this week, so it "
               . "was matched by team_codes name ('$team_name') against the present-day label. "
               . "Correct only if that franchise has not changed identity since.";
        return ($team_name === $home_label) ? $home_id : $away_id;
    }

    $log[] = "side code '$code' UNRESOLVED: no franchise_identities row for it in this week, and "
           . "team_codes name (" . ($team_name === false ? 'no team_codes row at all' : "'$team_name'")
           . ") matches neither team's present-day label. Possession carried forward -- a guess.";
    return null;
}

// Parses one quarter block's text into an array of structured play arrays. Handles the
// Overtime heading (only ever appears within the 4th Quarter block) by splitting on it and
// assigning quarter=5 to everything after.
function parse_quarter_plays($block_text, $quarter_num) {
    $ot_pos = strpos($block_text, '<B>Overtime<C>');

    // QB-replacement merge: borrow time+side from the announcement line, discard its text,
    // attach to the following line's real play data. Done as a pre-processing substitution
    // before the main play regex runs, rather than folding into that regex directly.
    $qb_replacement_pattern =
        '/(\d+:\d+\s+[A-Z]{0,4})\s*<Z>[^<]*and replaced by[^<]*<C><L>\s*\n\s*' .
        '(\d+\s+\d\w\w(?:\s+and\s+\d+|\s*&\s*Goal)\s+\w\s+\w+\s+\w+\s+.*?<L>)/s';
    $preprocessed = preg_replace($qb_replacement_pattern, '$1 $2', $block_text);

    $play_pattern =
        '/(\d+:\d+)\s+((?!KO\b|ON\b)[A-Z]{0,4})\s*' .
        '(?:(\d+)\s+(\d\w\w(?:\s+and\s+\d+|\s*&\s*Goal))\s+(\w)\s+)?' .
        '(\w+)\s+(\w+)\s+(.*?)<L>(?!\s*\n\s+[a-z])/s';

    preg_match_all($play_pattern, $preprocessed, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $plays = [];
    foreach ($matches as $m) {
        $pos = $m[0][1];
        $g = array_map(fn($x) => $x[0], $m);

        $raw_result = $g[8];
        if (stripos($raw_result, 'quarterback flop') !== false) {
            continue; // confirmed: not a real play, drop entirely
        }

        $quarter = ($ot_pos !== false && $pos > $ot_pos) ? 5 : $quarter_num;

        [$field_position, $down, $yards_to_go] = parse_down_distance($g[3], $g[4]);
        [$result_text, $score_after] = clean_result_and_score($raw_result);
        $yards_gained = extract_yards_gained($result_text, $raw_result);

        // Interception special case: yards_gained is always 0 regardless of any yardage
        // mentioned in the text -- confirmed directly, that number describes pass distance,
        // not an offensive gain.
        if (stripos($raw_result, 'intercepted') !== false) {
            $yards_gained = 0;
        }

        $form = $g[5];
        $off_call = $g[6];
        // Kickoffs/onsides kicks print no formation letter at all -- synthesized as 'X' per
        // explicit instruction, matching the legacy migration's own convention.
        if ($form === '' && in_array($off_call, ['KO', 'ON'])) {
            $form = 'X';
        }

        $plays[] = [
            'quarter' => $quarter,
            'time_gone_seconds' => time_to_seconds($g[1]),
            'side' => trim($g[2]),
            'field_position' => $field_position,
            'down' => $down,
            'yards_to_go' => $yards_to_go,
            'formation' => $form,
            'off_call' => $off_call,
            'def_call' => $g[7],
            'result_text' => $result_text,
            'yards_gained' => $yards_gained,
            'score_after' => $score_after,
        ];
    }

    return $plays;
}

// "mm:ss" (cumulative clock, can exceed 60:00 in overtime) -> total seconds.
function time_to_seconds($time_str) {
    [$m, $s] = explode(':', trim($time_str));
    return ((int)$m) * 60 + (int)$s;
}

// down/distance: "1st and 10" -> down=1, yards_to_go=10. "1st & Goal" -> down=1,
// yards_to_go=null (no explicit number given for goal-line situations).
function parse_down_distance($fld_str, $downdist_str) {
    if ($fld_str === '') {
        return [null, null, null]; // kickoff-style row -- no field position at all
    }
    $field_position = (int)$fld_str;
    if (preg_match('/^(\d)\w\w\s+and\s+(\d+)$/', trim($downdist_str), $m)) {
        return [$field_position, (int)$m[1], (int)$m[2]];
    }
    if (preg_match('/^(\d)\w\w\s*&\s*Goal$/i', trim($downdist_str), $m)) {
        return [$field_position, (int)$m[1], null];
    }
    return [$field_position, null, null];
}

// Extracts score_after if present, and strips <Z>/<T>...<C>/embedded <L> formatting markers
// from the raw result text, leaving clean, readable prose.
function clean_result_and_score($raw_result) {
    $score_after = null;
    if (preg_match('/<T>([^<]*)<C>/', $raw_result, $m)) {
        $score_after = trim($m[1]);
        $raw_result = substr($raw_result, 0, strpos($raw_result, $m[0]));
    }
    $cleaned = preg_replace('/<Z>\s*/', '', $raw_result);
    $cleaned = preg_replace('/<L>\s*/', ' ', $cleaned);
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
    return [$cleaned, $score_after];
}

// yards_gained: "AT gain/loss of N" is a cumulative position marker (last one wins); a
// trailing "FOR gain/loss of N" is an additional increment on top of it. Validated two
// independent ways against real plays (down/distance progression, field-position delta) --
// see file header and conversation.
function extract_yards_gained($cleaned_result, $raw_result) {
    // Match against the raw (pre-cleaned) text so <Z>/<T>/<L> markers don't interfere with
    // "at"/"for" positioning, though the phrase content itself is unaffected by cleaning.
    // "no gain" (e.g. "HB run for no gain", "pass dumped off to RB at no gain, and run for
    // gain of 3 yards") is a genuinely different phrasing from "gain/loss of N yards" -- no
    // "of"/"yards" at all -- confirmed directly against real text, not assumed. Missing this
    // branch entirely meant a play with only "for no gain" mentioned returned NULL (no match
    // found at all) rather than the known value 0, and a compound play with both an "at no
    // gain" position marker and a later "for gain of N" increment silently dropped the first
    // segment, only getting the right final total by coincidence in the one case checked.
    preg_match_all('/(at|for) (?:(gain|loss) of ([\w-]+) yards?|(no) gain)/i', $raw_result, $matches, PREG_SET_ORDER);
    if (empty($matches)) {
        return null;
    }
    $total = null;
    foreach ($matches as $m) {
        if (!empty($m[4])) {
            // The "no gain" branch matched -- unambiguously 0, regardless of gain/loss
            // wording (there is none here).
            $val = 0;
        } else {
            $num = strtolower($m[3]) === 'no' ? 0 : (int)$m[3];
            $val = (strtolower($m[2]) === 'loss') ? -$num : $num;
        }
        if (strtolower($m[1]) === 'at') {
            $total = $val;
        } else {
            $total = ($total ?? 0) + $val;
        }
    }
    return $total;
}

// Loads every row from play_text_patterns once per page load, longest pattern_text first so
// a more specific phrase is checked before a shorter one that might be its substring.
function load_play_text_patterns($conn) {
    $stmt = $conn->query("SELECT * FROM play_text_patterns");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, fn($a, $b) => strlen($b['pattern_text']) <=> strlen($a['pattern_text']));
    return $rows;
}

// Checks result_text against every pattern row (substring match), OR-ing together every
// flag from every pattern that matches -- a play can accumulate flags from more than one
// matching row, not just the single best match.
function apply_play_text_patterns($result_text, $patterns) {
    $flag_names = ['sets_touchdown', 'sets_fumble', 'sets_interception', 'sets_turnover',
        'sets_penalty_offense', 'sets_penalty_defense', 'sets_sack', 'sets_hurry',
        'sets_blitz_pickup', 'sets_blitz_no_pickup', 'sets_safety', 'sets_incomplete'];
    $flags = array_fill_keys($flag_names, 0);
    foreach ($patterns as $p) {
        if (stripos($result_text, $p['pattern_text']) !== false) {
            foreach ($flag_names as $fn) {
                if ((int)$p[$fn] === 1) { $flags[$fn] = 1; }
            }
        }
    }
    return $flags;
}
?>

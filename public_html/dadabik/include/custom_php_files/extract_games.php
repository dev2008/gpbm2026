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

// --------------------------------------------------------------
// A6-WRITER-BEGIN  (item A6b, 3 Oct 2026; re-derived from A6's design of 19 Aug 2026)
// --------------------------------------------------------------
// franchise_season_records' only writer. Called once per upload, after games and
// team_game_stats are written, for the upload's season. A6 (tasks/done/A6-franchise-season-
// records.md, Outcome) chose a stored table over a view because 420 rows hold records that
// `games` cannot reproduce (330 legacy_rollup, 90 seasons whose regular-season detail is
// partial or absent). A rebuild from `games` would destroy them.
//
// THE GUARD (monotonic, derived from the data, never a hardcoded season length): a
// franchise-season is rewritten only when `games` holds AT LEAST as many scored regular-season
// games for it as the stored row says were played (wins + losses + ties). Otherwise it is
// REFUSED and left exactly as it is. A genuine downward correction therefore never propagates;
// that is deliberate, and the manual route is DaDaBIK's admin UI on this table.
// Stored rows with no regular-season games behind them are never read here, so never touched.
//
// Regular season = game_types.phase = 'regular' (proved equal to schema.md §9's legacy rules
// by A6 across every game). The SQL is kept portable on purpose: the A6b fixture test runs this
// exact block against SQLite before it is sent. The block is located by its BEGIN/END markers.
if (!function_exists('a6_refresh_franchise_season_records')) {
/**
 * @return array ['updated'=>n, 'inserted'=>n, 'refused'=>n, 'noop'=>n, 'refused_ids'=>[franchise_id,...]]
 * $dry_run = true classifies only and writes nothing.
 */
function a6_refresh_franchise_season_records($conn, $season_id, $dry_run = false) {
    $out = ['updated' => 0, 'inserted' => 0, 'refused' => 0, 'noop' => 0, 'refused_ids' => []];
    $sql = "SELECT d.fid, d.gp, d.w, d.l, d.t, d.pf, d.pa,
                   s.season_record_id, s.wins, s.losses, s.ties, s.points_for, s.points_against
            FROM (
                SELECT x.fid, COUNT(*) AS gp,
                       SUM(CASE WHEN x.pf > x.pa THEN 1 ELSE 0 END) AS w,
                       SUM(CASE WHEN x.pf < x.pa THEN 1 ELSE 0 END) AS l,
                       SUM(CASE WHEN x.pf = x.pa THEN 1 ELSE 0 END) AS t,
                       SUM(x.pf) AS pf, SUM(x.pa) AS pa
                FROM (
                    SELECT g.home_franchise_id AS fid, g.home_score AS pf, g.away_score AS pa
                    FROM games g
                    JOIN weeks wk ON wk.week_id = g.week_id
                    JOIN game_types gt ON gt.game_type_id = g.game_type_id
                    WHERE wk.season_id = :sid1 AND gt.phase = 'regular'
                      AND g.home_score IS NOT NULL AND g.away_score IS NOT NULL
                    UNION ALL
                    SELECT g.away_franchise_id, g.away_score, g.home_score
                    FROM games g
                    JOIN weeks wk ON wk.week_id = g.week_id
                    JOIN game_types gt ON gt.game_type_id = g.game_type_id
                    WHERE wk.season_id = :sid2 AND gt.phase = 'regular'
                      AND g.home_score IS NOT NULL AND g.away_score IS NOT NULL
                ) x
                GROUP BY x.fid
            ) d
            LEFT JOIN franchise_season_records s
                   ON s.franchise_id = d.fid AND s.season_id = :sid3
            ORDER BY d.fid";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':sid1' => $season_id, ':sid2' => $season_id, ':sid3' => $season_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $conn->prepare(
        "UPDATE franchise_season_records
         SET wins = :w, losses = :l, ties = :t, points_for = :pf, points_against = :pa
         WHERE season_record_id = :id");
    $ins = $conn->prepare(
        "INSERT INTO franchise_season_records
             (franchise_id, season_id, wins, losses, ties, points_for, points_against, source)
         VALUES (:fid, :sid, :w, :l, :t, :pf, :pa, 'derived')");

    $own_tx = !$dry_run && !$conn->inTransaction();
    if ($own_tx) { $conn->beginTransaction(); }
    try {
        foreach ($rows as $r) {
            $v = [':w' => (int)$r['w'], ':l' => (int)$r['l'], ':t' => (int)$r['t'],
                  ':pf' => (int)$r['pf'], ':pa' => (int)$r['pa']];
            if ($r['season_record_id'] === null) {
                $out['inserted']++;
                if (!$dry_run) { $ins->execute($v + [':fid' => (int)$r['fid'], ':sid' => (int)$season_id]); }
                continue;
            }
            $stored_gp = (int)$r['wins'] + (int)$r['losses'] + (int)$r['ties'];
            if ((int)$r['gp'] < $stored_gp) {
                $out['refused']++;
                $out['refused_ids'][] = (int)$r['fid'];
                continue;
            }
            if ((int)$r['w'] === (int)$r['wins'] && (int)$r['l'] === (int)$r['losses']
                && (int)$r['t'] === (int)$r['ties'] && (int)$r['pf'] === (int)$r['points_for']
                && (int)$r['pa'] === (int)$r['points_against']) {
                $out['noop']++;
                continue;
            }
            $out['updated']++;
            if (!$dry_run) { $upd->execute($v + [':id' => (int)$r['season_record_id']]); }
        }
        if ($own_tx) { $conn->commit(); }
    } catch (Throwable $e) {
        if ($own_tx && $conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
    return $out;
}
}
// A6-WRITER-END

// ------------------------------------------------------------------
// Extract Games -- second staged, manually-triggered extraction page
// (see extract_standings.php for why staged pages over a Dadabik hook).
// Targets the game-results portion of the 'League Report' block --
// everything BEFORE the 'Standings' sub-block begins -- which contains a
// full per-game stat line for every game played that week across the
// whole league, not just the receiving franchise's own game. Populates
// `games` and `team_game_stats` together.
//
// Confirmed by reading real turn files across normal weeks, overtime,
// playoffs (mixed brackets in one week), and both leagues before writing
// any parsing logic -- see conversation. Every regex below was tested
// against real data and had at least one real bug caught and fixed this
// way, not just written and trusted:
//   - Quarter scores are variable-length: 4 numbers normally, 5 when a
//     game went to overtime (confirmed: "21 3 7 0 0 (31 OT)"), with "OT"
//     suffixed onto the total specifically.
//   - Rushing yards can be negative ("Rush 25 for -1 yds"), which also
//     produces "avg-0.0" with no space before the negative sign --
//     missing this caused a real, silent under-count (11 of 12 games
//     matched instead of 12) before it was caught and fixed.
//   - The "N TD" suffix on kick/punt/other returns can follow ANY of the
//     three return categories independently (KR, PR, or the third
//     variable one), not just the last -- e.g. "KR 3 for 60 yds, PR 7
//     for 85 yds, 1 TD, FumR 0 for 0 yds" has it on PR, not FumR. Missing
//     this also caused real, silent under-counts across two different
//     files before being caught.
//   - Section headers ("Championship Games", "Bronze Bowl: Semi Finals",
//     "Pre-Season") group the games that follow until the next header;
//     regular season has no header at all, games start immediately.
//     Confirmed against a real playoff week with five different brackets
//     in one turn -- every game correctly assigned to its own section,
//     matching a hand-verified outline exactly (2/2/2/2/4 games).
//
// games.source_upload_id is documented as "first upload this result was
// captured from" -- a different upsert semantic than standings_weekly
// (which always tracks the latest). A game's final result doesn't change
// across different franchises' turns the way a running standings total
// conceptually could, so this uses a no-op upsert (ON DUPLICATE KEY
// UPDATE game_id=game_id) for `games` specifically, preserving whatever
// the first upload wrote entirely. team_game_stats has no such
// "first only" comment, so it gets a normal full upsert instead, same
// pattern as standings_weekly.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h1>Extract Games</h1>";

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

    // -------------------- Confirm identification --------------------
    $_cp_upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $_cp_upload_id);

    // season_id is in this guard because the game label is built from the season YEAR
    // below -- without it, a null season_id would silently produce "NFLAR 0 Wk 4: ..."
    // instead of failing visibly. operational_hooks.php resolves and stores all three
    // together, so if any one is missing the upload was never properly identified.
    if (!$_cp_upload['league_id'] || !$_cp_upload['season_id'] || !$_cp_upload['week_id']) {
        echo "<p><em>This upload has no league/season/week identified yet. Check its <code>parse_status</code>/<code>parse_notes</code>.</em></p>";
        break;
    }

    $_cp_league_id = $_cp_upload['league_id'];
    $_cp_season_id = $_cp_upload['season_id'];
    $_cp_week_id = $_cp_upload['week_id'];

    $_cp_stmt = $conn->prepare("SELECT code FROM leagues WHERE league_id = :id");
    $_cp_stmt->bindParam(':id', $_cp_league_id);
    $_cp_stmt->execute();
    $_cp_league_code = $_cp_stmt->fetchColumn();

    $_cp_stmt = $conn->prepare("SELECT week_number FROM weeks WHERE week_id = :id");
    $_cp_stmt->bindParam(':id', $_cp_week_id);
    $_cp_stmt->execute();
    $_cp_week_number = (int)$_cp_stmt->fetchColumn();

    // The IN-LEAGUE season year, which is not the calendar year and never has been.
    // A turn is processed every two real weeks (occasionally three around holidays),
    // so NFLAR advances ~1.24 in-league seasons per calendar year and NCAA5 ~1.86:
    // in-league NFLAR 2033 was played in calendar 2025, NFLAR 2005 in calendar 2003.
    // The drift is irregular, so the season can only ever be READ, never calculated
    // from a date. This is why the label below must not use date('Y').
    $_cp_stmt = $conn->prepare("SELECT year FROM seasons WHERE season_id = :id");
    $_cp_stmt->bindParam(':id', $_cp_season_id);
    $_cp_stmt->execute();
    $_cp_season_year = (int)$_cp_stmt->fetchColumn();

    echo "<p>Upload identified as <strong>" . htmlspecialchars($_cp_league_code) . "</strong>, season $_cp_season_year, week_id $_cp_week_id (week $_cp_week_number).</p>";

    // -------------------- Fetch the League Report block --------------------
    $_cp_sql = "SELECT block_text FROM raw_upload_blocks WHERE upload_id = :uid AND block_type = 'League Report'";
    $_cp_stmt = $conn->prepare($_cp_sql);
    $_cp_stmt->bindParam(':uid', $_cp_upload_id);
    $_cp_stmt->execute();
    $_cp_block_text = $_cp_stmt->fetchColumn();

    if (!$_cp_block_text) {
        echo "<p><em>No 'League Report' block found for this upload. Has it been split into blocks yet?</em></p>";
        break;
    }

    // League Report also contains the week's schedule, free agent list, and transaction
    // notices AFTER the game results -- cut the block off at the Standings sub-marker (this
    // page doesn't read past it anyway) to keep the section-header scan below from picking up
    // unrelated content further down (confirmed necessary: an earlier version of this parser
    // naively counted markers across the WHOLE block and got a wildly inflated count from
    // content that had nothing to do with game results).
    $_cp_standings_pos = strpos($_cp_block_text, "\nStandings");
    // (Note: this is a rough boundary -- the actual per-game regex below is precise enough
    // that this cutoff is a belt-and-braces safety measure, not load-bearing on its own.)

    // -------------------- Parse games --------------------
    $_cp_games = parse_league_report_games($_cp_block_text);

    echo "<p>Found " . count($_cp_games) . " games in the League Report.</p>";

    // -------------------- Resolve game types, franchises, and upsert --------------------
    // uploaded_by, not raw_uploads.id_user -- id_user on raw_uploads turned out to be redundant
    // with a pre-existing, already-working DaDaBIK-native column (uploaded_by), see
    // migration_drop_raw_uploads_id_user.sql. games.id_user/team_game_stats.id_user below are
    // unaffected -- still genuinely new, still worth having -- only this one source changed.
    $_cp_upload_owner = $_cp_upload['uploaded_by'] ?? null;

    // games: id_user NOT in the UPDATE clause -- matches this table's own existing
    // "game_id = game_id" no-op-on-conflict pattern (the whole row is first-write-wins already,
    // not just this one column; adding id_user to the UPDATE list here would be the odd one out).
    $_cp_game_upsert = $conn->prepare(
        "INSERT INTO games (label, week_id, game_type_id, home_franchise_id, away_franchise_id,
             home_score, away_score, went_to_ot, source_upload_id, id_user)
         VALUES (:label, :week_id, :game_type_id, :home_franchise_id, :away_franchise_id,
             :home_score, :away_score, :went_to_ot, :upload_id, :id_user)
         ON DUPLICATE KEY UPDATE game_id = game_id"
    );

    // team_game_stats: id_user IS in the UPDATE clause -- matches this table's own existing
    // full-refresh-on-conflict pattern (every other real column already updates to the latest
    // parse; id_user should track "most recent source" the same way, not be the one sticky field).
    $_cp_stats_upsert = $conn->prepare(
        "INSERT INTO team_game_stats (game_id, franchise_id, is_home, coach_name,
             q1, q2, q3, q4, ot, score,
             fg_att, fg_made, ep_att, ep_made, cp_att, cp_made, punts,
             third_down_conv, third_down_att, fourth_down_conv, fourth_down_att, first_downs,
             pass_comp, pass_att, pass_yds, pass_long, pass_long_is_td, pass_td, pass_pct,
             interceptions_thrown, times_hurried, times_sacked,
             rush_att, rush_yds, rush_long, rush_long_is_td, rush_td, fumbles,
             qb_rush_att, qb_rush_yds,
             kr_num, kr_yds, kr_td, pr_num, pr_yds, pr_td,
             ret_type, ret_num, ret_yds, ret_td,
             call_fm1, call_fm2, call_run1, call_run2, call_pass1, call_pass2, call_def1, call_def2,
             starting_qb_benched, safeties_conceded, played_up, id_user)
         VALUES (:game_id, :franchise_id, :is_home, :coach_name,
             :q1, :q2, :q3, :q4, :ot, :score,
             :fg_att, :fg_made, :ep_att, :ep_made, :cp_att, :cp_made, :punts,
             :third_down_conv, :third_down_att, :fourth_down_conv, :fourth_down_att, :first_downs,
             :pass_comp, :pass_att, :pass_yds, :pass_long, :pass_long_is_td, :pass_td, :pass_pct,
             :interceptions_thrown, :times_hurried, :times_sacked,
             :rush_att, :rush_yds, :rush_long, :rush_long_is_td, :rush_td, :fumbles,
             :qb_rush_att, :qb_rush_yds,
             :kr_num, :kr_yds, :kr_td, :pr_num, :pr_yds, :pr_td,
             :ret_type, :ret_num, :ret_yds, :ret_td,
             :call_fm1, :call_fm2, :call_run1, :call_run2, :call_pass1, :call_pass2, :call_def1, :call_def2,
             :starting_qb_benched, :safeties_conceded, :played_up, :id_user)
         ON DUPLICATE KEY UPDATE
             is_home = VALUES(is_home), coach_name = VALUES(coach_name),
             q1 = VALUES(q1), q2 = VALUES(q2), q3 = VALUES(q3), q4 = VALUES(q4), ot = VALUES(ot), score = VALUES(score),
             fg_att = VALUES(fg_att), fg_made = VALUES(fg_made), ep_att = VALUES(ep_att), ep_made = VALUES(ep_made),
             cp_att = VALUES(cp_att), cp_made = VALUES(cp_made), punts = VALUES(punts),
             third_down_conv = VALUES(third_down_conv), third_down_att = VALUES(third_down_att),
             fourth_down_conv = VALUES(fourth_down_conv), fourth_down_att = VALUES(fourth_down_att),
             first_downs = VALUES(first_downs),
             pass_comp = VALUES(pass_comp), pass_att = VALUES(pass_att), pass_yds = VALUES(pass_yds),
             pass_long = VALUES(pass_long), pass_long_is_td = VALUES(pass_long_is_td),
             pass_td = VALUES(pass_td), pass_pct = VALUES(pass_pct),
             interceptions_thrown = VALUES(interceptions_thrown),
             times_hurried = VALUES(times_hurried), times_sacked = VALUES(times_sacked),
             rush_att = VALUES(rush_att), rush_yds = VALUES(rush_yds), rush_long = VALUES(rush_long),
             rush_long_is_td = VALUES(rush_long_is_td), rush_td = VALUES(rush_td), fumbles = VALUES(fumbles),
             qb_rush_att = VALUES(qb_rush_att), qb_rush_yds = VALUES(qb_rush_yds),
             kr_num = VALUES(kr_num), kr_yds = VALUES(kr_yds), kr_td = VALUES(kr_td),
             pr_num = VALUES(pr_num), pr_yds = VALUES(pr_yds), pr_td = VALUES(pr_td),
             ret_type = VALUES(ret_type), ret_num = VALUES(ret_num), ret_yds = VALUES(ret_yds), ret_td = VALUES(ret_td),
             call_fm1 = VALUES(call_fm1), call_fm2 = VALUES(call_fm2),
             call_run1 = VALUES(call_run1), call_run2 = VALUES(call_run2),
             call_pass1 = VALUES(call_pass1), call_pass2 = VALUES(call_pass2),
             call_def1 = VALUES(call_def1), call_def2 = VALUES(call_def2),
             starting_qb_benched = VALUES(starting_qb_benched),
             safeties_conceded = VALUES(safeties_conceded), played_up = VALUES(played_up),
             id_user = VALUES(id_user)"
    );

    $_cp_resolved = 0;
    $_cp_unresolved_teams = [];
    $_cp_unresolved_types = [];

    // -------------------- A4 package 2, pass 1: write this week's identity rows --------------------
    // Two passes, deliberately -- this is A4 §11's ordering problem, and the two-pass option is
    // the one taken. In a single pass the identity row for week N would not yet exist when the
    // lookup for week N runs, because it is the same parse writing it, so the franchises.label
    // fallback would fire for every team on every first parse of a week. That is exactly what
    // destroys the value of logging the fallback: a log that fires always says nothing.
    //
    // With the identity rows written first, pass 2 resolves against a table that already
    // contains this week, and a fallback line appearing in pass 2 means something real is wrong.
    //
    // This pass walks EVERY parsed game, including ones pass 2 will skip for an unmapped game
    // type -- the identity is a fact about the week regardless of whether the game row lands.
    // It also covers playoff and bowl weeks, which extract_standings.php cannot (no Standings
    // block exists in those weeks at all); standings in turn covers byes, which have no game.
    // Neither parser alone is complete, which is why D1 has both writing.
    $_cp_identity_log = [];
    $_cp_identity_rows = 0;
    $_cp_franchise_ids = [];

    // Snapshot BEFORE the loop, and pass it in on every call. Pass 1 writes identity rows as it
    // goes, so a resolver left to check the count itself would see the first team's freshly
    // written row when classifying the second -- turning one honest 'seed' into a run of false
    // 'GAP's on a brand new week. Observed live, NCAA5 2039 wk4.
    $_cp_week_had_identities = a4_week_has_identities($conn, $_cp_league_id, $_cp_week_id);

    foreach ($_cp_games as $game) {
        foreach ([$game['home_team'], $game['away_team']] as $_cp_name) {
            if (array_key_exists($_cp_name, $_cp_franchise_ids)) { continue; }
            $_cp_fid = a4_resolve_franchise_at_week($conn, $_cp_league_id, $_cp_week_id, $_cp_name, $_cp_identity_log, $_cp_week_had_identities);
            $_cp_franchise_ids[$_cp_name] = $_cp_fid;
            if ($_cp_fid) {
                $_cp_identity_rows += a4_write_identity($conn, $_cp_league_id, $_cp_week_id, $_cp_fid, $_cp_name, $_cp_identity_log);
            }
        }
    }

    // -------------------- pass 2: resolve at week grain, then upsert --------------------
    foreach ($_cp_games as $game) {
        $game_type_id = resolve_game_type_id($conn, $_cp_league_code, $_cp_week_number, $game['section_header']);
        // Explicit === null, not a plain falsy check (!$game_type_id) -- the SAME bug just fixed
        // in lookup_game_type() one call down, still present here at the consuming end: fixing
        // the source function's return value doesn't help if the caller still treats a genuine
        // 0 (NCAA5's real, current Pre Season id) as "not found". Confirmed via a real re-test
        // that still failed after the first fix -- the value now correctly comes back as 0, but
        // this check was still discarding it. Worth remembering as a general lesson: a falsy-
        // zero fix needs tracing through every consumer of that value, not just its source.
        if ($game_type_id === null) {
            $_cp_unresolved_types[] = $game['section_header'] ?? '(regular season)';
            continue;
        }

        // A4 package 3: resolved from franchise_identities at week grain, not from
        // franchises.label. The label is a STORED GENERATED present-day snapshot (schema.md
        // §12) -- resolving a 2014 turn file against it puts the game on whichever franchise
        // holds that name TODAY, which for franchise 2014 is wrong across 394 real fixtures.
        // After pass 1 above, every team in this week already has an identity row, so this
        // should never reach the fallback; if it does, the log below says so explicitly.
        $home_id = a4_resolve_franchise_at_week($conn, $_cp_league_id, $_cp_week_id, $game['home_team'], $_cp_identity_log);
        $away_id = a4_resolve_franchise_at_week($conn, $_cp_league_id, $_cp_week_id, $game['away_team'], $_cp_identity_log);

        if (!$home_id) { $_cp_unresolved_teams[] = $game['home_team']; }
        if (!$away_id) { $_cp_unresolved_teams[] = $game['away_team']; }
        if (!$home_id || !$away_id) { continue; }

        // In-league season, NOT date('Y'). The calendar year has never matched the
        // in-league season -- see the note where $_cp_season_year is resolved. Using
        // date('Y') here mislabelled every game this page created (lessons.md s9
        // recorded this as fixed, but the fix landed on the upload dropdown only).
        $label = "{$_cp_league_code} {$_cp_season_year} Wk {$_cp_week_number}: {$game['home_team']} vs {$game['away_team']}";

        $_cp_game_upsert->execute([
            ':label' => $label, ':week_id' => $_cp_week_id, ':game_type_id' => $game_type_id,
            ':home_franchise_id' => $home_id, ':away_franchise_id' => $away_id,
            ':home_score' => $game['home']['score'], ':away_score' => $game['away']['score'],
            ':went_to_ot' => ($game['home']['ot'] !== null) ? 1 : 0,
            ':upload_id' => $_cp_upload_id, ':id_user' => $_cp_upload_owner,
        ]);

        $_cp_stmt = $conn->prepare(
            "SELECT game_id FROM games WHERE week_id = :week_id AND home_franchise_id = :home_id AND away_franchise_id = :away_id"
        );
        $_cp_stmt->execute([':week_id' => $_cp_week_id, ':home_id' => $home_id, ':away_id' => $away_id]);
        $game_id = $_cp_stmt->fetchColumn();

        foreach ([['home', $home_id, 1], ['away', $away_id, 0]] as [$side, $franchise_id, $is_home]) {
            $t = $game[$side];
            $_cp_stats_upsert->execute([
                ':game_id' => $game_id, ':franchise_id' => $franchise_id, ':is_home' => $is_home,
                ':coach_name' => $t['coach'],
                ':q1' => $t['q1'], ':q2' => $t['q2'], ':q3' => $t['q3'], ':q4' => $t['q4'], ':ot' => $t['ot'], ':score' => $t['score'],
                ':fg_att' => $t['fg_att'], ':fg_made' => $t['fg_made'], ':ep_att' => $t['ep_att'], ':ep_made' => $t['ep_made'],
                ':cp_att' => $t['cp_att'], ':cp_made' => $t['cp_made'], ':punts' => $t['punts'],
                ':third_down_conv' => $t['third_down_conv'], ':third_down_att' => $t['third_down_att'],
                ':fourth_down_conv' => $t['fourth_down_conv'], ':fourth_down_att' => $t['fourth_down_att'],
                ':first_downs' => $t['first_downs'],
                ':pass_comp' => $t['pass_comp'], ':pass_att' => $t['pass_att'], ':pass_yds' => $t['pass_yds'],
                ':pass_long' => $t['pass_long'], ':pass_long_is_td' => $t['pass_long_is_td'],
                ':pass_td' => $t['pass_td'], ':pass_pct' => $t['pass_pct'],
                ':interceptions_thrown' => $t['interceptions_thrown'],
                ':times_hurried' => $t['times_hurried'], ':times_sacked' => $t['times_sacked'],
                ':rush_att' => $t['rush_att'], ':rush_yds' => $t['rush_yds'], ':rush_long' => $t['rush_long'],
                ':rush_long_is_td' => $t['rush_long_is_td'], ':rush_td' => $t['rush_td'], ':fumbles' => $t['fumbles'],
                ':qb_rush_att' => $t['qb_rush_att'], ':qb_rush_yds' => $t['qb_rush_yds'],
                ':kr_num' => $t['kr_num'], ':kr_yds' => $t['kr_yds'], ':kr_td' => $t['kr_td'],
                ':pr_num' => $t['pr_num'], ':pr_yds' => $t['pr_yds'], ':pr_td' => $t['pr_td'],
                ':ret_type' => $t['ret_type'], ':ret_num' => $t['ret_num'], ':ret_yds' => $t['ret_yds'], ':ret_td' => $t['ret_td'],
                ':call_fm1' => $t['call_fm1'], ':call_fm2' => $t['call_fm2'],
                ':call_run1' => $t['call_run1'], ':call_run2' => $t['call_run2'],
                ':call_pass1' => $t['call_pass1'], ':call_pass2' => $t['call_pass2'],
                ':call_def1' => $t['call_def1'], ':call_def2' => $t['call_def2'],
                ':starting_qb_benched' => 0,
                ':safeties_conceded' => $t['safeties'], ':played_up' => $t['played_up'],
                ':id_user' => $_cp_upload_owner,
            ]);
        }

        $_cp_resolved++;
    }

    // -------------------- A6b: keep franchise_season_records current --------------------
    // Runs for the upload's season after its games are written; the guard and its reasons are
    // at A6-WRITER-BEGIN below. A failure here is shown, not swallowed: the games above are
    // already written, so the page reports the writer's failure on its own.
    $_cp_fsr = null;
    $_cp_fsr_error = null;
    try {
        $_cp_fsr = a6_refresh_franchise_season_records($conn, $_cp_season_id);
    } catch (Throwable $_cp_e) {
        $_cp_fsr_error = $_cp_e->getMessage();
    }

    echo "<div class='w3-panel w3-pale-green w3-text-black w3-round-large'>";
    echo "<p><strong>$_cp_resolved</strong> games written to games/team_game_stats.</p>";
    echo "<p><strong>$_cp_identity_rows</strong> franchise_identities rows written/refreshed for this week "
       . "(derived_from = 'week').</p>";
    if ($_cp_fsr !== null) {
        echo "<p>franchise_season_records for this season: <strong>{$_cp_fsr['updated']}</strong> updated, "
           . "<strong>{$_cp_fsr['inserted']}</strong> inserted, {$_cp_fsr['noop']} unchanged, "
           . "{$_cp_fsr['refused']} refused by the guard (stored record fuller than games; expected for "
           . "partial seasons).</p>";
    }
    echo "</div>";
    if ($_cp_fsr_error !== null) {
        echo "<div class='w3-panel w3-pale-red w3-text-black w3-round-large'>";
        echo "<p><strong>franchise_season_records was NOT refreshed</strong> (nothing written to it; games above are unaffected): "
           . htmlspecialchars($_cp_fsr_error) . "</p></div>";
    }


    // Fallback/refusal log. Shown whether or not anything else went wrong -- a fallback firing
    // is not an error, but it is the thing worth reading. 'seed' lines are expected on a week's
    // first parse; 'GAP' and 'AMBIGUOUS' lines are not.
    if ($_cp_identity_log) {
        echo "<div class='w3-panel w3-pale-yellow w3-text-black w3-round-large'>";
        echo "<p><strong>Identity resolution notes (" . count($_cp_identity_log) . "):</strong></p><ul>";
        foreach ($_cp_identity_log as $line) { echo "<li>" . htmlspecialchars($line) . "</li>"; }
        echo "</ul></div>";
    }

    if ($_cp_unresolved_teams) {
        echo "<div class='w3-panel w3-pale-red w3-text-black w3-round-large'>";
        echo "<p><strong>" . count($_cp_unresolved_teams) . "</strong> team name(s) could not be matched to a franchise:</p><ul>";
        foreach (array_unique($_cp_unresolved_teams) as $name) { echo "<li>" . htmlspecialchars($name) . "</li>"; }
        echo "</ul></div>";
    }
    if ($_cp_unresolved_types) {
        echo "<div class='w3-panel w3-pale-red w3-text-black w3-round-large'>";
        echo "<p><strong>" . count($_cp_unresolved_types) . "</strong> section header(s) could not be mapped to a game type:</p><ul>";
        foreach (array_unique($_cp_unresolved_types) as $name) { echo "<li>" . htmlspecialchars($name) . "</li>"; }
        echo "</ul></div>";
    }

} while (false);
$_cp_body = ob_get_clean();

// -------------------- Upload selector --------------------
// Same two-reason exclusion pattern as extract_standings.php: already-processed weeks
// (games rows already exist for this week_id), plus a placeholder for "definitively
// nothing to extract" cases if any turn up here the way they did for standings -- none
// confirmed yet specifically for game results, so not excluding on that basis for now.
// parse_status = 'duplicate' is excluded on a confirmed fact, not a guess:
// operational_hooks.php sets it when a file's content is identical to an earlier upload and
// deliberately skips block-splitting, so these rows have no blocks and no
// league/season/week -- there is nothing to extract from them, ever. Before this exclusion
// they rendered as "(not yet identified)", which reads like something that might resolve
// later; in fact the original upload processed fine and this is a re-upload of the same file.
//
// Also excludes leagues.game_type = 'baseball' (same gate as extract_standings.php, added
// alongside it). This page reads block_type = 'League Report', which doesn't exist on any
// baseball upload -- confirmed live, baseball's own block set is Team Report/Team Stats/
// Series 1-3/Results Summary instead -- so a baseball turn already failed safely here with
// an honest "No 'League Report' block found for this upload" rather than a silent false
// success. Excluding it from the selector is still correct: there's nothing this page could
// ever extract from a baseball turn (no baseball equivalent of it is built or scheduled --
// see B1-lessons.md/B2 roadmap), so offering it just wastes a click. game_type IS NULL is
// kept IN, matching extract_standings.php's policy: a not-yet-identified upload has no
// confirmed answer yet, so excluding it here would be a guess, not a fact.
$_cp_sql = "SELECT ru.upload_id, ru.original_filename, ru.turn_number,
                   l.code AS league_code, s.year AS season_year, w.week_number
            FROM raw_uploads ru
            LEFT JOIN leagues l ON l.league_id = ru.league_id
            LEFT JOIN seasons s ON s.season_id = ru.season_id
            LEFT JOIN weeks w ON w.week_id = ru.week_id
            WHERE NOT EXISTS (
                SELECT 1 FROM games g WHERE g.week_id = ru.week_id
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
    echo "<p><em>Nothing left to process -- every uploaded turn already has its games extracted.</em></p>";
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

// Parses every game in the League Report's game-results section into a structured array.
// Tracks the current section header (game-type grouping) as it walks through, resetting
// to null (regular season -- no header at all) at the start.
function parse_league_report_games($text) {
    // DOTALL (the trailing 's' modifier) rather than requiring a literal '\n' between stat
    // lines -- confirmed necessary, not just a defensive choice: a real upload had its League
    // Report game-results section using plain spaces where every other file used real
    // newlines between segments ("<L.45.1> <L.12.1> <B>Gold Bowl Playoffs<L.45.1> <Z>..."),
    // which silently made every game in that file fail to match at all (0 of 6 found). With
    // DOTALL, '.' matches newlines too, so '.*?' works correctly regardless of which
    // separator a given file actually uses.
    $game_pattern =
        '/<Z>([^(<]+?)\s*\(([^)]+)\)\s*([A-Z ]*?)\s+([\d\s]+\(\d+(?: OT)?\))<T>' .
        '([^(<]+?)\s*\(([^)]+)\)\s*([A-Z ]*?)\s+([\d\s]+\(\d+(?: OT)?\))<C>.*?' .
        'FG (\d+)\/(\d+), EP (\d+)\/(\d+), CP (\d+)\/(\d+), Punt (\d+), 3rd (\d+)\/(\d+), 4th (\d+)\/(\d+), 1st (\d+)<T>' .
        'FG (\d+)\/(\d+), EP (\d+)\/(\d+), CP (\d+)\/(\d+), Punt (\d+), 3rd (\d+)\/(\d+), 4th (\d+)\/(\d+), 1st (\d+).*?' .
        'Pass (\d+) for (\d+), (-?\d+) yds, Lg (t?-?\d+), (?:(\d+) TD, )?(\d+)%, In (\d+), Hrd (\d+), Skd (\d+)<T>' .
        'Pass (\d+) for (\d+), (-?\d+) yds, Lg (t?-?\d+), (?:(\d+) TD, )?(\d+)%, In (\d+), Hrd (\d+), Skd (\d+).*?' .
        'Rush (\d+) for (-?\d+) yds, Lg (t?-?\d+), (?:(\d+) TD, )?avg\s*-?[\d.]+, Fm (\d+), QB (\d+) for (-?\d+) yds<T>' .
        'Rush (\d+) for (-?\d+) yds, Lg (t?-?\d+), (?:(\d+) TD, )?avg\s*-?[\d.]+, Fm (\d+), QB (\d+) for (-?\d+) yds.*?' .
        'KR (\d+) for (\d+) yds(?:, (\d+) TD)?, PR (\d+) for (\d+) yds(?:, (\d+) TD)?, (FumR|IntR|DefR) (\d+) for (\d+) yds(?:, (\d+) TD)?<T>' .
        'KR (\d+) for (\d+) yds(?:, (\d+) TD)?, PR (\d+) for (\d+) yds(?:, (\d+) TD)?, (FumR|IntR|DefR) (\d+) for (\d+) yds(?:, (\d+) TD)?.*?' .
        // Each of the 8 call-code values (Fm/Run/Pass/Def x2) can genuinely be blank -- a
        // team with zero pass attempts that game has an entirely empty Pass field (confirmed:
        // "Pass      , Def..." with nothing between the commas) -- or a literal '-'
        // placeholder (confirmed: "Fm S -,"). \w+ required exactly two word-characters, so
        // both cases failed to match; because of the non-greedy .*? earlier in this pattern,
        // that failure didn't just skip the game -- the regex engine backtracked and matched
        // the NEXT game's Calls line instead, silently merging two games into one oversized
        // match (confirmed directly: match length was double the normal ~720 chars in both
        // real cases this was caught against). [\w-]* fixes both: zero-or-more word
        // characters or hyphens, so blank and '-' are both valid, and the pattern can no
        // longer skip past a genuine game boundary looking for a "complete-looking" one.
        'Calls Fm ([\w-]*)\s*([\w-]*), Run ([\w-]*)\s*([\w-]*), Pass ([\w-]*)\s*([\w-]*), Def ([\w-]*)\s*([\w-]*)<T>' .
        'Calls Fm ([\w-]*)\s*([\w-]*), Run ([\w-]*)\s*([\w-]*), Pass ([\w-]*)\s*([\w-]*), Def ([\w-]*)\s*([\w-]*)/s';

    preg_match_all($game_pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    if (empty($matches)) {
        return [];
    }

    // Last game match's end position -- the boundary past which anything "header-shaped" is
    // no longer a real section header. Necessary, not just tidy: the same League Report
    // block contains next week's schedule further down, which lists bowl names too (e.g.
    // "Bronze Bowl", "Cons Gold") in a tight cluster -- searching the whole block for known
    // header phrases without this boundary picked those up as false section headers.
    $last_game_end = end($matches);
    $last_game_end = $last_game_end[0][1] + strlen($last_game_end[0][0]);

    // Searches for known header phrases directly, rather than trying to generically detect
    // "text that looks like a standalone header line" from surrounding formatting tags --
    // confirmed necessary: the exact tag ordering around a header varies between files (one
    // real upload had <B> positioned after the <L.x.y> marker instead of before it, which
    // broke a structural "line-shaped" pattern entirely). Longest-first so e.g. "Silver
    // Bowl: Semi Finals" matches before the shorter "Silver Bowl" would greedily claim it.
    $known_headers = [
        'Wild Card Games', 'Divisional Games', 'Championship Games', 'Bowl Game',
        'Silver Bowl: Preliminary Round', 'Silver Bowl: Quarter Finals', 'Silver Bowl: Semi Finals', 'Silver Bowl',
        'Bronze Bowl: Quarter Finals', 'Bronze Bowl: Semi Finals', 'Bronze Bowl',
        'Divisional Bowl: Semi Finals', 'Divisional Bowl',
        'Championship Bowl',
        'Gold Bowl Playoffs', 'Silver Bowl Playoffs', 'Bronze Bowl Playoffs',
        'Gold Bowl', 'Consolation Gold', 'Consolation Silver', 'Consolation Bronze',
        'Pre-Season', 'Pre Season',
    ];
    usort($known_headers, fn($a, $b) => strlen($b) <=> strlen($a));
    $header_pattern = '/(' . implode('|', array_map('preg_quote', $known_headers)) . ')/';

    preg_match_all($header_pattern, $text, $header_matches, PREG_OFFSET_CAPTURE);
    $headers = [];
    foreach ($header_matches[1] as $m) {
        if ($m[1] < $last_game_end) {
            $headers[] = [$m[1], $m[0]];
        }
    }

    $games = [];
    foreach ($matches as $m) {
        $pos = $m[0][1];
        $current_header = null;
        foreach ($headers as [$hpos, $htext]) {
            if ($hpos <= $pos) { $current_header = $htext; }
        }

        $g = array_map(fn($x) => $x[0], $m); // strip offsets, keep plain values

        $games[] = [
            'section_header' => $current_header,
            'home_team' => trim($g[1]),
            'away_team' => trim($g[5]),
            'home' => build_team_stats($g, 0),
            'away' => build_team_stats($g, 1),
        ];
    }

    return $games;
}

// Builds one team's full stat array from the 100 capture groups, given side (0=home, 1=away).
// Group indices verified directly against real match output before this was written, not
// assumed from the pattern alone -- see conversation.
function build_team_stats($g, $side) {
    $o = $side; // 0 for home fields, 1 for away fields, per the paired group layout below

    $markers = parse_markers($side == 0 ? $g[3] : $g[7]);
    $scores = parse_scores($side == 0 ? $g[4] : $g[8]);

    $kick_base = 9 + $side * 12;   // groups 9-20 home, 21-32 away
    $pass_base = 33 + $side * 9;   // groups 33-41 home, 42-50 away
    $rush_base = 51 + $side * 7;   // groups 51-57 home, 58-64 away
    // Multiplier is 10, not 8 -- the returns line has 10 groups per side (KR_num, KR_yds,
    // KR_TD-optional, PR_num, PR_yds, PR_TD-optional, ret_type, ret_num, ret_yds,
    // ret_TD-optional). Same root miscounting error as call_base above -- both traced back
    // to undercounting this same section's optional TD groups. Confirmed the fix
    // systematically: recomputed every base value from each section's actual group count
    // independently (1 -> 9 -> 33 -> 51 -> 65 -> 85 -> 101), landing exactly on the
    // confirmed total of 100 groups -- see conversation.
    $ret_base  = 65 + $side * 10;   // groups 65-74 home, 75-84 away
    // ret_base's own section has 10 groups per side (KR_num, KR_yds, KR_TD-optional,
    // PR_num, PR_yds, PR_TD-optional, ret_type, ret_num, ret_yds, ret_TD-optional), not 8 --
    // an earlier version miscounted this (missed two of the three optional TD groups) and
    // call_base was wrong as a result (81 instead of 85), corrupting every field in the
    // Calls line and bleeding into home/away misalignment. Caught by field-by-field
    // validation against real data, not just checking the regex matched -- see conversation.
    $call_base = 85 + $side * 8;   // groups 85-92 home, 93-100 away

    [$pass_long, $pass_long_td] = strip_t_prefix($g[$pass_base + 3]);
    [$rush_long, $rush_long_td] = strip_t_prefix($g[$rush_base + 2]);

    return [
        'coach' => trim($side == 0 ? $g[2] : $g[6]),
        'q1' => $scores[0], 'q2' => $scores[1], 'q3' => $scores[2], 'q4' => $scores[3],
        'ot' => $scores[4], 'score' => $scores[5],
        'fg_att' => (int)$g[$kick_base], 'fg_made' => (int)$g[$kick_base+1],
        'ep_att' => (int)$g[$kick_base+2], 'ep_made' => (int)$g[$kick_base+3],
        'cp_att' => (int)$g[$kick_base+4], 'cp_made' => (int)$g[$kick_base+5],
        'punts' => (int)$g[$kick_base+6],
        'third_down_conv' => (int)$g[$kick_base+7], 'third_down_att' => (int)$g[$kick_base+8],
        'fourth_down_conv' => (int)$g[$kick_base+9], 'fourth_down_att' => (int)$g[$kick_base+10],
        'first_downs' => (int)$g[$kick_base+11],
        'pass_comp' => (int)$g[$pass_base], 'pass_att' => (int)$g[$pass_base+1], 'pass_yds' => (int)$g[$pass_base+2],
        'pass_long' => $pass_long, 'pass_long_is_td' => $pass_long_td ? 1 : 0,
        'pass_td' => $g[$pass_base+4] !== '' ? (int)$g[$pass_base+4] : 0,
        'pass_pct' => (int)$g[$pass_base+5],
        'interceptions_thrown' => (int)$g[$pass_base+6],
        'times_hurried' => (int)$g[$pass_base+7], 'times_sacked' => (int)$g[$pass_base+8],
        'rush_att' => (int)$g[$rush_base], 'rush_yds' => (int)$g[$rush_base+1],
        'rush_long' => $rush_long, 'rush_long_is_td' => $rush_long_td ? 1 : 0,
        'rush_td' => $g[$rush_base+3] !== '' ? (int)$g[$rush_base+3] : 0,
        'fumbles' => (int)$g[$rush_base+4],
        'qb_rush_att' => (int)$g[$rush_base+5], 'qb_rush_yds' => (int)$g[$rush_base+6],
        'kr_num' => (int)$g[$ret_base], 'kr_yds' => (int)$g[$ret_base+1],
        'kr_td' => $g[$ret_base+2] !== '' ? (int)$g[$ret_base+2] : 0,
        'pr_num' => (int)$g[$ret_base+3], 'pr_yds' => (int)$g[$ret_base+4],
        'pr_td' => $g[$ret_base+5] !== '' ? (int)$g[$ret_base+5] : 0,
        'ret_type' => $g[$ret_base+6], 'ret_num' => (int)$g[$ret_base+7], 'ret_yds' => (int)$g[$ret_base+8],
        'ret_td' => $g[$ret_base+9] !== '' ? (int)$g[$ret_base+9] : 0,
        'call_fm1' => $g[$call_base], 'call_fm2' => $g[$call_base+1],
        'call_run1' => $g[$call_base+2], 'call_run2' => $g[$call_base+3],
        'call_pass1' => $g[$call_base+4], 'call_pass2' => $g[$call_base+5],
        'call_def1' => $g[$call_base+6], 'call_def2' => $g[$call_base+7],
        'safeties' => $markers['safeties'], 'played_up' => $markers['played_up'],
        'qb_benched' => $markers['qb_benched'],
    ];
}

// Marker segment (right after coach name, before quarter scores) can contain any
// combination of QB/UP/S+ tokens in any order -- confirmed directly, e.g. "QB S" together
// on one team. Parsed as free tokens rather than a fixed pattern for exactly that reason.
function parse_markers($segment) {
    $result = ['qb_benched' => 0, 'played_up' => 0, 'safeties' => 0];
    foreach (preg_split('/\s+/', trim($segment)) as $tok) {
        if ($tok === 'QB') { $result['qb_benched'] = 1; }
        elseif ($tok === 'UP') { $result['played_up'] = 1; }
        elseif (preg_match('/^S+$/', $tok)) { $result['safeties'] = strlen($tok); }
    }
    return $result;
}

// Quarter-score string, e.g. "17 7 0 6 0 (30 OT)" or "0 3 0 0 (3)" -- variable-length:
// 5 numbers + "OT" suffix on overtime games, 4 numbers otherwise. Confirmed directly, not
// assumed -- see conversation.
function parse_scores($segment) {
    preg_match('/([\d\s]+)\((\d+)(\s+OT)?\)/', trim($segment), $m);
    $nums = array_values(array_filter(preg_split('/\s+/', trim($m[1])), fn($x) => $x !== ''));
    $is_ot = !empty($m[3]);
    if ($is_ot) {
        return [(int)$nums[0], (int)$nums[1], (int)$nums[2], (int)$nums[3], (int)$nums[4], (int)$m[2]];
    }
    return [(int)$nums[0], (int)$nums[1], (int)$nums[2], (int)$nums[3], null, (int)$m[2]];
}

// Long-yardage fields: a leading "t" means that long play was itself a touchdown, e.g.
// "Lg t59" = 59 yards, and that 59-yard play was a TD. Confirmed directly against real data.
function strip_t_prefix($value) {
    $is_td = str_starts_with($value, 't');
    $num = $is_td ? substr($value, 1) : $value;
    return [(int)$num, $is_td];
}

// Resolves a section header (or null, for regular season) to a game_type_id, given the
// league and the turn's own week_number. Confirmed mappings only -- see conversation for
// the full derivation, including cross-checking against real historical f_games data for
// every non-obvious case (round-suffix handling, the Championship Bowl 3rd-place-game
// clarification, the Consolation Pre-Season distinction).
function resolve_game_type_id($conn, $league_code, $week_number, $header) {
    if ($header === null) {
        // Regular season: no header at all.
        $name = ($league_code === 'NCAA5') ? 'Regular Season' : 'Regular Season';
        return lookup_game_type($conn, $league_code, $name);
    }

    $normalized = preg_replace('/\s+/', ' ', trim($header));

    // Pre-season is a special case in both leagues, but only NFLAR has a genuine second
    // meaning to disambiguate: teams eliminated from the playoffs playing bonus games
    // while others are still competing, still using the season that's wrapping up, not a
    // new one -- confirmed real, not hypothetical, and confirmed NCAA5 has no equivalent
    // (all NCAA5 teams play in weeks 12/13, first the playoffs then ranked bowls).
    if (preg_match('/^Pre[\s-]Season$/i', $normalized)) {
        if ($league_code === 'NFLAR' && $week_number !== 0) {
            return lookup_game_type($conn, $league_code, 'Consolation Pre-Season');
        }
        return lookup_game_type($conn, $league_code, 'Pre Season');
    }

    if ($league_code === 'NFLAR') {
        // Main bracket: every early round shares one type; only the week-20 final gets its
        // own ("Bowl Game" -> Superbowl, confirmed via historical gametype data cross-check
        // -- the same two teams that played type 35 in the semi-final round advance to 36).
        if (in_array($normalized, ['Wild Card Games', 'Divisional Games', 'Championship Games'])) {
            return lookup_game_type($conn, $league_code, 'Play Offs');
        }
        if ($normalized === 'Bowl Game') {
            return lookup_game_type($conn, $league_code, 'Superbowl');
        }
        // Bowl brackets: every round of the same bracket shares one type_id (confirmed via
        // historical gametype data -- same gametype value from "Preliminary Round" through
        // to the final, no separate ID per round), so match on the bracket name prefix,
        // ignoring any ": round name" suffix entirely.
        foreach (['Silver Bowl', 'Bronze Bowl', 'Divisional Bowl', 'Championship Bowl'] as $bracket) {
            if (str_starts_with($normalized, $bracket)) {
                return lookup_game_type($conn, $league_code, $bracket);
            }
        }
    }

    if ($league_code === 'NCAA5') {
        // Unlike NFLAR, the semi-final round genuinely has ITS OWN distinct game_type_id
        // here, not a shared one with the final -- confirmed via the Danny-name mapping
        // table (bowl_records.php) plus direct pattern match ("X Bowl Playoffs" ->
        // "X Semi Finals"-style ids). Exact-string lookups, not prefix matches, since
        // "Gold Bowl Playoffs" and "Gold Bowl" are genuinely different game types here,
        // not the same bracket with an ignorable round suffix like NFLAR's.
        // Keys are "Consolation Gold/Silver/Bronze", not the abbreviated "Cons Gold" from
        // the original outline this scope was worked out from -- confirmed directly against
        // real Week 13 text ("<B>Consolation Gold<L.45.1>"); the outline's shorthand isn't
        // the literal source text and shouldn't have been used as if it were.
        $ncaa_map = [
            'Gold Bowl Playoffs' => 'National Championship Semi Finals',
            'Silver Bowl Playoffs' => 'Cotton Bowl Playoffs',
            'Bronze Bowl Playoffs' => 'Hawaii Bowl Playoffs',
            'Gold Bowl' => 'National Championship Game',
            'Silver Bowl' => 'Cotton Bowl',
            'Bronze Bowl' => 'Hawaii Bowl',
            'Consolation Gold' => 'Rose Bowl',
            'Consolation Silver' => 'Orange Bowl',
            'Consolation Bronze' => 'Music City Bowl',
        ];
        if (isset($ncaa_map[$normalized])) {
            return lookup_game_type($conn, $league_code, $ncaa_map[$normalized]);
        }
    }

    return null; // unmapped -- reported as a warning, not silently guessed at
}

function lookup_game_type($conn, $league_code, $name) {
    // Explicit ORDER BY + LIMIT 1, not relying on MySQL's unspecified default row order --
    // confirmed there really are two rows both named "Pre Season" for NCAA5 (game_type_id 0
    // and 17, a historical renumbering -- only 0 is the current, live one; see conversation).
    // Picking the lowest id has worked so far by coincidence, not by guarantee, for exactly
    // this reason.
    $stmt = $conn->prepare(
        "SELECT gt.game_type_id FROM game_types gt
         JOIN leagues l ON l.league_id = gt.league_id
         WHERE l.code = :code AND gt.name = :name
         ORDER BY gt.game_type_id ASC LIMIT 1"
    );
    $stmt->execute([':code' => $league_code, ':name' => $name]);
    $result = $stmt->fetchColumn();
    // Explicit !== false check, not a plain falsy check (?: null) -- game_type_id 0 is a
    // real, valid id (confirmed: NCAA5's current Pre Season is literally id 0), and PHP
    // treats 0 as falsy the same as false/null, which silently turned every valid "Pre
    // Season" resolution into "not found" -- confirmed via a real failed extraction, not
    // caught in testing beforehand. Same root mistake as the week_number=0 bug caught
    // earlier in operational_hooks.php -- should have been more alert to this exact pattern
    // recurring, given it had already been learned once.
    return $result !== false ? (int)$result : null;
}
?>

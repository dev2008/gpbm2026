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
// include because these files are deployed independently (three DaDaBIK static pages and the
// hooks file) and a missing include would fail at exactly the moment an upload is being
// processed. The function_exists() guards exist because DaDaBIK loads the hooks file on every
// request, including the request that renders this static page -- without them that
// combination is a fatal redeclare.
//
// Why this exists at all (schema.md §12): franchises.label is a STORED GENERATED column
// (city + nickname), so it always reads as the slot's PRESENT-DAY identity. Resolving a turn
// file's team name against it is right only by accident, and wrong silently.
// franchise_identities records identity at week grain and is the correct source.
// --------------------------------------------------------------

if (!function_exists('a4_week_has_identities')) {
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
// B1: draft-specific parser classes (DraftListEntry/BbDraftListParser,
// DraftPickAnnouncement/BbDraftActionsParser, ScoutingResult/BbScoutingParser,
// BbDraftRatings, bb_process_draft_turn() and its upsert helpers, plus
// bb_resolve_franchise_by_nickname_at_week()).
//
// DEVIATION FROM THE DUPLICATION CONVENTION ABOVE, FLAGGED DELIBERATELY: everything above this
// point is duplicated inline (matching extract_games.php/extract_standings.php/
// extract_playbyplay.php/operational_hooks.php) because those four files are small, stable, and
// deployed independently. bb_draft_parser.php is substantially larger, is draft-specific (no
// other page needs it), and every symbol in it is already class_exists()/function_exists()
// guarded -- so a require_once here is safe even if this page and bb_draft_parser.php both load
// on the same request. If the actual deployment target can't place bb_draft_parser.php
// alongside this file (or doesn't support require_once across custom pages the way this
// comment assumes), inline its contents here instead -- flagging this as a decision point for
// Alan to confirm against the real deployment layout, not something settled by this session.
// --------------------------------------------------------------
require_once __DIR__ . '/bb_draft_parser.php';

// --------------------------------------------------------------
// Extract Baseball Draft -- manually-triggered extraction page (task doc §2), not a Dadabik
// hook, matching extract_standings.php/extract_games.php/extract_playbyplay.php's own pattern
// (chosen there for visibility/control/timeout-safety given how much parsing a single turn
// needs -- same reasoning applies here, arguably more so given a draft turn's Draft List block
// alone can carry 90 draftees).
//
// UNLIKE extract_standings.php, this page does NOT exclude "already processed" uploads from
// the selector. Standings are a single-shot-per-week fact (once written, re-running is a no-op
// duplicate); a baseball draft is inherently multi-turn (task doc §5) -- the pool arrives once,
// order arrives once, each round's selections and any scouting results arrive on separate later
// turns, so the SAME upload_id being re-selected and re-run, or a LATER turn covering the same
// draft being run next, are both normal, expected uses of this page, not repeats to hide.
// bb_process_draft_turn() is idempotent (upserts on the schema's own unique keys), so re-running
// any turn is always safe.
// --------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h1>Extract Baseball Draft</h1>";

$_cp_upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;

// -------------------- Extraction (buffered) --------------------
// Same reasoning as extract_standings.php: runs BEFORE the upload selector is queried, output
// held in a buffer until after the selector renders, so this turn's own effects (e.g. a
// franchise_identities row this run just wrote) are visible if the selector's own query
// happens to depend on them, and so the do/while(false) wrapper's `break`s can exit early
// without truncating the page (a bare `return` in an included file exits the whole file).
ob_start();
do {

    if (!$_cp_upload_id) {
        break;
    }

    $_cp_upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $_cp_upload_id);

    if (!$_cp_upload || !$_cp_upload['league_id'] || !$_cp_upload['week_id']) {
        echo "<p><em>This upload has no league/week identified yet. Check its <code>parse_status</code>/<code>parse_notes</code> -- "
           . "the after-insert hook should have identified it automatically when it was uploaded.</em></p>";
        break;
    }

    $_cp_stmt = $conn->prepare(
        "SELECT COUNT(*) FROM raw_upload_blocks WHERE upload_id = :u AND block_type IN ('Draft List','Actions','Team Report')"
    );
    $_cp_stmt->execute([':u' => $_cp_upload_id]);
    if ((int)$_cp_stmt->fetchColumn() === 0) {
        echo "<p><em>No 'Draft List', 'Actions', or 'Team Report' block found for this upload -- "
           . "nothing for the draft extraction to work with. Has it been split into blocks yet?</em></p>";
        break;
    }

    $_cp_summary = bb_process_draft_turn($conn, $_cp_upload_id);

    // Per-upload completion marker (B1_draft_extracted_at.r001.sql) -- see the selector comment
    // below for why this exists instead of a NOT EXISTS check against a downstream table the
    // way extract_standings.php/extract_games.php do it. Stamped unconditionally on every
    // successful run, including a re-run of an already-stamped upload (e.g. after a parser fix
    // like the draftId bug caught 24 Aug 2026) -- idempotent, and "when was this last
    // processed" is a more useful value here than "was it ever processed".
    $conn->prepare("UPDATE raw_uploads SET draft_extracted_at = NOW() WHERE upload_id = :u")
         ->execute([':u' => $_cp_upload_id]);

    echo "<div class='w3-panel w3-pale-green w3-text-black w3-round-large'>";
    echo "<p><strong>{$_cp_summary['draftees']}</strong> draftee row(s) written/refreshed.</p>";
    echo "<p><strong>{$_cp_summary['draft_order']}</strong> draft order pick(s) written/refreshed.</p>";
    echo "<p><strong>{$_cp_summary['picks']}</strong> round selection(s) written/refreshed.</p>";
    echo "<p><strong>{$_cp_summary['scouted']}</strong> scouting result(s) written/refreshed.</p>";
    echo "</div>";

    if (!empty($_cp_summary['warnings'])) {
        echo "<div class='w3-panel w3-pale-yellow w3-text-black w3-round-large'>";
        echo "<p><strong>Notes (" . count($_cp_summary['warnings']) . "):</strong></p><ul>";
        foreach ($_cp_summary['warnings'] as $line) {
            echo "<li>" . htmlspecialchars($line) . "</li>";
        }
        echo "</ul></div>";
    }

} while (false);
$_cp_body = ob_get_clean();

// -------------------- Upload selector --------------------
// Baseball uploads only (leagues.game_type = 'baseball'), identified (league_id/week_id set),
// carrying at least one block this page can act on.
//
// Excludes already-processed uploads (Alan, 24 Aug 2026 -- with every turn always shown, the
// list becomes unmanageable within a couple of turns). This is NOT the same NOT EXISTS trick
// extract_standings.php/extract_games.php use, because that trick relies on the downstream
// table being WEEK-scoped (standings_weekly/games each get one row set per week_id, so "does
// this week already have a row" unambiguously means "was this upload processed"). Baseball
// draft writes (bb_draftees, bb_draft_order, bb_draftee_scouting) are DRAFT-scoped instead --
// keyed to draft_id, which spans every turn of a league's draft -- so "does the draft have
// rows" would wrongly hide turn 16 the moment turn 15 is processed, since turn 15's pool is
// still sitting there. No existing table can answer "was THIS upload_id processed" the way
// games/standings_weekly can for football, so raw_uploads.draft_extracted_at
// (B1_draft_extracted_at.r001.sql) exists as an explicit per-upload marker instead, stamped by
// this page itself right after a successful bb_process_draft_turn() call, above.
//
// Hiding from this dropdown does NOT block re-processing -- exactly like the football pages,
// nothing here gates $_cp_upload_id above; going directly to this page's URL with
// &upload_id=NN re-runs and re-stamps it regardless of whether it's in this list. That's the
// deliberate "force" path for a turn that genuinely needs re-running (e.g. after a parser fix).
//
// Ordered by league, then season, then week (Alan, 24 Aug 2026 -- turns from different leagues
// were interleaving in a single flat turn-number sort). w.week_number is used rather than
// ru.turn_number as the tertiary sort key since that's what "week" means to a reader of this
// dropdown -- the two are expected to be identical per the week_number/turn_number invariant
// documented in findings/B1-header-shape-and-week-collision.md, but week_number is the one this
// label is actually built from two lines below, so sorting and labelling agree by construction.
// Each key keeps the "IS NULL, ASC" trick so unidentified rows (which shouldn't reach this list
// at all given the WHERE clause, but kept as a defensive tail-sort rather than assumed
// impossible) sort last instead of first.
$_cp_sql = "SELECT ru.upload_id, ru.original_filename, ru.turn_number,
                   l.code AS league_code, s.year AS season_year, w.week_number, w.phase
            FROM raw_uploads ru
            JOIN leagues l ON l.league_id = ru.league_id AND l.game_type = 'baseball'
            LEFT JOIN seasons s ON s.season_id = ru.season_id
            LEFT JOIN weeks w ON w.week_id = ru.week_id
            WHERE ru.league_id IS NOT NULL AND ru.week_id IS NOT NULL
              AND ru.parse_status <> 'duplicate'
              AND ru.draft_extracted_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM raw_upload_blocks rub
                  WHERE rub.upload_id = ru.upload_id AND rub.block_type IN ('Draft List','Actions','Team Report')
              )
            ORDER BY l.code ASC,
                     s.year IS NULL, s.year ASC,
                     w.week_number IS NULL, w.week_number ASC,
                     ru.upload_id ASC";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute();
$_cp_uploads = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<form method='get'>";
// Hidden inputs preserving Dadabik's own routing params (function=show_static_page&
// id_static_page=N) -- a GET form with no explicit action submits to the current path but
// REPLACES the entire query string with only its own fields, dropping these -- Dadabik then
// doesn't recognize the request as "show this static page" and falls through to its default
// (home) page instead. Same fix already applied to extract_standings.php's own upload selector
// (and current_standings.php's league toggle, team.php's franchise selector) -- reused here
// rather than rediscovered. Read from the current request rather than hardcoded, so it's
// self-correcting across any future reinstall.
echo "<input type='hidden' name='function' value='" . htmlspecialchars($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . htmlspecialchars($_GET['id_static_page'] ?? '') . "'>";
if (empty($_cp_uploads)) {
    echo "<p><em>Nothing left to process -- every identified baseball turn with a Draft List, Actions, "
       . "or Team Report block has already been extracted. To re-run one deliberately (e.g. after a "
       . "parser fix), add its <code>&amp;upload_id=NN</code> to this page's URL directly.</em></p>";
}
echo "<select name='upload_id' onchange='this.form.submit()' style='width:480px'>";
echo "<option value=''>-- select a turn --</option>";
foreach ($_cp_uploads as $u) {
    $label = "{$u['league_code']} {$u['season_year']} Wk {$u['week_number']}"
        . ($u['phase'] === 'postseason' ? ' (postseason)' : '')
        . ($u['turn_number'] !== null ? " (turn {$u['turn_number']})" : '');
    $sel = (isset($_GET['upload_id']) && $_GET['upload_id'] == $u['upload_id']) ? 'selected' : '';
    echo "<option value='{$u['upload_id']}' $sel>"
       . htmlspecialchars("{$u['original_filename']} ($label)") . "</option>";
}
echo "</select>";
echo "</form><br>";

echo $_cp_body;
echo "</div>";

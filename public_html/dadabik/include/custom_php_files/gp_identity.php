<?php
// gp_identity.php · rev 001 · 17 Aug 2026 · role: shared helper · authority: derived
// Task A5. Week-grain team-identity resolution for reader-facing pages.
//
// ------------------------------------------------------------------
// WHY THIS FILE EXISTS
//
// franchises.label is a STORED GENERATED column (city + nickname) and always reads as the
// slot's CURRENT identity -- schema.md section 12. Franchise 2014 reads "Dallas Cowboys"
// across its whole history including 394 fixtures actually played as Washington Commanders.
// Every reader-facing page in this app resolved names through that column, so 4,003 of 10,035
// games showed a team under a name it was not playing under that week (A5 audit, 17-Aug-2026).
//
// franchise_identities holds the correct identity per (franchise, week) inside gplan_pbm, and
// both parsers write to it during a normal parse. That is what this file resolves against.
//
// replay.php's own header says a shared include is "a bigger architectural call than this one
// page justifies by itself" and flags it as "worth reconsidering if this logic ever needs to
// change in both places". It now needs to change in four, and Alan asked for ONE toggle rather
// than four copies of it -- so this is that reconsideration, scoped to identity resolution only.
//
// DEPLOYMENT: this file lives in `include/custom_php_files/`, beside the pages that use it,
// and is included as __DIR__ . '/gp_identity.php'. NOT beside error_handler.php one level up:
// that directory is DaDaBIK's own, holding three dozen files this project neither manages nor
// touches, so anything left there is a file an upgrade can overwrite and a move can forget.
// Nothing here depends on the location -- four constants and three functions -- so it belongs
// in the folder this project owns and copies as a unit. (First delivered pointing at the parent
// on precedent alone, corrected 17 Aug 2026 before deployment.)
//
// It is a hard dependency of game.php, replay.php, team.php and home.php. Deploy it FIRST.
// It is included unguarded, exactly like error_handler.php: if it is missing the page fails
// loudly, which is the intended behaviour -- a page that silently fell back to franchises.label
// would look correct and be wrong, which is the defect this file exists to remove.
// ------------------------------------------------------------------

// ==================================================================
// INTERNAL TOGGLES -- not exposed to the UI, not read from the query string, not per-user.
// Change the value here, save, reload the page. No data change, no rebuild, no deploy of
// anything else. Each is 'historical' (the identity that week) or 'current' (the slot's
// present-day identity); GP_IDENT_GAME_TITLE additionally accepts 'stored'.
// ==================================================================

// team.php's season game-log OPPONENT column. This is the one Alan explicitly reserved the
// right to change his mind about (A5 decision gate, 17-Aug-2026).
//   'historical' -- "1997 Wk 4 vs Washington Commanders" (who they actually played)
//   'current'    -- "1997 Wk 4 vs Dallas Cowboys" (what that slot is called today)
if (!defined('GP_IDENT_OPPONENT'))       define('GP_IDENT_OPPONENT', 'historical');

// game.php box-score team headings and summary table; play-by-play and drive-summary offence
// names on game.php and replay.php. All describe one specific game.
if (!defined('GP_IDENT_GAME_TEAMS'))     define('GP_IDENT_GAME_TEAMS', 'historical');

// The <h1> on game.php and replay.php.
//   'historical' -- composed from franchise_identities (recommended; always right)
//   'current'    -- composed from franchises.label
//   'stored'     -- games.label as held in the database. Correct only for as long as the
//                   stored column is correct; A5_rebuild_games_label.r001.sql makes it so and
//                   extract_games.php keeps new games right, but this option makes the page
//                   depend on that staying true. Kept because it is the pre-A5 behaviour and
//                   reverting to it should not require an edit to any page.
if (!defined('GP_IDENT_GAME_TITLE'))     define('GP_IDENT_GAME_TITLE', 'historical');

// team.php's own page heading, and coach.php's franchise list. A franchise page arguably
// SHOULD say what the team is called today -- Alan's §1c answer 2. Default 'current'.
// Neither page reads this constant today (both already show the current name and needed no
// change); it is declared here so the decision is written down in the same place as the others
// and so a future change has somewhere obvious to hook into.
if (!defined('GP_IDENT_FRANCHISE_PAGE')) define('GP_IDENT_FRANCHISE_PAGE', 'current');

// ==================================================================
// HELPERS
// ==================================================================

/**
 * The LEFT JOIN onto franchise_identities for one franchise column at week grain.
 *
 * LEFT, not INNER, deliberately. tasks/A5-games-label.md section 9 records that an inner join
 * is safe today only because zero games lack an identity row -- a measured fact, not a
 * constraint. An inner join here would make a page silently drop a game the day that stops
 * being true; a left join degrades to the current name for that one row instead.
 *
 * @param string $fi_alias        alias to give franchise_identities
 * @param string $franchise_expr  e.g. "g.home_franchise_id" or "p.offense_franchise_id"
 * @param string $week_expr       e.g. "g.week_id" or a bound parameter like ":wid"
 */
function gp_ident_join($fi_alias, $franchise_expr, $week_expr) {
    return "LEFT JOIN franchise_identities $fi_alias "
         . "ON $fi_alias.franchise_id = $franchise_expr AND $fi_alias.week_id = $week_expr";
}

/**
 * Pick the name to display, given both candidates and a toggle value.
 * Falls back to the current label whenever the historical one is absent or empty.
 */
function gp_ident_name($mode, $identity_name, $current_label) {
    if ($mode === 'historical' && $identity_name !== null && $identity_name !== '') {
        return $identity_name;
    }
    return $current_label;
}

/**
 * Build a game title in exactly the format games.label holds:
 *   "NFLAR 2021 Wk 11: Washington Commanders vs New York Giants"
 * so that switching GP_IDENT_GAME_TITLE between 'historical', 'current' and 'stored' changes
 * the names and nothing else about the string.
 *
 * Expects a row carrying: label, league_code, season_year, week_number,
 * home_label, away_label, home_ident, away_ident.
 */
function gp_game_title(array $r) {
    if (GP_IDENT_GAME_TITLE === 'stored') {
        return ($r['label'] ?? '') !== '' ? $r['label'] : gp_game_title_compose($r, 'historical');
    }
    return gp_game_title_compose($r, GP_IDENT_GAME_TITLE);
}

function gp_game_title_compose(array $r, $mode) {
    $home = gp_ident_name($mode, $r['home_ident'] ?? null, $r['home_label'] ?? '');
    $away = gp_ident_name($mode, $r['away_ident'] ?? null, $r['away_label'] ?? '');
    // If the row somehow carries no names at all, the stored label is a better answer than
    // a string of empty gaps -- and if that is empty too there is nothing honest to show.
    if ($home === '' || $away === '') {
        return $r['label'] ?? '';
    }
    return $r['league_code'] . ' ' . $r['season_year'] . ' Wk ' . $r['week_number']
         . ': ' . $home . ' vs ' . $away;
}

<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// gp_game_type.php · rev 002 · 21 Oct 2026 · role: shared helper · authority: derived
// Item P16 (Alan, Q1 to Q3 of 21 Oct 2026 session2). The one rule for showing a game's type on a page.
// rev 002: NFLAR's "Play Offs" games are named by round (Alan, V3 of 21 Oct 2026); the helper takes the game row.
// rev 001: first issue (never committed): the type by name, and the switch.
//
// ------------------------------------------------------------------
// WHY THIS FILE EXISTS
//
// Every game carries its type (games.game_type_id, NOT NULL, FK to game_types), but team.php's
// season game log did not show it, so a Superbowl, a play-off game and an eliminated franchise's
// pre-season warm-up in the same play-off week all read alike (the backlog's case: NFLAR 2005
// Minnesota Vikings, Wk 20). game.php already showed the type in its subtitle for any game that is
// not regular season; this file makes that one rule, read by game.php, team.php and replay.php,
// so the three cannot drift and one switch changes all three (Q3).
//
// DEPLOYMENT: beside the pages in `include/custom_php_files/`, included as
// __DIR__ . '/gp_game_type.php' (the gp_identity.php precedent, same reasons). Deploy it FIRST:
// the pages include it unguarded and fail loudly without it, by design.
// ------------------------------------------------------------------

// ==================================================================
// INTERNAL TOGGLE -- not exposed to the UI, not read from the query string, not per-user.
// Change the value here, save, reload the page. On gpbm.uk the file has to be sent up like any
// other code change.
// ==================================================================

// Whether a REGULAR-season game shows its type ("Regular Season") on the pages.
//   false -- the type is shown only for pre-season and postseason games; a regular-season game
//            shows nothing (Alan, Q1 of 21 Oct 2026: the default)
//   true  -- every game shows its type, "Regular Season" included
if (!defined('GP_GAME_TYPE_SHOW_REGULAR')) define('GP_GAME_TYPE_SHOW_REGULAR', false);

// ==================================================================
// NFLAR's PLAY-OFF ROUNDS
//
// game_types holds one type, 35 "Play Offs", for all three NFLAR play-off rounds before the
// Superbowl. The round is the week (Alan, V3 of 21 Oct 2026): ✓ measured 21 Oct 2026, every one of
// the 31 seasons 2004 to 2034 holds 2 Play Offs games in week 17, 4 in week 18 and 2 in week 19
// (248 games). Week 19 is the conference final: all 62 of its games are between two teams of
// the same conference, one AFC and one NFC game each season, so the home team's conference
// names it. franchises.conference is a present-day snapshot, but no NFLAR franchise has ever
// changed conference (0 of 11,688 standings_weekly rows differ from it, 21 Oct 2026).
// A week not listed here keeps the type's own name.
// ==================================================================
const GP_PLAYOFF_TYPE_ID = 35;
const GP_PLAYOFF_TYPE_NAME = 'Play Offs';
const GP_PLAYOFF_ROUNDS = [
    17 => 'Wild Card Round',
    18 => 'Divisional Round',
    19 => '%s Championship Game',   // %s: the conference, "AFC" or "NFC"
];

/**
 * The round name for an NFLAR Play Offs game, or '' when the row is not one or its week has no round.
 * Keyed on the type's id AND its name, so a renumbered or renamed type falls back to its own name.
 */
function gp_playoff_round(array $r) {
    if ((int)($r['game_type_id'] ?? -1) !== GP_PLAYOFF_TYPE_ID
        || ($r['game_type_name'] ?? '') !== GP_PLAYOFF_TYPE_NAME) {
        return '';
    }
    $week = (int)($r['week_number'] ?? -1);
    if (!isset(GP_PLAYOFF_ROUNDS[$week])) {
        return '';
    }
    $name = GP_PLAYOFF_ROUNDS[$week];
    if (strpos($name, '%s') !== false) {
        $conf = trim((string)($r['home_conference'] ?? ''));
        $name = $conf !== '' ? sprintf($name, $conf) : 'Conference Championship Game';
    }
    return $name;
}

/**
 * The type to display for one game, or '' for none.
 *
 * Expects a row carrying: game_type_id, game_type_name, phase ('preseason', 'regular' or
 * 'postseason'), week_number, home_conference.
 */
function gp_game_type_label(array $r) {
    $name = $r['game_type_name'] ?? null;
    if ($name === null || $name === '') {
        return '';
    }
    if (($r['phase'] ?? null) === 'regular' && !GP_GAME_TYPE_SHOW_REGULAR) {
        return '';
    }
    $round = gp_playoff_round($r);
    return $round !== '' ? $round : $name;
}

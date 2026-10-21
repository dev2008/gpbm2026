<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// gp_venue.php · rev 001 · 21 Oct 2026 · role: shared helper · authority: derived
// Item P17 (Alan, Q7 to Q15 of 21 Oct 2026 session2). Where a neutral-site game is played, as the pages show it.
//
// ------------------------------------------------------------------
// WHY THIS FILE EXISTS
//
// No turn file names a venue (schema.md s28), so a neutral game's site is entered by the administrator on dev
// (Q8, Q14) and carried to gpbm.uk by the up-sync. It is chosen from the `venues` table (Q9): the game's own
// games.venue_id if set, otherwise its type's game_types.default_venue_id (Q7). venues.label is "name, city"
// (Q10's naming rule). team.php, game.php and replay.php all read it through this file, so they show the same
// thing and one switch changes all three.
//
// DEPLOYMENT: beside the pages in `include/custom_php_files/`, included as __DIR__ . '/gp_venue.php'
// (the gp_identity.php precedent). Deploy it FIRST: the pages include it unguarded and fail loudly without it.
// ------------------------------------------------------------------

// ==================================================================
// INTERNAL TOGGLE -- not exposed to the UI, not read from the query string, not per-user.
// Change the value here, save, reload the page. On gpbm.uk the file has to be sent up like any other code change.
// ==================================================================

// Whether a site is followed by a "neutral" marker.
//   false -- "Rose Bowl, Pasadena" (Alan, Q15 of 21 Oct 2026: the default)
//   true  -- "Rose Bowl, Pasadena (neutral)" on team.php, "Rose Bowl, Pasadena (neutral site)" in the subtitles
if (!defined('GP_VENUE_SHOW_NEUTRAL')) define('GP_VENUE_SHOW_NEUTRAL', false);

/**
 * The joins a game query adds to reach its venue. $g is the games alias, $gt the game_types alias (already joined).
 * LEFT joins: a game with no venue anywhere keeps its row.
 */
function gp_venue_join($g = 'g', $gt = 'gt') {
    return "LEFT JOIN venues gpv_g ON gpv_g.venue_id = $g.venue_id "
         . "LEFT JOIN venues gpv_t ON gpv_t.venue_id = $gt.default_venue_id";
}

/** The column a game query selects for its venue: the game's own, else its type's. */
function gp_venue_select() {
    return "COALESCE(gpv_g.label, gpv_t.label) AS venue_label";
}

/**
 * The site to show for one game, or '' when there is none to show.
 * Only a neutral game shows a site: a home or road game is shown as before.
 *
 * Expects a row carrying: neutral_site, venue_label.
 * $marker is what GP_VENUE_SHOW_NEUTRAL appends: ' (neutral)' in a table cell, ' (neutral site)' in a subtitle.
 */
function gp_venue_label(array $r, $marker = ' (neutral)') {
    if (empty($r['neutral_site'])) {
        return '';
    }
    $label = trim((string)($r['venue_label'] ?? ''));
    if ($label === '') {
        return '';
    }
    return GP_VENUE_SHOW_NEUTRAL ? $label . $marker : $label;
}

<?php
// gp_league.php · rev 002 · 11 Oct 2026 · role: shared helper · authority: derived
// Item H52 (NZ7 of 3 Oct 2026; ruled Q8 of 6 Oct 2026). The one place that reads `league=` from the URL.
// rev 002: an unserved code starting NCAA falls back to NCAA5, one starting NFL to NFLAR (Alan, 11 Oct 2026).
//
// ------------------------------------------------------------------
// WHY THIS FILE EXISTS
//
// team.php, bowl_records.php and current_standings.php each carried the same line:
//   (isset($_GET['league']) && $_GET['league'] === 'NCAA5') ? 'NCAA5' : 'NFLAR'
// so `league=NCAA7` (a real league in `leagues`, but not one these pages serve) showed NFLAR
// and said nothing. Alan ruled a VISIBLE note instead of the silent fallback, and one helper
// for every page that reads the parameter, so the three copies cannot drift.
//
// What it does: the served leagues are a short fixed list (the two these pages have data for,
// in the order the toggle shows them). A missing parameter is the default league with no note.
// A served code is that league with no note. Anything else falls back WITH a note: a code
// starting NCAA (any case) to NCAA5, one starting NFL to NFLAR, the rest to the default. The
// note names the value only when it looks like a league code (letters and digits, at most 12);
// any other value is described, never echoed, so the note cannot be used to reflect text into
// the page (security.md §5c: an error must never disclose, and must not be a vehicle either).
//
// DEPLOYMENT: beside the pages in `include/custom_php_files/`, included as
// __DIR__ . '/gp_league.php' (the gp_identity.php precedent, same reasons). Deploy it FIRST:
// the three pages include it unguarded and fail loudly without it, by design.
// ------------------------------------------------------------------

// The leagues these pages serve, code => toggle label, in toggle order. The first is the default.
function gp_leagues_served() {
    return ['NFLAR' => 'NFLAR (Pro)', 'NCAA5' => 'NCAA5 (College)'];
}

// Reads `league=` from the URL. Returns [code, note]: the league code to use, and the note to
// show (empty string when there is nothing to say). Never throws, never echoes.
function gp_league_from_url() {
    $served  = gp_leagues_served();
    $default = array_key_first($served);
    if (!isset($_GET['league'])) {
        return [$default, ''];
    }
    $asked = $_GET['league'];
    if (is_string($asked) && isset($served[$asked])) {
        return [$asked, ''];
    }
    $shown = $default;
    if (is_string($asked) && preg_match('/^[A-Za-z0-9]{1,12}$/', $asked)) {
        $what = "The league \"" . htmlspecialchars($asked, ENT_QUOTES, 'UTF-8') . "\" is not available on this page";
        // the family decides the fallback: an NCAA code is a college league, an NFL code a pro one
        if (stripos($asked, 'NCAA') === 0 && isset($served['NCAA5'])) {
            $shown = 'NCAA5';
        } elseif (stripos($asked, 'NFL') === 0 && isset($served['NFLAR'])) {
            $shown = 'NFLAR';
        }
    } else {
        $what = "The league named in the address is not available on this page";
    }
    return [$shown, $what . ", so " . htmlspecialchars($shown, ENT_QUOTES, 'UTF-8') . " is shown instead."];
}

// The note as HTML, in the style current_standings.php already uses for its mixed-weeks notice.
// Prints nothing for an empty note, so every page can call it unconditionally.
function gp_league_note_html($note) {
    if ($note === '') {
        return '';
    }
    return "<div class='w3-panel w3-pale-yellow w3-text-black w3-leftbar w3-border-yellow w3-round-large'>"
         . "<p>" . $note . "</p></div>\n";
}

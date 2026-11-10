<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// team_plays.php · rev 004 · 10 Nov 2026 · role: page (administrator only, on both hosts; read-only) · authority: derived
// rev 004: a line under the heading says when the seasons listed are fewer than the seasons held (Alan, on V14a of 10 Nov 2026
//          session1: "another line: Filtered to current and previous season"): the page opened on two seasons of eight and
//          nothing but the two selectors said so.
// rev 003: Alan's NZ4 to NZ6 of 10 Nov 2026 session1. "Every coach" defaults to EVERY season with plays (it showed the latest two,
//          the same two as the current coach, so the years did not move when it was chosen); a named coach still defaults to
//          the latest two seasons of his tenure. The "Hide special teams" label is white: DaDaBIK styles a label dark, which
//          on this panel could not be read.
// rev 002: Alan's browser checks of 10 Nov 2026 session1 (NZ1 to NZ3). (1) The count line names the side: "359 plays on offence".
//          (2) Seasons default to the latest season with plays for the selection and the one before it, not the whole span.
//          (3) The games, and so the plays and the download, run from the most recent game back; the plays of a game stay in
//          the order they were run. (4) The form is autocomplete='off' and re-enables its selectors when the page is shown
//          again, so a page reached by Back or reload shows the selectors the server used, never the browser's remembered ones.
// rev 001: first issue. Item H92 (Alan, item 0 and Q2, Q3 of 10 Nov 2026 session1; GameplanPBM/20261110/session1/SESSION_20261110_1.md).
//
// ------------------------------------------------------------------
// WHAT THIS PAGE DOES
//
// Team Plays: every play held for one team on offence or on defence, play by play (down, distance, formation, both
// calls, result), inside one coach's tenure and a span of seasons, with a CSV download of the selection. The Play Calls
// views beside it in the menu are totals, and only for PE, MV and PI; this lists the plays themselves, for any team.
// What is held is what the uploaded turn reports printed: a team's plays exist only for its games against a team whose
// reports were uploaded, and the page says which games it found.
//
// The rows come from gp_team_plays.php (included), which reads the view v_team_plays, so this page and the CLI that
// proves it gate on one piece of code. Selectors (style-guide.md s4), each a GET form submit: league, team, side, coach
// (default the current coach; "every coach" available), season from and to (default the coach's latest two with plays; for
// every coach, all of them), and a box
// to hide special teams. The most recent game is first. A selector
// that changes disables the ones below it first (bb_roster_compare.php rev 003's rule), so the address carries only what
// was chosen. Administrator only (Q3, as the Play Calls views). Nothing is written.
//
// The table shows at most TP_LIST_CAP plays; the download always carries the whole selection, and the page says so
// when the two differ.
// ------------------------------------------------------------------

// Games (game.php) is static page 10 on both hosts (GPBM s6: page numbers are equal across hosts)
define('TP_GAME_PAGE_STATIC_ID', 10);
// the most plays the table shows: a slot's whole history on one side is tens of thousands of rows
define('TP_LIST_CAP', 3000);

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h3>Team Plays</h3>";

// Administrator only (Q3). DaDaBIK's own page permission is the gate (zpbm_permissions, custom_page); this is the second.
if ($current_user_is_administrator != 1) {
    echo "<p>This page is for the administrator.</p></div>";
    return;
}
require_once __DIR__ . '/gp_league.php';
require_once __DIR__ . '/gp_identity.php';
require_once __DIR__ . '/gp_team_plays.php';

$_tp_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// style-guide.md s1: every cell is padded. Here by one rule for the page's tables, not a style on each of 60,000 cells
echo "<style>table.gp-tp td, table.gp-tp th { padding: 4px 8px; }</style>";
$_tp_td = fn($v, $tag = 'td') => "<$tag>$v</$tag>";

// -------------------- the selection: only ever a value read from the database --------------------
[$_tp_league, $_tp_note] = gp_league_from_url();
echo gp_league_note_html($_tp_note);

$_tp_st = $conn->prepare(
    "SELECT f.franchise_id, f.label FROM franchises f JOIN leagues l ON l.league_id = f.league_id
      WHERE l.code = :lg ORDER BY f.label");
$_tp_st->execute([':lg' => $_tp_league]);
$_tp_teams = $_tp_st->fetchAll(PDO::FETCH_ASSOC);
if (!$_tp_teams) { echo "<p>No team is held for " . $_tp_h($_tp_league) . ".</p></div>"; return; }

// the request's value when it names one on offer, else the default
$_tp_pick = function (array $allowed, $want, $default) {
    foreach ($allowed as $a) { if ((string)$a === (string)$want) { return $a; } }
    return $default;
};
$_tp_fids = array_map(fn($r) => (int)$r['franchise_id'], $_tp_teams);
$_tp_fid = (int)$_tp_pick($_tp_fids, $_GET['franchise'] ?? '', $_tp_fids[0]);
$_tp_label = '';
foreach ($_tp_teams as $r) { if ((int)$r['franchise_id'] === $_tp_fid) { $_tp_label = (string)$r['label']; } }
$_tp_side = $_tp_pick(['off', 'def'], $_GET['side'] ?? '', 'off');

// the coach: a tenure of this franchise, or every coach. The default is the open tenure (the current coach).
$_tp_tenures = gp_tp_tenures($conn, $_tp_fid);
$_tp_default_coach = 'all';
foreach ($_tp_tenures as $t) { if ($t['open']) { $_tp_default_coach = (string)$t['tenure_id']; break; } }
$_tp_coach = (string)$_tp_pick(array_merge(['all'], array_map(fn($t) => (string)$t['tenure_id'], $_tp_tenures)), $_GET['coach'] ?? '', $_tp_default_coach);
$_tp_lo = null; $_tp_hi = null; $_tp_coach_text = 'every coach';
foreach ($_tp_tenures as $t) {
    if ((string)$t['tenure_id'] === $_tp_coach) { $_tp_lo = $t['lo']; $_tp_hi = $t['hi']; $_tp_coach_text = $t['label']; }
}

// the seasons that hold plays for that team, side and coach. The default is the latest of them and the season before it
// when that one holds plays too (NZ2: the current and previous seasons): the recent games are the ones that matter.
// "Every coach" is the whole history by its nature, so its default is every season (NZ4). The seasons are always set
// afresh when the team, side or coach changes: a span chosen for one coach means nothing for another
$_tp_seasons = gp_tp_seasons($conn, $_tp_side, $_tp_league, $_tp_fid, $_tp_lo, $_tp_hi);
$_tp_years = array_keys($_tp_seasons);
$_tp_last = $_tp_years ? (int)$_tp_years[count($_tp_years) - 1] : null;
$_tp_from = $_tp_years ? (int)$_tp_pick($_tp_years, $_GET['from'] ?? '', ($_tp_coach === 'all') ? (int)$_tp_years[0] : (isset($_tp_seasons[$_tp_last - 1]) ? $_tp_last - 1 : $_tp_last)) : null;
$_tp_to   = $_tp_years ? (int)$_tp_pick($_tp_years, $_GET['to'] ?? '', $_tp_last) : null;
$_tp_hide_st = (($_GET['st'] ?? '') === 'hide');

// -------------------- the selectors (style-guide.md s4: a GET form, a reload per change) --------------------
$_tp_opt = fn($value, $text, $chosen) => "<option value='" . $_tp_h($value) . "'" . ((string)$value === (string)$chosen ? ' selected' : '') . '>' . $_tp_h($text) . '</option>';
// a change disables the selectors below it, then submits: the address then carries only the levels chosen
echo "<script>function gpTpGo(s, below) { for (const n of below.split(' ')) { if (n && s.form[n]) { s.form[n].disabled = true; } } s.form.submit(); }</script>";
$_tp_go = fn($below) => "onchange='gpTpGo(this, \"$below\")'";
// rev 002: a browser restores a form's remembered values (and a disabled selector) on Back and on reload; then the selectors can
// show one side or coach while the page lists another. autocomplete='off' stops the values on a re-fetched page; on a page kept
// whole by the browser (pageshow, persisted) the form is reset to what the server wrote, and every selector enabled again.
echo "<form method='get' id='tp-form' autocomplete='off' class='w3-container w3-padding-small'>";
echo "<input type='hidden' name='function' value='" . $_tp_h($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . $_tp_h($_GET['id_static_page'] ?? '') . "'>";
echo "League <select name='league' " . $_tp_go('franchise coach from to') . ">";
foreach (gp_leagues_served() as $code => $text) { echo $_tp_opt($code, $text, $_tp_league); }
echo "</select> ";
echo "Team <select name='franchise' " . $_tp_go('coach from to') . ">";
foreach ($_tp_teams as $r) { echo $_tp_opt($r['franchise_id'], $r['label'], $_tp_fid); }
echo "</select> ";
echo "Side <select name='side' " . $_tp_go('from to') . ">" . $_tp_opt('off', 'Offence', $_tp_side) . $_tp_opt('def', 'Defence', $_tp_side) . "</select> ";
echo "Coach <select name='coach' " . $_tp_go('from to') . ">";
foreach ($_tp_tenures as $t) { echo $_tp_opt($t['tenure_id'], $t['label'], $_tp_coach); }
echo $_tp_opt('all', 'Every coach', $_tp_coach) . "</select> ";
if ($_tp_years) {
    echo "Season <select name='from' " . $_tp_go('') . ">";
    foreach ($_tp_seasons as $y => $n) { echo $_tp_opt($y, $y, $_tp_from); }
    echo "</select> to <select name='to' " . $_tp_go('') . ">";
    foreach ($_tp_seasons as $y => $n) { echo $_tp_opt($y, $y, $_tp_to); }
    echo "</select> ";
}
echo "<label class='w3-text-white'><input type='checkbox' name='st' value='hide'" . ($_tp_hide_st ? ' checked' : '') . " " . $_tp_go('') . "> Hide special teams</label>";
echo "</form>";
echo "<script>window.addEventListener('pageshow', function (e) { var f = document.getElementById('tp-form'); if (e.persisted) { f.reset(); } for (var i = 0; i < f.elements.length; i++) { f.elements[i].disabled = false; } });</script>";

$_tp_side_text = ($_tp_side === 'def') ? 'defence' : 'offence';
echo "<h4>" . $_tp_h("$_tp_league: $_tp_label, $_tp_side_text, $_tp_coach_text") . "</h4>";
if (!$_tp_years) {
    echo "<p>No play is held for this team on $_tp_side_text under this coach. Plays are held only for games against a team whose turn reports were uploaded.</p></div>";
    return;
}
if ($_tp_from > $_tp_to) {
    echo "<p>The first season must not be after the last: " . (int)$_tp_from . " is after " . (int)$_tp_to . ".</p></div>";
    return;
}

// rev 004: say so when fewer seasons are listed than are held for this team, side and coach. The default for the current
// coach is "the current and previous seasons" in Alan's words; any other narrowed span is named by its years.
if ($_tp_from > $_tp_years[0] || $_tp_to < $_tp_last) {
    $_tp_is_default = !isset($_GET['from']) && !isset($_GET['to']) && $_tp_coach === $_tp_default_coach && $_tp_coach !== 'all';
    $_tp_span = ($_tp_from === $_tp_to) ? "season " . (int)$_tp_from : "seasons " . (int)$_tp_from . " to " . (int)$_tp_to;
    echo "<p>" . ($_tp_is_default ? "Filtered to the current and previous seasons (" . (int)$_tp_from . " to " . (int)$_tp_to . ")." : "Filtered to $_tp_span.")
       . " Plays are held for " . (int)$_tp_years[0] . " to " . (int)$_tp_last . ": change Season above to list more.</p>";
}

// -------------------- the listing --------------------
$_tp_rows = gp_tp_rows($conn, $_tp_side, $_tp_league, $_tp_fid, $_tp_lo, $_tp_hi, $_tp_from, $_tp_to, $_tp_hide_st, true);   // the newest game first
$_tp_n = count($_tp_rows);

// the games found, the most recent first (the rows' order): [game_id => season, week, opponent, home or away, plays]
$_tp_games = [];
foreach ($_tp_rows as $r) {
    $g = $r['game_id'];
    if (!isset($_tp_games[$g])) { $_tp_games[$g] = ['season' => $r['season'], 'week' => $r['week'], 'opp' => $r['opponent_name'], 'ha' => $r['home_away'], 'n' => 0]; }
    $_tp_games[$g]['n']++;
}
echo "<p>" . number_format($_tp_n) . ($_tp_n === 1 ? " play" : " plays") . " on $_tp_side_text in "  . number_format(count($_tp_games)) . (count($_tp_games) === 1 ? " game" : " games") . ", seasons " . (int)$_tp_from . " to " . (int)$_tp_to
   . ($_tp_hide_st ? ", special teams hidden" : "") . ".</p>";
if ($_tp_n === 0) { echo "</div>"; return; }

// the download: the whole selection, built in the browser from the rows below (style-guide.md s5: data in a JSON tag)
$_tp_cols = gp_tp_columns();
$_tp_file = preg_replace('/[^A-Za-z0-9]+/', '_', "team_plays_{$_tp_league}_{$_tp_label}_{$_tp_side_text}_{$_tp_from}_{$_tp_to}") . '.csv';
echo "<script type='application/json' id='tp-data'>"
   . json_encode(['file' => $_tp_file, 'cols' => $_tp_cols, 'rows' => array_map(fn($r) => array_map(fn($c) => $r[$c], $_tp_cols), $_tp_rows)],
                 JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
   . "</script>";
echo "<p><button type='button' class='w3-button w3-white' id='tp-csv'>Download CSV (" . number_format($_tp_n) . " plays)</button></p>";
echo <<<'JS'
<script>
document.getElementById('tp-csv').addEventListener('click', function () {
    var d = JSON.parse(document.getElementById('tp-data').textContent);
    var q = function (v) { v = String(v); return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var lines = [d.cols.map(q).join(',')];
    for (var i = 0; i < d.rows.length; i++) { lines.push(d.rows[i].map(q).join(',')); }
    var blob = new Blob([lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = d.file;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
});
</script>
JS;

// the games, the most recent first
$_tp_tbl = "<div style='overflow-x:auto;margin-bottom:16px'><table class='gp-tp w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black w3-small' style='width:auto;white-space:nowrap'>";
echo $_tp_tbl . '<tr>' . implode('', array_map(fn($x) => $_tp_td($x, 'th'), ['Season', 'Week', 'Opponent', 'H/A', 'Plays', 'Game'])) . '</tr>';
foreach ($_tp_games as $g => $x) {
    $link = $_tp_h('index.php?function=show_static_page&id_static_page=' . TP_GAME_PAGE_STATIC_ID . '&game=' . urlencode($g));
    echo '<tr>' . $_tp_td($_tp_h($x['season'])) . $_tp_td($_tp_h($x['week'])) . $_tp_td($_tp_h($x['opp'])) . $_tp_td($_tp_h($x['ha']))
       . $_tp_td(number_format($x['n'])) . $_tp_td("<a href='$link' style='color:inherit;text-decoration:underline'>game</a>") . '</tr>';
}
echo '</table></div>';

// the plays
if ($_tp_n > TP_LIST_CAP) {
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>The table shows the first " . number_format(TP_LIST_CAP) . " of " . number_format($_tp_n)
       . " plays. The download carries all of them; narrow the seasons to list the rest here.</p>";
}
$_tp_heads = ['Season', 'Wk', 'Opp', 'H/A', 'Q', 'Clock', 'Yd line', 'Down', 'To go', 'Form', 'Off', 'Def', 'Yards', 'Pen yds', 'TD', 'TO', 'Sack', 'Inc', 'Penalty on', 'Result'];
echo $_tp_tbl . '<tr>' . implode('', array_map(fn($x) => $_tp_td($x, 'th'), $_tp_heads)) . '</tr>';
$_tp_shown = array_slice($_tp_cols, 0, count($_tp_heads));          // the columns up to the result; source and game id are in the download
foreach (array_slice($_tp_rows, 0, TP_LIST_CAP) as $r) {
    echo '<tr>';
    foreach ($_tp_shown as $c) { echo $_tp_td($_tp_h($r[$c])); }
    echo '</tr>';
}
echo '</table></div>';
echo "<p>Yd line is the distance to the goal line. Form X is a kickoff, P a punt, F a field goal. The download adds the source of each row (plays or legacy) and its game number.</p>";
echo "</div>";
?>

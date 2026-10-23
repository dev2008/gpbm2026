<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// bb_roster_compare.php · rev 003 · 23 Oct 2026 · role: page (administrator only, on both hosts; read-only) · authority: derived
// rev 003: two things, item H77 (Alan, 23 Oct 2026 session4, Q1 and Q4). (1) The counts line is reworded: it gave "31 players
//          in both weeks, 1 new" and read as "each week has 31, yet one was added"; it now gives each week's total, then the
//          carried-over, new (named) and gone counts, so it adds up on its face. (2) A selector that changes DISABLES the
//          selectors below it before the form submits, so the address bar carries only the values the page uses: before,
//          changing League resubmitted the old team, season and weeks too, which the page's fallback replaced silently while
//          the URL kept them (a copied link named one team and showed another). The fallback itself is unchanged (B4's Q2).
// rev 002: the "No longer on the roster" line moves into its own pale-yellow panel between the table and the legend, the
//          shape Extract Roster uses for its messages, and only when someone is gone. Item H75 (Alan, V4d and Q8 of
//          23 Oct 2026 session1: "the message is not clearly separated"). Nothing else changed.
// rev 001: first issue. Item B4 (Alan, Q1 to Q7 of 23 Oct 2026 session1; GameplanPBM/20261023/session1/SESSION_20261023_1.md s2).
//
// ------------------------------------------------------------------
// WHAT THIS PAGE DOES
//
// Roster Comparison: one baseball team's roster in one week against its roster in a later week, from bb_roster_entries
// (schema.md s32), as the legacy bb_rostercompare.php did it. The comparison itself is bb_roster_diff.php (included), so
// the CLI that proves it and this page gate on one piece of code.
//
// Selectors (Q2), each a GET form submit as style-guide.md s4: league, team, season, then the earlier and the later week,
// over every week with a roster stored for that team; the default is the two latest. A selector that changes disables
// the ones below it first (a disabled control is not submitted), so the request names only what is chosen and the page's
// defaults fill the rest (rev 003). Administrator only on both hosts (Q1): a coach-level version, each coach his own
// team, is a later item.
//
// The table (Q3): the legacy columns, Exp also old and new (it changes at week 21), no Notes column. A player new in
// the later week shows his current values alone. Players gone since the earlier week are listed under the table (Q4).
// The summary (Q5) counts as the legacy did: level and double-potential-loss per player, skill ups and downs per skill
// column, Pit and Bat/Cat apart. Nothing is written.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h3>Roster Comparison</h3>";

// Administrator only (Q1). DaDaBIK's own page permission is the gate (zpbm_permissions, custom_page); this is the second.
if ($current_user_is_administrator != 1) {
    echo "<p>This page is for the administrator.</p></div>";
    return;
}
require_once __DIR__ . '/bb_roster_diff.php';

$_rc_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
// style-guide.md s1: the table class list, padded cells
$_rc_tbl = "<div style='overflow-x:auto;margin-bottom:16px'><table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black w3-small' style='width:auto;min-width:480px'>";
$_rc_td = fn($v, $extra = '', $tag = 'td') => "<$tag style='padding:4px 8px'$extra>$v</$tag>";

// ---------------- every team-week with a roster stored ----------------
$_rc_all = $conn->query(
    "SELECT l.code, f.franchise_id, f.abbr, f.label, s.season_id, s.year, w.week_id, w.week_number, COUNT(*) AS players
     FROM bb_roster_entries e
     JOIN weeks w ON w.week_id = e.week_id
     JOIN seasons s ON s.season_id = w.season_id
     JOIN leagues l ON l.league_id = s.league_id
     JOIN franchises f ON f.franchise_id = e.franchise_id
     GROUP BY l.code, f.franchise_id, f.abbr, f.label, s.season_id, s.year, w.week_id, w.week_number
     ORDER BY l.code, f.abbr, s.year, w.week_number")->fetchAll(PDO::FETCH_ASSOC);
if (!$_rc_all) { echo "<p>No roster is stored yet (Extract Roster).</p></div>"; return; }

// the chosen league, team, season, week: the request's value when it names a stored one, else the default given
// (the first row where none is given). An unknown or malformed value is never echoed: the page only ever shows values
// read from the database.
$_rc_pick = function (array $rows, string $key, $want, $default = null) {
    foreach ($rows as $r) { if ((string)$r[$key] === (string)$want) { return (string)$r[$key]; } }
    return (string)($default ?? $rows[0][$key]);
};
$_rc_league = $_rc_pick($_rc_all, 'code', $_GET['league'] ?? '');
$_rc_lrows = array_values(array_filter($_rc_all, fn($r) => $r['code'] === $_rc_league));
$_rc_fid = (int)$_rc_pick($_rc_lrows, 'franchise_id', $_GET['franchise'] ?? '');
$_rc_frows = array_values(array_filter($_rc_lrows, fn($r) => (int)$r['franchise_id'] === $_rc_fid));
$_rc_sid = (int)$_rc_pick($_rc_frows, 'season_id', $_GET['season'] ?? '');
$_rc_weeks = array_values(array_filter($_rc_frows, fn($r) => (int)$r['season_id'] === $_rc_sid));   // ordered by week number
$_rc_n = count($_rc_weeks);
// the two weeks: the request's when each is a stored week of this team and season, else the two latest (Q2)
$_rc_wid = fn($w) => (int)$w['week_id'];
$_rc_to = (int)$_rc_pick($_rc_weeks, 'week_id', $_GET['to'] ?? '', $_rc_weeks[$_rc_n - 1]['week_id']);
$_rc_from = (int)$_rc_pick($_rc_weeks, 'week_id', $_GET['from'] ?? '', $_rc_weeks[max(0, $_rc_n - 2)]['week_id']);
$_rc_byid = []; foreach ($_rc_weeks as $w) { $_rc_byid[$_rc_wid($w)] = $w; }
$_rc_team = $_rc_weeks[0];

// ---------------- the selectors (style-guide.md s4: a GET form, a reload per change) ----------------
$_rc_opts = function (array $rows, string $key, string $labelKey, $chosen, ?callable $label = null) use ($_rc_h) {
    $seen = []; $o = '';
    foreach ($rows as $r) {
        $v = (string)$r[$key]; if (isset($seen[$v])) { continue; } $seen[$v] = 1;
        $o .= "<option value='" . $_rc_h($v) . "'" . ($v === (string)$chosen ? ' selected' : '') . '>'
            . $_rc_h($label ? $label($r) : $r[$labelKey]) . '</option>';
    }
    return $o;
};
// rev 003: a change disables the selectors below it, then submits; the URL then carries only the levels chosen
echo "<script>function gpRcGo(s, below) { for (const n of below.split(' ')) { if (s.form[n]) { s.form[n].disabled = true; } } s.form.submit(); }</script>";
$_rc_go = fn($below) => "onchange='gpRcGo(this, \"$below\")'";
echo "<form method='get' class='w3-container w3-padding-small'>";
echo "<input type='hidden' name='function' value='" . $_rc_h($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . $_rc_h($_GET['id_static_page'] ?? '') . "'>";
echo "League <select name='league' " . $_rc_go('franchise season from to') . ">" . $_rc_opts($_rc_all, 'code', 'code', $_rc_league) . "</select> ";
echo "Team <select name='franchise' " . $_rc_go('season from to') . ">" . $_rc_opts($_rc_lrows, 'franchise_id', 'label', $_rc_fid, fn($r) => "{$r['label']} ({$r['abbr']})") . "</select> ";
echo "Season <select name='season' " . $_rc_go('from to') . ">" . $_rc_opts($_rc_frows, 'season_id', 'year', $_rc_sid) . "</select> ";
$_rc_wlabel = fn($r) => "Week {$r['week_number']} ({$r['players']} players)";
echo "From <select name='from' onchange='this.form.submit()'>" . $_rc_opts($_rc_weeks, 'week_id', 'week_number', $_rc_from, $_rc_wlabel) . "</select> ";
echo "To <select name='to' onchange='this.form.submit()'>" . $_rc_opts($_rc_weeks, 'week_id', 'week_number', $_rc_to, $_rc_wlabel) . "</select>";
echo "</form>";

if ($_rc_n < 2) { echo "<p>Only one week is stored for this team and season: nothing to compare.</p></div>"; return; }
if ($_rc_from === $_rc_to) { echo "<p>Choose two different weeks.</p></div>"; return; }
$_rc_wf = $_rc_byid[$_rc_from]; $_rc_wt = $_rc_byid[$_rc_to];
if ((int)$_rc_wf['week_number'] > (int)$_rc_wt['week_number']) {
    echo "<p>The From week must be the earlier one: Week {$_rc_wf['week_number']} is after Week {$_rc_wt['week_number']}.</p></div>"; return;
}

// ---------------- the comparison ----------------
$_rc_d = bb_roster_diff($conn, $_rc_from, $_rc_to, $_rc_fid);
$_rc_c = $_rc_d['counts']; $_rc_s = $_rc_d['summary'];
echo "<h4>" . $_rc_h("{$_rc_league} Season {$_rc_team['year']}: {$_rc_team['label']} ({$_rc_team['abbr']}), Week {$_rc_wf['week_number']} to Week {$_rc_wt['week_number']}") . "</h4>";
// the counts line (rev 003): each week's total first, so the carried-over, new and gone counts add up on its face
$_rc_newnames = implode(', ', array_map(fn($r) => "{$r['shirt']} {$r['name']}", array_filter($_rc_d['rows'], fn($r) => $r['is_new'])));
echo "<p>" . $_rc_h("Week {$_rc_wf['week_number']}: {$_rc_wf['players']} players. Week {$_rc_wt['week_number']}: {$_rc_wt['players']} players: "
   . (int)$_rc_c['matched'] . " carried over, " . (int)$_rc_c['new'] . " new" . ($_rc_newnames !== '' ? " ($_rc_newnames)" : '') . ", "
   . (int)$_rc_c['gone'] . " gone since Week {$_rc_wf['week_number']}.") . "</p>";

// the summary, as the legacy page counted it (Q5)
$_rc_tag = fn($cls, $text) => "<span class='w3-tag w3-round $cls'>" . $_rc_h($text) . "</span>";
$_rc_pair = fn($k) => "Pit {$_rc_s['Pit'][$k]}, Bat/Cat {$_rc_s['BatCat'][$k]}";
echo "<div class='w3-container w3-padding-small'>";
echo "<p>" . $_rc_tag('w3-green', 'Level up: ' . $_rc_pair('level_up')) . ' ' . $_rc_tag('w3-red', 'Level down: ' . $_rc_pair('level_down')) . "</p>";
echo "<p>" . $_rc_tag('w3-green', 'Skills up: ' . $_rc_pair('skill_up')) . ' ' . $_rc_tag('w3-red', 'Skills down: ' . $_rc_pair('skill_down')) . "</p>";
echo "<p>" . $_rc_tag('w3-cyan w3-text-black', 'Potential down by more than one: ' . $_rc_pair('double_pot')) . "</p>";
echo "</div>";

// the table (Q3): a changed cell reads old → new and is coloured; an unchanged one reads the value alone
$_rc_dirc = ['up' => " class='w3-green'", 'down' => " class='w3-red'", '' => ''];
$_rc_cell = function (array $c, bool $show, string $extra = '') use ($_rc_td, $_rc_h) {
    return $_rc_td($show ? $_rc_h($c['old']) . ' &rarr; ' . $_rc_h($c['new']) : $_rc_h($c['new']), $extra);
};
echo $_rc_tbl . '<tr>' . implode('', array_map(fn($h) => $_rc_td($h, '', 'th'), ['Sh', 'Name', 'Type', 'Pos', 'Exp', 'Level', 'Pot', 'Value', 'Skill 1', 'Skill 2', 'Skill 3', 'Skill 4'])) . '</tr>';
foreach ($_rc_d['rows'] as $r) {
    $new = $r['is_new'];
    echo '<tr>' . $_rc_td($r['shirt']) . $_rc_td($_rc_h($r['name']) . ($new ? " <span class='w3-tag w3-round w3-blue'>new</span>" : ''))
       . $_rc_td($_rc_h($r['type'])) . $_rc_td($_rc_h($r['pos']))
       . $_rc_cell($r['exp'], $r['exp']['changed'])
       . $_rc_cell($r['level'], $r['level']['dir'] !== '', $_rc_dirc[$r['level']['dir']])
       . $_rc_cell($r['pot'], !$new, $r['pot']['double'] ? " class='w3-cyan'" : '')
       . $_rc_cell($r['value'], !$new, $r['value']['tier'] === 'high' ? " class='w3-red'" : ($r['value']['tier'] === 'mid' ? " class='w3-amber'" : ''));
    foreach ($r['skills'] as $s) { echo $_rc_cell($s, $s['dir'] !== '', $_rc_dirc[$s['dir']]); }
    echo '</tr>';
}
echo '</table></div>';
// the gone line (Q4), in its own panel between the table and the legend, only when someone is gone (H75, rev 002)
if ($_rc_d['gone']) {
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>No longer on the roster since Week {$_rc_wf['week_number']}: "
       . $_rc_h(implode(', ', array_map(fn($g) => "{$g['shirt']} {$g['player_name']} ({$g['player_type']})", $_rc_d['gone']))) . ".</p>";
}
echo "<p>Exp R is a rookie. Skill 1 to 4: Acc Con Qui Sta for a pitcher, Hit Pow Spd Fld for a batter or catcher. Value is red above 99 and amber from 70 to 99.</p>";
echo "</div>";
?>

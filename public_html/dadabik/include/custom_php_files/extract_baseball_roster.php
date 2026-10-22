<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// extract_baseball_roster.php · rev 003 · 22 Oct 2026 · role: page (administrator only, on both hosts) · authority: derived
// rev 003: H74 (Alan, Q11 of 22 Oct 2026 session2; V17a and V35a, and "Let's write Week in full please"): both tables in the
//          style guide's shape (s1: its class list, cells padded 4px 8px; s2: links styled), each in a wrapper that scrolls
//          sideways and keeps a margin below it, so a table no longer runs to the panel's rounded edge; "Season" and "Week"
//          written in full in the heading and the list. Nothing else changed.
// rev 002: writes on whichever host it runs on, like Extract Games (Alan, Q8 and NZ2 of 22 Oct 2026 session2: "Any
//          administrator needs to be able to carry out any action on prod"). Rev 001 wrote only where GPBM_ENV was dev.
// rev 001: first issue (never deployed).
// Item B2 (Alan, Q2 to Q7 of 22 Oct 2026 session2; GameplanPBM/20261022/session2/SESSION_20261022_2.md s2).
//
// ------------------------------------------------------------------
// WHAT THIS PAGE DOES
//
// Lists every baseball upload that holds a Team Report block, with the roster rows stored for its team and week, and
// extracts one on request: bb_roster_write() in bb_roster_parser.php reads the block and writes the week's snapshot for
// that team (Q3). A second extract of the same upload changes nothing. A refused block writes nothing and the page
// lists why. Choosing an upload shows the roster as stored, in the order the turn prints it.
//
// It runs and writes on both hosts (NZ2). Rows written on gpbm.uk are league data the standing up-sync would replace, so
// they reach dev first (schema.md s20; on gpbm.uk the page says so). The extract is a GET carrying a one-time token held
// in the session, as on the Neutral venues page: a refreshed or replayed extract writes nothing.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h3>Extract Roster</h3>";

// Administrator only. DaDaBIK's own page permission is the gate (zpbm_permissions, custom_page); this is the second.
if ($current_user_is_administrator != 1) {
    echo "<p>This page is for the administrator.</p></div>";
    return;
}
require_once __DIR__ . '/bb_roster_parser.php';

$_br_is_dev = defined('GPBM_ENV') && GPBM_ENV === 'dev';   // only for the reminder below; the page writes on both hosts
$_br_has_session = (session_status() === PHP_SESSION_ACTIVE);
if ($_br_has_session && empty($_SESSION['gp_br_token'])) {
    $_SESSION['gp_br_token'] = bin2hex(random_bytes(16));
}
$_br_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$_br_page = (string)($_GET['id_static_page'] ?? '');
// style-guide.md s1 and s2: the table class list, padded cells, links that look like links
$_br_tbl = "<div style='overflow-x:auto;margin-bottom:16px'><table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black w3-small' style='width:auto;min-width:480px'>";
$_br_td = fn($v, $tag = 'td') => "<$tag style='padding:4px 8px'>$v</$tag>";
$_br_a = fn($href, $text) => "<a href='$href' style='color:inherit;text-decoration:underline'>$text</a>";
$_br_url = function (array $q) use ($_br_page) {
    return 'index.php?' . http_build_query(['function' => 'show_static_page', 'id_static_page' => $_br_page] + $q);
};

// ---------------- the extract ----------------
$_br_show = isset($_GET['upload']) ? (int)$_GET['upload'] : 0;
if (($_GET['extract'] ?? '') === '1' && $_br_show > 0) {
    if (!$_br_has_session || !hash_equals((string)$_SESSION['gp_br_token'], (string)($_GET['token'] ?? ''))) {
        $_br_msg = "Not extracted: this link was used already, or is out of date. Nothing was written; the list below is current.";
    } else {
        $_SESSION['gp_br_token'] = bin2hex(random_bytes(16));   // a refresh of this address now writes nothing
        $r = bb_roster_write($conn, $_br_show, false);
        if ($r['ok']) {
            $_br_msg = $_br_h($r['label']) . ": " . (int)$r['parsed'] . " players read; " . (int)$r['inserted'] . " added, "
                     . (int)$r['updated'] . " changed, " . (int)$r['deleted'] . " removed, " . (int)$r['unchanged'] . " unchanged.";
        } else {
            $_br_msg = "Not extracted, nothing was written: " . $_br_h($r['label']) . "<br>"
                     . implode('<br>', array_map($_br_h, $r['errors']));
        }
    }
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>" . $_br_msg . "</p>";
}
if (!$_br_is_dev) {
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>An extract here is new league data on gpbm.uk: tell Alan, so it reaches dev before the next up-sync.</p>";
}

// ---------------- the uploads ----------------
$_br_ups = $conn->query(
    "SELECT r.upload_id, r.original_filename, l.code, s.year, w.week_number, f.abbr, r.week_id, r.franchise_id,
            (SELECT COUNT(*) FROM bb_roster_entries e WHERE e.week_id = r.week_id AND e.franchise_id = r.franchise_id) AS stored,
            (SELECT COUNT(*) FROM bb_roster_entries e WHERE e.week_id = r.week_id AND e.franchise_id = r.franchise_id
                AND e.source_upload_id = r.upload_id) AS from_this
     FROM raw_uploads r
     JOIN leagues l ON l.league_id = r.league_id
     JOIN weeks w ON w.week_id = r.week_id
     JOIN seasons s ON s.season_id = w.season_id
     JOIN franchises f ON f.franchise_id = r.franchise_id
     WHERE l.code LIKE 'MLB%'
       AND EXISTS (SELECT 1 FROM raw_upload_blocks b WHERE b.upload_id = r.upload_id AND b.block_type = 'Team Report')
     ORDER BY l.code, s.year, w.week_number, f.abbr, r.upload_id")->fetchAll(PDO::FETCH_ASSOC);
if (!$_br_ups) { echo "<p>No baseball upload holds a Team Report.</p></div>"; return; }

echo $_br_tbl . '<tr>' . implode('', array_map(fn($h) => $_br_td($h, 'th'), ['Upload', 'League', 'Season', 'Week', 'Team', 'File', 'Rows stored', ''])) . '</tr>';
foreach ($_br_ups as $u) {
    $id = (int)$u['upload_id'];
    $stored = (int)$u['stored'] === 0 ? 'none' : (int)$u['stored'] . ((int)$u['from_this'] === (int)$u['stored'] ? '' : ' (another upload)');
    $act = $_br_a($_br_h($_br_url(['upload' => $id])), 'Show');
    if ($_br_has_session) {
        $act .= ' · ' . $_br_a($_br_h($_br_url(['upload' => $id, 'extract' => 1, 'token' => $_SESSION['gp_br_token']])), 'Extract');
    }
    $mark = ($id === $_br_show) ? " style='font-weight:bold'" : '';
    echo "<tr$mark>" . implode('', array_map($_br_td, [$id, $_br_h($u['code']), (int)$u['year'], (int)$u['week_number'], $_br_h($u['abbr']),
        $_br_h($u['original_filename']), $stored, $act])) . '</tr>';
}
echo '</table></div>';

// ---------------- one roster, as stored ----------------
$_br_sel = null;
foreach ($_br_ups as $u) { if ((int)$u['upload_id'] === $_br_show) { $_br_sel = $u; } }
if ($_br_sel !== null) {
    $st = $conn->prepare("SELECT * FROM bb_roster_entries WHERE week_id = :w AND franchise_id = :f ORDER BY report_order");
    $st->execute([':w' => (int)$_br_sel['week_id'], ':f' => (int)$_br_sel['franchise_id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "<h4>" . $_br_h("{$_br_sel['code']} Season {$_br_sel['year']} Week {$_br_sel['week_number']} {$_br_sel['abbr']}") . ": "
       . count($rows) . " players stored</h4>";
    if ($rows) {
        echo $_br_tbl;
        echo "<tr><th style='padding:4px 8px'>Sh</th><th style='padding:4px 8px'>Name</th><th style='padding:4px 8px'>Type</th><th style='padding:4px 8px'>Ability</th><th style='padding:4px 8px'>Exp</th><th style='padding:4px 8px'>Pot</th><th style='padding:4px 8px'>Trd</th><th style='padding:4px 8px'>Fat</th><th style='padding:4px 8px'>Frm</th>"
           . "<th style='padding:4px 8px'>Tot</th><th style='padding:4px 8px'>Inj</th><th style='padding:4px 8px'>Value</th><th style='padding:4px 8px'>Wages</th><th style='padding:4px 8px' colspan='4'>Skills</th><th style='padding:4px 8px'>Sqd</th><th style='padding:4px 8px' colspan='5'>MxB MxH MxE FIn MnB / Pos vRH Plt Pin</th></tr>";
        foreach ($rows as $e) {
            $x = $e['player_type'] === 'Pit'
                ? [$e['pit_mxb'], $e['pit_mxh'], $e['pit_mxe'], $e['pit_fin'], $e['pit_mnb']]
                : [$e['bat_pos'], $e['bat_vrh'], $e['bat_plt'], $e['bat_pin'], ''];
            echo "<tr><td style='padding:4px 8px'>" . (int)$e['shirt_no'] . "</td><td style='padding:4px 8px'>" . $_br_h($e['player_name']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['player_type'] . ' ' . $e['bats_throws'])
               . "</td><td style='padding:4px 8px'>" . (int)$e['player_level'] . ' ' . $_br_h($e['best_skill']) . "</td><td style='padding:4px 8px'>" . ((int)$e['experience'] === 0 ? 'R' : (int)$e['experience'])
               . "</td><td style='padding:4px 8px'>" . (int)$e['potential'] . "</td><td style='padding:4px 8px'>" . (int)$e['trade'] . "</td><td style='padding:4px 8px'>" . (int)$e['fatigue']
               . "</td><td style='padding:4px 8px'>" . (int)$e['form'] . ((int)$e['addform_active'] ? '+' : '') . "</td><td style='padding:4px 8px'>" . (int)$e['total'] . "</td><td style='padding:4px 8px'>" . (int)$e['injury']
               . "</td><td style='padding:4px 8px'>" . (int)$e['value_lp'] . "</td><td style='padding:4px 8px'>" . (int)$e['wages_lp'] . ((int)$e['half_wages'] ? '*' : '')
               . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_a']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_b']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_c']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_d'])
               . "</td><td style='padding:4px 8px'>" . $_br_h($e['squad']) . "</td>";
            foreach ($x as $v) { echo "<td style='padding:4px 8px'>" . $_br_h($v ?? '') . "</td>"; }
            echo "</tr>";
        }
        echo "</table></div>";
        echo "<p>Exp R is a rookie. Frm + is an active ADDFORM. Wages * is a draft squad player on half wages.</p>";
    }
}
echo "</div>";
?>

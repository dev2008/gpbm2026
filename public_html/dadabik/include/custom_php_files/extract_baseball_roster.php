<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// extract_baseball_roster.php · rev 006 · 23 Oct 2026 · role: page (administrator only, on both hosts) · authority: derived
// rev 006: H73 (Alan, Q2 and Q4 of 23 Oct 2026 session5): gpbm.uk is the master and dev takes a copy when it needs one, so the
//          reminder shown on gpbm.uk ("tell Alan, so it reaches dev before the next up-sync") and the variable it used are
//          gone; the comment below says what is now true. Nothing else changed.
// rev 005: B7 (Alan, Q1 of 23 Oct 2026 session3): Show prints Exp with its * (no_free_agent) as the turn does, and the legend
//          says what it means. Nothing else changed.
// rev 004: B3 (Alan, Q5 to Q9 of 22 Oct 2026 session3): Extract also reads the upload's Team Stats (bb_stats_parser.php)
//          into bb_pitching_stats and bb_batting_stats, in ONE transaction with the roster (Q9): either both are written or
//          neither. The list gains "Stats stored"; Show adds the pitching and batting totals under the roster, IP as
//          printed (22 2/3, Q8), each player's name from his roster row by shirt (Q7).
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
// It runs and writes on both hosts (NZ2). gpbm.uk is the master of league data: rows written there are kept, and dev takes
// a copy by the down-sync when it needs one (schema.md s20, H73); nothing is told to anybody. The extract is a GET carrying a
// one-time token held in the session, as on the Neutral venues page: a refreshed or replayed extract writes nothing.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h3>Extract Roster</h3>";

// Administrator only. DaDaBIK's own page permission is the gate (zpbm_permissions, custom_page); this is the second.
if ($current_user_is_administrator != 1) {
    echo "<p>This page is for the administrator.</p></div>";
    return;
}
require_once __DIR__ . '/bb_roster_parser.php';
require_once __DIR__ . '/bb_stats_parser.php';

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
        // Q9: the roster and the stats in one transaction; either both are written or neither
        $r = null; $s = null;
        $conn->beginTransaction();
        try {
            $r = bb_roster_write($conn, $_br_show, false);
            if ($r['ok']) { $s = bb_stats_write($conn, $_br_show, false); }
            if ($r['ok'] && $s['ok']) { $conn->commit(); } else { $conn->rollBack(); }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $r = ($r !== null) ? ['ok' => false] + $r : ['ok' => false, 'label' => "upload $_br_show", 'errors' => []];
            $s = ['ok' => false, 'errors' => ['the database refused the write, nothing was written: ' . $e->getMessage()]];
        }
        $_br_counts = fn($x, $what) => (int)$x['parsed'] . " $what read; " . (int)$x['inserted'] . " added, " . (int)$x['updated']
                     . " changed, " . (int)$x['deleted'] . " removed, " . (int)$x['unchanged'] . " unchanged.";
        if ($r['ok'] && $s !== null && $s['ok']) {
            $_br_msg = $_br_h($r['label']) . ": " . $_br_counts($r, 'players') . "<br>Stats (" . $_br_h($s['scope']) . " totals): "
                     . $_br_counts($s, 'lines');
        } else {
            $errs = array_merge($r['errors'] ?? [], $s['errors'] ?? []);
            $_br_msg = "Not extracted, nothing was written: " . $_br_h($r['label']) . "<br>"
                     . implode('<br>', array_map($_br_h, $errs));
        }
    }
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>" . $_br_msg . "</p>";
}

// ---------------- the uploads ----------------
$_br_ups = $conn->query(
    "SELECT r.upload_id, r.original_filename, l.code, s.year, w.week_number, f.abbr, r.week_id, r.franchise_id,
            (SELECT COUNT(*) FROM bb_roster_entries e WHERE e.week_id = r.week_id AND e.franchise_id = r.franchise_id) AS stored,
            (SELECT COUNT(*) FROM bb_roster_entries e WHERE e.week_id = r.week_id AND e.franchise_id = r.franchise_id
                AND e.source_upload_id = r.upload_id) AS from_this,
            (SELECT COUNT(*) FROM bb_pitching_stats p WHERE p.week_id = r.week_id AND p.franchise_id = r.franchise_id)
          + (SELECT COUNT(*) FROM bb_batting_stats b WHERE b.week_id = r.week_id AND b.franchise_id = r.franchise_id) AS stats
     FROM raw_uploads r
     JOIN leagues l ON l.league_id = r.league_id
     JOIN weeks w ON w.week_id = r.week_id
     JOIN seasons s ON s.season_id = w.season_id
     JOIN franchises f ON f.franchise_id = r.franchise_id
     WHERE l.code LIKE 'MLB%'
       AND EXISTS (SELECT 1 FROM raw_upload_blocks b WHERE b.upload_id = r.upload_id AND b.block_type = 'Team Report')
     ORDER BY l.code, s.year, w.week_number, f.abbr, r.upload_id")->fetchAll(PDO::FETCH_ASSOC);
if (!$_br_ups) { echo "<p>No baseball upload holds a Team Report.</p></div>"; return; }

echo $_br_tbl . '<tr>' . implode('', array_map(fn($h) => $_br_td($h, 'th'), ['Upload', 'League', 'Season', 'Week', 'Team', 'File', 'Rows stored', 'Stats stored', ''])) . '</tr>';
foreach ($_br_ups as $u) {
    $id = (int)$u['upload_id'];
    $stored = (int)$u['stored'] === 0 ? 'none' : (int)$u['stored'] . ((int)$u['from_this'] === (int)$u['stored'] ? '' : ' (another upload)');
    $act = $_br_a($_br_h($_br_url(['upload' => $id])), 'Show');
    if ($_br_has_session) {
        $act .= ' · ' . $_br_a($_br_h($_br_url(['upload' => $id, 'extract' => 1, 'token' => $_SESSION['gp_br_token']])), 'Extract');
    }
    $mark = ($id === $_br_show) ? " style='font-weight:bold'" : '';
    echo "<tr$mark>" . implode('', array_map($_br_td, [$id, $_br_h($u['code']), (int)$u['year'], (int)$u['week_number'], $_br_h($u['abbr']),
        $_br_h($u['original_filename']), $stored, ((int)$u['stats'] === 0 ? 'none' : (int)$u['stats']), $act])) . '</tr>';
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
               . "</td><td style='padding:4px 8px'>" . (int)$e['player_level'] . ' ' . $_br_h($e['best_skill']) . "</td><td style='padding:4px 8px'>" . ((int)$e['experience'] === 0 ? 'R' : (int)$e['experience']) . ((int)$e['no_free_agent'] ? '*' : '')
               . "</td><td style='padding:4px 8px'>" . (int)$e['potential'] . "</td><td style='padding:4px 8px'>" . (int)$e['trade'] . "</td><td style='padding:4px 8px'>" . (int)$e['fatigue']
               . "</td><td style='padding:4px 8px'>" . (int)$e['form'] . ((int)$e['addform_active'] ? '+' : '') . "</td><td style='padding:4px 8px'>" . (int)$e['total'] . "</td><td style='padding:4px 8px'>" . (int)$e['injury']
               . "</td><td style='padding:4px 8px'>" . (int)$e['value_lp'] . "</td><td style='padding:4px 8px'>" . (int)$e['wages_lp'] . ((int)$e['half_wages'] ? '*' : '')
               . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_a']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_b']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_c']) . "</td><td style='padding:4px 8px'>" . $_br_h($e['skill_d'])
               . "</td><td style='padding:4px 8px'>" . $_br_h($e['squad']) . "</td>";
            foreach ($x as $v) { echo "<td style='padding:4px 8px'>" . $_br_h($v ?? '') . "</td>"; }
            echo "</tr>";
        }
        echo "</table></div>";
        echo "<p>Exp R is a rookie; Exp * is a player who would not become a free agent if released. Frm + is an active ADDFORM. Wages * is a draft squad player on half wages.</p>";
    }

    // ---------------- the stats, as stored (B3) ----------------
    $_br_names = []; $_br_pos = [];
    foreach ($rows as $e) { $_br_names[(int)$e['shirt_no']] = $e['player_name']; $_br_pos[(int)$e['shirt_no']] = strtolower((string)$e['bat_pos']); }
    $_br_dec = fn($v) => $v === null ? '-' : preg_replace('/^0(?=\.)/', '', (string)$v);   // as printed: .226, 1.000
    $_br_stats = function (string $tab) use ($conn, $_br_sel) {
        $st = $conn->prepare("SELECT * FROM $tab WHERE week_id = :w AND franchise_id = :f ORDER BY report_order");
        $st->execute([':w' => (int)$_br_sel['week_id'], ':f' => (int)$_br_sel['franchise_id']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $_br_p = $_br_stats('bb_pitching_stats'); $_br_b = $_br_stats('bb_batting_stats');
    $_br_scope = $_br_p[0]['stats_scope'] ?? ($_br_b[0]['stats_scope'] ?? null);
    echo "<h4>Stats: " . ($_br_scope === null ? 'none stored' : $_br_h(ucfirst($_br_scope)) . ' totals, ' . count($_br_p) . ' pitchers, ' . count($_br_b) . ' batters') . "</h4>";
    $_br_head = fn(array $hs) => '<tr>' . implode('', array_map(fn($h) => $_br_td($h, 'th'), $hs)) . '</tr>';
    if ($_br_p) {
        echo $_br_tbl . $_br_head(['Sh', 'Name', 'G', 'GS', 'CG', 'GF', 'IP', 'H', 'R', 'ER', 'HR', 'BB', 'SO', 'SB', 'W-L', 'Sv', 'WS-LS', 'RunS', 'HAvg', 'ERA']);
        foreach ($_br_p as $x) {
            $sh = (int)$x['shirt_no'];
            echo '<tr>' . implode('', array_map($_br_td, [$sh, $_br_h($_br_names[$sh] ?? '?'), (int)$x['g'], (int)$x['gs'], (int)$x['cg'],
                (int)$x['gf'], $_br_h(bb_ip_shown($x['ip_outs'])), (int)$x['h'], (int)$x['r'], (int)$x['er'], (int)$x['hr'], (int)$x['bb'],
                (int)$x['so'], (int)$x['sb'], (int)$x['w'] . '-' . (int)$x['l'], (int)$x['sv'], (int)$x['ws'] . '-' . (int)$x['ls'],
                $_br_h($_br_dec($x['run_s'])), $_br_h($_br_dec($x['h_avg'])), $_br_h($x['era'])])) . '</tr>';
        }
        echo '</table></div>';
    }
    if ($_br_b) {
        echo $_br_tbl . $_br_head(['Sh', 'Name', 'Pos', 'G', 'AB', 'R', 'H', 'TB', '2B', '3B', 'HR', 'RBI', 'BB', 'SA', 'SB', 'SO', 'FS', 'Er', 'DP', 'PO', 'As', 'FldP', 'BAvg', 'OnBs', 'SlgP']);
        foreach ($_br_b as $x) {
            $sh = (int)$x['shirt_no'];
            $cells = [$sh, $_br_h($_br_names[$sh] ?? '?'), $_br_h($_br_pos[$sh] ?? '?')];
            foreach (['g', 'ab', 'r', 'h', 'tb', 'd2', 'd3', 'hr', 'rbi', 'bb', 'sa', 'sb', 'so', 'fs', 'er', 'dp', 'po', 'ast'] as $c) { $cells[] = (int)$x[$c]; }
            foreach (['fld_p', 'b_avg', 'on_bs', 'slg_p'] as $c) { $cells[] = $_br_h($_br_dec($x[$c])); }
            echo '<tr>' . implode('', array_map($_br_td, $cells)) . '</tr>';
        }
        echo '</table></div>';
    }
}
echo "</div>";
?>

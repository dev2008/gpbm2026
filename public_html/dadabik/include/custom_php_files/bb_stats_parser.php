<?php
// bb_stats_parser.php · rev 001 · 22 Oct 2026 · role: helper, included by extract_baseball_roster.php and the runner's CLI
//                      (scripts/gpbm_b3_stats_cli.php) · authority: B3 (Alan, Q5 to Q9 of 22 Oct 2026 session3;
//                      GameplanPBM/20261022/session3/SESSION_20261022_3.md s2, s4)
// rev 001: first issue.
//
// ------------------------------------------------------------------
// WHAT THIS FILE DOES
//
// Reads a baseball turn's Team Stats block (raw_upload_blocks, block_type 'Team Stats') into bb_pitching_stats and
// bb_batting_stats: one row per player per team per week, the totals as printed (Q5, Q8). The team is the uploader and the
// week the upload's; the block's heading must agree with both (Q6), and every stats line must match a player in the same
// upload's Team Report by shirt, type and (batters) position, and every player there must have a stats line (Q7).
//
// A write replaces the team's week snapshot in each table, as bb_roster_write does (insert, update, delete, unchanged per
// shirt), so a second run of the same upload changes nothing. The parse REFUSES the whole block, writing nothing, on any
// line it cannot read. Measured shapes (534 lines, 18 turns, 22 Oct 2026; session log s4), after the style tags go:
//   63 Jake Arrieta<T> 28   28    0    0  134       118   54   44   12   24  104   16<T>  2-7<T>  0<T> 13-15<T>  3.36<T> .226<T>  2.96
//   28 T.Munson c<T> 18   62    8   21   29    2   0   2    7    3   0   1    9   0   0   0    0    0<T> .000<T> .338<T> .369<T> .467
//   - IP prints 134, 22 2/3, or 1/3 alone; it is stored as outs (Q8). RunS prints - for no start: NULL.
//   - The heading sets the scope (Q6): "Regular Season 35 Totals  Week 15" (regular, that week), "Final Regular Season 35
//     Totals" (regular, week 18), "Playoff Totals  Playoff Week 1" (playoff, week 18 + 1), "Preseason Totals  Playoff Week 2"
//     (preseason, week 18 + 2).
//   - Gates on every batting line: TB = H + 2B + 2 x 3B + 3 x HR; BAvg = H / AB cut to 3 places (both held on all 284).
//   - The Team Pitching and Team Batting lines end each section; they are not stored (Q8) and not summed against.
// ------------------------------------------------------------------

if (!defined('BB_STATS_COLS')) {
    // the parsed columns, in table order after the key; a row is unchanged when every one of these is equal
    define('BB_STATS_COLS', [
        'P' => ['source_upload_id', 'report_order', 'stats_scope', 'g', 'gs', 'cg', 'gf', 'ip_outs', 'h', 'r', 'er', 'hr', 'bb',
                'so', 'sb', 'w', 'l', 'sv', 'ws', 'ls', 'run_s', 'h_avg', 'era'],
        'B' => ['source_upload_id', 'report_order', 'stats_scope', 'g', 'ab', 'r', 'h', 'tb', 'd2', 'd3', 'hr', 'rbi', 'bb', 'sa',
                'sb', 'so', 'fs', 'er', 'dp', 'po', 'ast', 'fld_p', 'b_avg', 'on_bs', 'slg_p'],
    ]);
    define('BB_STATS_TABLE', ['P' => 'bb_pitching_stats', 'B' => 'bb_batting_stats']);
    define('BB_STATS_REGULAR_WEEKS', 18);   // Q6: Final Regular Season is week 18; Playoff Week k is week 18 + k
}

if (!function_exists('bb_stats_parse')) {
/**
 * Parses one Team Stats block. Pure: no database.
 * Returns ['errors' => [...], 'league' => 'MLB21', 'season' => 35|null, 'scope' => 'regular'|'playoff'|'preseason'|null,
 *          'week_no' => 15|18|19...|null, 'team' => '...', 'rows' => ['P' => [shirt => [...]], 'B' => [shirt => [...]]],
 *          'pos' => [shirt => 'c'] (the batters' printed positions)].
 */
function bb_stats_parse(string $block): array {
    $out = ['errors' => [], 'league' => null, 'season' => null, 'scope' => null, 'week_no' => null, 'team' => null,
            'rows' => ['P' => [], 'B' => []], 'pos' => []];
    $lines = explode("\n", str_replace("\r\n", "\n", $block));
    $tagless = function (string $l): string {
        $l = preg_replace('/(<(?:L|ST)\.[0-9.]+>)+\s*$/', '', $l);
        return str_replace(['<Z>', '<C>', '<B>'], '', $l);
    };

    // the heading, then "Team  (Coach)"
    $hi = null;
    foreach ($lines as $i => $l) {
        $t = $tagless($l);
        if (strpos($t, 'GAMEPLAN BASEBALL') === false) { continue; }
        if (preg_match('/GAMEPLAN BASEBALL\s+(MLB\d+)\s+Regular Season\s+(\d+)\s+Totals\s+Week\s+(\d+)\s/', $t, $m)) {
            $out['scope'] = 'regular'; $out['season'] = (int)$m[2]; $out['week_no'] = (int)$m[3];
        } elseif (preg_match('/GAMEPLAN BASEBALL\s+(MLB\d+)\s+Final Regular Season\s+(\d+)\s+Totals\s/', $t, $m)) {
            $out['scope'] = 'regular'; $out['season'] = (int)$m[2]; $out['week_no'] = BB_STATS_REGULAR_WEEKS;
        } elseif (preg_match('/GAMEPLAN BASEBALL\s+(MLB\d+)\s+(Playoff|Preseason) Totals\s+Playoff Week\s+(\d+)\s/', $t, $m)) {
            $out['scope'] = strtolower($m[2]); $out['week_no'] = BB_STATS_REGULAR_WEEKS + (int)$m[3];
        } else {
            $out['errors'][] = 'a heading of no known shape: ' . substr(trim($t), 0, 80); return $out;
        }
        $out['league'] = $m[1]; $hi = $i; break;
    }
    if ($hi === null) { $out['errors'][] = 'no Team Stats heading'; return $out; }
    if (!isset($lines[$hi + 1]) || !preg_match('/^(.+?)\s+\((.+?)\)\s*$/', $tagless($lines[$hi + 1]), $m)) {
        $out['errors'][] = 'no "Team (Coach)" line under the heading'; return $out;
    }
    $out['team'] = $m[1];

    // the first figure after <T> may have no space before it; every later one has at least one, so a number is never split
    $n0 = '\s*(\d{1,4})'; $n = '\s+(\d{1,4})';
    $rat = '\s*(\d?\.\d{3})\s*';
    $reP = '/^\s*(\d{1,3}) (\S.*?)<T>' . $n0 . str_repeat($n, 3) . '\s+(\d{1,4} [12]\/3|[12]\/3|\d{1,4})' . str_repeat($n, 7)
         . '\s*<T>\s*(\d{1,3})-(\d{1,3})<T>\s*(\d{1,3})<T>\s*(\d{1,3})-(\d{1,3})<T>\s*(-|\d{1,3}\.\d\d)\s*<T>' . $rat
         . '<T>\s*(\d{1,3}\.\d\d)\s*$/';
    $reB = '/^\s*(\d{1,3}) (\S.*?) ([a-z0-9]{1,2})<T>' . $n0 . str_repeat($n, 17) . '\s*<T>' . $rat . '<T>' . $rat . '<T>' . $rat . '<T>' . $rat . '$/';
    $sec = null; $order = ['P' => 0, 'B' => 0]; $ended = ['P' => false, 'B' => false];
    for ($i = $hi + 2; $i < count($lines); $i++) {
        $l = $tagless($lines[$i]);
        if (strpos($l, 'Individual Pitching<T>') === 0) {
            if ($ended['P'] || $order['P']) { $out['errors'][] = 'line ' . ($i + 1) . ': a second Individual Pitching'; }
            $sec = 'P'; continue;
        }
        if (strpos($l, 'Individual Batting<T>') === 0) {
            if (!$ended['P']) { $out['errors'][] = 'line ' . ($i + 1) . ': Individual Batting before Team Pitching'; }
            if ($ended['B'] || $order['B']) { $out['errors'][] = 'line ' . ($i + 1) . ': a second Individual Batting'; }
            $sec = 'B'; continue;
        }
        if (strpos($l, 'Team Pitching<T>') === 0) { if ($sec !== 'P') { $out['errors'][] = 'line ' . ($i + 1) . ': Team Pitching out of place'; } $ended['P'] = true; $sec = null; continue; }
        if (strpos($l, 'Team Batting<T>') === 0) { if ($sec !== 'B') { $out['errors'][] = 'line ' . ($i + 1) . ': Team Batting out of place'; } $ended['B'] = true; break; }
        if ($sec === null) {
            if (trim($l) === '' || preg_match('/^_+$/', trim($l))) { continue; }
            $out['errors'][] = 'line ' . ($i + 1) . ': text outside a section: ' . substr($l, 0, 60); continue;
        }
        if (trim($l) === '') { continue; }
        if ($sec === 'P') {
            if (!preg_match($reP, $l, $m)) { $out['errors'][] = 'line ' . ($i + 1) . ': not a pitching line: ' . substr($l, 0, 90); continue; }
            $sh = (int)$m[1];
            if (isset($out['rows']['P'][$sh]) || isset($out['rows']['B'][$sh])) { $out['errors'][] = 'line ' . ($i + 1) . ": shirt $sh printed twice"; continue; }
            $ip = $m[7];
            if (preg_match('/^(\d+) ([12])\/3$/', $ip, $x)) { $outs = 3 * (int)$x[1] + (int)$x[2]; }
            elseif (preg_match('/^([12])\/3$/', $ip, $x)) { $outs = (int)$x[1]; }
            else { $outs = 3 * (int)$ip; }
            $out['rows']['P'][$sh] = [
                'report_order' => ++$order['P'], 'name' => trim($m[2]), 'stats_scope' => $out['scope'],
                'g' => (int)$m[3], 'gs' => (int)$m[4], 'cg' => (int)$m[5], 'gf' => (int)$m[6], 'ip_outs' => $outs,
                'h' => (int)$m[8], 'r' => (int)$m[9], 'er' => (int)$m[10], 'hr' => (int)$m[11], 'bb' => (int)$m[12],
                'so' => (int)$m[13], 'sb' => (int)$m[14], 'w' => (int)$m[15], 'l' => (int)$m[16], 'sv' => (int)$m[17],
                'ws' => (int)$m[18], 'ls' => (int)$m[19], 'run_s' => ($m[20] === '-' ? null : $m[20]),
                'h_avg' => bb_stats_dec($m[21], 3), 'era' => $m[22],
            ];
        } else {
            if (!preg_match($reB, $l, $m)) { $out['errors'][] = 'line ' . ($i + 1) . ': not a batting line: ' . substr($l, 0, 90); continue; }
            $sh = (int)$m[1];
            if (isset($out['rows']['P'][$sh]) || isset($out['rows']['B'][$sh])) { $out['errors'][] = 'line ' . ($i + 1) . ": shirt $sh printed twice"; continue; }
            $v = array_map('intval', array_slice($m, 4, 18));
            [$g, $ab, $r, $h, $tb, $d2, $d3, $hr, $rbi, $bb, $sa, $sb, $so, $fs, $er, $dp, $po, $ast] = $v;
            if ($tb !== $h + $d2 + 2 * $d3 + 3 * $hr) { $out['errors'][] = 'line ' . ($i + 1) . ": shirt $sh: TB $tb is not H + 2B + 2 x 3B + 3 x HR"; continue; }
            $bavg = bb_stats_dec($m[23], 3);
            $want = $ab > 0 ? sprintf('%.3f', intdiv($h * 1000, $ab) / 1000) : '0.000';
            if ($bavg !== $want) { $out['errors'][] = 'line ' . ($i + 1) . ": shirt $sh: BAvg $bavg is not H / AB ($want)"; continue; }
            $out['pos'][$sh] = $m[3];
            $out['rows']['B'][$sh] = [
                'report_order' => ++$order['B'], 'name' => trim($m[2]), 'stats_scope' => $out['scope'],
                'g' => $g, 'ab' => $ab, 'r' => $r, 'h' => $h, 'tb' => $tb, 'd2' => $d2, 'd3' => $d3, 'hr' => $hr, 'rbi' => $rbi,
                'bb' => $bb, 'sa' => $sa, 'sb' => $sb, 'so' => $so, 'fs' => $fs, 'er' => $er, 'dp' => $dp, 'po' => $po, 'ast' => $ast,
                'fld_p' => bb_stats_dec($m[22], 3), 'b_avg' => $bavg, 'on_bs' => bb_stats_dec($m[24], 3), 'slg_p' => bb_stats_dec($m[25], 3),
            ];
        }
    }
    if (!$ended['P']) { $out['errors'][] = 'no Team Pitching line: the pitching section has no end'; }
    if (!$ended['B']) { $out['errors'][] = 'no Team Batting line: the batting section has no end'; }
    if (!$out['rows']['P'] || !$out['rows']['B']) {
        $out['errors'][] = 'the block needs pitchers and batters: read ' . count($out['rows']['P']) . ' and ' . count($out['rows']['B']);
    }
    return $out;
}

// a printed decimal as the database returns it: ".226" -> "0.226", "13.50" stays
function bb_stats_dec(string $s, int $places): string {
    return sprintf('%.' . $places . 'f', (float)$s);
}
}

if (!function_exists('bb_stats_write')) {
/**
 * Writes one upload's Team Stats into bb_pitching_stats and bb_batting_stats (the team's week snapshot in each), or with
 * $dry only counts what it would do. Runs inside the caller's transaction when there is one, else in its own.
 * Returns ['ok' => bool, 'errors' => [...], 'parsed' => n, 'inserted' => n, 'updated' => n, 'unchanged' => n,
 *          'deleted' => n, 'scope' => '...', 'label' => 'MLB21 Season 35 Week 21 PH'], the counts over both tables.
 */
function bb_stats_write(PDO $conn, int $uploadId, bool $dry): array {
    $r = ['ok' => false, 'errors' => [], 'parsed' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0,
          'scope' => null, 'label' => "upload $uploadId"];
    $st = $conn->prepare(
        "SELECT r.upload_id, r.week_id, r.franchise_id, l.code, s.year, w.week_number, w.phase, f.label AS team, f.abbr,
                f.league_id AS f_league, s.league_id AS s_league, l.league_id
         FROM raw_uploads r
         JOIN leagues l ON l.league_id = r.league_id
         JOIN weeks w ON w.week_id = r.week_id
         JOIN seasons s ON s.season_id = w.season_id
         JOIN franchises f ON f.franchise_id = r.franchise_id
         WHERE r.upload_id = :u");
    $st->execute([':u' => $uploadId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) { $r['errors'][] = 'no such upload, or it has no league, week or team'; return $r; }
    $r['label'] = "{$u['code']} Season {$u['year']} Week {$u['week_number']} {$u['abbr']}";
    if (strpos($u['code'], 'MLB') !== 0) { $r['errors'][] = "{$u['code']} is not a baseball league"; return $r; }
    if ((int)$u['f_league'] !== (int)$u['league_id']) { $r['errors'][] = "the upload's team is not in its league"; return $r; }
    if ((int)$u['s_league'] !== (int)$u['league_id']) { $r['errors'][] = "the upload's week is not in its league"; return $r; }
    $blk = function (string $type) use ($conn, $uploadId) {
        $st = $conn->prepare("SELECT block_text FROM raw_upload_blocks WHERE upload_id = :u AND block_type = :t");
        $st->execute([':u' => $uploadId, ':t' => $type]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $sb = $blk('Team Stats'); $rb = $blk('Team Report');
    if (count($sb) !== 1) { $r['errors'][] = count($sb) . " Team Stats blocks, not 1"; return $r; }
    if (count($rb) !== 1) { $r['errors'][] = count($rb) . " Team Report blocks, not 1: the players are read from it (Q7)"; return $r; }

    $p = bb_stats_parse((string)$sb[0]);
    $r['errors'] = $p['errors']; $r['scope'] = $p['scope'];
    if ($p['league'] !== null && $p['league'] !== $u['code']) { $r['errors'][] = "the stats are {$p['league']}, the upload {$u['code']}"; }
    if ($p['season'] !== null && $p['season'] !== (int)$u['year']) { $r['errors'][] = "the stats are season {$p['season']}, the upload {$u['year']}"; }
    if ($p['week_no'] !== null && $p['week_no'] !== (int)$u['week_number']) {
        $r['errors'][] = "the heading is week {$p['week_no']} ({$p['scope']}), the upload week {$u['week_number']}";
    }
    if ($p['scope'] !== null && ($p['scope'] === 'regular') !== ($u['phase'] === 'regular')) {
        $r['errors'][] = "the heading is {$p['scope']} totals, the upload's week is {$u['phase']}";
    }
    if ($p['team'] !== null && $p['team'] !== $u['team']) { $r['errors'][] = "the stats are for {$p['team']}, the upload's team is {$u['team']}"; }

    // Q7: the players are the same upload's Team Report, matched by shirt
    $ro = bb_roster_parse((string)$rb[0]);
    if ($ro['errors']) { $r['errors'][] = 'the Team Report does not read, so the players cannot be matched: ' . $ro['errors'][0]; }
    else {
        foreach ($ro['rows'] as $sh => $e) {
            $k = $e['player_type'] === 'Pit' ? 'P' : 'B';
            if (!isset($p['rows'][$k][$sh])) { $r['errors'][] = "shirt $sh ({$e['player_name']}, {$e['player_type']}) has no " . ($k === 'P' ? 'pitching' : 'batting') . " line"; continue; }
            if ($k === 'B' && $p['pos'][$sh] !== strtolower((string)$e['bat_pos'])) {
                $r['errors'][] = "shirt $sh: the stats print position {$p['pos'][$sh]}, the roster {$e['bat_pos']}";
            }
            if ($k === 'P' && $p['rows']['P'][$sh]['name'] !== $e['player_name']) {
                $r['errors'][] = "shirt $sh: the stats print {$p['rows']['P'][$sh]['name']}, the roster {$e['player_name']}";
            }
        }
        foreach (['P', 'B'] as $k) {
            foreach (array_keys($p['rows'][$k]) as $sh) {
                if (!isset($ro['rows'][$sh])) { $r['errors'][] = "shirt $sh has a " . ($k === 'P' ? 'pitching' : 'batting') . " line and no roster line"; }
                elseif (($ro['rows'][$sh]['player_type'] === 'Pit') !== ($k === 'P')) { $r['errors'][] = "shirt $sh is a {$ro['rows'][$sh]['player_type']} in the roster and printed under " . ($k === 'P' ? 'pitching' : 'batting'); }
            }
        }
    }
    $r['parsed'] = count($p['rows']['P']) + count($p['rows']['B']);
    if ($r['errors']) { return $r; }

    $wk = (int)$u['week_id']; $fr = (int)$u['franchise_id'];
    $norm = fn($v) => $v === null ? null : (string)$v;
    $plan = [];
    foreach (['P', 'B'] as $k) {
        $cols = BB_STATS_COLS[$k]; $tab = BB_STATS_TABLE[$k]; $idc = $k === 'P' ? 'pitching_stat_id' : 'batting_stat_id';
        $st = $conn->prepare("SELECT $idc AS id, shirt_no, " . implode(', ', $cols) . " FROM $tab WHERE week_id = :w AND franchise_id = :f");
        $st->execute([':w' => $wk, ':f' => $fr]);
        $have = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $have[(int)$row['shirt_no']] = $row; }
        $ins = []; $upd = []; $del = [];
        foreach ($p['rows'][$k] as $sh => $row) {
            $row = ['source_upload_id' => $uploadId] + $row;
            if (!isset($have[$sh])) { $ins[$sh] = $row; continue; }
            foreach ($cols as $c) { if ($norm($row[$c]) !== $norm($have[$sh][$c])) { $upd[$sh] = $row; break; } }
            if (!isset($upd[$sh])) { $r['unchanged']++; }
        }
        foreach ($have as $sh => $row) { if (!isset($p['rows'][$k][$sh])) { $del[] = (int)$row['id']; } }
        $r['inserted'] += count($ins); $r['updated'] += count($upd); $r['deleted'] += count($del);
        $plan[$k] = [$ins, $upd, $del];
    }
    if ($dry) { $r['ok'] = true; return $r; }

    $own = !$conn->inTransaction();
    if ($own) { $conn->beginTransaction(); }
    try {
        foreach (['P', 'B'] as $k) {
            [$ins, $upd, $del] = $plan[$k];
            $cols = BB_STATS_COLS[$k]; $tab = BB_STATS_TABLE[$k]; $idc = $k === 'P' ? 'pitching_stat_id' : 'batting_stat_id';
            if ($del) {
                $d = $conn->prepare("DELETE FROM $tab WHERE $idc = :id AND week_id = :w AND franchise_id = :f");
                foreach ($del as $id) { $d->execute([':id' => $id, ':w' => $wk, ':f' => $fr]); }
            }
            $all = array_merge(['week_id', 'franchise_id', 'shirt_no'], $cols);
            if ($ins) {
                $q = $conn->prepare("INSERT INTO $tab (" . implode(', ', $all) . ") VALUES (:" . implode(', :', $all) . ")");
                foreach ($ins as $sh => $row) {
                    $vals = [':week_id' => $wk, ':franchise_id' => $fr, ':shirt_no' => $sh];
                    foreach ($cols as $c) { $vals[":$c"] = $row[$c]; }
                    $q->execute($vals);
                }
            }
            if ($upd) {
                $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
                $q = $conn->prepare("UPDATE $tab SET $set WHERE week_id = :week_id AND franchise_id = :franchise_id AND shirt_no = :shirt_no");
                foreach ($upd as $sh => $row) {
                    $vals = [':week_id' => $wk, ':franchise_id' => $fr, ':shirt_no' => $sh];
                    foreach ($cols as $c) { $vals[":$c"] = $row[$c]; }
                    $q->execute($vals);
                }
            }
            $st = $conn->prepare("SELECT COUNT(*) FROM $tab WHERE week_id = :w AND franchise_id = :f");
            $st->execute([':w' => $wk, ':f' => $fr]);
            $n = (int)$st->fetchColumn();
            if ($n !== count($p['rows'][$k])) { throw new RuntimeException("$tab: stored $n rows for the week, the stats hold " . count($p['rows'][$k])); }
        }
        if ($own) { $conn->commit(); }
        $r['ok'] = true;
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) { $conn->rollBack(); }
        $r['errors'][] = 'the database refused the write, nothing was written: ' . $e->getMessage();
        if (!$own) { throw $e; }
    }
    return $r;
}
}

if (!function_exists('bb_ip_shown')) {
// Q8: IP as printed: 68 outs -> "22 2/3", 1 -> "1/3", 0 -> "0"
function bb_ip_shown($outs): string {
    $o = (int)$outs; $w = intdiv($o, 3); $t = $o % 3;
    if ($t === 0) { return (string)$w; }
    return ($w > 0 ? "$w " : '') . "$t/3";
}
}

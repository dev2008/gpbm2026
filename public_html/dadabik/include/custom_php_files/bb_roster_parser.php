<?php
// bb_roster_parser.php · rev 002 · 22 Oct 2026 · role: helper, included by extract_baseball_roster.php and the runner's CLI
//                       (scripts/gpbm_b2_roster_cli.php) · authority: B2 (Alan, Q2 to Q7 of 22 Oct 2026 session2;
//                       GameplanPBM/20261022/session2/SESSION_20261022_2.md s2, s3)
// rev 002: H74 (Alan, Q11 of 22 Oct 2026 session2, and "Let's write Week in full please"): the label reads
//          "MLB21 Season 35 Week 21 PH", not "MLB21 35 Wk 21 PH". Nothing else changed.
// rev 001: first issue.
//
// ------------------------------------------------------------------
// WHAT THIS FILE DOES
//
// Reads a baseball turn's Team Report block (raw_upload_blocks, block_type 'Team Report') into bb_roster_entries: one
// row per player per team per week, the whole roster as printed (Q2). The team is the uploader (raw_uploads.franchise_id)
// and the week the upload's (raw_uploads.week_id); the block's own header must agree with both or nothing is written.
//
// A parse writes the week's snapshot for that team (Q3): a player printed and not stored is inserted, one stored and
// printed differently is updated, one stored and no longer printed is deleted, and one unchanged is left alone. So a
// second run of the same upload changes nothing, ids and extracted_at included.
//
// The parse REFUSES the whole block, and writes nothing, on any line it cannot read: every line between the first
// column header and "Key to Ratings" is a column header, a spacer, or a player line, and a player line must match its
// header's type (pitchers under "Acc Con Qui Sta", batters and catchers under "Hit Pow Spd Fld"). Measured shapes
// (534 lines, 18 turns, 22 Oct 2026; session log s3):
//   98  Nick Lodolo<T>Pit R   9 CON    8   8  10   3   2  15   0   59 LP   5 LP<T> Go  Ex  Go  Ex <T>Act<T><T> 30   5   9   1  18
//   26  Zak Williams<T>Cat L   9 FLD    5  12  11   3   1+ 16   0   49 LP   4 LP<T> Go  Go  Go  Ex <T>Act<T>  C   C
//   - Exp R is a rookie, stored 0 (Q4); a + after Frm is an active ADDFORM (Q7); a * after Wages is half wages.
//   - The batters' last field is fixed width: four right-aligned 3-character columns with a space between (15 characters).
//   - An injured player's Inj is wrapped in style tags (`   0<Z>  14<C>   19 LP`); <Z>, <C> and <B> are removed anywhere.
//   - Uploads 63 to 71 hold CRLF line ends; the rest LF. Both are read.
// ------------------------------------------------------------------

if (!defined('BB_ROSTER_COLS')) {
    // the parsed columns, in table order after the key; a row is unchanged when every one of these is equal
    define('BB_ROSTER_COLS', ['source_upload_id', 'report_order', 'player_name', 'player_type', 'bats_throws', 'player_level',
        'best_skill', 'experience', 'potential', 'trade', 'fatigue', 'form', 'addform_active', 'total', 'injury', 'value_lp',
        'wages_lp', 'half_wages', 'skill_a', 'skill_b', 'skill_c', 'skill_d', 'squad', 'pit_mxb', 'pit_mxh', 'pit_mxe',
        'pit_fin', 'pit_mnb', 'bat_pos', 'bat_vrh', 'bat_plt', 'bat_pin']);
}

if (!function_exists('bb_roster_parse')) {
/**
 * Parses one Team Report block. Pure: no database.
 * Returns ['errors' => [...], 'league' => 'MLB21', 'season' => 35, 'week_no' => 21|null, 'playoff_week' => 3|null,
 *          'team' => 'Philadelphia Phillies', 'coach' => 'Alan Milnes', 'rows' => [shirt => [col => value]]].
 * Any error means the rows are not to be written.
 */
function bb_roster_parse(string $block): array {
    $out = ['errors' => [], 'league' => null, 'season' => null, 'week_no' => null, 'playoff_week' => null,
            'team' => null, 'coach' => null, 'rows' => []];
    $lines = explode("\n", str_replace("\r\n", "\n", $block));
    $tagless = function (string $l): string {
        $l = preg_replace('/(<(?:L|ST)\.[0-9.]+>)+\s*$/', '', $l);   // the layout tags that end a line
        return str_replace(['<Z>', '<C>', '<B>'], '', $l);            // the style tags, wherever they fall: an
    };                                                                // injured player's Inj is printed <Z>  14<C>

    // the header: "GAMEPLAN BASEBALL  MLB21  Team Report  Season 35  Playoff Week 3   20/8/26", then "Team (Coach)"
    $hi = null;
    foreach ($lines as $i => $l) {
        if (preg_match('/GAMEPLAN BASEBALL\s+(MLB\d+)\s+Team Report\s+Season\s+(\d+)\s+(?:Playoff Week\s+(\d+)|Week\s+(\d+))\s/', $l, $m)) {
            $out['league'] = $m[1]; $out['season'] = (int)$m[2];
            if ($m[3] !== '') { $out['playoff_week'] = (int)$m[3]; } else { $out['week_no'] = (int)$m[4]; }
            $hi = $i; break;
        }
    }
    if ($hi === null) { $out['errors'][] = 'no Team Report header line'; return $out; }
    if (!isset($lines[$hi + 1]) || !preg_match('/^(.+?) \((.+?)\)\s*$/', $tagless($lines[$hi + 1]), $m)) {
        $out['errors'][] = 'no "Team (Coach)" line under the header'; return $out;
    }
    $out['team'] = $m[1]; $out['coach'] = $m[2];

    $g = '(Po|Fa|Av|Go|Ex|WC)';
    $re = '/^\s*(\d{1,3})\s+(\S.*?)<T>(Pit|Bat|Cat)\s+([LRS])\s+(\d{1,2})\s+(ACC|CON|QUI|STA|HIT|POW|SPD|FLD)\s+(R|\d{1,2})'
        . '\s+(\d{1,2})\s+(\d{1,2})\s+(-?\d{1,2})\s+(-?\d{1,2})(?:(\+)\s*|\s+)(-?\d{1,3})\s+(\d{1,3})\s+(\d{1,3}) LP\s+(\d{1,3}) LP(\*?)'
        . "<T>\\s*$g\\s+$g\\s+$g\\s+$g\\s*<T>(Act|Res|Drf)<T>(.*)$/";
    $kind = null; $order = 0; $end = false;
    for ($i = $hi + 2; $i < count($lines); $i++) {
        $raw = $lines[$i];
        $l = $tagless($raw);
        if (strpos($l, 'Key to Ratings') === 0) { $end = true; break; }
        if (strpos($l, 'Sh  Name<T>Type') === 0) {
            if (strpos($l, 'Acc Con Qui Sta') !== false) { $kind = 'P'; }
            elseif (strpos($l, 'Hit Pow Spd Fld') !== false) { $kind = 'B'; }
            else { $out['errors'][] = "line " . ($i + 1) . ": a column header of neither type"; }
            continue;
        }
        if ($kind === null) {
            if (trim($l) === '' || preg_match('/^_+$/', trim($l)) || strpos($l, 'Turn Credits') !== false) { continue; }
            $out['errors'][] = "line " . ($i + 1) . ": text before the first column header: " . substr($l, 0, 60); continue;
        }
        if (trim($l) === '') { continue; }                                          // a spacer (<L.6.1>)
        if (!preg_match($re, $l, $m)) {
            $out['errors'][] = "line " . ($i + 1) . ": not a player line: " . substr($l, 0, 80); continue;
        }
        $type = $m[3];
        if (($type === 'Pit') !== ($kind === 'P')) {
            $out['errors'][] = "line " . ($i + 1) . ": a $type under the " . ($kind === 'P' ? 'pitchers' : 'batters') . "' header"; continue;
        }
        $sh = (int)$m[1];
        if (isset($out['rows'][$sh])) { $out['errors'][] = "line " . ($i + 1) . ": shirt $sh printed twice"; continue; }
        $row = [
            'report_order' => ++$order, 'player_name' => trim($m[2]), 'player_type' => $type, 'bats_throws' => $m[4],
            'player_level' => (int)$m[5], 'best_skill' => $m[6], 'experience' => ($m[7] === 'R' ? 0 : (int)$m[7]),
            'potential' => (int)$m[8], 'trade' => (int)$m[9], 'fatigue' => (int)$m[10], 'form' => (int)$m[11],
            'addform_active' => ($m[12] === '+' ? 1 : 0), 'total' => (int)$m[13], 'injury' => (int)$m[14],
            'value_lp' => (int)$m[15], 'wages_lp' => (int)$m[16], 'half_wages' => ($m[17] === '*' ? 1 : 0),
            'skill_a' => $m[18], 'skill_b' => $m[19], 'skill_c' => $m[20], 'skill_d' => $m[21], 'squad' => $m[22],
            'pit_mxb' => null, 'pit_mxh' => null, 'pit_mxe' => null, 'pit_fin' => null, 'pit_mnb' => null,
            'bat_pos' => null, 'bat_vrh' => null, 'bat_plt' => null, 'bat_pin' => null,
        ];
        if ($m[7] === '0') { $out['errors'][] = "line " . ($i + 1) . ": Exp printed 0, which would read as R"; continue; }
        $rest = $m[23];
        if ($type === 'Pit') {
            if (!preg_match('/^<T>\s*(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})\s*$/', $rest, $x)) {
                $out['errors'][] = "line " . ($i + 1) . ": the pitcher's MxB MxH MxE FIn MnB block does not read"; continue;
            }
            [$row['pit_mxb'], $row['pit_mxh'], $row['pit_mxe'], $row['pit_fin'], $row['pit_mnb']] = array_map('intval', array_slice($x, 1));
        } else {
            $f = [];
            if (strlen($rest) === 15 && $rest[3] === ' ' && $rest[7] === ' ' && $rest[11] === ' ') {
                foreach ([0, 4, 8, 12] as $p) { $f[] = trim(substr($rest, $p, 3)); }
            }
            if (count($f) !== 4 || count(array_filter($f, fn($v) => !preg_match('/^(|-|[A-Z0-9]{1,2})$/', $v))) > 0) {
                $out['errors'][] = "line " . ($i + 1) . ": the Pos vRH Plt Pin field does not read: '" . $rest . "'"; continue;
            }
            [$row['bat_pos'], $row['bat_vrh'], $row['bat_plt'], $row['bat_pin']] = $f;
        }
        $out['rows'][$sh] = $row;
    }
    if (!$end) { $out['errors'][] = 'no "Key to Ratings" line: the roster has no end'; }
    $types = array_count_values(array_column($out['rows'], 'player_type'));
    if (empty($types['Pit']) || (empty($types['Bat']) && empty($types['Cat']))) {
        $out['errors'][] = 'the roster needs pitchers and batters: read ' . count($out['rows']) . ' row(s)';
    }
    return $out;
}
}

if (!function_exists('bb_roster_write')) {
/**
 * Writes one upload's Team Report into bb_roster_entries (Q3: the week's snapshot for that team), or with $dry only
 * counts what it would do. Runs inside the caller's transaction when there is one, else in its own.
 * Returns ['ok' => bool, 'errors' => [...], 'parsed' => n, 'inserted' => n, 'updated' => n, 'unchanged' => n,
 *          'deleted' => n, 'week_id' => n, 'franchise_id' => n, 'label' => 'MLB21 Season 35 Week 21 PH'].
 */
function bb_roster_write(PDO $conn, int $uploadId, bool $dry): array {
    $r = ['ok' => false, 'errors' => [], 'parsed' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0,
          'week_id' => null, 'franchise_id' => null, 'label' => "upload $uploadId"];
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
    $st = $conn->prepare("SELECT block_text FROM raw_upload_blocks WHERE upload_id = :u AND block_type = 'Team Report'");
    $st->execute([':u' => $uploadId]);
    $blocks = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($blocks) !== 1) { $r['errors'][] = count($blocks) . " Team Report blocks, not 1"; return $r; }

    $p = bb_roster_parse((string)$blocks[0]);
    $r['errors'] = $p['errors'];
    if ($p['league'] !== null && $p['league'] !== $u['code']) { $r['errors'][] = "the report is {$p['league']}, the upload {$u['code']}"; }
    if ($p['season'] !== null && $p['season'] !== (int)$u['year']) { $r['errors'][] = "the report is season {$p['season']}, the upload {$u['year']}"; }
    if ($p['week_no'] !== null && ($p['week_no'] !== (int)$u['week_number'] || $u['phase'] !== 'regular')) {
        $r['errors'][] = "the report is Week {$p['week_no']}, the upload week {$u['week_number']} ({$u['phase']})";
    }
    if ($p['playoff_week'] !== null && $u['phase'] !== 'postseason') {
        $r['errors'][] = "the report is Playoff Week {$p['playoff_week']}, the upload's week is {$u['phase']}";
    }
    if ($p['team'] !== null && $p['team'] !== $u['team']) { $r['errors'][] = "the report is for {$p['team']}, the upload's team is {$u['team']}"; }
    $r['parsed'] = count($p['rows']);
    if ($r['errors']) { return $r; }

    $wk = (int)$u['week_id']; $fr = (int)$u['franchise_id'];
    $r['week_id'] = $wk; $r['franchise_id'] = $fr;
    $st = $conn->prepare("SELECT roster_entry_id, shirt_no, " . implode(', ', BB_ROSTER_COLS)
                       . " FROM bb_roster_entries WHERE week_id = :w AND franchise_id = :f");
    $st->execute([':w' => $wk, ':f' => $fr]);
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $have[(int)$row['shirt_no']] = $row; }

    $norm = fn($v) => $v === null ? null : (string)$v;
    $ins = []; $upd = []; $del = [];
    foreach ($p['rows'] as $sh => $row) {
        $row = ['source_upload_id' => $uploadId] + $row;
        if (!isset($have[$sh])) { $ins[$sh] = $row; continue; }
        foreach (BB_ROSTER_COLS as $c) {
            if ($norm($row[$c]) !== $norm($have[$sh][$c])) { $upd[$sh] = $row; break; }
        }
        if (!isset($upd[$sh])) { $r['unchanged']++; }
    }
    foreach ($have as $sh => $row) { if (!isset($p['rows'][$sh])) { $del[] = (int)$row['roster_entry_id']; } }
    $r['inserted'] = count($ins); $r['updated'] = count($upd); $r['deleted'] = count($del);
    if ($dry) { $r['ok'] = true; return $r; }

    $own = !$conn->inTransaction();
    if ($own) { $conn->beginTransaction(); }
    try {
        if ($del) {
            $d = $conn->prepare("DELETE FROM bb_roster_entries WHERE roster_entry_id = :id AND week_id = :w AND franchise_id = :f");
            foreach ($del as $id) { $d->execute([':id' => $id, ':w' => $wk, ':f' => $fr]); }
        }
        if ($ins) {
            $cols = array_merge(['week_id', 'franchise_id', 'shirt_no'], BB_ROSTER_COLS);
            $i = $conn->prepare("INSERT INTO bb_roster_entries (" . implode(', ', $cols) . ") VALUES (:" . implode(', :', $cols) . ")");
            foreach ($ins as $sh => $row) {
                $vals = [':week_id' => $wk, ':franchise_id' => $fr, ':shirt_no' => $sh];
                foreach (BB_ROSTER_COLS as $c) { $vals[":$c"] = $row[$c]; }
                $i->execute($vals);
            }
        }
        if ($upd) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", BB_ROSTER_COLS));
            $q = $conn->prepare("UPDATE bb_roster_entries SET $set WHERE week_id = :week_id AND franchise_id = :franchise_id AND shirt_no = :shirt_no");
            foreach ($upd as $sh => $row) {
                $vals = [':week_id' => $wk, ':franchise_id' => $fr, ':shirt_no' => $sh];
                foreach (BB_ROSTER_COLS as $c) { $vals[":$c"] = $row[$c]; }
                $q->execute($vals);
            }
        }
        // the snapshot now equals the report: count it back before committing
        $st = $conn->prepare("SELECT COUNT(*) FROM bb_roster_entries WHERE week_id = :w AND franchise_id = :f");
        $st->execute([':w' => $wk, ':f' => $fr]);
        $n = (int)$st->fetchColumn();
        if ($n !== $r['parsed']) { throw new RuntimeException("stored $n rows for the week, the report holds {$r['parsed']}"); }
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

<?php
// gp_honours.php · rev 001 · 22 Oct 2026 · role: shared helper · authority: derived (schema.md s19)
// A7. Derives a completed season's franchise_honors from its games and inserts the ones missing.
//
// ------------------------------------------------------------------
// WHY THIS FILE EXISTS
//
// Nothing wrote franchise_honors after the migration (H46, 3 Oct 2026): every completed season
// needed h46_honours_20261003.sql run by hand. A7 (Alan, Q8 of 22 Oct 2026) makes Extract Games
// call gp_honours_refresh() for the upload's season, and the same function backfills from the CLI
// (scripts/gpbm_a7_honours_cli.php), so the page and the backfill cannot disagree.
//
// THE RULES (H46's, proved against every migrated honour; schema.md s19), and A7's additions:
//   NCAA5   winner / loser of game types 8 (LEAGUE_), 9 ROSE_BOWL_, 10 COTTON_BOWL_, 11 ORANGE_BOWL_,
//           12 HAWAII_BOWL_, 13 MUSIC_CITY_BOWL_ + WINNER / RUNNERUP
//           PERFECT_SEASON     13 games outside pre-season, all won
//           UNDEFEATED_SEASON  13 games outside pre-season, none lost, at least one drawn (A7, Q10)
//   NFLAR   LEAGUE_WINNER / LEAGUE_RUNNERUP   winner / loser of the Superbowl (36)
//           WILDCARD_BERTH       both teams of the week-17 Play Offs (35)
//           DIVISION_CHAMPION    the week-18 Play Offs teams, less the week-17 winners
//           CONFERENCE_CHAMPION / _RUNNERUP   winners / losers of the week-19 Play Offs
//           PERFECT_SEASON       the Superbowl winner, 16-0-0 in franchise_season_records (A7, Q7)
//           UNDEFEATED_SEASON    the Superbowl winner, 16 games, none lost, at least one drawn (Q10)
//   "Perfect" is winning every game; "undefeated" is losing none with a draw (Alan, Q6 and Q10 of
//   22 Oct 2026). A season gets one of the two, never both.
//
// WHY THE PLAY-OFF WEEKS ARE NUMBERS, NOT "THE FIRST PLAY-OFF WEEK": H46 took MIN(week). Weeks are
// uploaded out of order (A3, H49); with week 18 in and week 17 not, MIN is 18 and every week-18 team
// would read as a wild card. In all 31 seasons with Play Offs games (2004 to 2034) they are weeks
// 17, 18, 19 with 2, 4, 2 games, and the Superbowl week 20 (claude_reader, 22 Oct 2026).
//
// WHEN A SEASON COUNTS AS COMPLETE: its final is stored and decided (NCAA5: one type-8 game; NFLAR:
// one Superbowl) and, for NFLAR, its Play Offs are either absent altogether (1989 to 2003: the
// migration holds those seasons' play-off honours, which the games cannot derive) or exactly 2, 4, 2
// games in weeks 17, 18, 19 and none elsewhere. Anything else is reported and nothing is written.
//
// WHAT IT NEVER DOES: delete or change a held honour. A held honour of a type the season's rules
// derive, which the rules do not derive, is listed as an extra for a person to look at. Honour types
// the rules do not derive (college CONFERENCE_CHAMPION, CIC_WINNER, RIVALRY_WINNER; NFLAR's play-off
// types in a season with no Play Offs games) are outside its view altogether.
//
// Included as __DIR__ . '/gp_honours.php' by extract_games.php (guarded: a missing file is reported
// and the games are still written) and by the CLI. No DaDaBIK dependency: PDO in, arrays out.
// ------------------------------------------------------------------

if (!function_exists('gp_honours_refresh')) {

// Derives one season. Returns ['league', 'year', 'complete', 'why', 'codes' (the codes the season's
// rules derive), 'derived' (list of [code, franchise_id])].
function gp_honours_derive(PDO $conn, $season_id) {
    $out = ['league' => null, 'year' => null, 'complete' => false, 'why' => '', 'codes' => [], 'derived' => []];
    $st = $conn->prepare("SELECT l.code, s.year FROM seasons s JOIN leagues l ON l.league_id = s.league_id WHERE s.season_id = ?");
    $st->execute([(int)$season_id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) { $out['why'] = "no season $season_id"; return $out; }
    $out['league'] = $s['code'];
    $out['year'] = (int)$s['year'];

    $st = $conn->prepare(
        "SELECT g.game_type_id AS gt, t.phase, w.week_number AS wk,
                g.home_franchise_id AS h, g.away_franchise_id AS a, g.home_score AS hs, g.away_score AS aws
         FROM games g
         JOIN weeks w ON w.week_id = g.week_id
         JOIN game_types t ON t.game_type_id = g.game_type_id
         WHERE w.season_id = ?");
    $st->execute([(int)$season_id]);
    $games = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hs = $r['hs'] === null ? null : (int)$r['hs'];
        $as = $r['aws'] === null ? null : (int)$r['aws'];
        $games[] = ['gt' => (int)$r['gt'], 'phase' => $r['phase'], 'wk' => (int)$r['wk'],
                    'h' => (int)$r['h'], 'a' => (int)$r['a'], 'hs' => $hs, 'as' => $as,
                    'scored' => $hs !== null && $as !== null,
                    'w' => ($hs !== null && $as !== null && $hs !== $as) ? ($hs > $as ? (int)$r['h'] : (int)$r['a']) : null,
                    'l' => ($hs !== null && $as !== null && $hs !== $as) ? ($hs > $as ? (int)$r['a'] : (int)$r['h']) : null];
    }
    $d = [];

    if ($s['code'] === 'NCAA5') {
        $final = array_values(array_filter($games, fn($g) => $g['gt'] === 8));
        if (count($final) !== 1 || $final[0]['w'] === null) {
            $out['why'] = count($final) === 0 ? 'the National Championship Game is not stored yet'
                        : (count($final) > 1 ? count($final) . ' National Championship Games' : 'the National Championship Game is not decided');
            return $out;
        }
        $bowls = [8 => 'LEAGUE', 9 => 'ROSE_BOWL', 10 => 'COTTON_BOWL', 11 => 'ORANGE_BOWL', 12 => 'HAWAII_BOWL', 13 => 'MUSIC_CITY_BOWL'];
        foreach ($bowls as $p) { $out['codes'][] = $p . '_WINNER'; $out['codes'][] = $p . '_RUNNERUP'; }
        $out['codes'][] = 'PERFECT_SEASON';
        $out['codes'][] = 'UNDEFEATED_SEASON';
        foreach ($games as $g) {
            if (isset($bowls[$g['gt']]) && $g['w'] !== null) {
                $d[] = [$bowls[$g['gt']] . '_WINNER', $g['w']];
                $d[] = [$bowls[$g['gt']] . '_RUNNERUP', $g['l']];
            }
        }
        // every game outside pre-season, per team: played, lost, drawn
        $rec = [];
        foreach ($games as $g) {
            if ($g['phase'] === 'preseason' || !$g['scored']) { continue; }
            foreach ([$g['h'], $g['a']] as $f) {
                $rec[$f] = $rec[$f] ?? ['n' => 0, 'l' => 0, 't' => 0];
                $rec[$f]['n']++;
                if ($g['w'] === null) { $rec[$f]['t']++; } elseif ($g['l'] === $f) { $rec[$f]['l']++; }
            }
        }
        foreach ($rec as $f => $r) {
            if ($r['n'] === 13 && $r['l'] === 0) { $d[] = [$r['t'] === 0 ? 'PERFECT_SEASON' : 'UNDEFEATED_SEASON', $f]; }
        }
    } elseif ($s['code'] === 'NFLAR') {
        $sb = array_values(array_filter($games, fn($g) => $g['gt'] === 36));
        if (count($sb) !== 1 || $sb[0]['w'] === null) {
            $out['why'] = count($sb) === 0 ? 'the Superbowl is not stored yet'
                        : (count($sb) > 1 ? count($sb) . ' Superbowls' : 'the Superbowl is not decided');
            return $out;
        }
        $po = array_values(array_filter($games, fn($g) => $g['gt'] === 35));
        $by = [17 => [], 18 => [], 19 => []];
        $odd = 0;
        foreach ($po as $g) { if (isset($by[$g['wk']])) { $by[$g['wk']][] = $g; } else { $odd++; } }
        $shape = count($by[17]) . ',' . count($by[18]) . ',' . count($by[19]);
        if (count($po) > 0 && ($shape !== '2,4,2' || $odd > 0)) {
            $out['why'] = "the Play Offs are incomplete or unexpected: weeks 17, 18, 19 hold $shape games, $odd elsewhere";
            return $out;
        }
        foreach ($po as $g) {
            if ($g['w'] === null) { $out['why'] = 'a Play Offs game is not decided'; return $out; }
        }
        $out['codes'] = ['LEAGUE_WINNER', 'LEAGUE_RUNNERUP', 'PERFECT_SEASON', 'UNDEFEATED_SEASON'];
        $d[] = ['LEAGUE_WINNER', $sb[0]['w']];
        $d[] = ['LEAGUE_RUNNERUP', $sb[0]['l']];
        if (count($po) > 0) {
            array_push($out['codes'], 'WILDCARD_BERTH', 'DIVISION_CHAMPION', 'CONFERENCE_CHAMPION', 'CONFERENCE_RUNNERUP');
            $w17 = array_map(fn($g) => $g['w'], $by[17]);
            foreach ($by[17] as $g) { $d[] = ['WILDCARD_BERTH', $g['h']]; $d[] = ['WILDCARD_BERTH', $g['a']]; }
            foreach ($by[18] as $g) {
                foreach ([$g['h'], $g['a']] as $f) { if (!in_array($f, $w17, true)) { $d[] = ['DIVISION_CHAMPION', $f]; } }
            }
            foreach ($by[19] as $g) { $d[] = ['CONFERENCE_CHAMPION', $g['w']]; $d[] = ['CONFERENCE_RUNNERUP', $g['l']]; }
        }
        // the champion's regular season, from franchise_season_records (it holds 1989 onwards; for
        // 2005 to 2034 it equals the games, 720 of 720, claude_reader 22 Oct 2026)
        $st = $conn->prepare("SELECT wins, losses, ties FROM franchise_season_records WHERE season_id = ? AND franchise_id = ?");
        $st->execute([(int)$season_id, $sb[0]['w']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)$r['wins'] + (int)$r['losses'] + (int)$r['ties'] === 16 && (int)$r['losses'] === 0) {
            $d[] = [(int)$r['ties'] === 0 ? 'PERFECT_SEASON' : 'UNDEFEATED_SEASON', $sb[0]['w']];
        }
    } else {
        $out['why'] = "no honour rules for league {$s['code']}";
        return $out;
    }

    // one row per (code, franchise)
    $seen = [];
    foreach ($d as [$c, $f]) { $seen["$c|$f"] = [$c, (int)$f]; }
    $out['derived'] = array_values($seen);
    $out['complete'] = true;
    return $out;
}

// Derives the season and inserts the honours it does not hold. $dry_run lists without writing.
// Returns the derivation plus 'missing' (list of [code, franchise_id]), 'inserted', 'extras' (held,
// of a code the rules derive, not derived) and 'unmapped' (derived codes with no honor_types row:
// then nothing is written for the season).
function gp_honours_refresh(PDO $conn, $season_id, $dry_run = false) {
    $out = gp_honours_derive($conn, $season_id) + ['missing' => [], 'inserted' => 0, 'extras' => [], 'unmapped' => []];
    if (!$out['complete']) { return $out; }

    $st = $conn->prepare("SELECT ht.code, ht.honor_type_id FROM honor_types ht JOIN seasons s ON s.league_id = ht.league_id WHERE s.season_id = ?");
    $st->execute([(int)$season_id]);
    $types = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $types[$r['code']] = (int)$r['honor_type_id']; }

    $st = $conn->prepare("SELECT ht.code, fh.franchise_id FROM franchise_honors fh JOIN honor_types ht ON ht.honor_type_id = fh.honor_type_id WHERE fh.season_id = ?");
    $st->execute([(int)$season_id]);
    $held = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $held[$r['code'] . '|' . (int)$r['franchise_id']] = [$r['code'], (int)$r['franchise_id']]; }

    $derived = [];
    foreach ($out['derived'] as [$c, $f]) { $derived["$c|$f"] = true; }
    foreach ($out['derived'] as [$c, $f]) {
        if (!isset($types[$c])) { $out['unmapped'][$c] = true; continue; }
        if (!isset($held["$c|$f"])) { $out['missing'][] = [$c, $f]; }
    }
    $out['unmapped'] = array_keys($out['unmapped']);
    foreach ($held as $k => [$c, $f]) {
        if (in_array($c, $out['codes'], true) && !isset($derived[$k])) { $out['extras'][] = [$c, $f]; }
    }
    if ($dry_run || $out['unmapped'] || !$out['missing']) { return $out; }

    $own_tx = !$conn->inTransaction();
    if ($own_tx) { $conn->beginTransaction(); }
    try {
        $ins = $conn->prepare("INSERT INTO franchise_honors (franchise_id, season_id, honor_type_id) VALUES (?, ?, ?)");
        foreach ($out['missing'] as [$c, $f]) {
            $ins->execute([$f, (int)$season_id, $types[$c]]);
            $out['inserted']++;
        }
        if ($own_tx) { $conn->commit(); }
    } catch (Throwable $e) {
        if ($own_tx && $conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
    return $out;
}

}

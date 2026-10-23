<?php
// bb_roster_diff.php · rev 001 · 23 Oct 2026 · role: helper (functions only, included by bb_roster_compare.php and the B4 CLI) · authority: derived
// rev 001: first issue. Item B4 (Alan, Q1 to Q7 of 23 Oct 2026 session1; GameplanPBM/20261023/session1/SESSION_20261023_1.md s2).
//
// ------------------------------------------------------------------
// WHAT THIS DOES
//
// Compares one team's roster between two weeks, from bb_roster_entries (schema.md s32), the way the legacy
// bb_rostercompare.php did it against bb_myteam (baseball/legacy/, the behavioural spec):
//   - the two snapshots are keyed by SHIRT (s32: a shirt stays with a player through a season; a name does not identify him);
//   - a shirt in both weeks is a matched row: level up or down, a potential loss of more than one ("double"), Exp and Value
//     old and new, each of the four skills ranked Po < Fa < Av < Go < Ex < WC and marked up or down;
//   - a shirt in the later week only is a NEW row, shown with its current values and no arrows;
//   - a shirt in the earlier week only is GONE (Q4): listed, not diffed. The legacy page left these out.
// The summary counts, as the legacy did (Q5): per Pit and per Bat/Cat together, level ups and downs and double potential
// losses per PLAYER, skill ups and downs per SKILL COLUMN (a player whose Hit and Pow both rose counts twice).
//
// No HTML here: the page renders, the CLI prints, and both gate on the same arrays. The skills are ranked in PHP, never
// by comparing the ENUM columns in SQL: MariaDB compares two ENUM columns as strings, so Fa > Av reads as a rise there
// (SESSION_20261023_1.md s3).
// ------------------------------------------------------------------

/** The rank of a printed skill grade, 1 (Po) to 6 (WC); 0 for anything else. */
function bb_skill_rank(?string $g): int {
    static $r = ['Po' => 1, 'Fa' => 2, 'Av' => 3, 'Go' => 4, 'Ex' => 5, 'WC' => 6];
    return $r[(string)$g] ?? 0;
}

/** The Value tier the legacy page coloured: 'high' above 99, 'mid' 70 to 99, '' below. */
function bb_value_tier(int $v): string {
    return $v > 99 ? 'high' : ($v >= 70 ? 'mid' : '');
}

/** One week's snapshot of a team, keyed by shirt, in the order the turn printed it. */
function bb_roster_snapshot(PDO $conn, int $weekId, int $franchiseId): array {
    $st = $conn->prepare("SELECT shirt_no, player_name, player_type, player_level, best_skill, experience, potential, value_lp,
                                 skill_a, skill_b, skill_c, skill_d, squad, bat_pos, report_order
                          FROM bb_roster_entries WHERE week_id = :w AND franchise_id = :f ORDER BY report_order");
    $st->execute([':w' => $weekId, ':f' => $franchiseId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['shirt_no']] = $r; }
    return $out;
}

/**
 * The comparison. Returns
 *   rows    one per shirt in the later week, in the later week's printed order: see bb_roster_diff_row()
 *   gone    the earlier week's rows whose shirt is not in the later week (shirt, player_name, player_type)
 *   summary ['Pit' => [...], 'BatCat' => [...]] each with level_up, level_down, skill_up, skill_down, double_pot
 *   counts  matched, new, gone
 */
function bb_roster_diff(PDO $conn, int $fromWeekId, int $toWeekId, int $franchiseId): array {
    $from = bb_roster_snapshot($conn, $fromWeekId, $franchiseId);
    $to   = bb_roster_snapshot($conn, $toWeekId, $franchiseId);
    return bb_roster_diff_arrays($from, $to);
}

/** The comparison over two snapshots already read (bb_roster_snapshot's shape). */
function bb_roster_diff_arrays(array $from, array $to): array {
    $zero = ['level_up' => 0, 'level_down' => 0, 'skill_up' => 0, 'skill_down' => 0, 'double_pot' => 0];
    $summary = ['Pit' => $zero, 'BatCat' => $zero];
    $rows = []; $counts = ['matched' => 0, 'new' => 0, 'gone' => 0];
    foreach ($to as $sh => $p2) {
        $p1 = $from[$sh] ?? null;
        $row = bb_roster_diff_row($p1, $p2);
        $rows[] = $row;
        if ($p1 === null) { $counts['new']++; continue; }
        $counts['matched']++;
        $g = ($p2['player_type'] === 'Pit') ? 'Pit' : 'BatCat';   // Bat and Cat together, as the legacy counted them
        if ($row['level']['dir'] === 'up') { $summary[$g]['level_up']++; }
        if ($row['level']['dir'] === 'down') { $summary[$g]['level_down']++; }
        if ($row['pot']['double']) { $summary[$g]['double_pot']++; }
        foreach ($row['skills'] as $s) {
            if ($s['dir'] === 'up') { $summary[$g]['skill_up']++; }
            if ($s['dir'] === 'down') { $summary[$g]['skill_down']++; }
        }
    }
    $gone = [];
    foreach ($from as $sh => $p1) {
        if (!isset($to[$sh])) { $gone[] = ['shirt' => $sh, 'player_name' => $p1['player_name'], 'player_type' => $p1['player_type']]; $counts['gone']++; }
    }
    return ['rows' => $rows, 'gone' => $gone, 'summary' => $summary, 'counts' => $counts];
}

/**
 * One row of the comparison. $p1 is the earlier week's row for the shirt, or null for a player new in the later week.
 *   shirt, name, type, pos, is_new
 *   exp    ['old' => 'R'|n, 'new' => 'R'|n, 'changed' => bool]
 *   level  ['old', 'new', 'dir' => 'up'|'down'|'']
 *   pot    ['old', 'new', 'double' => bool]            double: a loss of more than one (the legacy's cyan)
 *   value  ['old', 'new', 'tier' => 'high'|'mid'|'']   tier from the NEW value
 *   skills four of ['old', 'new', 'dir' => 'up'|'down'|'']
 * For a new player old = new throughout, dir '' and double false, so a renderer can print the new values alone.
 */
function bb_roster_diff_row(?array $p1, array $p2): array {
    $exp = fn($e) => ((int)$e === 0) ? 'R' : (int)$e;          // s32: Exp 0 is printed R
    $o = $p1 ?? $p2;
    $skills = [];
    foreach (['skill_a', 'skill_b', 'skill_c', 'skill_d'] as $k) {
        $r1 = bb_skill_rank($o[$k]); $r2 = bb_skill_rank($p2[$k]);
        $skills[] = ['old' => $o[$k], 'new' => $p2[$k], 'dir' => ($p1 === null || $r1 === $r2) ? '' : ($r2 > $r1 ? 'up' : 'down')];
    }
    $l1 = (int)$o['player_level']; $l2 = (int)$p2['player_level'];
    return [
        'shirt' => (int)$p2['shirt_no'], 'name' => $p2['player_name'], 'type' => $p2['player_type'],
        'pos' => ($p2['player_type'] === 'Pit') ? '' : (string)($p2['bat_pos'] ?? ''), 'is_new' => ($p1 === null),
        'exp'    => ['old' => $exp($o['experience']), 'new' => $exp($p2['experience']), 'changed' => ($p1 !== null && (int)$o['experience'] !== (int)$p2['experience'])],
        'level'  => ['old' => $l1, 'new' => $l2, 'dir' => ($p1 === null || $l1 === $l2) ? '' : ($l2 > $l1 ? 'up' : 'down')],
        'pot'    => ['old' => (int)$o['potential'], 'new' => (int)$p2['potential'], 'double' => ($p1 !== null && (int)$o['potential'] - (int)$p2['potential'] > 1)],
        'value'  => ['old' => (int)$o['value_lp'], 'new' => (int)$p2['value_lp'], 'tier' => bb_value_tier((int)$p2['value_lp'])],
        'skills' => $skills,
    ];
}

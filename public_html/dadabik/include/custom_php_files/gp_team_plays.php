<?php
// gp_team_plays.php · rev 003 · 12 Nov 2026 · role: helper (functions only, included by team_plays.php and the H92 CLI) · authority: derived
// rev 003: H96 (Alan, Q1 of 12 Nov 2026 session2: "show Goal"). A play with a down and no distance lists its distance as
//          "Goal", on the page and in the download: plays.yards_to_go is NULL only where the report read "<n>th & Goal"
//          (extract_playbyplay.php, parse_down_distance()). Legacy rows carry a number there and are listed as stored. The
//          H92 CLI's proof against the exports of 10 Nov 2026 was made before this and would now differ on those rows.
// rev 002: gp_tp_rows() takes $newest_first (Alan, NZ2 and NZ3 of 10 Nov 2026 session1: the most recent game first; the plays of
//          a game stay in the order they were run). The default is the old order, so the CLI's proof against the exports stands.
// rev 001: first issue. Item H92 (Alan, item 0 and Q2, Q3 of 10 Nov 2026 session1; GameplanPBM/20261110/session1/SESSION_20261110_1.md).
//
// ------------------------------------------------------------------
// WHAT THIS DOES
//
// Lists one franchise's plays on one side of the ball, from the view v_team_plays (schema.md: one row per play from
// `plays` and `legacy_play_log`, a game from one source), inside a coach's tenure and a span of seasons. The page
// team_plays.php renders what this returns; the CLI gpbm_h92_plays_cli.php proves it against the exports of 10 Nov 2026.
// Both go through gp_tp_rows(), so the page and its proof gate on one piece of code. Nothing is written.
//
// A tenure is a slot's coach from one week to another (franchise_coach_tenures; end_week_id is INCLUSIVE, schema.md s24).
// Weeks are compared as year*100 + week_number: a football season's week numbers are unique (0 preseason, then the
// regular and play-off weeks in order; measured 10 Nov 2026: no football season repeats a week number), so this is the
// same order as schema.md s24a's, and the view carries no week ordering of its own to disagree with it.
// ------------------------------------------------------------------

if (!defined('custom_page_from_inclusion') && PHP_SAPI !== 'cli') { exit; }

// The listing's columns, in order: the page's table, the CSV download and the 10 Nov 2026 exports all use this list.
function gp_tp_columns() {
    return ['season', 'week', 'opponent', 'home_away', 'quarter', 'clock', 'field_pos', 'down', 'distance', 'formation',
            'off_call', 'def_call', 'yards', 'penalty_yards', 'touchdown', 'turnover', 'sack', 'incomplete', 'penalty_on',
            'result', 'source', 'game_id'];
}

// The result text as listed. A legacy row stores the whole report line ("0:22      88  2nd and 7    H LL IX    quarterback
// scramble ... <L>"): what follows the call triple is the result. Tags like <L> and <Z> go, runs of spaces close up.
function gp_tp_result($source, $text, $formation, $off_call, $def_call) {
    $t = (string)$text;
    if ($source === 'legacy') {
        $triple = implode(' ', array_filter([$formation, $off_call, $def_call], fn($x) => $x !== null && $x !== ''));
        $pair   = implode(' ', array_filter([$off_call, $def_call], fn($x) => $x !== null && $x !== ''));
        foreach ([$triple, $pair] as $c) {
            if ($c === '') { continue; }
            $i = strpos($t, $c);
            if ($i !== false) { $t = substr($t, $i + strlen($c)); break; }
        }
    }
    $t = preg_replace('/<[A-Za-z]>/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

// A franchise's tenures, newest first. lo and hi are year*100 + week_number; hi is null on the open tenure.
function gp_tp_tenures($conn, $franchise_id) {
    $st = $conn->prepare(
        "SELECT t.tenure_id, c.name AS coach_name,
                s1.year AS start_year, w1.week_number AS start_week,
                s2.year AS end_year, w2.week_number AS end_week, t.end_week_id
           FROM franchise_coach_tenures t
           JOIN coaches c ON c.coach_id = t.coach_id
           JOIN weeks w1 ON w1.week_id = t.start_week_id
           JOIN seasons s1 ON s1.season_id = w1.season_id
           LEFT JOIN weeks w2 ON w2.week_id = t.end_week_id
           LEFT JOIN seasons s2 ON s2.season_id = w2.season_id
          WHERE t.franchise_id = :fid
          ORDER BY s1.year DESC, w1.week_number DESC, t.tenure_id DESC");
    $st->execute([':fid' => (int)$franchise_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $open = ($r['end_week_id'] === null);
        $out[] = [
            'tenure_id' => (int)$r['tenure_id'],
            'coach'     => (string)$r['coach_name'],
            'open'      => $open,
            'lo'        => (int)$r['start_year'] * 100 + (int)$r['start_week'],
            'hi'        => $open ? null : (int)$r['end_year'] * 100 + (int)$r['end_week'],
            'label'     => $r['coach_name'] . ' (' . $r['start_year'] . ' wk ' . $r['start_week'] . ' to '
                         . ($open ? 'now' : $r['end_year'] . ' wk ' . $r['end_week']) . ')',
        ];
    }
    return $out;
}

// The WHERE both queries below share. $side is 'off' or 'def': which side of the ball the franchise is on.
// $lo and $hi bound the weeks (year*100 + week_number), null for no bound.
function gp_tp_where($side, $league, $franchise_id, $lo, $hi, array &$bind) {
    $mine = ($side === 'def') ? 'defense_franchise_id' : 'offense_franchise_id';
    // the numbers are written into the statement as integers (each is cast here, so nothing else can reach it): a bound
    // value arrives as a string, and a string against an expression does not compare as a number on every engine
    $bind = [':lg' => (string)$league];
    $w = "v.league_code = :lg AND v.$mine = " . (int)$franchise_id;
    if ($lo !== null) { $w .= " AND v.season * 100 + v.week_number >= " . (int)$lo; }
    if ($hi !== null) { $w .= " AND v.season * 100 + v.week_number <= " . (int)$hi; }
    return $w;
}

// The seasons that hold plays for the selection, oldest first: [season => plays].
function gp_tp_seasons($conn, $side, $league, $franchise_id, $lo, $hi) {
    $bind = [];
    $w = gp_tp_where($side, $league, $franchise_id, $lo, $hi, $bind);
    $st = $conn->prepare("SELECT v.season, COUNT(*) AS n FROM v_team_plays v WHERE $w GROUP BY v.season ORDER BY v.season");
    $st->execute($bind);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['season']] = (int)$r['n']; }
    return $out;
}

// The listing: one array per play, keyed by gp_tp_columns(), plus 'opponent_name' (the opponent's identity that week,
// through gp_ident_name() when gp_identity.php is loaded, else the name as stored). Oldest game first, or the newest first
// when $newest_first is true; inside a game always in play order.
// $season_from and $season_to are inclusive years, null for no bound. $hide_st drops kickoffs, punts and field goals
// (formation X, P, F: the special-teams rule of the play-call views, schema.md s15).
function gp_tp_rows($conn, $side, $league, $franchise_id, $lo, $hi, $season_from = null, $season_to = null, $hide_st = false, $newest_first = false) {
    $bind = [];
    $w = gp_tp_where($side, $league, $franchise_id, $lo, $hi, $bind);
    if ($season_from !== null) { $w .= " AND v.season >= " . (int)$season_from; }
    if ($season_to !== null)   { $w .= " AND v.season <= " . (int)$season_to; }
    if ($hide_st) { $w .= " AND (v.formation_code IS NULL OR v.formation_code NOT IN ('P','X','F'))"; }
    $other = ($side === 'def') ? 'offense' : 'defense';
    $st = $conn->prepare(
        "SELECT v.source, v.season, v.week_number, v.game_id, v.home_franchise_id, v.{$other}_team_code AS opp_code,
                v.quarter, v.time_gone_seconds, v.field_position, v.down, v.distance, v.formation_code,
                v.offense_call_code, v.defense_call_code, v.yards_gained, v.penalty_yards, v.is_touchdown, v.is_turnover,
                v.is_sack, v.is_incomplete, v.is_penalty_offense, v.is_penalty_defense, v.result_text,
                oi.team_name AS opp_ident, ofr.label AS opp_label
           FROM v_team_plays v
           LEFT JOIN franchise_identities oi ON oi.franchise_id = v.{$other}_franchise_id AND oi.week_id = v.week_id
           LEFT JOIN franchises ofr ON ofr.franchise_id = v.{$other}_franchise_id
          WHERE $w
          ORDER BY " . ($newest_first ? "v.season DESC, v.week_number DESC, v.game_id DESC" : "v.season, v.week_number, v.game_id") . ", v.play_order");
    $st->execute($bind);
    $yn = fn($v) => ((int)$v === 1) ? 'Y' : '';
    $nv = fn($v) => ($v === null) ? '' : (string)$v;
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $t = $r['time_gone_seconds'];
        $has_down = ((int)$r['down'] > 0);           // a kickoff carries down 0 (legacy) or NULL (plays): no down, no distance
        $name = function_exists('gp_ident_name')
              ? gp_ident_name('historical', $r['opp_ident'], (string)$r['opp_label'])
              : (string)($r['opp_ident'] ?? $r['opp_label']);
        $out[] = [
            'season'        => (string)$r['season'],
            'week'          => (string)$r['week_number'],
            'opponent'      => $nv($r['opp_code']),
            'home_away'     => ((int)$r['home_franchise_id'] === (int)$franchise_id) ? 'H' : 'A',
            'quarter'       => $nv($r['quarter']),
            'clock'         => ($t === null) ? '' : sprintf('%d:%02d', intdiv((int)$t, 60), (int)$t % 60),
            'field_pos'     => $nv($r['field_position']),
            'down'          => $has_down ? (string)(int)$r['down'] : '',
            'distance'      => $has_down ? (($r['distance'] === null) ? 'Goal' : (string)$r['distance']) : '',
            'formation'     => $nv($r['formation_code']),
            'off_call'      => $nv($r['offense_call_code']),
            'def_call'      => $nv($r['defense_call_code']),
            'yards'         => $nv($r['yards_gained']),
            'penalty_yards' => $nv($r['penalty_yards']),
            'touchdown'     => $yn($r['is_touchdown']),
            'turnover'      => $yn($r['is_turnover']),
            'sack'          => $yn($r['is_sack']),
            'incomplete'    => $yn($r['is_incomplete']),
            'penalty_on'    => ((int)$r['is_penalty_offense'] === 1) ? 'offence' : (((int)$r['is_penalty_defense'] === 1) ? 'defence' : ''),
            'result'        => gp_tp_result($r['source'], $r['result_text'], $r['formation_code'], $r['offense_call_code'], $r['defense_call_code']),
            'source'        => (string)$r['source'],
            'game_id'       => $nv($r['game_id']),
            'opponent_name' => $name,
        ];
    }
    return $out;
}

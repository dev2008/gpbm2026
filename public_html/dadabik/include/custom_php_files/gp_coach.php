<?php
// gp_coach.php - H67: the coach of each franchise kept current from the turn reports
// rev 001 · 2 Nov 2026 · role: helper · authority: Alan Q2 (b), Q7 (a), Q8, Q9, Q10 (a) of 2 Nov 2026 session1
//          (SESSION_20261102_1.md s2, s3e, s3h); schema.md s24
//
// Loaded by extract_standings.php (regular-season weeks: the coach in brackets on every Standings line) and
// extract_games.php (preseason and postseason weeks: the coach on each game line). Both call gp_coach_apply()
// once per team and render gp_coach_panel() under their result.
//
// The rule, per (franchise, week, printed name):
//   - the printed name is resolved to a coach by coaches.name in the league, following coaches.alias_of (Q10):
//     a spelling such as "S.Londesbrough" is a coaches row pointing at "Steve Londesbrough", never a tenure's coach;
//   - the tenure covering the week names that coach: nothing to do (a repeated takeover line, a re-run, a later week);
//   - the week is LATER than the start of the franchise's open tenure and the coach differs: the open tenure is
//     closed at the league's previous week (end_week_id is inclusive, schema.md s24) and a new one opened at this
//     week; a name the league has never seen becomes a new coaches row;
//   - anything else (an out-of-order older upload, the open tenure's own first week, no tenure at all) is REPORTED
//     and nothing is written (Q8).
// Weeks are ordered by (year, phase, week_number), never by week_id (GPBM s8; a4_resolve_from_neighbours()).
// The takeover line ("<name> takes over as coach of the <team>") only labels a change: it never makes one.
//
// The merge (Q7): a change the administrator recognises as a spelling of the previous coach is undone by
// gp_coach_merge(): the new coach becomes an alias of the old (coaches.alias_of), its tenures pass to the old coach,
// and adjacent tenures of one coach at one franchise are joined. A POST from the panel's button, administrator only
// ($current_user_is_administrator), with a session token.
if (!defined('custom_page_from_inclusion') && PHP_SAPI !== 'cli') { exit; }

const GP_COACH_ORD = "(s.year * 1000 + CASE w.phase WHEN 'preseason' THEN 0 WHEN 'regular' THEN 100 ELSE 200 END + w.week_number)";

// One week: league, order key, phase and a readable label ("2035 wk1"); null if the week does not exist.
function gp_coach_week($conn, $week_id) {
    $st = $conn->prepare("SELECT s.league_id, " . GP_COACH_ORD . " AS ord, w.phase, CONCAT(s.year, ' wk', w.week_number) AS label
                          FROM weeks w JOIN seasons s ON s.season_id = w.season_id WHERE w.week_id = :w");
    $st->execute([':w' => $week_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    $r['ord'] = (int)$r['ord']; $r['league_id'] = (int)$r['league_id'];
    return $r;
}

// The league's week immediately before an order key, or null.
function gp_coach_prev_week($conn, $league_id, $ord) {
    $st = $conn->prepare("SELECT w.week_id FROM weeks w JOIN seasons s ON s.season_id = w.season_id
                          WHERE s.league_id = :l AND " . GP_COACH_ORD . " < :o ORDER BY " . GP_COACH_ORD . " DESC LIMIT 1");
    $st->execute([':l' => $league_id, ':o' => $ord]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

// A printed name to its coach: [coach_id of the real coach, the real coach's name] or null when the league has no such name.
// One level of alias_of: gp_coach_merge() repoints an alias's own aliases, so no chain is ever left.
function gp_coach_resolve($conn, $league_id, $name) {
    $st = $conn->prepare("SELECT c.coach_id, c.alias_of, r.coach_id AS real_id, r.name AS real_name, c.name
                          FROM coaches c LEFT JOIN coaches r ON r.coach_id = c.alias_of
                          WHERE c.league_id = :l AND c.name = :n");
    $st->execute([':l' => $league_id, ':n' => $name]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    return $r['alias_of'] !== null ? [(int)$r['real_id'], $r['real_name']] : [(int)$r['coach_id'], $r['name']];
}

// The franchise's tenures with their order keys, oldest first.
function gp_coach_tenures($conn, $franchise_id) {
    $o1 = str_replace(['s.', 'w.'], ['ss.', 'ws.'], GP_COACH_ORD);
    $o2 = str_replace(['s.', 'w.'], ['se.', 'we.'], GP_COACH_ORD);
    $st = $conn->prepare("SELECT ft.tenure_id, ft.coach_id, c.name, ft.start_week_id, ft.end_week_id, $o1 AS start_ord,
                                 IF(ft.end_week_id IS NULL, NULL, $o2) AS end_ord
                          FROM franchise_coach_tenures ft JOIN coaches c ON c.coach_id = ft.coach_id
                          JOIN weeks ws ON ws.week_id = ft.start_week_id JOIN seasons ss ON ss.season_id = ws.season_id
                          LEFT JOIN weeks we ON we.week_id = ft.end_week_id LEFT JOIN seasons se ON se.season_id = we.season_id
                          WHERE ft.franchise_id = :f ORDER BY start_ord");
    $st->execute([':f' => $franchise_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $t['start_ord'] = (int)$t['start_ord']; $t['end_ord'] = $t['end_ord'] === null ? null : (int)$t['end_ord'];
        $t['coach_id'] = (int)$t['coach_id']; $t['tenure_id'] = (int)$t['tenure_id'];
        $out[] = $t;
    }
    return $out;
}

// The takeover lines of one upload: [team name as printed => coach name as printed].
function gp_coach_takeovers($conn, $upload_id) {
    $st = $conn->prepare("SELECT raw_text FROM raw_uploads WHERE upload_id = :u");
    $st->execute([':u' => $upload_id]);
    return gp_coach_takeovers_text((string)$st->fetchColumn());
}
function gp_coach_takeovers_text($text) {
    $out = [];
    if (preg_match_all('/^[ \t]*([^<>\r\n]+?) takes over as coach of the ([^<>\r\n]+?)[ \t]*(?:<|\r|$)/mi', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $out[trim($x[2])] = trim($x[1]); }
    }
    return $out;
}

// The rule (see the header). Appends one entry to $log for anything but "same"; returns 'same', 'change' or 'report'.
// $source names where the name came from ('Standings' or 'game line'), $team the team name as printed this week.
function gp_coach_apply($conn, $league_id, $week_id, $franchise_id, $name, $team, $source, $takeovers, &$log) {
    $name = trim((string)$name);
    $wk = gp_coach_week($conn, $week_id);
    if ($name === '' || !$wk) {
        $log[] = ['kind' => 'report', 'team' => $team, 'week' => $wk['label'] ?? "week_id $week_id", 'name' => $name,
                  'why' => $name === '' ? "no coach name on the $source" : 'the week is not in weeks'];
        return 'report';
    }
    $res = gp_coach_resolve($conn, $league_id, $name);
    $cid = $res ? $res[0] : null;
    $ten = gp_coach_tenures($conn, $franchise_id);
    $cover = null; $open = null;
    foreach ($ten as $t) {
        if ($t['start_ord'] <= $wk['ord'] && ($t['end_ord'] === null || $t['end_ord'] >= $wk['ord'])) { $cover = $t; }
        if ($t['end_ord'] === null) { $open = $t; }
    }
    if ($cover && $cid !== null && $cover['coach_id'] === $cid) { return 'same'; }

    $tk = $takeovers[$team] ?? null;
    $confirmed = $tk !== null && ($tk === $name || (($r2 = gp_coach_resolve($conn, $league_id, $tk)) && $cid !== null && $r2[0] === $cid));
    $label = ['team' => $team, 'week' => $wk['label'], 'name' => $name, 'source' => $source,
              'takeover' => $tk === null ? 'no takeover line' : ($confirmed ? 'confirmed by the takeover line' : "the takeover line names $tk")];

    if ($open && $wk['ord'] > $open['start_ord'] && $cover && $cover['tenure_id'] === $open['tenure_id']) {
        $prev = gp_coach_prev_week($conn, $league_id, $wk['ord']);
        if ($prev === null) {
            $log[] = $label + ['kind' => 'report', 'why' => 'no earlier week in the league to close the tenure at'];
            return 'report';
        }
        $own = !$conn->inTransaction();
        if ($own) { $conn->beginTransaction(); }
        try {
            $created = false;
            if ($cid === null) {
                $conn->prepare("INSERT INTO coaches (league_id, name, active) VALUES (:l, :n, 1)")->execute([':l' => $league_id, ':n' => $name]);
                $cid = (int)$conn->lastInsertId(); $created = true;
            }
            $u = $conn->prepare("UPDATE franchise_coach_tenures SET end_week_id = :p WHERE tenure_id = :t AND end_week_id IS NULL");
            $u->execute([':p' => $prev, ':t' => $open['tenure_id']]);
            if ($u->rowCount() !== 1) { throw new RuntimeException("tenure {$open['tenure_id']} was not open"); }
            $conn->prepare("INSERT INTO franchise_coach_tenures (franchise_id, coach_id, start_week_id, end_week_id) VALUES (:f, :c, :w, NULL)")
                 ->execute([':f' => $franchise_id, ':c' => $cid, ':w' => $week_id]);
            if ($own) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) { $conn->rollBack(); }
            $log[] = $label + ['kind' => 'report', 'why' => 'the change was refused and nothing was written: ' . $e->getMessage()];
            return 'report';
        }
        $log[] = $label + ['kind' => 'change', 'old' => $open['name'], 'old_id' => $open['coach_id'], 'new_id' => $cid,
                           'created' => $created, 'franchise_id' => $franchise_id];
        return 'change';
    }
    $why = !$ten ? 'the franchise has no tenure'
         : (!$cover ? 'no tenure covers this week'
         : ($open && $wk['ord'] < $open['start_ord'] ? "an older week than the open tenure (from its own start): the tenure covering it names {$cover['name']}"
         : "the tenure covering this week names {$cover['name']}"));
    $log[] = $label + ['kind' => 'report', 'why' => $why . ($cid === null ? '; the name is new to the league' : '')];
    return 'report';
}

// The merge (Q7): coach $alias_id is a spelling of coach $into_id. Returns [ok, message].
function gp_coach_merge($conn, $alias_id, $into_id) {
    $alias_id = (int)$alias_id; $into_id = (int)$into_id;
    $st = $conn->prepare("SELECT coach_id, league_id, name, alias_of, id_user FROM coaches WHERE coach_id IN (:a, :b)");
    $st->execute([':a' => $alias_id, ':b' => $into_id]);
    $c = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $c[(int)$r['coach_id']] = $r; }
    if ($alias_id === $into_id || !isset($c[$alias_id], $c[$into_id])) { return [false, 'not two different coaches']; }
    if ($c[$alias_id]['league_id'] !== $c[$into_id]['league_id']) { return [false, 'the two coaches are in different leagues']; }
    if ($c[$alias_id]['alias_of'] !== null || $c[$into_id]['alias_of'] !== null) { return [false, 'one of the two is already a spelling of another coach']; }
    if ($c[$alias_id]['id_user'] !== null) { return [false, "{$c[$alias_id]['name']} is linked to a login: merge refused"]; }
    $own = !$conn->inTransaction();
    if ($own) { $conn->beginTransaction(); }
    try {
        $conn->prepare("UPDATE franchise_coach_tenures SET coach_id = :i WHERE coach_id = :a")->execute([':i' => $into_id, ':a' => $alias_id]);
        $conn->prepare("UPDATE coaches SET alias_of = :i WHERE alias_of = :a")->execute([':i' => $into_id, ':a' => $alias_id]);
        $conn->prepare("UPDATE coaches SET alias_of = :i WHERE coach_id = :a")->execute([':i' => $into_id, ':a' => $alias_id]);
        // join adjacent tenures of the merged coach at each franchise: B starts in the league week right after A ends
        $joined = 0;
        $fs = $conn->prepare("SELECT DISTINCT franchise_id FROM franchise_coach_tenures WHERE coach_id = :i");
        $fs->execute([':i' => $into_id]);
        foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $prev = null;
            foreach (gp_coach_tenures($conn, (int)$f) as $t) {
                if ($prev && $prev['coach_id'] === $into_id && $t['coach_id'] === $into_id && $prev['end_ord'] !== null) {
                    $wk = gp_coach_week($conn, $t['start_week_id']);
                    if ($wk && gp_coach_prev_week($conn, $wk['league_id'], $t['start_ord']) === (int)$prev['end_week_id']) {
                        $conn->prepare("UPDATE franchise_coach_tenures SET end_week_id = :e WHERE tenure_id = :p")
                             ->execute([':e' => $t['end_week_id'], ':p' => $prev['tenure_id']]);
                        $conn->prepare("DELETE FROM franchise_coach_tenures WHERE tenure_id = :t")->execute([':t' => $t['tenure_id']]);
                        $prev['end_week_id'] = $t['end_week_id']; $prev['end_ord'] = $t['end_ord']; $joined++;
                        continue;
                    }
                }
                $prev = $t;
            }
        }
        if ($own) { $conn->commit(); }
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) { $conn->rollBack(); }
        return [false, 'refused, nothing written: ' . $e->getMessage()];
    }
    return [true, "{$c[$alias_id]['name']} is now a spelling of {$c[$into_id]['name']}; $joined tenure(s) joined"];
}

// The merge button's POST, handled at the top of either page. Returns HTML (empty when there was no POST).
function gp_coach_handle_post($conn, $is_admin) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['gp_coach_action'] ?? '') !== 'merge') { return ''; }
    $tok = $_SESSION['gp_coach_token'] ?? '';
    if ($is_admin != 1) {
        [$ok, $msg] = [false, 'only the administrator can merge coaches'];
    } elseif ($tok === '' || !hash_equals($tok, (string)($_POST['gp_coach_token'] ?? ''))) {
        [$ok, $msg] = [false, 'the form has expired: reload the page and press the button again'];
    } else {
        [$ok, $msg] = gp_coach_merge($conn, $_POST['alias_id'] ?? 0, $_POST['into_id'] ?? 0);
    }
    return "<div class='w3-panel " . ($ok ? 'w3-pale-green' : 'w3-pale-red') . " w3-text-black w3-round-large'><p><strong>Coach merge:</strong> "
         . htmlspecialchars($msg) . "</p></div>";
}

// The "Coach changes" panel. $checked is how many teams were put to the rule.
function gp_coach_panel($log, $checked, $is_admin) {
    $changes = array_values(array_filter($log, fn($e) => $e['kind'] === 'change'));
    $reports = array_values(array_filter($log, fn($e) => $e['kind'] === 'report'));
    if (!$changes && !$reports) {
        return "<div class='w3-panel w3-pale-green w3-text-black w3-round-large'><p><strong>Coach changes:</strong> none ($checked teams checked).</p></div>";
    }
    if (empty($_SESSION['gp_coach_token'])) { $_SESSION['gp_coach_token'] = bin2hex(random_bytes(16)); }
    $h = "<div class='w3-panel w3-pale-yellow w3-text-black w3-round-large'><p><strong>Coach changes</strong> ($checked teams checked):</p>";
    if ($changes) {
        $h .= "<ul>";
        foreach ($changes as $e) {
            $h .= "<li>" . htmlspecialchars("{$e['team']}, {$e['week']}: {$e['old']} -> {$e['name']} (from the {$e['source']}; "
                . ($e['created'] ? 'a new coach' : 'an existing coach') . "; {$e['takeover']})");
            if ($is_admin == 1 && $e['created']) {
                $h .= " <form method='post' style='display:inline'>"
                    . "<input type='hidden' name='gp_coach_action' value='merge'>"
                    . "<input type='hidden' name='gp_coach_token' value='" . htmlspecialchars($_SESSION['gp_coach_token']) . "'>"
                    . "<input type='hidden' name='alias_id' value='" . (int)$e['new_id'] . "'>"
                    . "<input type='hidden' name='into_id' value='" . (int)$e['old_id'] . "'>"
                    . "<button type='submit'>" . htmlspecialchars("Same coach: merge {$e['name']} into {$e['old']}") . "</button></form>";
            }
            $h .= "</li>";
        }
        $h .= "</ul>";
    }
    if ($reports) {
        $h .= "<p><strong>Reported, nothing written</strong> (" . count($reports) . "):</p><ul>";
        foreach ($reports as $e) {
            $h .= "<li>" . htmlspecialchars("{$e['team']}, {$e['week']}: \"{$e['name']}\""
                . (isset($e['source']) ? " on the {$e['source']}" : '') . " - {$e['why']}" . (isset($e['takeover']) ? " ({$e['takeover']})" : '')) . "</li>";
        }
        $h .= "</ul>";
    }
    return $h . "</div>";
}

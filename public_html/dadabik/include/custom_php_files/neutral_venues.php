<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// neutral_venues.php · rev 003 · 24 Oct 2026 · role: page (administrator only; writes on whichever host it runs on) · authority: derived
// rev 003: the page saves on either host (H72; the direction of H73: gpbm.uk is the master of league data and dev takes a copy
//          by its refresh, Alan Q1 to Q6 of 23 Oct 2026 session5, schema.md s20). The GPBM_ENV 'dev' guard, the "Not saved:
//          venues are entered on dev and carried here by the up-sync" message, the "Read only here" panel and the "Saving is
//          on dev only" note are gone. Nothing else changed.
// rev 002: the administrator test is DaDaBIK's $current_user_is_administrator, as in game.php (Alan, 21 Oct 2026); rev 001
//          compared $current_id_group with 1. Nothing else changed.
// Item P18 (Alan, Q1 to Q11 of 21 Oct 2026 session3; GameplanPBM/20261021/session3/SESSION_20261021_3.md s2).
//
// ------------------------------------------------------------------
// WHAT THIS PAGE DOES
//
// Lists the neutral-site games of one league and season and gives each a venue drop-down, so a whole season is
// filled in with one Save. By default it lists only the games with no venue at all (neither games.venue_id nor
// game_types.default_venue_id); "all neutral games" lists every one, so a game's own venue can be changed.
//
// The choices (Alan, Q2 to Q4, Q8):
//   - a league with rows in team_venues (NFLAR): the venues of the team names that league holds in
//     franchise_identities that season. One venue per team name (Q3). A Super Bowl stadium that was no team's
//     home that season is not offered (Q4).
//   - any other league (NCAA5): every venue. The National Championship Game rotates (Q8).
//   - a game whose own venue is outside that list keeps it as a choice, marked, so a Save never clears it.
//
// The page writes on whichever host it runs on (rev 003, H72). gpbm.uk is the master of league data: a venue set
// there is kept, and dev takes a copy at its next refresh (schema.md s20). A venue set on dev stays on dev.
//
// The save is a GET, like extract_games.php's, carrying a one-time token held in the session: a refreshed or
// replayed save writes nothing. Each row is written only if it changed, and only if the game still holds the
// venue the form was drawn with (no overwriting an edit made meanwhile). All rows commit together or none do.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h3>Neutral venues</h3>";

// Administrator only. DaDaBIK's own page permission is the gate (zpbm_permissions, custom_page); this is the second.
// DaDaBIK's own global, as game.php checks it (HANDOVER-2026-08-13 sec.2): no hard-coded group id.
if ($current_user_is_administrator != 1) {
    echo "<p>This page is for the administrator.</p></div>";
    return;
}

$_nv_has_session = (session_status() === PHP_SESSION_ACTIVE);
if ($_nv_has_session && empty($_SESSION['gp_nv_token'])) {
    $_SESSION['gp_nv_token'] = bin2hex(random_bytes(16));
}
$_nv_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$_nv_page = (string)($_GET['id_static_page'] ?? '');

// ---------------- the leagues with neutral games ----------------
$_nv_stmt = $conn->prepare(
    "SELECT l.league_id, l.code, COUNT(*) AS neutral
     FROM games g JOIN weeks w ON w.week_id = g.week_id JOIN seasons s ON s.season_id = w.season_id
     JOIN leagues l ON l.league_id = s.league_id
     WHERE g.neutral_site = 1
     GROUP BY l.league_id, l.code ORDER BY l.code");
$_nv_stmt->execute();
$_nv_leagues = $_nv_stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$_nv_leagues) { echo "<p>No league has a neutral-site game.</p></div>"; return; }

$_nv_league = null;
foreach ($_nv_leagues as $l) { if ($l['code'] === ($_GET['league'] ?? '')) { $_nv_league = $l; } }
if ($_nv_league === null) {
    foreach ($_nv_leagues as $l) { if ($l['code'] === 'NFLAR') { $_nv_league = $l; } }
    if ($_nv_league === null) { $_nv_league = $_nv_leagues[0]; }
}
$_nv_lid = (int)$_nv_league['league_id'];
$_nv_show_all = (($_GET['show'] ?? '') === 'all');

// ---------------- that league's seasons with neutral games ----------------
$_nv_stmt = $conn->prepare(
    "SELECT s.season_id, s.year, COUNT(*) AS neutral,
            SUM(g.venue_id IS NULL AND gt.default_venue_id IS NULL) AS missing
     FROM games g JOIN game_types gt ON gt.game_type_id = g.game_type_id
     JOIN weeks w ON w.week_id = g.week_id JOIN seasons s ON s.season_id = w.season_id
     WHERE g.neutral_site = 1 AND s.league_id = :l
     GROUP BY s.season_id, s.year ORDER BY s.year");
$_nv_stmt->execute([':l' => $_nv_lid]);
$_nv_seasons = $_nv_stmt->fetchAll(PDO::FETCH_ASSOC);
$_nv_season = null;
foreach ($_nv_seasons as $s) { if ((string)$s['season_id'] === (string)($_GET['season'] ?? '')) { $_nv_season = $s; } }
if ($_nv_season === null) {
    foreach ($_nv_seasons as $s) { if ($_nv_season === null && (int)$s['missing'] > 0) { $_nv_season = $s; } }
    if ($_nv_season === null) { $_nv_season = end($_nv_seasons); }
}
$_nv_sid = (int)$_nv_season['season_id'];

// ---------------- the choices for this league and season ----------------
$_nv_stmt = $conn->prepare("SELECT COUNT(*) FROM team_venues WHERE league_id = :l");
$_nv_stmt->execute([':l' => $_nv_lid]);
$_nv_by_team = ((int)$_nv_stmt->fetchColumn() > 0);
$_nv_unmapped = [];
if ($_nv_by_team) {
    $_nv_stmt = $conn->prepare(
        "SELECT DISTINCT v.venue_id, v.label
         FROM team_venues tv JOIN venues v ON v.venue_id = tv.venue_id
         WHERE tv.league_id = :l
           AND tv.team_name IN (SELECT fi.team_name FROM franchise_identities fi JOIN weeks w ON w.week_id = fi.week_id
                                WHERE w.season_id = :s)
         ORDER BY v.label");
    $_nv_stmt->execute([':l' => $_nv_lid, ':s' => $_nv_sid]);
    $_nv_choices = $_nv_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    // a team name this season with no team_venues row: its stadium cannot be offered, so say so
    $_nv_stmt = $conn->prepare(
        "SELECT DISTINCT fi.team_name FROM franchise_identities fi JOIN weeks w ON w.week_id = fi.week_id
         WHERE w.season_id = :s
           AND NOT EXISTS (SELECT 1 FROM team_venues tv WHERE tv.league_id = :l AND tv.team_name = fi.team_name)
         ORDER BY fi.team_name");
    $_nv_stmt->execute([':l' => $_nv_lid, ':s' => $_nv_sid]);
    $_nv_unmapped = $_nv_stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $_nv_stmt = $conn->prepare("SELECT venue_id, label FROM venues ORDER BY label");
    $_nv_stmt->execute();
    $_nv_choices = $_nv_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ---------------- the games ----------------
$_nv_games_sql =
    "SELECT g.game_id, w.week_number, gt.name AS type_name, g.label, g.venue_id, gt.default_venue_id,
            vg.label AS own_label, vt.label AS type_label
     FROM games g JOIN game_types gt ON gt.game_type_id = g.game_type_id JOIN weeks w ON w.week_id = g.week_id
     LEFT JOIN venues vg ON vg.venue_id = g.venue_id LEFT JOIN venues vt ON vt.venue_id = gt.default_venue_id
     WHERE w.season_id = :s AND g.neutral_site = 1";
$_nv_load = function ($all) use ($conn, $_nv_games_sql, $_nv_sid) {
    $sql = $_nv_games_sql . ($all ? '' : ' AND g.venue_id IS NULL AND gt.default_venue_id IS NULL')
         . ' ORDER BY w.week_number, gt.game_type_id, g.game_id';
    $st = $conn->prepare($sql);
    $st->execute([':s' => $_nv_sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

// ---------------- the save ----------------
if (($_GET['save'] ?? '') === '1') {
    $_nv_msg = '';
    if (!$_nv_has_session || !hash_equals((string)$_SESSION['gp_nv_token'], (string)($_GET['token'] ?? ''))) {
        $_nv_msg = "Not saved: this form was saved already, or is out of date. Nothing was written; the list below is current.";
    } else {
        $_nv_want = is_array($_GET['v'] ?? null) ? $_GET['v'] : [];
        $_nv_orig = is_array($_GET['o'] ?? null) ? $_GET['o'] : [];
        $_nv_now = [];
        foreach ($_nv_load(true) as $r) { $_nv_now[(int)$r['game_id']] = $r; }
        $_nv_changes = []; $_nv_bad = [];
        foreach ($_nv_want as $gid => $val) {
            $gid = (int)$gid; $val = trim((string)$val);
            if (!isset($_nv_now[$gid])) { $_nv_bad[] = "game $gid is not a neutral game of this season"; continue; }
            $cur = $_nv_now[$gid]['venue_id'];
            $new = ($val === '') ? null : (int)$val;
            if ($new !== null && !isset($_nv_choices[$new]) && $new !== (int)$cur) {
                $_nv_bad[] = "game $gid: venue $new is not a choice this season"; continue;
            }
            $was = trim((string)($_nv_orig[$gid] ?? ''));
            $was = ($was === '') ? null : (int)$was;
            if ($new === ($cur === null ? null : (int)$cur)) { continue; }   // unchanged
            $_nv_changes[$gid] = [$was, $new];
        }
        if ($_nv_bad) {
            $_nv_msg = "Not saved: " . implode('; ', array_map($_nv_h, $_nv_bad)) . ". Nothing was written.";
        } elseif (!$_nv_changes) {
            $_nv_msg = "Nothing to save: no venue was changed.";
        } else {
            $up = $conn->prepare("UPDATE games SET venue_id = :new WHERE game_id = :gid AND neutral_site = 1 AND venue_id <=> :was");
            $done = 0; $stale = [];
            $conn->beginTransaction();
            try {
                foreach ($_nv_changes as $gid => [$was, $new]) {
                    $up->bindValue(':new', $new, $new === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $up->bindValue(':was', $was, $was === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $up->bindValue(':gid', $gid, PDO::PARAM_INT);
                    $up->execute();
                    if ($up->rowCount() === 1) { $done++; } else { $stale[] = $gid; }
                }
                if ($stale) {
                    $conn->rollBack();
                    $_nv_msg = "Not saved: game(s) " . $_nv_h(implode(', ', $stale)) . " changed since this form was drawn. Nothing was written; the list below is current.";
                } else {
                    $conn->commit();
                    $_SESSION['gp_nv_token'] = bin2hex(random_bytes(16));   // a refresh of this address now writes nothing
                    $_nv_msg = "Saved: $done game(s).";
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $_nv_msg = "Not saved: the database refused the change. Nothing was written.";
            }
        }
    }
    echo "<p class='w3-panel w3-pale-yellow w3-text-black'>" . $_nv_msg . "</p>";
}

$_nv_games = $_nv_load($_nv_show_all);

// ---------------- the selectors ----------------
$_nv_base = function () use ($_nv_h, $_nv_page) {
    return "<input type='hidden' name='function' value='show_static_page'>"
         . "<input type='hidden' name='id_static_page' value='" . $_nv_h($_nv_page) . "'>";
};
echo "<form method='get'>" . $_nv_base();
echo "League <select name='league' onchange='this.form.season.value=\"\"; this.form.submit()'>";
foreach ($_nv_leagues as $l) {
    $sel = ($l['code'] === $_nv_league['code']) ? ' selected' : '';
    echo "<option value='" . $_nv_h($l['code']) . "'$sel>" . $_nv_h($l['code']) . "</option>";
}
echo "</select> Season <select name='season' onchange='this.form.submit()'>";
foreach ($_nv_seasons as $s) {
    $sel = ((int)$s['season_id'] === $_nv_sid) ? ' selected' : '';
    $tag = ((int)$s['missing'] > 0) ? " ({$s['missing']} without a venue)" : '';
    echo "<option value='" . (int)$s['season_id'] . "'$sel>" . $_nv_h($s['year'] . $tag) . "</option>";
}
echo "</select> Show <select name='show' onchange='this.form.submit()'>";
echo "<option value='missing'" . ($_nv_show_all ? '' : ' selected') . ">games without a venue</option>";
echo "<option value='all'" . ($_nv_show_all ? ' selected' : '') . ">all neutral games</option>";
echo "</select></form>";

$_nv_left = 0; foreach ($_nv_seasons as $s) { $_nv_left += (int)$s['missing']; }
echo "<p>" . $_nv_h($_nv_league['code']) . ": " . number_format($_nv_left) . " neutral game(s) without a venue, in "
   . count(array_filter($_nv_seasons, fn($s) => (int)$s['missing'] > 0)) . " season(s). "
   . ($_nv_by_team ? count($_nv_choices) . " venue(s) are this season's team stadiums." : count($_nv_choices) . " venue(s) to choose from.")
   . "</p>";
if ($_nv_unmapped) {
    echo "<p class='w3-panel w3-pale-red w3-text-black'>No stadium is set for: " . $_nv_h(implode(', ', $_nv_unmapped))
       . ". Add a row for each in Team venues to offer it.</p>";
}
// ---------------- the list ----------------
if (!$_nv_games) {
    echo "<p>No " . ($_nv_show_all ? '' : 'unset ') . "neutral game in " . $_nv_h($_nv_season['year']) . ".</p></div>";
    return;
}
$_nv_opts = function ($cur, $own_label) use ($_nv_choices, $_nv_h) {
    $o = "<option value=''>-- none --</option>";
    if ($cur !== null && !isset($_nv_choices[(int)$cur])) {
        $o .= "<option value='" . (int)$cur . "' selected>" . $_nv_h($own_label) . " (not a team stadium this season)</option>";
    }
    foreach ($_nv_choices as $id => $label) {
        $sel = ($cur !== null && (int)$cur === (int)$id) ? ' selected' : '';
        $o .= "<option value='" . (int)$id . "'$sel>" . $_nv_h($label) . "</option>";
    }
    return $o;
};

echo "<form method='get' id='gp_nv_form'>" . $_nv_base();
echo "<input type='hidden' name='league' value='" . $_nv_h($_nv_league['code']) . "'>";
echo "<input type='hidden' name='season' value='" . $_nv_sid . "'>";
echo "<input type='hidden' name='show' value='" . ($_nv_show_all ? 'all' : 'missing') . "'>";
echo "<input type='hidden' name='save' value='1'>";
echo "<input type='hidden' name='token' value='" . $_nv_h($_nv_has_session ? $_SESSION['gp_nv_token'] : '') . "'>";
echo "<p>Set every game below to <select id='gp_nv_all'>" . $_nv_opts(null, '') . "</select> "
   . "<button type='button' class='w3-button w3-small w3-white' onclick='gpNvAll()'>Apply to the list</button> "
   . "(fills the drop-downs only; nothing is saved until Save)</p>";
echo "<table class='w3-table w3-bordered w3-small' style='background:#fff;color:#000'>";
echo "<tr><th>Wk</th><th>Type</th><th>Game</th><th>Type's default</th><th>Venue</th></tr>";
foreach ($_nv_games as $r) {
    $gid = (int)$r['game_id'];
    $cur = ($r['venue_id'] === null) ? null : (int)$r['venue_id'];
    echo "<tr><td>" . (int)$r['week_number'] . "</td><td>" . $_nv_h($r['type_name']) . "</td>"
       . "<td>" . $_nv_h($r['label']) . "</td><td>" . $_nv_h($r['type_label'] ?? '') . "</td>"
       . "<td><input type='hidden' name='o[$gid]' value='" . ($cur === null ? '' : $cur) . "'>"
       . "<select class='gp_nv_pick' name='v[$gid]'>" . $_nv_opts($cur, $r['own_label']) . "</select></td></tr>";
}
echo "</table>";
echo "<p><button type='submit' class='w3-button w3-white'>Save</button></p>";
echo "</form>";
echo "<script>function gpNvAll(){var v=document.getElementById('gp_nv_all').value;"
   . "document.querySelectorAll('#gp_nv_form select.gp_nv_pick').forEach(function(s){"
   . "for(var i=0;i<s.options.length;i++){if(s.options[i].value===v){s.value=v;break;}}});}</script>";
echo "</div>";
?>

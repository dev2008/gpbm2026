<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// H26 (18-Aug-2026): no error_handler.php include here. DaDaBIK's bootstrap has already
// included it before any custom page runs -- observed on this page, not assumed. See H26.
// A5 (17-Aug-2026): week-grain identity resolution + the internal display toggles. Used by
// fact_highest_scoring_game(), which rendered games.label straight to the front page.
include_once(__DIR__ . '/gp_identity.php');
// H26: display_errors is inherited from php.ini per environment -- not set in page code.

// ------------------------------------------------------------------
// Home page. Built from g_home.php (uploaded for reference), with real
// changes rather than a straight port:
//
//   1. No more /2 division for game counts -- games is already one row
//      per game, unlike f_games which had one row per team per game.
//   2. League-agnostic: loops over whatever's in `leagues` rather than
//      hardcoding "College"/"Pro" via LIKE 'NC%'/'NF%'.
//   3. Uses w3-theme-* (w3-theme-d5, w3-theme, w3-theme-l3/l4), same as
//      the old page, kept applied to the WHOLE page rather than just
//      the top banner -- confirmed this Dadabik install has a theme
//      file loaded, so these resolve to real colours correctly. The
//      actual low-contrast bug in the old page was narrower than "the
//      theme doesn't work": w3-text-white gets set once on the outer
//      wrapper and never reset before the table, which uses the LIGHT
//      tier (w3-theme-l3/l4) for its rows -- white text on a light
//      background. Fixed by being explicit about text colour on every
//      light-background element individually (the table rows, the
//      highlighted-fact box), rather than closing the dark wrapper
//      early and losing the theme for the rest of the page.
//   4. No "Quick Links" section -- Dadabik's own left-hand menu already
//      lists Current Standings and Teams, so a duplicate set of links
//      here was redundant.
//   5. A random highlighted stat, picked from a small pool of "fact"
//      generators each time the page loads -- see the bottom of the
//      file. Easy to add more to the pool later.
// ------------------------------------------------------------------

// $current_user (DaDaBIK's own global -- confirmed via DaDaBIK's documented custom-code
// globals) rather than reading the session array directly. Falsy check (?:), not ??, since
// $current_user is presumably always defined by DaDaBIK in this context but may be an empty
// string for a guest, which ?? alone wouldn't catch.
$_cp_myname = $current_user ?: 'there';

// Public/not-logged-in access is group 3 in this Dadabik install (confirmed directly). Now
// reads DaDaBIK's own $current_id_group global rather than
// $_SESSION['logged_user_infos_ar']['id_group'] directly -- same value, documented API instead
// of inferred session-array shape. Unlike game.php's admin check, this one still names a
// specific group id -- DaDaBIK's globals give an $current_user_is_administrator flag but no
// equivalent "$current_user_is_public" one, so there's no way to ask this question without
// naming group 3 somewhere. There's only ever one public user by design, so checking the group
// rather than a specific username is still both simpler and correct regardless of what that
// account happens to be named or whether it's ever renamed.
$_cp_is_public_user = ($current_id_group == 3);

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
echo "<h1>&nbsp;Welcome to the Gameplan PBM site</h1>";

// Same dev/pre-prod/production distinction as the old page, kept for whenever a separate
// dev or staging database exists -- currently always falls through to the generic branch,
// since gplan_pbm matches neither prefix. The dev/pre-prod indicator is worth keeping even
// for a public visitor (useful, non-personal context); the generic "Welcome, X" line is
// suppressed entirely for a public/dummy user instead, rather than showing a name that
// doesn't mean anything.
$_cp_db_prefix = substr($db_name ?? '', 0, 3);
if ($_cp_db_prefix === 'dev') {
    $who = $_cp_is_public_user ? 'You are' : htmlspecialchars($_cp_myname) . ' you are';
    echo "<div class='w3-panel w3-theme w3-text-white w3-round-large'>";
    echo "<h2>$who logged on to ** DEV **</h2>";
    echo "</div>";
} elseif ($_cp_db_prefix === 'pre') {
    $who = $_cp_is_public_user ? 'You are' : htmlspecialchars($_cp_myname) . ' you are';
    echo "<div class='w3-panel w3-theme w3-text-white w3-round-large'>";
    echo "<h2>$who logged on to ** PRE-PROD **</h2>";
    echo "</div>";
} elseif (!$_cp_is_public_user) {
    echo "<div class='w3-panel w3-theme w3-text-white w3-round-large'>";
    echo "<h2>Welcome, " . htmlspecialchars($_cp_myname) . "</h2>";
    echo "</div>";
}

echo "<h1>Gameplan Football</h1>";

// -------------------- Game counts + latest data, per league --------------------
$_cp_sql = "SELECT l.league_id, l.code, l.sport_type, COUNT(g.game_id) AS game_count, MAX(w.week_id) AS latest_week_id
            FROM leagues l
            JOIN seasons s ON s.league_id = l.league_id
            JOIN weeks w ON w.season_id = s.season_id
            JOIN games g ON g.week_id = w.week_id
            GROUP BY l.league_id, l.code, l.sport_type
            ORDER BY l.code";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute();
$_cp_league_stats = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

$_cp_summary_parts = [];
foreach ($_cp_league_stats as $ls) {
    $word = ($ls['sport_type'] === 'college') ? 'College' : 'Pro';
    $_cp_summary_parts[] = "<i>" . number_format($ls['game_count']) . "</i> $word";
}
echo "<p>We have " . implode(' and ', $_cp_summary_parts) . " games in our database, the latest updates are:-</p>";

// H45 (3 Oct 2026, Alan's NZ3): a Current Champions column. width:auto and nowrap rather than
// width:40%: the champion's name and its run of titles need the room, and no cell should wrap
// (the same reasoning as current_standings.php, H40).
echo "<table style='width:auto;white-space:nowrap' class='w3-table w3-striped w3-bordered'>";
echo "<tr class='w3-blue w3-text-white'><th>League</th><th>Latest Week</th><th>Current Champions</th></tr>";
$i = 0;
foreach ($_cp_league_stats as $ls) {
    $row_class = (($i % 2) == 1) ? 'w3-white w3-text-black' : 'w3-light-grey w3-text-black';
    $_cp_stmt2 = $conn->prepare(
        "SELECT s.year, w.week_number FROM weeks w JOIN seasons s ON s.season_id = w.season_id WHERE w.week_id = :wid"
    );
    $_cp_stmt2->bindParam(':wid', $ls['latest_week_id']);
    $_cp_stmt2->execute();
    $_cp_week_row = $_cp_stmt2->fetch(PDO::FETCH_ASSOC);
    $latest_label = $_cp_week_row ? "{$_cp_week_row['year']} Wk {$_cp_week_row['week_number']}" : '-';
    $_cp_champ = home_current_champion($conn, $ls['league_id']);
    $_cp_champ_text = '-';
    if ($_cp_champ) {
        $_cp_champ_text = htmlspecialchars($_cp_champ['name']);
        if ($_cp_champ['run'] >= 2) {
            $_cp_champ_text .= " ({$_cp_champ['run']} consecutive titles)";
        }
    }
    echo "<tr class='$row_class'><td>" . htmlspecialchars($ls['code']) . "</td><td>"
       . htmlspecialchars($latest_label) . "</td><td>$_cp_champ_text</td></tr>";
    $i++;
}
echo "</table><br>";

// -------------------- Random highlighted stat --------------------
$_cp_fact = pick_random_fact($conn);
if ($_cp_fact) {
    echo "<div class='w3-panel w3-pale-yellow w3-text-black w3-leftbar w3-border-orange w3-round-large'>";
    echo "<h3>Did you know?</h3>";
    echo "<p>$_cp_fact</p>";
    echo "</div>";
}

echo "</div>";  // closes the outer w3-theme-d5 wrapper opened at the very top of the page

// --------------------------------------------------------------
// Current champions (H45, 3 Oct 2026)
// --------------------------------------------------------------

// The winner of the league's most recent championship game, and how many seasons in a row that
// franchise has won it. Read from the GAMES, not from franchise_honors: the honours can lag the
// games (NCAA5 2038's final was loaded with no LEAGUE_WINNER honour written, H46), and a played
// final is the record of who won. The championship game is found by its game type's name
// ('Superbowl' for NFLAR, 'National Championship Game' for NCAA5, as game_types holds them,
// measured 3 Oct 2026) rather than by id, so the page reads as what it means.
//
// Named by the identity the franchise played under in that game's week (GP_IDENT_GAME_TEAMS,
// gp_identity.php), not franchises.label, which is the slot's present-day name (schema.md s12).
//
// The run counts back season by season while the same franchise slot, under the same name, won
// the final. A season
// with no final on record, or a final without a winner, ends the run: a gap is not "consecutive"
// (the same rule as division_current_streak() on current_standings.php). Alan, Q4 and Q5 of
// 3 Oct 2026: the winner of the latest final; "(N consecutive titles)" shown only from 2.
function home_current_champion($conn, $league_id) {
    $sql = "SELECT s.year, g.home_franchise_id, g.away_franchise_id, g.home_score, g.away_score,
                   hf.label AS home_label, af.label AS away_label,
                   hi.team_name AS home_ident, ai.team_name AS away_ident
            FROM games g
            JOIN game_types gt ON gt.game_type_id = g.game_type_id
            JOIN weeks w ON w.week_id = g.week_id
            JOIN seasons s ON s.season_id = w.season_id
            JOIN franchises hf ON hf.franchise_id = g.home_franchise_id
            JOIN franchises af ON af.franchise_id = g.away_franchise_id
            " . gp_ident_join('hi', 'g.home_franchise_id', 'g.week_id') . "
            " . gp_ident_join('ai', 'g.away_franchise_id', 'g.week_id') . "
            WHERE s.league_id = :league_id
              AND gt.name IN ('Superbowl', 'National Championship Game')
              AND g.home_score IS NOT NULL AND g.away_score IS NOT NULL
            ORDER BY s.year DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':league_id', $league_id);
    $stmt->execute();
    $finals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($finals)) {
        return null;
    }

    $winner = static function ($f) {
        if ((int)$f['home_score'] > (int)$f['away_score']) {
            return ['id' => (int)$f['home_franchise_id'], 'ident' => $f['home_ident'], 'label' => $f['home_label']];
        }
        if ((int)$f['away_score'] > (int)$f['home_score']) {
            return ['id' => (int)$f['away_franchise_id'], 'ident' => $f['away_ident'], 'label' => $f['away_label']];
        }
        return null;   // a tied final names no champion
    };

    $champ = $winner($finals[0]);
    if (!$champ) {
        return null;
    }
    $name = gp_ident_name(GP_IDENT_GAME_TEAMS, $champ['ident'], $champ['label']);
    // A run needs the same slot AND the same name: a slot that won under one identity and then
    // another (franchise 2013 won as Arizona Cardinals in 2007 and New York Giants in 2010) is
    // not one team's run of titles.
    $run = 1;
    $prev_year = (int)$finals[0]['year'];
    for ($i = 1; $i < count($finals); $i++) {
        $w = $winner($finals[$i]);
        if ((int)$finals[$i]['year'] !== $prev_year - 1 || !$w || $w['id'] !== $champ['id']
            || gp_ident_name(GP_IDENT_GAME_TEAMS, $w['ident'], $w['label']) !== $name) {
            break;
        }
        $run++;
        $prev_year = (int)$finals[$i]['year'];
    }
    return ['name' => $name, 'run' => $run];
}

// --------------------------------------------------------------
// Random stat pool
// --------------------------------------------------------------

// Tries facts in random order until one actually returns something -- a fact generator can
// return null if its underlying data happens to be empty (e.g. no games loaded yet), and the
// page should still show SOMETHING rather than a blank section or an empty "Did you know?" box.
function pick_random_fact($conn) {
    $generators = [
        'fact_longest_win_streak',
        'fact_longest_coaching_tenure',
        'fact_most_league_championships',
        'fact_longest_division_streak',
        'fact_highest_scoring_game',
    ];
    shuffle($generators);
    foreach ($generators as $fn) {
        $result = $fn($conn);
        if ($result) {
            return $result;
        }
    }
    return null;
}

function fact_longest_win_streak($conn) {
    $sql = "SELECT franchise_label, streak, league_code FROM v_current_standings
            WHERE streak LIKE 'W%'
            ORDER BY CAST(SUBSTRING(streak, 2) AS UNSIGNED) DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $wins = substr($row['streak'], 1);
    return "The " . htmlspecialchars($row['franchise_label']) . " (" . htmlspecialchars($row['league_code'])
         . ") are on the longest active winning streak right now -- $wins games in a row.";
}

function fact_longest_coaching_tenure($conn) {
    $sql = "SELECT c.name, f.label, s.year, l.code AS league_code
            FROM franchise_coach_tenures fct
            JOIN coaches c ON c.coach_id = fct.coach_id
            JOIN franchises f ON f.franchise_id = fct.franchise_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN weeks w ON w.week_id = fct.start_week_id
            JOIN seasons s ON s.season_id = w.season_id
            WHERE fct.end_week_id IS NULL
            ORDER BY fct.start_week_id ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return htmlspecialchars($row['name']) . " has been coaching the " . htmlspecialchars($row['label'])
         . " (" . htmlspecialchars($row['league_code']) . ") continuously since {$row['year']} -- the longest current tenure of any coach in the league.";
}

function fact_most_league_championships($conn) {
    $sql = "SELECT f.label, l.code AS league_code, COUNT(*) AS titles
            FROM franchise_honors fh
            JOIN honor_types ht ON ht.honor_type_id = fh.honor_type_id
            JOIN franchises f ON f.franchise_id = fh.franchise_id
            JOIN leagues l ON l.league_id = f.league_id
            WHERE ht.code = 'LEAGUE_WINNER'
            GROUP BY fh.franchise_id, f.label, l.code
            ORDER BY titles DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $word = ($row['league_code'] === 'NCAA5') ? 'National Championships' : 'Superbowls';
    return "The " . htmlspecialchars($row['label']) . " have won more $word than any other "
         . htmlspecialchars($row['league_code']) . " franchise -- {$row['titles']} titles.";
}

function fact_longest_division_streak($conn) {
    $sql = "SELECT f.label, f.division, l.code AS league_code, s.year
            FROM franchise_honors fh
            JOIN honor_types ht ON ht.honor_type_id = fh.honor_type_id
            JOIN franchises f ON f.franchise_id = fh.franchise_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN seasons s ON s.season_id = fh.season_id
            WHERE ht.code = 'DIVISION_CHAMPION'
            ORDER BY f.division, s.year DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return null;
    }

    $by_division = [];
    foreach ($rows as $r) {
        $by_division[$r['division']][] = $r;
    }

    $best = null;
    foreach ($by_division as $division => $entries) {
        $champion = $entries[0]['label'];
        $streak = 1;
        $prev_year = (int)$entries[0]['year'];
        for ($i = 1; $i < count($entries); $i++) {
            $this_year = (int)$entries[$i]['year'];
            if ($entries[$i]['label'] === $champion && $this_year === $prev_year - 1) {
                $streak++;
                $prev_year = $this_year;
            } else {
                break;
            }
        }
        if ($streak > 1 && ($best === null || $streak > $best['streak'])) {
            $best = ['label' => $champion, 'division' => $division, 'league_code' => $entries[0]['league_code'], 'streak' => $streak];
        }
    }

    if (!$best) {
        return null;
    }
    return "The " . htmlspecialchars($best['label']) . " have won the " . htmlspecialchars($best['division'])
         . " ({$best['league_code']}) for {$best['streak']} consecutive seasons -- the longest active division streak in the league.";
}

function fact_highest_scoring_game($conn) {
    // A5 (17-Aug-2026): this was "SELECT label ... FROM games", i.e. the stored games.label
    // rendered straight to the front page. That column was built from franchises.label and so
    // named the slot's present-day identity, wrong for 4,003 of 10,035 games. The title is now
    // composed at week grain like every other reader-facing name; nothing here depends on the
    // stored column any more.
    $sql = "SELECT g.label, g.home_score, g.away_score,
                   l.code AS league_code, s.year AS season_year, w.week_number,
                   hf.label AS home_label, af.label AS away_label,
                   hi.team_name AS home_ident, ai.team_name AS away_ident
            FROM games g
            JOIN weeks w ON w.week_id = g.week_id
            JOIN seasons s ON s.season_id = w.season_id
            JOIN leagues l ON l.league_id = s.league_id
            JOIN franchises hf ON hf.franchise_id = g.home_franchise_id
            JOIN franchises af ON af.franchise_id = g.away_franchise_id
            " . gp_ident_join('hi', 'g.home_franchise_id', 'g.week_id') . "
            " . gp_ident_join('ai', 'g.away_franchise_id', 'g.week_id') . "
            WHERE g.home_score IS NOT NULL AND g.away_score IS NOT NULL
            ORDER BY (g.home_score + g.away_score) DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $total = $row['home_score'] + $row['away_score'];
    return "The highest-scoring game on record: " . htmlspecialchars(gp_game_title($row))
         . " -- $total combined points.";
}
?>

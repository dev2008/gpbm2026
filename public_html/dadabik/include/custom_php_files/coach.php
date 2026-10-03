<?php
// don't delete this line, this must be the first line of your code
if(!defined('custom_page_from_inclusion')) { die(); }
// H26 (18-Aug-2026): no error_handler.php include and no ini_set('display_errors') here.
// DaDaBIK's bootstrap includes error_handler.php before any custom page runs (measured),
// and display_errors is a php.ini setting per environment. See H26.

// ------------------------------------------------------------------
// Coach View -- shows one coach's career record, honours, and current
// franchises. Reconciles two genuinely different things, not one:
//
//   1. Browse ANY coach's record -- most coaches (historical or
//      current) have no website login at all, and never will. This is
//      the "show all coaches" goal, and it's keyed on coaches.coach_id
//      directly -- no id_user required.
//   2. Show the COMBINED record for a coach who's linked their login
//      (coaches.id_user) -- the same real person can hold two separate
//      `coaches` rows at once (one per league, e.g. Alan Milnes: NFLAR
//      coach_id 5 and NCAA5 coach_id 160), and only id_user reliably
//      ties those together as one person. This is the original design.
//
// Earlier version conflated the two -- routed and rendered everything
// through id_user only, which meant a coach with no linked login (i.e.
// nearly everyone) was invisible to this page entirely, and the "browse
// every active coach" dropdown was a list of one. See conversation.
//
// The fix: resolve down to a SET of coach_id values regardless of which
// path got you there (id_user -> every coaches row sharing that login;
// coach_id -> just that one row), then every downstream query (franchise
// list, career record, honours) filters on that set uniformly. Linked
// coaches get the multi-league combined view they always did; unlinked
// coaches get a single-league view of their own, rather than nothing.
//
// coaches.id_user is deliberately NOT UNIQUE, against DaDaBIK's own User
// Entities recommendation -- confirmed in new_schema.sql's own comment,
// and in the real data (Alan Milnes' two rows). It was never meant to be
// the only way into this page, just the way to unify multi-league data
// for whoever happens to have it set.
//
// Field names below (first_name_user/last_name_user/username_user)
// confirmed against this install's actual users table -- it's
// zpbm_users, not DaDaBIK's default dadabik_users (custom table prefix,
// per DESCRIBE zpbm_users).
//
// Which team ownership a coach has is already effectively public
// information (team_game_stats.coach_name is shown, unguarded, on every
// game page and the team page's Coach: row) -- so this page carries no
// new privacy exposure, and isn't gated. That's also why it's fine to
// let anyone browse anyone's record via the dropdown below, linked or
// not -- nothing here is being newly exposed, just made reachable.
//
// A dropdown of every coach, past and present, is ALWAYS shown, regardless
// of who's looking or whether a specific coach ends up displayed below
// it. Deliberately NOT limited to currently-active coaches -- a current
// coach is already discoverable via their team page (team.php's Coach:
// row links here), so scoping the dropdown to current-only would just
// duplicate an existing path while leaving historical coaches with no
// way to be found at all. The dropdown's real value is surfacing the
// ones nothing else links to.
// ------------------------------------------------------------------

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";

// team.php's registered id_static_page (confirmed value, see conversation).
define('TEAM_PAGE_STATIC_ID', 4);

function build_team_link($league_code, $franchise_id) {
    return htmlspecialchars(
        'index.php?function=show_static_page&id_static_page=' . TEAM_PAGE_STATIC_ID
        . '&league=' . urlencode($league_code) . '&franchise=' . urlencode($franchise_id)
    );
}

// Builds a "?,?,?" placeholder string for a positional-parameter IN clause -- PDO has no
// native array-binding for IN, this is the standard workaround. Used for every query below
// that filters on a set of coach_id values, whether that set has one member or several.
function in_placeholders($count) {
    return implode(',', array_fill(0, max($count, 1), '?'));
}

// Best-effort display name -- try first+last, fall back to username, fall back to a bare ID.
// Only meaningful for a LINKED coach (resolved via zpbm_users) -- an unlinked coach's display
// name is just coaches.name directly, see identity resolution below.
function resolve_display_name($first, $last, $username, $user_id) {
    $first = trim($first ?? '');
    $last = trim($last ?? '');
    if ($first || $last) {
        return trim("$first $last");
    }
    if (!empty($username)) {
        return $username;
    }
    return "User #$user_id";
}

// -------------------- All coaches, past and present (linked + unlinked, deduped) --------------------
// Deliberately NOT scoped to currently-open tenures -- a current coach is already discoverable
// via their team page (team.php's Coach: row links here); a historical coach has no other path
// to being found at all except knowing their internal coach_id, which nobody would ever guess.
// The dropdown's actual value is surfacing the ones with no other way in, not duplicating a
// discovery path that already exists. (Started open-tenures-only; widened after recognising
// that was backwards -- see conversation.)
//
// Linked coaches (id_user set) are grouped by id_user. Unlinked coaches are grouped by NAME,
// not coach_id -- a coach active under the same name in both leagues (no login) should be
// treated as one person, not two, the same way a linked coach's two `coaches` rows already are
// via id_user. Confirmed low-risk directly against the real legacy coach-name list (165
// distinct names across both leagues) -- no ambiguous/collision-prone names in it, though one
// real oddity remains unresolved: combined-coach entries like "Alan & Gordon Milnes" don't get
// unified with the solo entries for the same people -- accepted as an explicit, known gap, not
// something this aggregation tries to solve. (A second oddity from that same check, "Kate & T.J.
// Whittingham" vs. "Kate/T.J. Whittingham" as two spellings of one pairing, has since been
// resolved directly in the data -- merged to one canonical `coaches` row, not worked around
// here.)
$_cp_sql = "SELECT c.coach_id, c.name AS coach_name, c.id_user
            FROM coaches c
            JOIN franchise_coach_tenures fct ON fct.coach_id = c.coach_id";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute();
$_cp_all_coach_rows = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

$_cp_linked_ids = [];        // id_user => true (dedupe via array keys)
$_cp_unlinked_by_name = [];  // coaches.name => a representative coach_id (dedupe by name)
foreach ($_cp_all_coach_rows as $row) {
    if ($row['id_user']) {
        $_cp_linked_ids[(int)$row['id_user']] = true;
    } elseif (!isset($_cp_unlinked_by_name[$row['coach_name']])) {
        // First one seen wins as the representative -- doesn't matter which, since selecting
        // it expands to every matching unlinked row by name anyway, below.
        $_cp_unlinked_by_name[$row['coach_name']] = (int)$row['coach_id'];
    }
}

// -------------------- Identity resolution --------------------
// Three ways in, checked in this order: the dropdown's own prefixed value (?who=uNNN or
// ?who=cNNN), a direct legacy-style ?user=/?coach= link, then session fallback for a real
// logged-in user (never the public/guest account -- group 3, confirmed elsewhere in this app,
// home.php/game.php -- since that was never meant to represent a specific coach).
$_cp_id_user = 0;
$_cp_coach_id = 0;

if (isset($_GET['who']) && preg_match('/^([uc])(\d+)$/', $_GET['who'], $_cp_who_m)) {
    if ($_cp_who_m[1] === 'u') {
        $_cp_id_user = (int)$_cp_who_m[2];
    } else {
        $_cp_coach_id = (int)$_cp_who_m[2];
    }
} elseif (isset($_GET['user'])) {
    $_cp_id_user = (int)$_GET['user'];
} elseif (isset($_GET['coach'])) {
    $_cp_coach_id = (int)$_GET['coach'];
} elseif (($current_id_group ?? 3) != 3) {
    $_cp_id_user = (int)($_SESSION['logged_user_infos_ar']['id_user'] ?? 0);
}

// A ?coach=/?who=cNNN value might actually point at a LINKED coach -- e.g. current_standings.php
// links to "whoever currently holds this franchise" without knowing in advance whether that
// person has a login. If it turns out they do, convert to the id_user path right here, before
// resolution runs -- the unlinked-coach path below explicitly filters id_user IS NULL, so
// without this conversion a linked coach's own coach_id would resolve to zero rows and
// incorrectly show "not found," even though their data is right there.
if ($_cp_coach_id) {
    $_cp_stmt = $conn->prepare("SELECT id_user FROM coaches WHERE coach_id = :cid");
    $_cp_stmt->bindParam(':cid', $_cp_coach_id);
    $_cp_stmt->execute();
    $_cp_linked_check = $_cp_stmt->fetchColumn();
    if ($_cp_linked_check) {
        $_cp_id_user = (int)$_cp_linked_check;
        $_cp_coach_id = 0;
    }
}

// -------------------- Resolve to a set of coach_id values + a display name --------------------
$_cp_coach_ids = [];
$_cp_coach_display = '';

if ($_cp_id_user) {
    // Every coaches row sharing this login, across both leagues -- a verified signal, kept
    // strictly separate from the name-based aggregation below. A name collision with a linked
    // coach must never pull in that person's real, verified data via a name match.
    $_cp_stmt = $conn->prepare("SELECT coach_id FROM coaches WHERE id_user = :uid");
    $_cp_stmt->bindParam(':uid', $_cp_id_user);
    $_cp_stmt->execute();
    $_cp_coach_ids = array_map('intval', $_cp_stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($_cp_coach_ids) {
        // get_record_details()'s 4th param (TRUE) makes it return FALSE instead of throwing on
        // no match, rather than a raw query here -- this table belongs to DaDaBIK itself, not
        // this project, so going through the Custom Code API (documented, stable interface)
        // rather than assuming exact column names/types in a hand-written SELECT.
        $_cp_user = ddb_api::get_record_details('zpbm_users', 'id_user', $_cp_id_user, true);
        $_cp_coach_display = $_cp_user
            ? resolve_display_name($_cp_user['first_name_user'] ?? null, $_cp_user['last_name_user'] ?? null, $_cp_user['username_user'] ?? null, $_cp_id_user)
            : "User #$_cp_id_user";
    }
} elseif ($_cp_coach_id) {
    // An unlinked coach -- resolve their name, then pull in every OTHER unlinked row (any
    // league, id_user IS NULL) sharing that exact name too, not just this one row. Excludes any
    // row that HAS an id_user -- see above, verified logins never get folded into a name match.
    // Not scoped to currently-active tenures here -- same reasoning as the id_user case above:
    // a career record should span every tenure this person's held, not just the open one.
    $_cp_stmt = $conn->prepare("SELECT coach_id, name FROM coaches WHERE coach_id = :cid");
    $_cp_stmt->bindParam(':cid', $_cp_coach_id);
    $_cp_stmt->execute();
    $_cp_coach_row = $_cp_stmt->fetch(PDO::FETCH_ASSOC);
    if ($_cp_coach_row) {
        $_cp_coach_display = $_cp_coach_row['name'];
        $_cp_stmt = $conn->prepare("SELECT coach_id FROM coaches WHERE name = :name AND id_user IS NULL");
        $_cp_stmt->bindParam(':name', $_cp_coach_display);
        $_cp_stmt->execute();
        $_cp_coach_ids = array_map('intval', $_cp_stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

// -------------------- Dropdown (always shown, built after resolution above) --------------------
// Built after identity resolution, not before -- so the "currently selected" highlight can use
// the resolved display name to find its representative dropdown entry, rather than the raw
// clicked coach_id, which might not be the same row the dropdown itself uses to represent that
// name (see $_cp_unlinked_by_name above -- whichever row was seen first becomes the
// representative, and that's not guaranteed to be the one actually clicked).
$_cp_dropdown = []; // 'uNNN'/'cNNN' => display name
if ($_cp_linked_ids) {
    $_cp_ids = array_keys($_cp_linked_ids);
    $_cp_sql = "SELECT id_user, first_name_user, last_name_user, username_user FROM zpbm_users
                WHERE id_user IN (" . in_placeholders(count($_cp_ids)) . ")";
    $_cp_stmt = $conn->prepare($_cp_sql);
    $_cp_stmt->execute($_cp_ids);
    foreach ($_cp_stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $_cp_dropdown['u' . $u['id_user']] = resolve_display_name(
            $u['first_name_user'], $u['last_name_user'], $u['username_user'], $u['id_user']
        );
    }
}
foreach ($_cp_unlinked_by_name as $name => $cid) {
    $_cp_dropdown['c' . $cid] = $name;
}
// Sorted by the same display name shown, not a raw SQL column -- see resolve_display_name,
// same reasoning as before: what's sorted should match what's shown.
asort($_cp_dropdown);

if ($_cp_id_user) {
    $_cp_current_who = 'u' . $_cp_id_user;
} elseif ($_cp_coach_id && $_cp_coach_display && isset($_cp_unlinked_by_name[$_cp_coach_display])) {
    $_cp_current_who = 'c' . $_cp_unlinked_by_name[$_cp_coach_display];
} else {
    $_cp_current_who = '';
}

// -------------------- Search widget (replaces the old plain <select>) --------------------
// A flat dropdown stopped being usable once the scope widened from "currently active" to
// "every coach ever" -- confirmed directly, 174 distinct coach_id values have held a tenure at
// some point. Client-side search, not a server round-trip: the full list (~150-170 entries
// after dedup) is small enough to embed and filter in the browser, same reasoning as game.php's
// Playback widget embedding its own play list rather than fetching per-interaction. This is
// the second piece of real client-side JS in the app, after that one -- still vanilla, no
// framework, consistent with everything else here.
//
// The underlying identity resolution above (reading $_GET['who']) is completely unchanged --
// this only replaces how that value gets produced on the page, not how it's consumed. Direct
// ?who=/?user=/?coach= links and team.php's existing "all their teams" link all still work
// exactly as before.
echo "<p style='margin-top:12px;margin-bottom:4px'>Select a Coach to see their record:</p>";
echo "<div style='position:relative;max-width:320px'>";
echo "<input type='text' id='coach-search-input' autocomplete='off' placeholder='Type a coach&#8217;s name...' "
   . "value='" . htmlspecialchars($_cp_coach_display ?: '') . "' "
   . "style='width:100%;padding:6px;box-sizing:border-box;border:1px solid #999;border-radius:4px'>";
echo "<div id='coach-search-results' style='display:none;position:absolute;top:100%;left:0;right:0;"
   . "max-height:260px;overflow-y:auto;background:white;color:black;border:1px solid #999;"
   . "border-top:none;border-radius:0 0 4px 4px;z-index:50'></div>";
echo "</div>";

echo "<form method='get' id='coach-search-form'>";
echo "<input type='hidden' name='function' value='" . htmlspecialchars($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . htmlspecialchars($_GET['id_static_page'] ?? '') . "'>";
echo "<input type='hidden' name='who' id='coach-search-who' value='" . htmlspecialchars($_cp_current_who) . "'>";
echo "</form><br>";

// Data for the widget only -- JSON, never executed. HEX_TAG/AMP/APOS/QUOT so a coach name
// containing something unusual can't break out of the tag, same defensive pattern as
// game.php's play-by-play payload.
echo "<script type='application/json' id='coach-search-data'>"
   . json_encode($_cp_dropdown, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
   . "</script>";

echo <<<'JS'
<script>
(function () {
    var coachData = JSON.parse(document.getElementById('coach-search-data').textContent);
    var entries = Object.keys(coachData).map(function (key) {
        return { value: key, name: coachData[key] };
    });

    var input = document.getElementById('coach-search-input');
    var resultsBox = document.getElementById('coach-search-results');
    var hiddenWho = document.getElementById('coach-search-who');
    var form = document.getElementById('coach-search-form');

    var MAX_RESULTS = 15;
    var highlighted = -1;
    var visible = [];

    function clearResults() {
        resultsBox.textContent = '';
        resultsBox.style.display = 'none';
        highlighted = -1;
        visible = [];
    }

    function selectCoach(entry) {
        hiddenWho.value = entry.value;
        input.value = entry.name;
        clearResults();
        form.submit();
    }

    function renderResults(query) {
        var q = query.trim().toLowerCase();
        if (!q) {
            clearResults();
            return;
        }

        // Contains, not starts-with -- searching "Milnes" should find "Alan Milnes" too, not
        // just names beginning with the typed text.
        var matches = entries.filter(function (e) {
            return e.name.toLowerCase().indexOf(q) !== -1;
        });

        resultsBox.textContent = '';
        highlighted = -1;

        if (!matches.length) {
            var none = document.createElement('div');
            none.style.padding = '6px 8px';
            none.style.color = '#888';
            none.textContent = 'No matches';
            resultsBox.appendChild(none);
            visible = [];
            resultsBox.style.display = 'block';
            return;
        }

        visible = matches.slice(0, MAX_RESULTS);
        visible.forEach(function (m, i) {
            var item = document.createElement('div');
            item.textContent = m.name;
            item.style.padding = '6px 8px';
            item.style.cursor = 'pointer';
            item.dataset.index = i;
            item.addEventListener('mouseenter', function () { setHighlight(i); });
            item.addEventListener('mousedown', function (e) {
                // mousedown, not click -- fires before the input's blur handler would otherwise
                // hide this box first and swallow the click.
                e.preventDefault();
                selectCoach(m);
            });
            resultsBox.appendChild(item);
        });

        if (matches.length > MAX_RESULTS) {
            var more = document.createElement('div');
            more.style.padding = '6px 8px';
            more.style.color = '#888';
            more.style.fontSize = '0.85em';
            more.textContent = (matches.length - MAX_RESULTS) + ' more -- keep typing to narrow down';
            resultsBox.appendChild(more);
        }

        resultsBox.style.display = 'block';
    }

    function setHighlight(i) {
        var children = resultsBox.children;
        if (highlighted >= 0 && children[highlighted]) {
            children[highlighted].style.background = '';
        }
        highlighted = i;
        if (highlighted >= 0 && children[highlighted]) {
            children[highlighted].style.background = '#eee';
        }
    }

    input.addEventListener('input', function () {
        hiddenWho.value = ''; // typing invalidates whatever was previously selected
        renderResults(input.value);
    });

    input.addEventListener('focus', function () {
        // Select-all on focus -- after picking a coach, the box holds their name; without this,
        // searching for someone else means manually deleting that text first. Standard pattern
        // (same as a browser address bar) -- focusing puts you straight into "type to replace."
        input.select();
        if (input.value) {
            renderResults(input.value);
        }
    });

    input.addEventListener('keydown', function (e) {
        if (!visible.length) {
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlight(highlighted < visible.length - 1 ? highlighted + 1 : 0);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlight(highlighted > 0 ? highlighted - 1 : visible.length - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlighted >= 0) {
                selectCoach(visible[highlighted]);
            } else if (visible.length === 1) {
                selectCoach(visible[0]);
            }
        } else if (e.key === 'Escape') {
            clearResults();
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target !== input && !resultsBox.contains(e.target)) {
            clearResults();
        }
    });
})();
</script>
JS;

if (!$_cp_id_user && !$_cp_coach_id) {
    echo "<p><em>Select a coach above to view their record.</em></p>";
    echo "</div>";
    exit;
}

if (!$_cp_coach_ids) {
    echo "<p><em>Coach not found.</em></p>";
    echo "</div>";
    exit;
}

// -------------------- Franchises this coach currently manages --------------------
// Scoped to $_cp_coach_ids (one or more coach_id values, resolved above), not id_user directly
// -- correct for both a linked coach (multiple rows) and an unlinked one (a single row).
$_cp_sql = "SELECT DISTINCT f.franchise_id, f.label, l.code AS league_code, l.name AS league_name
            FROM coaches c
            JOIN franchise_coach_tenures fct ON fct.coach_id = c.coach_id AND fct.end_week_id IS NULL
            JOIN franchises f ON f.franchise_id = fct.franchise_id
            JOIN leagues l ON l.league_id = f.league_id
            WHERE c.coach_id IN (" . in_placeholders(count($_cp_coach_ids)) . ")
            ORDER BY l.code, f.label";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute($_cp_coach_ids);
$_cp_franchises = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------- Career record (every tenure, not just the current one) --------------------
// Same season-ending-coach tie-break rule as team.php's coach_for_season() -- a mid-season
// coaching change attributes that season's full record to whoever finished it, not split or
// attributed to whoever started it, for consistency with how the rest of this app already
// handles that ambiguity.
//
// Reusing franchise_season_records (not summing raw games/team_game_stats) is deliberate: it's
// the only source that includes the legacy_rollup era (pre-2003 NFLAR, no individual game data
// at all) -- summing games directly would silently drop a coach's entire pre-2003 career, the
// same gap schema.md already documents for team.php's own season table.
$_cp_sql = "SELECT fsr.franchise_id, fsr.season_id, l.code AS league_code, l.name AS league_name,
                   fsr.wins, fsr.losses, fsr.ties, fsr.points_for, fsr.points_against
            FROM franchise_season_records fsr
            JOIN seasons s ON s.season_id = fsr.season_id
            JOIN leagues l ON l.league_id = s.league_id
            JOIN weeks w ON w.week_id = (SELECT MAX(week_id) FROM weeks WHERE season_id = fsr.season_id)
            JOIN franchise_coach_tenures fct ON fct.franchise_id = fsr.franchise_id
                AND fct.start_week_id <= w.week_id
                AND (fct.end_week_id IS NULL OR fct.end_week_id >= w.week_id)
            JOIN coaches c ON c.coach_id = fct.coach_id
            WHERE c.coach_id IN (" . in_placeholders(count($_cp_coach_ids)) . ")";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute($_cp_coach_ids);
$_cp_seasons_coached = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Tallied in PHP rather than a second SQL pass -- this result set is one row per season this
// specific person (or single unlinked coach) coached, small regardless of how the totals get
// sliced.
$_cp_record_overall = ['wins' => 0, 'losses' => 0, 'ties' => 0, 'pf' => 0, 'pa' => 0];
$_cp_record_by_league = [];
foreach ($_cp_seasons_coached as $row) {
    foreach (['wins', 'losses', 'ties'] as $k) {
        $_cp_record_overall[$k] += (int)$row[$k];
    }
    $_cp_record_overall['pf'] += (int)$row['points_for'];
    $_cp_record_overall['pa'] += (int)$row['points_against'];

    $lg = $row['league_name'];
    if (!isset($_cp_record_by_league[$lg])) {
        $_cp_record_by_league[$lg] = ['wins' => 0, 'losses' => 0, 'ties' => 0, 'pf' => 0, 'pa' => 0];
    }
    foreach (['wins', 'losses', 'ties'] as $k) {
        $_cp_record_by_league[$lg][$k] += (int)$row[$k];
    }
    $_cp_record_by_league[$lg]['pf'] += (int)$row['points_for'];
    $_cp_record_by_league[$lg]['pa'] += (int)$row['points_against'];
}

// -------------------- Honours won during those same seasons --------------------
// Same tenure-join pattern as above, applied to franchise_honors -- an honour only counts
// toward this coach's career if they were the season-ending coach when it was won, for
// consistency with how the record above is attributed.
$_cp_sql = "SELECT l.code AS league_code, l.name AS league_name, ht.code AS honor_code
            FROM franchise_honors fh
            JOIN honor_types ht ON ht.honor_type_id = fh.honor_type_id
            JOIN seasons s ON s.season_id = fh.season_id
            JOIN leagues l ON l.league_id = s.league_id
            JOIN weeks w ON w.week_id = (SELECT MAX(week_id) FROM weeks WHERE season_id = fh.season_id)
            JOIN franchise_coach_tenures fct ON fct.franchise_id = fh.franchise_id
                AND fct.start_week_id <= w.week_id
                AND (fct.end_week_id IS NULL OR fct.end_week_id >= w.week_id)
            JOIN coaches c ON c.coach_id = fct.coach_id
            WHERE c.coach_id IN (" . in_placeholders(count($_cp_coach_ids)) . ")";
$_cp_stmt = $conn->prepare($_cp_sql);
$_cp_stmt->execute($_cp_coach_ids);
$_cp_honors_won = $_cp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Every *_BOWL_WINNER/CIC_WINNER code bucketed together as one "Other Bowl Wins" count, EXCEPT
// the Rose Bowl, which gets its own line -- calling out every specific bowl by name felt like
// more detail than a career-summary page needs, but Rose Bowl specifically is a big enough deal
// (the Granddaddy of Them All) to warrant not disappearing into a merged number. team.php's own
// per-franchise honours table already gives full per-bowl detail for anyone who wants it.
// honor_types codes confirmed in new_schema.sql: LEAGUE_WINNER, LEAGUE_RUNNERUP (college only),
// CONFERENCE_CHAMPION, DIVISION_CHAMPION + WILDCARD_BERTH (pro only), the five named bowls +
// CIC_WINNER (college only), RIVALRY_WINNER, PERFECT_SEASON (college only). RIVALRY_WINNER
// deliberately excluded below -- a season-by-season rivalry result isn't really a "career
// honour" the way a championship or bowl win is.
//
// Keyed by league_code (NFLAR/NCAA5), not league_name -- code is the reliable pro/college
// signal already used everywhere else in this app (team.php: $_cp_is_pro =
// $_cp_franchise['league_code'] === 'NFLAR'), name is just display text and shouldn't be
// parsed/guessed at to infer anything.
$_cp_honor_counts = []; // [league_code]['name' => ..., 'counts' => [bucket => count]]
$_cp_bowl_codes = ['COTTON_BOWL_WINNER', 'ORANGE_BOWL_WINNER',
                    'HAWAII_BOWL_WINNER', 'MUSIC_CITY_BOWL_WINNER', 'CIC_WINNER'];
foreach ($_cp_honors_won as $row) {
    if ($row['honor_code'] === 'RIVALRY_WINNER') {
        continue;
    }
    $lc = $row['league_code'];
    if (!isset($_cp_honor_counts[$lc])) {
        $_cp_honor_counts[$lc] = ['name' => $row['league_name'], 'counts' => []];
    }
    $bucket = in_array($row['honor_code'], $_cp_bowl_codes, true) ? 'OTHER_BOWL_WINS' : $row['honor_code'];
    $_cp_honor_counts[$lc]['counts'][$bucket] = ($_cp_honor_counts[$lc]['counts'][$bucket] ?? 0) + 1;
}

// -------------------- Header --------------------
echo "<div class='w3-panel w3-theme'>";
echo "<h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>";
echo "<b>" . htmlspecialchars($_cp_coach_display) . "</b></h1>";
echo "</div>";

// -------------------- Career Record --------------------
if (!empty($_cp_seasons_coached)) {
    echo "<div class='w3-panel w3-theme'><h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>"
       . "<b>Career Record</b></h1></div>";
    echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black' style='width:55%;min-width:420px'>";
    echo "<tr><th>&nbsp;</th><th>Record</th><th>Points</th></tr>";
    echo career_record_row('Overall', $_cp_record_overall);
    foreach ($_cp_record_by_league as $lg => $rec) {
        echo career_record_row($lg, $rec);
    }
    echo "</table><br>";
}

// -------------------- Championships & Honours --------------------
if (!empty($_cp_honor_counts)) {
    echo "<div class='w3-panel w3-theme'><h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>"
       . "<b>Championships &amp; Honours</b></h1></div>";
    foreach ($_cp_honor_counts as $lc => $league_data) {
        echo "<h3 style='margin-top:16px'>" . htmlspecialchars($league_data['name']) . "</h3>";
        echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black' style='width:55%;min-width:420px'>";
        foreach (honor_display_labels($lc) as $bucket => $label) {
            if (!empty($league_data['counts'][$bucket])) {
                echo "<tr><td style='padding:4px 8px'>" . htmlspecialchars($label) . "</td>"
                   . "<td style='padding:4px 8px'>{$league_data['counts'][$bucket]}</td></tr>";
            }
        }
        echo "</table>";
    }
    echo "<br>";
}

if (empty($_cp_franchises)) {
    // Combined-coach entries ("Alan & Gordon Milnes") are a real, confirmed pattern in the
    // live data -- every one of the four uses '&' specifically (checked directly:
    // SELECT * FROM coaches WHERE name LIKE '%&%'), not some other separator, so this is a
    // precise check against confirmed data, not a guess at a pattern that might exist.
    $_cp_is_pair = (strpos($_cp_coach_display, '&') !== false);
    $_cp_verb = $_cp_is_pair ? "aren't" : "isn't";
    echo "<p><em>" . htmlspecialchars($_cp_coach_display) . " $_cp_verb currently linked to any franchises.</em></p>";
    echo "</div>";
    exit;
}

// -------------------- Franchise list, grouped by league --------------------
$_cp_by_league = [];
foreach ($_cp_franchises as $f) {
    $_cp_by_league[$f['league_name']][] = $f;
}

foreach ($_cp_by_league as $_cp_league_name => $_cp_league_franchises) {
    echo "<h3 style='margin-top:16px'>" . htmlspecialchars($_cp_league_name) . "</h3>";
    echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black' style='width:55%;min-width:400px'>";
    foreach ($_cp_league_franchises as $f) {
        $link = build_team_link($f['league_code'], $f['franchise_id']);
        echo "<tr><td style='padding:4px 8px'><a href='$link' style='color:inherit;text-decoration:underline'>" . htmlspecialchars($f['label']) . "</a></td></tr>";
    }
    echo "</table>";
}
// Breathing room after the last table, before the outer panel closes -- the h3's margin-top
// above spaces multiple league tables apart from each other, but nothing was giving the final
// table any room before the panel's bottom edge.
echo "<br>";
echo "</div>";

// Record-text formatting matches team.php's Season by Season table exactly (see
// coach_since_year()/coach_for_season() area there) -- "{wins}-{losses}", with "(1 tie)" or
// "(N ties)" appended, not a new format invented for this page.
function career_record_row($label, $rec) {
    $record_text = "{$rec['wins']}-{$rec['losses']}";
    if ($rec['ties'] == 1) {
        $record_text .= " (1 tie)";
    } elseif ($rec['ties'] > 1) {
        $record_text .= " ({$rec['ties']} ties)";
    }
    $points_text = number_format($rec['pf']) . '-' . number_format($rec['pa']);
    return "<tr><th>" . htmlspecialchars($label) . "</th>"
         . "<td style='padding:4px 8px'>$record_text</td>"
         . "<td style='padding:4px 8px'>$points_text</td></tr>";
}

// Display order and labels for the Championships & Honours table. Only buckets with a nonzero
// count actually get a row (see call site) -- this list covers every honor_types code that can
// occur in either league, so it's safe to share across both league sections without
// hardcoding which codes belong to which league; a bucket a given league never earns simply
// never has a nonzero count and never renders.
function honor_display_labels($league_code) {
    // LEAGUE_WINNER's label matches team.php's own convention exactly (see there:
    // $_cp_is_pro ? 'Superbowl Champions' : 'National Championships') -- not a flat generic
    // label for both leagues. This is arguably the single most important honour on this whole
    // page, worth getting the actual name right rather than a placeholder-sounding one.
    // Checked against league_code (reliable, same signal team.php itself uses), not
    // league_name -- a display string shouldn't be parsed to infer anything.
    $league_champ_label = ($league_code === 'NFLAR') ? 'Superbowl Champions' : 'National Championships';

    return [
        'LEAGUE_WINNER' => $league_champ_label,
        'LEAGUE_RUNNERUP' => 'League Runner-Up',
        'ROSE_BOWL_WINNER' => 'Rose Bowl Wins',
        'CONFERENCE_CHAMPION' => 'Conference Championships',
        'DIVISION_CHAMPION' => 'Division Championships',
        'WILDCARD_BERTH' => 'Wildcard Berths',
        'PERFECT_SEASON' => 'Perfect Seasons',
        'OTHER_BOWL_WINS' => 'Other Bowl Wins',
    ];
}

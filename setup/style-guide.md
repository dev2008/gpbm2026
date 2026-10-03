Gameplan PBM — Front-End Style Guide

Purpose: every custom PHP page (game.php, team.php, coach.php, current_standings.php, replay.php, and the extraction pages) has independently arrived at the same conventions — some by design, a couple the hard way, via real bugs. This document exists so the next custom page starts from these instead of rediscovering them. Companion to lessons.md (which is explicitly scoped to backend parser work — see its own header) and security.md (access control) — this one is front-end/UI specifically.

Not a proposal — every rule below is already in production, confirmed working, across multiple pages. Treat this as "how things are actually done here," not aspirational.

1. Page structure (w3.css)

Every custom page wraps its content in one outer panel:

php
echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'>";
// ... page content ...
echo "</div>";

Section headers, consistently, everywhere:

php
echo "<div class='w3-panel w3-theme'><h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>"
   . "<b>Section Title</b></h1></div>";

Tables:

php
echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black' style='width:55%;min-width:480px'>";

Widths are a judgment call per table (season lists are narrower than box scores), but the class list itself doesn't vary.

Table cells need explicit padding. w3-table does not reliably provide it — a bare <td> sits flush against the cell edges. Always:

php
echo "<td style='padding:4px 8px'>...</td>";

Confirmed necessary directly (coach.php's franchise list originally had none, looked visibly cramped).

2. Links — the single most-repeated bug in this whole project

A plain <a href='...'>text</a> with no explicit style is invisible on this theme. No default underline, no distinguishing color — it renders as ordinary text with a working href underneath it, which means nothing looks clickable even though it is. This exact bug hit team.php's Coach: row, coach.php's franchise list, current_standings.php's team links, and replay.php's spoiler-warning link — independently, four separate times, before the pattern was recognized and applied everywhere at once.

Always style a link explicitly:

php
"<a href='$link' style='color:inherit;text-decoration:underline'>$label</a>"

color:inherit keeps it matching the surrounding text color (usually wanted, so a link doesn't jarringly stand out in an unrelated color); text-decoration:underline is what actually makes it look clickable. Both matter — replay.php's bug was specifically a link with a color matching its surroundings and no underline, making it doubly invisible.

3. Cross-page links: the build_X_link() + X_PAGE_STATIC_ID pattern

Every page that links to another custom page follows the same shape:

php
// X.php's registered id_static_page -- was a 0 placeholder, now the real value.
define('X_PAGE_STATIC_ID', 12);

function build_x_link($param) {
    return htmlspecialchars(
        'index.php?function=show_static_page&id_static_page=' . X_PAGE_STATIC_ID
        . '&param=' . urlencode($param)
    );
}

New page, not registered in DaDaBIK yet? Use 0 as an obviously-wrong placeholder, not a guessed real-looking number, with a comment explaining it needs updating once registered:

php
define('X_PAGE_STATIC_ID', 0);

Confirmed this actually breaks silently if forgotten — current_standings.php's TEAM_PAGE_STATIC_ID sat at 0 for a real stretch of live use before being caught, exactly the failure mode this placeholder-with-a-comment convention exists to make loud instead of silent.

4. Forms and selectors

Every dropdown that changes page state (league, franchise, season, coach) is a real GET form submit — a full page reload, not AJAX — following the same shape:

php
echo "<form method='get'>";
echo "<input type='hidden' name='function' value='" . htmlspecialchars($_GET['function'] ?? 'show_static_page') . "'>";
echo "<input type='hidden' name='id_static_page' value='" . htmlspecialchars($_GET['id_static_page'] ?? '') . "'>";
// ... any other state that must survive the reload (league, franchise, etc.) as more hidden inputs ...
echo "<select name='thing' onchange='this.form.submit()' style='width:...px'>";
// ... <option> tags ...
echo "</select>";
echo "</form>";

GET param names are short, singular resource names: franchise, season, game, league, user, coach, who, upload_id. Not abbreviated further, not pluralized.

A full-page-reload form submit resets scroll to the top. If the selector might be reached by scrolling down first (as opposed to something always at the top of the page, like a league toggle), that's worth fixing with the sessionStorage pattern in §6 — but only where it's actually annoying in practice; not every selector needs it (the franchise selector, e.g., is always at the top already, so there's no position to lose).

5. JavaScript — vanilla only, three real pieces exist so far

No framework, anywhere, on any page. Three real pieces of client-side JS exist in this app — game.php's Playback widget, coach.php's search-as-you-type widget, team.php's scroll-position restore — all plain JS, all following the same shape.

Embedding data for JS to read: a JSON <script> tag, not an inline JS variable assignment:

php
echo "<script type='application/json' id='some-data'>"
   . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
   . "</script>";

Then in JS: JSON.parse(document.getElementById('some-data').textContent). The JSON_HEX_* flags matter — they stop something like a </script> sequence inside real data (a play's result_text, a coach's name) from breaking out of the tag.

Writing the JS block itself — PHP nowdoc, not heredoc:

php
echo <<<'JS'
<script>
// ... plain JS ...
</script>
JS;

The single-quoted <<<'JS' (nowdoc) is deliberate, not a typo — a regular heredoc would have PHP try to interpolate any $variable-looking text inside the JS itself. Confirmed real mistake, caught before deployment, not just a theoretical risk: an early draft of one of these blocks used -- (SQL-style comments) instead of // inside what should have been a PHP comment immediately before the nowdoc — a fatal parse error, only caught by a careful re-read. Double-check comment syntax specifically whenever writing one of these blocks.

Never innerHTML with string-concatenated data. Use textContent/createElement for anything derived from a name, a result string, or any other data that ultimately came from a turn upload — avoids the same class of injection risk JSON_HEX_* protects against on the way in.

sessionStorage, not localStorage, for anything that only needs to survive one page navigation (e.g. scroll position across a form-submit reload). Clear it immediately after reading, so a value never leaks into an unrelated later visit:

js
var saved = sessionStorage.getItem('some_key');
if (saved !== null) {
    // ... use it ...
    sessionStorage.removeItem('some_key');
}
6. Session and identity — use DaDaBIK's own globals, not manual $_SESSION reads

DaDaBIK provides these directly in custom-page code; use them instead of reading $_SESSION['logged_user_infos_ar'][...] by hand:

Global	What it holds
$conn	PDO connection
$current_user	Logged-in user's username
$current_id_group	Logged-in user's id_group
$current_user_is_administrator	1/0
$quote	Identifier-quote character for the current DBMS

Started as manual session reads, corrected once these were confirmed to exist — game.php/home.php both originally read $_SESSION['logged_user_infos_ar']['id_group'] directly; both switched over once $current_user_is_administrator/$current_id_group were confirmed. Prefer the native global from the start on any new page.

Always allow-list, never block-list, for anything permission-relevant:

php
$is_admin = ($current_user_is_administrator == 1);       // correct
$is_public = ($current_id_group == 3);                    // correct (no native "is public" global exists)

Not != 3 for admin, not != 1 for public. An allow-list stays correct even if a new group gets added later; a block-list silently starts including things it shouldn't the moment it does. security.md §2 covers the full reasoning.

Public/guest is group 3 in this install, confirmed directly — not inferred, not assumed.

7. Text formatting

number_format() on anything that could plausibly reach four digits — points totals, career-aggregated stats. Harmless even when a value never actually gets that large (no visible change for small numbers), so default to using it rather than checking case-by-case whether it's "big enough to matter":

php
$points_text = number_format($points_for) . '-' . number_format($points_against);

UK spelling for user-facing display text, US spelling in code/schema references. "Honours" in an <h1>, but franchise_honors/honor_types/$_cp_honor_counts unchanged in code — the schema itself uses US spelling throughout, and matching that in variable/comment/table names keeps them grep-able against the actual database; only what a person actually reads on the page gets the UK spelling.

Grammar agreement for combined entities isn't automatic — check for a real marker. A combined-coach entry ("Alan & Gordon Milnes") needs "aren't," not "isn't." Don't guess a pattern — check what the data actually looks like first (every real combined entry in this project's data uses &, confirmed by direct query) before writing the detection logic.

8. Defensive coding baseline
htmlspecialchars() on every piece of database- or user-sourced text before echoing it, no exceptions.
(int) casts and ?? null / ?? 0 fallbacks on $_GET/$_SESSION reads — assume the value might not be there or might not be the type expected.
PDO has no native array-binding for IN (...) clauses — build the placeholder string yourself and pass the array positionally:
php
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $conn->prepare("SELECT ... WHERE id IN ($placeholders)");
  $stmt->execute($ids);
9. Comments and code organization
Section dividers: // -------------------- Section Name --------------------.
Comments explain why, not just what — this is the established norm across every file in this project, not a style preference for new code specifically. A comment that only restates the line below it in English isn't pulling its weight here.
A placeholder value (define('X_PAGE_STATIC_ID', 0), a guessed column width, an unconfirmed assumption) always gets a comment saying so explicitly, not just a bare value — the whole point is making an unfinished thing loud instead of silently wrong later.

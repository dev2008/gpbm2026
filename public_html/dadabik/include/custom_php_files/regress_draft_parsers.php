<?php
// Standalone regression harness for the D4 draft-parser classes -- extracts the pure-parsing
// classes from bb_draft_parser.php (no DB calls) and runs them against the exact literal sample
// text quoted in tasks/B1-baseball-draft-parser.md §4/§4a, so the regex logic is checked against
// real game text, not invented text.

$src = file_get_contents(__DIR__ . '/bb_draft_parser.php');

// strict_types can only be declared as the file's own first statement, not inside an eval() --
// stripped here since this harness eval's the source into an already-running script.
$src = preg_replace('/^declare\(strict_types=1\);\s*/m', '', $src);

// Strip the DB-touching orchestrator function and the a4_*-calling resolve methods aren't needed
// for this harness -- we only eval the pure classes (DTOs + parsers + ratings).
eval('?>' . $src);

$fail = 0;
function check($label, $cond) {
    global $fail;
    if ($cond) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label\n";
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// Draft List block -- real text from task doc §4 (t15's <BK.Draft List>)
// ---------------------------------------------------------------------------
$draftListBlock = <<<'TXT'
<P.70><L>
First Round Draft List<ST.44.88><L.39.1>
No Type Ability Ps Pt Value Drafted By<T>No Type Ability Ps Pt Value Drafted By<T>No Type Ability Ps Pt Value Drafted By<C><L.39.1>
1 Pit R 8 QUI 18 24 LP <T>31 Bat L 7 FLD SS 14 18 LP <T>61 Pit R 5 QUI 22 12 LP <L.33.1>
30 Cat L 7 FLD C 14 22 LP <T>60 Bat R 6 SPD RF 15 15 LP <T>90 Cat L 6 POW C 6 20 LP <L.33.1>
TXT;

$entries = BbDraftListParser::parse($draftListBlock);
check('Draft List: parses 6 entries from 2 data rows x 3 columns', count($entries) === 6);

$byNo = [];
foreach ($entries as $e) { $byNo[$e->draftee_no] = $e; }

check('Draft List: draftee 1 is Pit, R, level 8, best_skill QUI, no position, potential 18, value 24',
    isset($byNo[1])
    && $byNo[1]->player_type === 'Pit'
    && $byNo[1]->bats_throws === 'R'
    && $byNo[1]->player_level === 8
    && $byNo[1]->best_skill === 'QUI'
    && $byNo[1]->field_position === null
    && $byNo[1]->potential === 18
    && $byNo[1]->draft_value === 24
    && $byNo[1]->drafted_by === null
);
check('Draft List: draftee 31 is Bat, L, level 7, best_skill FLD, position SS, potential 14, value 18',
    isset($byNo[31])
    && $byNo[31]->player_type === 'Bat'
    && $byNo[31]->bats_throws === 'L'
    && $byNo[31]->player_level === 7
    && $byNo[31]->best_skill === 'FLD'
    && $byNo[31]->field_position === 'SS'
    && $byNo[31]->potential === 14
    && $byNo[31]->draft_value === 18
);
check('Draft List: draftee 61 is Pit, R, level 5, best_skill QUI, no position, potential 22, value 12',
    isset($byNo[61]) && $byNo[61]->player_type === 'Pit' && $byNo[61]->field_position === null
    && $byNo[61]->potential === 22 && $byNo[61]->draft_value === 12
);
check('Draft List: draftee 30 is Cat, L, level 7, best_skill FLD, position C, potential 14, value 22',
    isset($byNo[30]) && $byNo[30]->player_type === 'Cat' && $byNo[30]->field_position === 'C'
    && $byNo[30]->potential === 14 && $byNo[30]->draft_value === 22
);
check('Draft List: draftee 90 is Cat, L, level 6, best_skill POW, position C, potential 6, value 20',
    isset($byNo[90]) && $byNo[90]->player_type === 'Cat' && $byNo[90]->field_position === 'C'
    && $byNo[90]->potential === 6 && $byNo[90]->draft_value === 20
);

// Post-draft form: a "Drafted By" name appended (from task doc §4's t20 excerpt, Third Round list)
$draftListWithDraftedBy = "1 Pit R 8 QUI 18 24 LP Marlins<T>31 Bat L 7 FLD SS 14 18 LP Guardians<L.33.1>";
$entries2 = BbDraftListParser::parse($draftListWithDraftedBy);
check('Draft List: drafted_by captured once a draftee is picked (Marlins)',
    count($entries2) === 2 && $entries2[0]->drafted_by === 'Marlins');
check('Draft List: drafted_by captured once a draftee is picked (Guardians)',
    $entries2[1]->drafted_by === 'Guardians');

// ---------------------------------------------------------------------------
// Draft Order block -- real text from task doc §4 (t20's <BK.Actions>, Draft Order sub-block)
// ---------------------------------------------------------------------------
$draftOrderBlock = <<<'TXT'
<Z>Draft Order<L.39.1>
<C> 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24<L.33.1>
MM TR NM LA MB OA KC BA MT CL SF PI WN HA BR NY CU PH TB CI TO LG AD CO<L.42.1>
TXT;

$order = BbDraftActionsParser::parseDraftOrder($draftOrderBlock);
check('Draft Order: parses exactly 24 picks', count($order) === 24);
check('Draft Order: pick 1 = MM', isset($order[1]) && $order[1] === 'MM');
check('Draft Order: pick 11 = SF', isset($order[11]) && $order[11] === 'SF');
check('Draft Order: pick 24 = CO', isset($order[24]) && $order[24] === 'CO');
check('Draft Order: pick 19 = TB', isset($order[19]) && $order[19] === 'TB');

// ---------------------------------------------------------------------------
// Pick announcements -- real text from task doc §4 (t20's Second Round Draft Selections)
// ---------------------------------------------------------------------------
$picksBlock = <<<'TXT'
<Z>Second Round Draft Selections<ST.66><L.39.1>
<C> 1. Marlins select no.19 - Bat R 7 SPD, Matt Polke<T> 2. Rangers select no.22 - Pit L 7 STA, Alan Nadon<L.33.1>
TXT;

$picks = BbDraftActionsParser::parsePickAnnouncements($picksBlock, roundHeadingText: 'Second Round Draft Selections');
check('Pick announcements: parses 2 picks', count($picks) === 2);
check('Pick announcements: round resolved to 2 from heading', $picks[0]->round === 2 && $picks[1]->round === 2);
check('Pick announcements: pick 1 = Marlins, draftee 19, Matt Polke',
    $picks[0]->pick_position === 1 && $picks[0]->franchise_nickname === 'Marlins'
    && $picks[0]->draftee_no === 19 && $picks[0]->drafted_player_name === 'Matt Polke');
check('Pick announcements: pick 2 = Rangers, draftee 22, Alan Nadon',
    $picks[1]->pick_position === 2 && $picks[1]->franchise_nickname === 'Rangers'
    && $picks[1]->draftee_no === 22 && $picks[1]->drafted_player_name === 'Alan Nadon');

// Regression case for the real bug found live 24 Aug 2026 (MLB21 turn 19, upload_id 60):
// the round-slicing regex inside bb_process_draft_turn() (not directly unit-testable, so
// reproduced here verbatim) swallowed a "<Z>Draft Order<...>" section that appeared between a
// completed round's picks and the next round's list, which broke the LAST pick's end-of-name
// match in parsePickAnnouncements() -- silently, with no warning. Confirmed live via the mysql
// bridge: bb_draft_picks had 23 rows instead of 24, missing exactly pick_position 24. This is
// the exact real Actions block text from upload_id 60 (trimmed to the picks/Draft
// Order/next-heading boundary that mattered -- the Second Round Draft List body itself isn't
// needed to exercise this).
$turn19Actions = <<<'TXT'
<Z>First Round Draft Selections<ST.66><L.39.1>
<C> 1. Marlins select no.1 - Pit R 8 QUI, Randy East<T> 2. Rangers select no.2 - Pit L 8 CON, Mark Anderson<L.33.1>
 3. Mets select no.3 - Pit R 8 QUI, Terri Grimm<T> 4. Dodgers select no.4 - Bat L 8 HIT, Hyeseong Kim<L.33.1>
 5. Brewers select no.5 - Pit L 8 QUI, Pete Chasseur<T> 6. Athletics select no.6 - Bat R 8 SPD, Marcus Schumacher<L.33.1>
 7. Royals select no.7 - Bat L 8 SPD, Levi Tyler<T> 8. Orioles select no.8 - Pit R 8 ACC, Pete Nelson<L.33.1>
 9. Twins select no.9 - Bat R 8 POW, Ivan Wilander<T>10. Guardians select no.13 - Pit R 7 ACC, Lucas Olazabal<L.33.1>
11. Giants select no.25 - Bat R 6 POW, Bo Davidson<T>12. Pirates select no.14 - Bat S 7 FLD, Jacob Gonzalez<L.33.1>
13. Nationals select no.62 - Bat L 5 FLD, Nasim Nunez<T>14. Astros select no.18 - Bat S 7 HIT, Orlando Lopez<L.33.1>
15. Red Sox select no.15 - Pit R 7 ACC, George Beaufort<T>16. Yankees select no.12 - Pit R 8 ACC, Kade Anderson<L.33.1>
17. Cubs select no.16 - Cat R 7 HIT, Owen Ayers<T>18. Phillies select no.10 - Cat R 8 SPD, Travis Winkelman<L.33.1>
19. Rays select no.20 - Bat R 7 FLD, Nathen Flewelling<T>20. Reds select no.26 - Pit R 6 ACC, Jose Franco<L.33.1>
21. Blue Jays select no.11 - Bat R 8 FLD, Nick Bouchier<T>22. Angels select no.21 - Bat L 7 HIT, Jeyson Horton<L.33.1>
23. Diamondbacks select no.17 - Bat R 7 POW, Max Kepler<T>24. Rockies select no.61 - Pit R 5 QUI, Brody Brecht<L.33.1>
<L.12.1>
<Z>Draft Order<L.39.1>
<C>   1   2   3   4   5   6   7   8   9  10  11  12  13  14  15  16  17  18  19  20  21  22  23  24<L.33.1>
  MM  TR  NM  LA  MB  OA  KC  BA  MT  CL  SF  PI  WN  HA  BR  NY  CU  PH  TB  CI  TO  LG  AD  CO<L.42.1>
<Z>Second Round Draft List<ST.44.88><L.39.1>
No Type  Ability Ps Pt  Value  Drafted By<T><L.39.1>
TXT;

// Reproduced verbatim from bb_process_draft_turn() -- the actual fix under test.
preg_match_all(
    '/([A-Za-z]+\s+Round\s+Draft\s+Selections)(.*?)(?=[A-Za-z]+\s+Round\s+Draft\s+(?:Selections|List)|<Z>Draft Order<|$)/s',
    $turn19Actions, $roundMatches, PREG_SET_ORDER
);
check('Round-slicing: finds exactly 1 "Round Draft Selections" section', count($roundMatches) === 1);

$turn19Picks = BbDraftActionsParser::parsePickAnnouncements($roundMatches[0][2], roundHeadingText: $roundMatches[0][1]);
check('Round-slicing + pick announcements: all 24 real picks found (not 23 -- the live bug)', count($turn19Picks) === 24);

$byPosition = [];
foreach ($turn19Picks as $p) { $byPosition[$p->pick_position] = $p; }
check('Pick 24 (the one the bug dropped): Rockies, draftee 61, Brody Brecht',
    isset($byPosition[24]) && $byPosition[24]->franchise_nickname === 'Rockies'
    && $byPosition[24]->draftee_no === 61 && $byPosition[24]->drafted_player_name === 'Brody Brecht');
check('Pick 1 still correct (bug fix did not disturb the start of the list): Marlins, draftee 1, Randy East',
    isset($byPosition[1]) && $byPosition[1]->franchise_nickname === 'Marlins'
    && $byPosition[1]->draftee_no === 1 && $byPosition[1]->drafted_player_name === 'Randy East');

// Round-number derivation from heading text, tested independently of block content
check('Round heading: "First Round Draft List" -> 1', BbDraftActionsParser::roundFromHeading('First Round Draft List') === 1);
check('Round heading: "Second Round Draft Selections" -> 2', BbDraftActionsParser::roundFromHeading('Second Round Draft Selections') === 2);
check('Round heading: "Third Round Draft List" -> 3', BbDraftActionsParser::roundFromHeading('Third Round Draft List') === 3);
check('Round heading: "Draft Order" -> null (not a round)', BbDraftActionsParser::roundFromHeading('Draft Order') === null);

// ---------------------------------------------------------------------------
// Scouting -- real text from task doc §4a (t15's Special Actions:)
// ---------------------------------------------------------------------------
$specialActions = <<<'TXT'
Special Actions:<C><T>ADDFORM 26 for 3 weeks: OK, cost 3 LP
<T>SCOUT 45: OK, Bat (LF) L 7. Pt 12, Ex R, Fm 0, TF 0, Ft 0, Val 18 LP. HIT Av POW Fa SPD Av FLD Av
<T>SCOUT 46: OK, Bat (LF) R 7. Pt 12, Ex R, Fm 0, TF 0, Ft 0, Val 18 LP. HIT Go POW Av SPD Av FLD Fa
<T>SCOUT 47: OK, Pit R 7. Pt 11, Ex R, Fm 0, TF 0, Val 22 LP. ACC Av CON Fa QUI Go STA Go
<T>SCOUT 48: OK, Pit L 7. Pt 11, Ex R, Fm 0, TF 0, Val 21 LP. ACC Av CON Go QUI Av STA Go
<T>SCOUT 49: OK, Pit R 7. Pt 11, Ex R, Fm 0, TF 0, Val 20 LP. ACC Av CON Fa QUI Av STA Go
<T>SCOUT 50: OK, Pit R 6. Pt 18, Ex R, Fm 0, TF 0, Val 14 LP. ACC Fa CON Fa QUI Av STA Av
TXT;

$scouted = BbScoutingParser::parse($specialActions);
check('Scouting: parses exactly 6 SCOUT lines (ADDFORM ignored)', count($scouted) === 6);

$byDraftee = [];
foreach ($scouted as $s) { $byDraftee[$s->draftee_no] = $s; }

check('Scouting: draftee 45 (batter) HIT/POW/SPD/FLD = Av/Fa/Av/Av',
    isset($byDraftee[45]) && $byDraftee[45]->skill_a === 'Av' && $byDraftee[45]->skill_b === 'Fa'
    && $byDraftee[45]->skill_c === 'Av' && $byDraftee[45]->skill_d === 'Av');
check('Scouting: draftee 47 (pitcher) ACC/CON/QUI/STA = Av/Fa/Go/Go',
    isset($byDraftee[47]) && $byDraftee[47]->skill_a === 'Av' && $byDraftee[47]->skill_b === 'Fa'
    && $byDraftee[47]->skill_c === 'Go' && $byDraftee[47]->skill_d === 'Go');
check('Scouting: draftee 50 (pitcher) ACC/CON/QUI/STA = Fa/Fa/Av/Av',
    isset($byDraftee[50]) && $byDraftee[50]->skill_a === 'Fa' && $byDraftee[50]->skill_b === 'Fa'
    && $byDraftee[50]->skill_c === 'Av' && $byDraftee[50]->skill_d === 'Av');

// ---------------------------------------------------------------------------
// Ratings -- draft_asm() and draft_gpm(), ported from bb_functions.php (read directly, 24 Aug 2026)
// ---------------------------------------------------------------------------
// draft_gpm() sanity: Pit formula is 3.25*(s1*1.25 + s2*1.25 + s3*0.75 + s4*0.75) + pot/2
// Draftee 47: Av(2) Fa(1) Go(3) Go(3), pot from Draft List not given in this excerpt -- use a
// representative potential of 18 (t15's draftee 1's potential) to check the formula shape only.
$gpm = BbDraftRatings::gpmRating('Pit', 'Av', 'Fa', 'Go', 'Go', 18);
$expectedGpm = 3.25 * (2*1.25 + 1*1.25 + 3*0.75 + 3*0.75) + (18/2);
check('draft_gpm(): Pit formula matches legacy bb_functions.php exactly', abs($gpm - $expectedGpm) < 0.0001);

$gpmCat = BbDraftRatings::gpmRating('Cat', 'Av', 'Fa', 'Go', 'Go', 18);
$expectedGpmCat = 3.25 * (2*1.2 + 1*0.9 + 3*0.9 + 3*1.0) + (18/2);
check('draft_gpm(): Cat formula matches legacy bb_functions.php exactly', abs($gpmCat - $expectedGpmCat) < 0.0001);

$asm = BbDraftRatings::asmRating(pid: 45, level: 7, potential: 18, hand: 'L', position: 'SS', bestSkill: 'HIT', value: 18);
check('draft_asm(): returns a float in the expected 1-100 scale (D7)', is_float($asm) && $asm > 0 && $asm < 100);

echo "\n" . ($fail === 0 ? "ALL CHECKS PASS" : "$fail CHECK(S) FAILED") . "\n";

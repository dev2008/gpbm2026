<?php
// bb_draft_parser.php
// Gameplan PBM · Task B1 (baseball draft page) · D4 -- the draft-turn parser classes.
//
// rev 001 · 24 Aug 2026 · role: code, written by build chat · authority: derived
//
// Modern PHP 8.x typed classes, per the task doc's own objective (§2) -- NOT a port of the five
// legacy line-scanning scripts (g_process_baseballdraft15a/15b/18/192021.php,
// g_process_baseballactions.php). What IS ported, byte-for-byte where the legacy math itself is
// the spec, is draft_gpm() and draft_asm() (both read directly from bb_functions.php, 24 Aug
// 2026) -- see BbDraftRatings below.
//
// Scope confirmed with Alan 24 Aug 2026 (task doc §2): draft page only. Draftee scouting is in
// scope (§4a) because it's issued/reported as part of the draft cycle itself.
//
// WHAT THIS FILE CONTAINS
//   - Pure, DB-free parsing classes (DraftListEntry/BbDraftListParser, DraftPickAnnouncement/
//     BbDraftActionsParser, ScoutingResult/BbScoutingParser) -- regression-tested against the
//     exact literal sample text quoted in tasks/B1-baseball-draft-parser.md §4/§4a, not invented
//     text. See regress_draft_parsers.php in the same delivery for the test harness and its
//     result.
//   - BbDraftRatings -- draft_gpm() (D8-scoped, only for scouted draftees) and draft_asm() (D7,
//     unconditional bulk rating), ported formula-for-formula from bb_functions.php.
//   - bb_process_draft_turn() and its upsert helpers -- the DB-touching orchestrator that ties
//     the above into raw_upload_blocks / bb_drafts / bb_draftees / bb_draft_order /
//     bb_draft_picks / bb_draftee_scouting. NOT regression-tested against a live upload_id this
//     revision -- the mysql device bridge dropped mid-session before this could be run against
//     real raw_upload_blocks rows. Structurally complete and reviewed against the exact schema
//     `B1_leagues_weeks_bbtables.r004.sql` created (gate-verified, rev 009), but flagged here as
//     the one part of this delivery still needing a live test pass once the bridge is back.
//
// IDEMPOTENCY (task doc §5's own flagged gap in every legacy script): every write below is an
// upsert keyed on the schema's own unique key (uk_draftee, uk_draft_order_position, uk_pick,
// uk_scouting) -- re-processing the same upload_id, or a later turn re-covering the same draft,
// converges rather than duplicating.
//
// FRANCHISE RESOLUTION -- two different identifiers appear in real draft text, resolved two
// different ways, both confirmed against live sample text rather than assumed:
//   - Draft Order block: 2-LETTER CODES ("MM TR NM LA ..."). Resolved via the existing
//     a4_resolve_franchise_by_code_at_week() (operational_hooks.php) -- code-to-franchise at
//     week grain, already built for football's play-by-play side column.
//   - Round selection announcements ("1. Marlins select no.19 ..."): PLAIN NICKNAMES, not codes.
//     franchise_identities.team_name is the FULL name ("Miami Marlins"), so this file adds
//     bb_resolve_franchise_by_nickname_at_week() -- a nickname-suffix match, falling back to
//     franchises.nickname (the D5 seed) when no franchise_identities row exists yet for that
//     week, which is the documented, expected coverage gap (task doc §4d) until a wider baseball
//     Team Report parser exists.

declare(strict_types=1);

// ============================================================================
// DTOs
// ============================================================================

if (!class_exists('DraftListEntry')) {
final class DraftListEntry {
    public function __construct(
        public readonly int $draftee_no,
        public readonly string $player_type,      // Bat|Cat|Pit
        public readonly string $bats_throws,       // L|R|S
        public readonly int $player_level,
        public readonly string $best_skill,
        public readonly ?string $field_position,   // NULL for Pit -- confirmed against t15
        public readonly int $potential,
        public readonly int $draft_value,
        public readonly ?string $drafted_by,        // franchise nickname once picked, else NULL
    ) {}
}
}

if (!class_exists('DraftPickAnnouncement')) {
final class DraftPickAnnouncement {
    public function __construct(
        public readonly ?int $round,
        public readonly int $pick_position,
        public readonly string $franchise_nickname,
        public readonly int $draftee_no,
        public readonly string $drafted_player_name,
    ) {}
}
}

if (!class_exists('ScoutingResult')) {
final class ScoutingResult {
    public function __construct(
        public readonly int $draftee_no,
        public readonly string $skill_a,  // HIT (Bat/Cat) or ACC (Pit)
        public readonly string $skill_b,  // POW / CON
        public readonly string $skill_c,  // SPD / QUI
        public readonly string $skill_d,  // FLD / STA
    ) {}
}
}

// ============================================================================
// BbDraftListParser -- <BK.Draft List> block -> DraftListEntry[]
// ============================================================================

if (!class_exists('BbDraftListParser')) {
final class BbDraftListParser {
    /**
     * Rows are 3 draftees wide, separated by <T>, terminated by an <L.nn.n> row-end tag.
     * Header rows ("No Type Ability Ps Pt Value Drafted By", round-title lines like "First
     * Round Draft List<ST.44.88>") don't start with "<digits> (Bat|Cat|Pit)" and are skipped.
     */
    public static function parse(string $blockText): array {
        $entries = [];
        foreach (self::tokenize($blockText) as $token) {
            if (!preg_match('/^(\d+)\s+(Bat|Cat|Pit)\s+([LRS])\s+/', $token, $head)) {
                continue; // not a draftee row -- header/label text
            }
            $type = $head[2];
            if ($type === 'Pit') {
                // Pit rows carry no field_position column at all (confirmed t15/t20: "1 Pit R  8
                // QUI     18  24 LP" -- best_skill straight into potential/value).
                if (!preg_match('/^(\d+)\s+Pit\s+([LRS])\s+(\d+)\s+(\S+)\s+(\d+)\s+(\d+)\s+LP\s*(.*)$/', $token, $m)) {
                    continue;
                }
                $entries[] = new DraftListEntry(
                    draftee_no: (int)$m[1], player_type: 'Pit', bats_throws: $m[2],
                    player_level: (int)$m[3], best_skill: $m[4], field_position: null,
                    potential: (int)$m[5], draft_value: (int)$m[6],
                    drafted_by: $m[7] !== '' ? $m[7] : null,
                );
            } else {
                if (!preg_match('/^(\d+)\s+(Bat|Cat)\s+([LRS])\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+LP\s*(.*)$/', $token, $m)) {
                    continue;
                }
                $entries[] = new DraftListEntry(
                    draftee_no: (int)$m[1], player_type: $m[2], bats_throws: $m[3],
                    player_level: (int)$m[4], best_skill: $m[5], field_position: $m[6],
                    potential: (int)$m[7], draft_value: (int)$m[8],
                    drafted_by: $m[9] !== '' ? $m[9] : null,
                );
            }
        }
        return $entries;
    }

    /** Strips row-end/column tags into a flat list of trimmed entry candidates. */
    private static function tokenize(string $block): array {
        $normalized = preg_replace('/<L\.\d+\.\d+>/', "\x01", $block);
        $parts = preg_split('/<T>|\x01/', $normalized);
        $tokens = [];
        foreach ($parts as $part) {
            $clean = trim(preg_replace('/<[^>]*>/', '', $part));
            if ($clean !== '') {
                $tokens[] = $clean;
            }
        }
        return $tokens;
    }
}
}

// ============================================================================
// BbDraftActionsParser -- <BK.Actions> sub-blocks: Draft Order, per-round selections
// ============================================================================

if (!class_exists('BbDraftActionsParser')) {
final class BbDraftActionsParser {
    /**
     * The Draft Order block is two lines after tag-stripping: a "1 2 3 ... 24" position line
     * (discarded -- position is implied by array index) and a 24-code line. Returns
     * [pick_position => 2-letter code], 1-indexed.
     */
    public static function parseDraftOrder(string $blockText): array {
        $clean = preg_replace('/<[^>]*>/', "\n", $blockText);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $clean)), fn($l) => $l !== ''));
        foreach ($lines as $line) {
            $tokens = preg_split('/\s+/', $line);
            if (count($tokens) === 24 && array_reduce(
                $tokens, fn($ok, $t) => $ok && (bool)preg_match('/^[A-Z]{2,3}$/', $t), true
            )) {
                $order = [];
                foreach ($tokens as $i => $code) {
                    $order[$i + 1] = $code;
                }
                return $order;
            }
        }
        return [];
    }

    /**
     * "First Round Draft List" -> 1, "Second Round Draft Selections" -> 2, etc. Returns null
     * for headings that aren't a round at all (e.g. "Draft Order" itself).
     */
    public static function roundFromHeading(string $heading): ?int {
        static $words = ['First' => 1, 'Second' => 2, 'Third' => 3, 'Fourth' => 4, 'Fifth' => 5,
                          'Sixth' => 6, 'Seventh' => 7, 'Eighth' => 8, 'Ninth' => 9, 'Tenth' => 10];
        if (preg_match('/(First|Second|Third|Fourth|Fifth|Sixth|Seventh|Eighth|Ninth|Tenth)\s+Round/i', $heading, $m)) {
            return $words[ucfirst(strtolower($m[1]))] ?? null;
        }
        return null;
    }

    /**
     * Parses "N. <Nickname> select no.NN - <public stats>, <Full Name>" lines. $roundHeadingText
     * is normally the caller's own already-sliced section heading (see bb_process_draft_turn);
     * when omitted, this method looks for a "... Round Draft Selections" heading inside
     * $blockText itself.
     */
    public static function parsePickAnnouncements(string $blockText, ?string $roundHeadingText = null): array {
        $round = $roundHeadingText !== null
            ? self::roundFromHeading($roundHeadingText)
            : (preg_match('/([A-Za-z]+\s+Round\s+Draft\s+Selections)/', $blockText, $hm)
                ? self::roundFromHeading($hm[1]) : null);

        $clean = preg_replace('/<[^>]*>/', ' ', $blockText);
        preg_match_all(
            '/(\d+)\.\s*([A-Za-z\.\' ]+?)\s+select\s+no\.(\d+)\s*-\s*[^,]+,\s*([A-Za-z\.\'\- ]+?)(?=\s+\d+\.|\s*$)/',
            $clean, $matches, PREG_SET_ORDER
        );

        $picks = [];
        foreach ($matches as $m) {
            $picks[] = new DraftPickAnnouncement(
                round: $round,
                pick_position: (int)$m[1],
                franchise_nickname: trim($m[2]),
                draftee_no: (int)$m[3],
                drafted_player_name: trim($m[4]),
            );
        }
        return $picks;
    }
}
}

// ============================================================================
// BbScoutingParser -- each franchise's own Team Report "Special Actions:" text -> ScoutingResult[]
// ============================================================================

if (!class_exists('BbScoutingParser')) {
final class BbScoutingParser {
    private const GRADES = 'Po|Fa|Av|Go|Ex|WC';

    public static function parse(string $blockText): array {
        $clean = preg_replace('/<[^>]*>/', "\n", $blockText);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $clean)), fn($l) => $l !== ''));

        $results = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'SCOUT ')) {
                continue; // other Special Actions entries (ADDFORM, etc.) are out of scope here
            }
            if (!preg_match('/^SCOUT\s+(\d+):/', $line, $noMatch)) {
                continue;
            }
            $drafteeNo = (int)$noMatch[1];
            if ($drafteeNo >= 100) {
                // D9: free-agent scouting explicitly out of scope for v1, matching legacy's own
                // "Ignoring Free Agent scouting action".
                continue;
            }

            $batterPattern = '/^SCOUT\s+\d+:.*?HIT\s+(' . self::GRADES . ')\s+POW\s+(' . self::GRADES
                . ')\s+SPD\s+(' . self::GRADES . ')\s+FLD\s+(' . self::GRADES . ')/';
            $pitcherPattern = '/^SCOUT\s+\d+:.*?ACC\s+(' . self::GRADES . ')\s+CON\s+(' . self::GRADES
                . ')\s+QUI\s+(' . self::GRADES . ')\s+STA\s+(' . self::GRADES . ')/';

            if (preg_match($batterPattern, $line, $m) || preg_match($pitcherPattern, $line, $m)) {
                $results[] = new ScoutingResult($drafteeNo, $m[1], $m[2], $m[3], $m[4]);
            }
            // A SCOUT line matching neither shape is left unparsed rather than guessed at.
        }
        return $results;
    }
}
}

// ============================================================================
// BbDraftRatings -- draft_gpm() and draft_asm(), ported from bb_functions.php (read directly,
// decoded via the confirmed base64 route, 24 Aug 2026). Formulas kept exact, not "improved".
// ============================================================================

if (!class_exists('BbDraftRatings')) {
final class BbDraftRatings {
    private static function gradeValue(string $grade): int {
        return match ($grade) {
            'Po' => 0, 'Fa' => 1, 'Av' => 2, 'Go' => 3, 'Ex' => 4, 'WC' => 5,
            default => throw new \InvalidArgumentException("Unknown skill grade: $grade"),
        };
    }

    /**
     * D8-scoped: only computed once a draftee has been scouted (never runs unconditionally --
     * that's asmRating's job). Position-weighted per player type, exactly as legacy's switch
     * statement -- Pit/Cat/default(Bat) have distinct weight sets, ported verbatim.
     */
    public static function gpmRating(
        string $playerType, string $skill1, string $skill2, string $skill3, string $skill4, int $potential
    ): float {
        $s1 = self::gradeValue($skill1);
        $s2 = self::gradeValue($skill2);
        $s3 = self::gradeValue($skill3);
        $s4 = self::gradeValue($skill4);

        return match ($playerType) {
            'Pit' => 3.25 * ($s1 * 1.25 + $s2 * 1.25 + $s3 * 0.75 + $s4 * 0.75) + ($potential / 2),
            'Cat' => 3.25 * ($s1 * 1.2 + $s2 * 0.9 + $s3 * 0.9 + $s4 * 1.0) + ($potential / 2),
            default => 3.25 * ($s1 * 1.2 + $s2 * 0.95 + $s3 * 1.1 + $s4 * 0.7) + ($potential / 2), // Bat
        };
    }

    /**
     * D7: the bulk "Alan's rating" -- runs unconditionally for every draftee from public info
     * only (level/potential/position/best_skill/bats_throws), no scouting required. $position
     * is 'P' for pitchers (bb_draftees.field_position is NULL for Pit rows -- callers pass the
     * literal 'P' for that case, matching how the legacy caller distinguished pitchers). $value
     * is accepted for signature parity with the legacy function but is unused by its own formula
     * body -- confirmed by reading bb_functions.php directly, not assumed.
     */
    public static function asmRating(
        int $pid, int $level, int $potential, string $hand, string $position, string $bestSkill, int $value
    ): float {
        $totalWeight = 10000;

        $levelScore = ($level / 10.0) * 3310;
        $potentialScore = ($potential * 100) + ((($potential - 14) / 10.0) * 1530);

        $positionScore = match ($position) {
            'SS' => 720, 'C' => 681, 'CF' => 652, 'IF' => 393, 'OF' => 374,
            '2B' => 205, '3B' => 176, '1B' => 127, 'RF' => 117, 'LF' => 107,
            'P' => 25, default => 1,
        };

        $bestScore = match ($bestSkill) {
            'HIT' => 480, 'POW' => 450, 'SPD' => 420, 'FLD' => 390,
            'ACC' => 360, 'CON' => 330, 'QUI' => 300, 'STA' => 270, default => 240,
        };

        $handScore = match (true) {
            $hand === 'S' => 1430,
            $hand === 'L' && $position === 'P' => 0,
            $hand === 'L' => 1200,
            $hand === 'R' && $position === 'P' => 1000,
            $hand === 'R' => 800,
            default => 600,
        };

        $rate = $levelScore + $potentialScore + $positionScore + $bestScore + $handScore;
        $rate += ($pid % 100) / 10000.0; // legacy's deterministic anti-clustering nudge, ported as-is
        $rate = ($rate / $totalWeight) * 100;

        if ($hand === 'L' && $position === 'P') {
            $rate *= 0.6; // legacy's left-handed-pitcher reduction, ported as-is
        }

        return round($rate, 2);
    }
}
}

// ============================================================================
// D4 orchestrator -- NOT regression-tested against a live upload_id this revision (bridge
// dropped mid-session). Structurally reviewed against B1_leagues_weeks_bbtables.r004.sql's
// gate-verified schema. Needs a live test pass before being wired into a real extraction page.
// ============================================================================

if (!function_exists('bb_resolve_franchise_by_code_at_week')) {
/**
 * The code-side counterpart of bb_resolve_franchise_by_nickname_at_week() below -- for the
 * Draft Order block's 2-letter codes. Tries a4_resolve_franchise_by_code_at_week()
 * (operational_hooks.php, franchise_identities at week grain) first, exactly as football's
 * pages do. Falls back to franchises.abbr (B1_franchises_abbr.r001.sql) when that finds
 * nothing.
 *
 * Confirmed live 24 Aug 2026 that the fallback is not an edge case here the way it is for
 * football: franchise_identities has ZERO rows for ANY baseball week (nothing in the baseball
 * pipeline writes them -- that's extract_games.php's job for football, reading team names out
 * of Results/Series blocks turn by turn, and there is no baseball equivalent live yet). Every
 * one of MLB21's 24 real Draft Order codes failed via the identity path alone. So for
 * baseball, right now, this fallback is not a fallback in practice -- it's the only path that
 * works -- but it's still written as try-identity-first, same as the nickname resolver, so
 * both resolvers pick up baseball extract_games.php's eventual identity data automatically
 * without another code change, and so a week that genuinely does have identity rows (e.g. one
 * a franchise has been renamed in) resolves correctly rather than always falling through to
 * the season-start default from franchises.abbr.
 */
function bb_resolve_franchise_by_code_at_week(PDO $conn, int $leagueId, int $weekId, string $code, array &$log): ?int {
    $franchiseId = a4_resolve_franchise_by_code_at_week($conn, $weekId, $code, $log);
    if ($franchiseId !== null) {
        return $franchiseId;
    }

    $stmt = $conn->prepare("SELECT franchise_id FROM franchises WHERE league_id = :league_id AND abbr = :code");
    $stmt->execute([':league_id' => $leagueId, ':code' => $code]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        $log[] = "fallback: code '$code' resolved from franchises.abbr (D5 seed), not franchise_identities -- expected until a baseball equivalent of extract_games.php writes weekly identities.";
        return (int)$ids[0];
    }
    if (count($ids) > 1) {
        $log[] = "AMBIGUOUS: code '$code' matches " . count($ids) . " franchises by franchises.abbr -- refusing to choose.";
    } else {
        $log[] = "code '$code' did not resolve to any franchise in league $leagueId -- skipped.";
    }
    return null;
}
}

if (!function_exists('bb_resolve_franchise_by_nickname_at_week')) {
/**
 * The nickname-side counterpart of a4_resolve_franchise_at_week() (operational_hooks.php),
 * for round-selection text which names franchises by plain nickname ("Marlins"), not the
 * 2-letter code the Draft Order block uses. Matches franchise_identities.team_name by suffix
 * (team_name is the FULL name, "Miami Marlins"), falling back to franchises.nickname (the D5
 * seed) when no identity row exists yet for this week -- the documented, expected coverage gap
 * (task doc §4d) until a wider baseball Team Report parser exists.
 */
function bb_resolve_franchise_by_nickname_at_week(PDO $conn, int $leagueId, int $weekId, string $nickname, array &$log): ?int {
    $stmt = $conn->prepare(
        "SELECT DISTINCT fi.franchise_id
           FROM franchise_identities fi
           JOIN franchises f ON f.franchise_id = fi.franchise_id
          WHERE f.league_id = :league_id AND fi.week_id = :week_id AND fi.team_name LIKE :pattern"
    );
    $stmt->execute([':league_id' => $leagueId, ':week_id' => $weekId, ':pattern' => '%' . $nickname]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        return (int)$ids[0];
    }
    if (count($ids) > 1) {
        $log[] = "AMBIGUOUS: nickname '$nickname' matches " . count($ids) . " franchises at week $weekId -- refusing to choose.";
        return null;
    }

    $stmt = $conn->prepare("SELECT franchise_id FROM franchises WHERE league_id = :league_id AND nickname = :nickname");
    $stmt->execute([':league_id' => $leagueId, ':nickname' => $nickname]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        $log[] = "fallback: nickname '$nickname' resolved from franchises.nickname (D5 seed), not franchise_identities -- expected until weekly Team Report coverage widens (§4d).";
        return (int)$ids[0];
    }
    if (count($ids) > 1) {
        $log[] = "AMBIGUOUS: nickname '$nickname' matches " . count($ids) . " franchises by franchises.nickname -- refusing to choose.";
    } else {
        $log[] = "nickname '$nickname' did not resolve to any franchise in league $leagueId -- skipped.";
    }
    return null;
}
}

if (!function_exists('bb_upsert_draftee')) {
function bb_upsert_draftee(PDO $conn, int $draftId, DraftListEntry $entry): int {
    $stmt = $conn->prepare(
        "INSERT INTO bb_draftees
            (draft_id, draftee_no, player_type, bats_throws, player_level, best_skill, field_position, potential, draft_value)
         VALUES (:draft_id, :no, :type, :bt, :level, :skill, :pos, :pot, :val)
         ON DUPLICATE KEY UPDATE
             player_type = VALUES(player_type), bats_throws = VALUES(bats_throws),
             player_level = VALUES(player_level), best_skill = VALUES(best_skill),
             field_position = VALUES(field_position), potential = VALUES(potential),
             draft_value = VALUES(draft_value)"
    );
    $stmt->execute([
        ':draft_id' => $draftId, ':no' => $entry->draftee_no, ':type' => $entry->player_type,
        ':bt' => $entry->bats_throws, ':level' => $entry->player_level, ':skill' => $entry->best_skill,
        ':pos' => $entry->field_position, ':pot' => $entry->potential, ':val' => $entry->draft_value,
    ]);

    $position = $entry->player_type === 'Pit' ? 'P' : ($entry->field_position ?? '');
    $rating = BbDraftRatings::asmRating(
        pid: $entry->draftee_no, level: $entry->player_level, potential: $entry->potential,
        hand: $entry->bats_throws, position: $position, bestSkill: $entry->best_skill, value: $entry->draft_value,
    );
    // Stored x10 (Alan, 25 Aug 2026) -- asm_rating is smallint, so draft_asm()'s decimal precision
    // (e.g. 64.3) was being discarded entirely by the earlier (int)round($rating) alone. Rather than
    // widen the column to a DECIMAL type, the agreed fix keeps it a whole-number smallint but scales
    // by 10 first -- 64.3 stores as 643, one decimal place recoverable by dividing by 10 wherever
    // it's displayed. See B1_ratings_x10.r001.sql for the one-time backfill of existing rows.
    $stmt = $conn->prepare("UPDATE bb_draftees SET asm_rating = :r WHERE draft_id = :d AND draftee_no = :no");
    $stmt->execute([':r' => (int)round($rating * 10), ':d' => $draftId, ':no' => $entry->draftee_no]);

    return 1;
}
}

if (!function_exists('bb_upsert_pick')) {
function bb_upsert_pick(PDO $conn, int $draftId, int $leagueId, int $weekId, int $uploadId, DraftPickAnnouncement $pick, array &$log): int {
    if ($pick->round === null) {
        $log[] = "Pick for draftee no.{$pick->draftee_no} has no resolvable round heading -- skipped.";
        return 0;
    }
    $stmt = $conn->prepare("SELECT draftee_id FROM bb_draftees WHERE draft_id = :d AND draftee_no = :no");
    $stmt->execute([':d' => $draftId, ':no' => $pick->draftee_no]);
    $drafteeId = $stmt->fetchColumn();
    if (!$drafteeId) {
        $log[] = "Pick: draftee no.{$pick->draftee_no} not found in bb_draftees for draft $draftId -- Draft List block for this draftee may not have parsed yet. Skipped.";
        return 0;
    }

    $franchiseId = bb_resolve_franchise_by_nickname_at_week($conn, $leagueId, $weekId, $pick->franchise_nickname, $log);
    if ($franchiseId === null) {
        return 0;
    }

    $stmt = $conn->prepare(
        "INSERT INTO bb_draft_picks (draft_id, round, pick_position, draftee_id, upload_id)
         VALUES (:d, :r, :p, :dr, :u)
         ON DUPLICATE KEY UPDATE draftee_id = VALUES(draftee_id), upload_id = VALUES(upload_id)"
    );
    $stmt->execute([':d' => $draftId, ':r' => $pick->round, ':p' => $pick->pick_position, ':dr' => $drafteeId, ':u' => $uploadId]);

    $stmt = $conn->prepare(
        "UPDATE bb_draftees SET drafted_by_franchise_id = :f, drafted_round = :r, drafted_pick = :p WHERE draftee_id = :dr"
    );
    $stmt->execute([':f' => $franchiseId, ':r' => $pick->round, ':p' => $pick->pick_position, ':dr' => $drafteeId]);

    return 1;
}
}

if (!function_exists('bb_upsert_scouting')) {
function bb_upsert_scouting(PDO $conn, int $draftId, int $scoutingFranchiseId, ScoutingResult $scouted, array &$log): int {
    $stmt = $conn->prepare("SELECT draftee_id, player_type, potential FROM bb_draftees WHERE draft_id = :d AND draftee_no = :no");
    $stmt->execute([':d' => $draftId, ':no' => $scouted->draftee_no]);
    $draftee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$draftee) {
        $log[] = "Scouting: draftee no.{$scouted->draftee_no} not found in bb_draftees for draft $draftId -- skipped (Draft List block may not have parsed yet).";
        return 0;
    }

    $gpm = BbDraftRatings::gpmRating(
        $draftee['player_type'], $scouted->skill_a, $scouted->skill_b, $scouted->skill_c, $scouted->skill_d,
        (int)$draftee['potential'],
    );

    // Key is draftee_id alone (B1_scouting_upsert_by_draftee.r001.sql) -- baseball on this project
    // is family-only, so unlike football's per-franchise-private grades, a later scout (from ANY
    // franchise) simply upserts onto the one row for this draftee rather than creating a second.
    // scouting_franchise_id is therefore included in the UPDATE clause, not just the INSERT --
    // without it, a second franchise's scout would silently leave the ORIGINAL franchise_id in
    // place while overwriting the grades, misattributing whose scouting the row now reflects.
    $stmt = $conn->prepare(
        "INSERT INTO bb_draftee_scouting (draftee_id, scouting_franchise_id, skill_a, skill_b, skill_c, skill_d, gpm_rating)
         VALUES (:dr, :f, :a, :b, :c, :d, :g)
         ON DUPLICATE KEY UPDATE
             scouting_franchise_id = VALUES(scouting_franchise_id),
             skill_a = VALUES(skill_a), skill_b = VALUES(skill_b), skill_c = VALUES(skill_c),
             skill_d = VALUES(skill_d), gpm_rating = VALUES(gpm_rating), scouted_at = CURRENT_TIMESTAMP"
    );
    // Stored x10 (Alan, 25 Aug 2026), same reasoning as asm_rating above -- gpm_rating is smallint,
    // so draft_gpm()'s decimal precision was being discarded by the earlier (int)round($gpm) alone.
    // See B1_ratings_x10.r001.sql for the one-time backfill of existing rows.
    $stmt->execute([
        ':dr' => $draftee['draftee_id'], ':f' => $scoutingFranchiseId,
        ':a' => $scouted->skill_a, ':b' => $scouted->skill_b, ':c' => $scouted->skill_c, ':d' => $scouted->skill_d,
        ':g' => (int)round($gpm * 10),
    ]);
    return 1;
}
}

if (!function_exists('bb_process_draft_turn')) {
/**
 * Processes one already-identified, already-block-split raw upload for a baseball draft turn.
 * Idempotent throughout -- see file header. Returns a summary array for the caller to report.
 */
function bb_process_draft_turn(PDO $conn, int $uploadId): array {
    $summary = ['draftees' => 0, 'draft_order' => 0, 'picks' => 0, 'scouted' => 0, 'warnings' => []];

    $upload = ddb_api::get_record_details('raw_uploads', 'upload_id', $uploadId);
    $leagueId = (int)$upload['league_id'];
    $weekId = (int)$upload['week_id'];
    $seasonId = dadabik_get_season_id_for_week($conn, $weekId);

    $stmt = $conn->prepare("SELECT draft_id FROM bb_drafts WHERE league_id = :l AND season_id = :s");
    $stmt->execute([':l' => $leagueId, ':s' => $seasonId]);
    $draftId = $stmt->fetchColumn();
    if (!$draftId) {
        $stmt = $conn->prepare("INSERT INTO bb_drafts (league_id, season_id, status) VALUES (:l, :s, 'pool_open')");
        $stmt->execute([':l' => $leagueId, ':s' => $seasonId]);
        $draftId = (int)$conn->lastInsertId();
    } else {
        // fetchColumn() returns a string for an int column (PDO does not stringify-fetch by
        // default, but MySQL's own wire protocol returns text for this driver regardless of
        // column type) -- the lastInsertId() branch above already casts, this one didn't, and
        // strict_types=1 does not coerce string->int at a typed parameter boundary. Silent on a
        // draft's first-ever turn (new row, cast applied); broke on every re-run once the row
        // already existed -- caught live re-running upload 56 a second time, 24 Aug 2026.
        $draftId = (int)$draftId;
    }

    $blocksStmt = $conn->prepare("SELECT block_type, block_text FROM raw_upload_blocks WHERE upload_id = :u ORDER BY block_seq");
    $blocksStmt->execute([':u' => $uploadId]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------- Draft List (draftee pool) --------------------
    $draftListSeen = false;
    foreach ($blocks as $block) {
        if ($block['block_type'] !== 'Draft List') {
            continue;
        }
        $draftListSeen = true;
        foreach (BbDraftListParser::parse($block['block_text']) as $entry) {
            $summary['draftees'] += bb_upsert_draftee($conn, $draftId, $entry);
        }
    }
    if ($draftListSeen) {
        $stmt = $conn->prepare("UPDATE bb_drafts SET status = 'pool_open' WHERE draft_id = :d AND status = 'pool_open'");
        $stmt->execute([':d' => $draftId]);
    }

    // -------------------- Actions: Draft Order + per-round selections --------------------
    foreach ($blocks as $block) {
        if ($block['block_type'] !== 'Actions') {
            continue;
        }

        $order = BbDraftActionsParser::parseDraftOrder($block['block_text']);
        if (!empty($order)) {
            foreach ($order as $pickPosition => $code) {
                $franchiseId = bb_resolve_franchise_by_code_at_week($conn, $leagueId, $weekId, $code, $summary['warnings']);
                if ($franchiseId === null) {
                    $summary['warnings'][] = "Draft Order: code '$code' (pick $pickPosition) did not resolve to a franchise at week $weekId -- skipped.";
                    continue;
                }
                $stmt = $conn->prepare(
                    "INSERT INTO bb_draft_order (draft_id, pick_position, franchise_id) VALUES (:d, :p, :f)
                     ON DUPLICATE KEY UPDATE franchise_id = VALUES(franchise_id)"
                );
                $stmt->execute([':d' => $draftId, ':p' => $pickPosition, ':f' => $franchiseId]);
                $summary['draft_order']++;
            }
            $stmt = $conn->prepare("UPDATE bb_drafts SET status = 'order_set' WHERE draft_id = :d AND status = 'pool_open'");
            $stmt->execute([':d' => $draftId]);
        }

        // One or more "<Round> Draft Selections" sections can appear in the same Actions block
        // (t20's real text carries a completed Second Round alongside the still-open Third
        // Round's Draft List) -- sliced apart by the heading pattern itself, not by count.
        //
        // BUG FOUND LIVE 24 Aug 2026 (MLB21 turn 19): the original terminator lookahead here
        // only stopped at the NEXT round's own "Round Draft Selections/List" heading, or end of
        // string. Real text can carry a "<Z>Draft Order<...>" section directly between a
        // completed round's picks and the next round's list (turn 19's actual layout: First
        // Round Draft Selections, then Draft Order, then Second Round Draft List) -- when that
        // happened, this regex swallowed the Draft Order section INTO the picks text handed to
        // parsePickAnnouncements(). That text's last real pick (pick 24, Rockies/no.61/Brody
        // Brecht) was then directly followed by "Draft Order" prose instead of end-of-string,
        // which broke parsePickAnnouncements()'s own end-of-name lookahead for that one pick --
        // it simply produced no match for pick 24, silently, with no warning logged (the pick
        // was never even detected, so there was nothing for bb_upsert_pick to warn about).
        // Confirmed via the mysql bridge: bb_draft_picks had 23 rows instead of the real 24,
        // missing exactly pick_position 24. Fixed at the source here -- stop this section at a
        // literal "<Z>Draft Order<" too, so parsePickAnnouncements() never receives anything but
        // clean picks text and its own end-of-name lookahead (unchanged) works as designed.
        if (preg_match_all(
            '/([A-Za-z]+\s+Round\s+Draft\s+Selections)(.*?)(?=[A-Za-z]+\s+Round\s+Draft\s+(?:Selections|List)|<Z>Draft Order<|$)/s',
            $block['block_text'], $roundMatches, PREG_SET_ORDER
        )) {
            foreach ($roundMatches as $rm) {
                $picks = BbDraftActionsParser::parsePickAnnouncements($rm[2], roundHeadingText: $rm[1]);
                foreach ($picks as $pick) {
                    $summary['picks'] += bb_upsert_pick($conn, $draftId, $leagueId, $weekId, $uploadId, $pick, $summary['warnings']);
                }
            }
            if (!empty($roundMatches)) {
                $stmt = $conn->prepare("UPDATE bb_drafts SET status = 'in_progress' WHERE draft_id = :d AND status IN ('pool_open','order_set')");
                $stmt->execute([':d' => $draftId]);
            }
        }
    }

    // -------------------- Scouting: each Team Report's own "Special Actions:" text --------------------
    foreach ($blocks as $block) {
        if ($block['block_type'] !== 'Team Report') {
            continue;
        }
        if (!preg_match('/Special Actions:(.*)/s', $block['block_text'], $sm)) {
            continue;
        }
        $scoutingFranchiseId = $upload['franchise_id'] ?? null;
        if ($scoutingFranchiseId === null) {
            $summary['warnings'][] = "Team Report block: no resolved franchise_id on raw_uploads $uploadId -- scouting results skipped (bb_draftee_scouting.scouting_franchise_id is NOT NULL provenance -- who most recently scouted -- not a privacy key; still required).";
            continue;
        }
        foreach (BbScoutingParser::parse($sm[1]) as $scouted) {
            $summary['scouted'] += bb_upsert_scouting($conn, $draftId, (int)$scoutingFranchiseId, $scouted, $summary['warnings']);
        }
    }

    // -------------------- Completion gate (Alan, 24 Aug 2026) --------------------
    // Fixed schedule, confirmed to hold for ANY league: week 15 pool issued, week 18 draft order
    // set, week 19 round 1, week 20 round 2, week 21 round 3 -- the draft ALWAYS completes at
    // week 21. Deliberately anchored on week_number rather than derived from round-counting or
    // pool-exhaustion (e.g. round 3 looking "short" purely because one pick's resolver had a
    // transient gap would be a fragile signal) -- week 21 is the one fact confirmed fixed and
    // authoritative across every league, so it's the only trigger.
    //
    // Cross-checked before marking complete, not assumed: Alan's own sanity check for a finished
    // draft is exactly 72 total picks (3 rounds x 24 franchises). If week 21 has been reached but
    // the count doesn't match, status is deliberately left as-is and a warning is raised instead
    // of silently marking an incomplete draft complete -- the same "verify, don't assert" lesson
    // rev 023 of the session doc exists to record.
    $stmt = $conn->prepare("SELECT week_number FROM weeks WHERE week_id = :w");
    $stmt->execute([':w' => $weekId]);
    $weekNumber = $stmt->fetchColumn();
    if ($weekNumber !== false && (int)$weekNumber === 21) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM bb_draft_picks WHERE draft_id = :d");
        $stmt->execute([':d' => $draftId]);
        $totalPicks = (int)$stmt->fetchColumn();
        if ($totalPicks === 72) {
            $stmt = $conn->prepare("UPDATE bb_drafts SET status = 'complete' WHERE draft_id = :d");
            $stmt->execute([':d' => $draftId]);
        } else {
            $summary['warnings'][] = "Draft completion cross-check: week 21 reached but bb_draft_picks has "
                . "$totalPicks row(s) for draft $draftId, not the expected 72 (3 rounds x 24 franchises) -- "
                . "status left as-is, NOT marked complete, pending investigation.";
        }
    }

    return $summary;
}
}

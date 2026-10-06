<?php
// whoami.php -- environment identification. Self-identifying by design: every mode leads with
// the host it was served from, so a screenshot or a pasted copy carries its own provenance once
// the URL bar is gone. That is the whole point of the page.
//
// TWO MODES:
//   1. Normal (default) -- included by Dadabik, Administrator-only. Shows database too.
//   2. Pre-deployment -- for probing a host BEFORE Dadabik is installed. Set the token below to
//      a non-empty string and request whoami.php?t=<token> directly. No database (no $conn).
//
// Standalone is OFF unless the token is set, so a deployed copy fails closed even if left in
// place: the inclusion guard applies exactly as it does to every other custom page.
// ===========================================================================
//  THE ONLY LINE TO EDIT. Put a throwaway string here to enable standalone
//  mode; put '' back to disable it. Nothing below needs changing, ever.
$_cp_whoami_token = '';
// ===========================================================================
//  SECOND SWITCH. 1 = append the custom-page globals probe (below); 0 = don't.
//  Dadabik mode only, admin only, off by default. Turn it on, read the answer,
//  turn it back off. It is a diagnostic, not a feature.
$_cp_whoami_probe = 1;
// ===========================================================================

$_cp_standalone = false;
if (!defined('custom_page_from_inclusion')) {
    // Do not edit this line -- set the token above. The '' here tests whether standalone has
    // been enabled at all; it is not where the token goes.
    if ($_cp_whoami_token !== '' && isset($_GET['t'])
        && hash_equals($_cp_whoami_token, (string)$_GET['t'])) {
        $_cp_standalone = true;
    } else {
        // Silent to the web -- no trace the page exists (security.md sec.4). But a blank screen
        // is undiagnosable, so say why in the error log, which only the operator can read.
        error_log('whoami.php: standalone request rejected -- '
            . ($_cp_whoami_token === ''
                ? 'token not set (edit $_cp_whoami_token at the top of the file)'
                : (isset($_GET['t']) ? 'token supplied did not match' : 'no ?t= supplied')));
        die();
    }
}

// A composite condition, so it earns a name -- unlike a bare alias for
// $current_user_is_administrator, which does not (see todo.md standing rules).
//
// Build order item 4, 7 Sep 2026. Was: (($current_user_is_administrator ?? 0) == 1).
// That reflects the MAIN group only, so a user holding admin as an ADDITIONAL group
// is granted this page by DaDaBIK and then served a blank one by this line, with no
// error, because the failure path below returns silently. It asks the same permission
// row DaDaBIK asks, against THIS page rather than against the vetting queue: whoami
// is an IT support diagnostic and should not inherit vetting rights.
//
// PORTABLE ACROSS INSTANCES, 8 Sep 2026. deploy_whoami.sh keeps ONE copy of this file
// in step across nine DaDaBIK instances in three projects, and user_identity.php is a
// Civ IV artefact of build order item 3 that exists on 4hof alone. require_once on a
// missing file is FATAL, so the include is guarded and the gate falls back to the flag
// it replaced. An instance without the helper therefore behaves exactly as it did
// before this change - which is what BUS needs, where administrators other than the
// operator exist and must stay restricted.
//
// The fallback is the older, blunter question, so the page SAYS which one answered
// rather than leaving it to be inferred: see 'Page gate' in the Environment table.
//
// Short-circuit order matters: in standalone mode DaDaBIK's globals and $conn do not
// exist, and $_cp_standalone being true means neither gate is consulted.
// --- whoami gate (portable) ---
$_cp_uid_lib      = __DIR__ . '/user_identity.php';
$_cp_have_uid_lib = is_readable($_cp_uid_lib);
if ($_cp_have_uid_lib) { require_once $_cp_uid_lib; }

// is_readable is not enough on its own: an older copy of the file could be present and
// not carry this function. The function is what is actually being called.
$_cp_gate_by_permission = function_exists('hof_user_can_view_page');
$_cp_gate_note = $_cp_gate_by_permission
    ? "page permission (hof_user_can_view_page('whoami'))"
    : '$current_user_is_administrator -- user_identity.php is not on this instance';

$_cp_show = $_cp_standalone || ($_cp_gate_by_permission
    ? hof_user_can_view_page('whoami')
    : (($current_user_is_administrator ?? 0) == 1));
// --- end whoami gate ---

if (!$_cp_show) {
    return;   // header AND data both hidden -- no trace the page exists (security.md sec.4)
}

// '' or '0' = off · '1' = On (unlimited) · anything else = buffer size in bytes.
// 13.6 changelog: up/up2 "did not work if output buffering was disabled or the buffer was not
// large enough". 13.5's up.php still has that bug, so this value decides 13.5 vs 13.6.
$_ob      = ini_get('output_buffering');
$_ob_note = ($_ob === '' || $_ob === '0') ? '  <-- OFF: up.php before 13.6 can fail'
          : (($_ob === '1')               ? '  (On -- unlimited)'
          :                                 ' bytes');

// H26 (18-Aug-2026): the conditional include of error_handler.php is gone. It only ever fired
// in DaDaBIK mode -- exactly the case where DaDaBIK's bootstrap has already included the file
// (measured) -- and was deliberately skipped in standalone mode, where it would have been the
// one situation that needed it. So it was a no-op in both branches. See H26.

$facts = [
    // Host first, always. This is the line that makes a stale paste self-evident.
    'Host'            => $_SERVER['HTTP_HOST']       ?? '?',
    'Server name'     => $_SERVER['SERVER_NAME']     ?? '?',
    'Server software' => $_SERVER['SERVER_SOFTWARE'] ?? '?',
    'Document root'   => $_SERVER['DOCUMENT_ROOT']   ?? '?',
    'HTTPS (to app)'  => !empty($_SERVER['HTTPS']) ? 'yes' : 'no',
    'X-Forwarded-For' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '(none)',
    'PHP'             => PHP_VERSION . ' (' . PHP_SAPI . ')',
    'DaDaBIK version' => $dadabik_config_version ?? '(unknown -- Dadabik not loaded)',
    'ionCube'         => function_exists('ioncube_loader_version') ? ioncube_loader_version() : '(not loaded)',
    'upload_max'      => ini_get('upload_max_filesize'),
    'post_max'        => ini_get('post_max_size'),
    'memory_limit'    => ini_get('memory_limit'),
    'max_execution'   => ini_get('max_execution_time') . 's',
    'display_errors'  => ini_get('display_errors') ? 'ON -- should be off in production' : 'off',
    'output_buffering' => ($_ob === '' ? '"" (empty)' : $_ob) . $_ob_note,
    'max_input_vars'  => ini_get('max_input_vars'),
    'PHP timezone'    => date_default_timezone_get()
                         . ' (ini: ' . (ini_get('date.timezone') ?: 'unset -- UTC assumed') . ')',
    'PHP clock'       => date('Y-m-d H:i:s') . ' local / ' . gmdate('Y-m-d H:i:s') . ' UTC',
    'Mode'            => $_cp_standalone ? 'STANDALONE' : 'Dadabik',
    'Page gate'       => $_cp_standalone ? 'standalone token' : $_cp_gate_note,
    'Generated'       => date('Y-m-d H:i:s'),
];

// Database identity -- only in Dadabik mode, where $conn is already open (security.md sec.4).
// Standalone deliberately does no database work: that would mean credentials in a
// web-readable file. Pre-deployment DB checks belong in a separate throwaway script.
if (!$_cp_standalone) {
    try {
        $facts['Database']  = $conn->query('SELECT DATABASE()')->fetchColumn();
        $facts['DB server'] = $conn->query('SELECT VERSION()')->fetchColumn();
        // The sql_mode THIS connection runs under, with the server's default beside it when the
        // two differ: an application that sets its own (GPBM H50, 6 Oct 2026) or a shared host
        // whose default is not what the application needs both show here, in the web runtime,
        // which is the only place the answer counts. Added 6 Oct 2026 (GPBM Q6).
        $_cp_sm = $conn->query('SELECT @@SESSION.sql_mode AS s, @@GLOBAL.sql_mode AS g')->fetch(PDO::FETCH_ASSOC);
        $_cp_show = static fn (string $m): string => $m === '' ? '(empty)' : $m;
        $facts['DB sql_mode'] = $_cp_show((string)$_cp_sm['s'])
            . ($_cp_sm['s'] === $_cp_sm['g'] ? ' (= server default)'
                                             : ' (server default: ' . $_cp_show((string)$_cp_sm['g']) . ')');
        // Which SERVER, not which account: @@time_zone belongs to the server this
        // connection landed on, and two instances on one hosting account may or may not
        // share one. Printing @@hostname means that is read rather than assumed.
        $_cp_tz = $conn->query(
            "SELECT @@hostname AS h, @@time_zone AS tz, @@system_time_zone AS stz,
                    DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s') AS lo,
                    DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s') AS ut,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS off_secs"
        )->fetch(PDO::FETCH_ASSOC);
        $facts['DB host']     = (string)$_cp_tz['h'];
        $facts['DB timezone'] = $_cp_tz['tz'] . ' (system: ' . $_cp_tz['stz'] . ')';
        $facts['DB clock']    = $_cp_tz['lo'] . ' local / ' . $_cp_tz['ut'] . ' UTC';
        // The arithmetic nobody should be left to do. Both readings come from inside
        // THIS request on THIS host, which is the pair that was got wrong on 8 Sep 2026.
        $_cp_fmt = static function (int $s): string {
            $sign = $s < 0 ? '-' : '+'; $s = abs($s);
            return sprintf('%s%dh %02dm', $sign, intdiv($s, 3600), intdiv($s % 3600, 60));
        };
        $_cp_zone = (int)$_cp_tz['off_secs'] - (int)date('Z');
        $facts['PHP vs DB'] = $_cp_zone === 0
            ? 'same offset today -- CURRENT_TIMESTAMP and date() write the same value'
            : 'DB writes ' . $_cp_fmt($_cp_zone)
              . ' from PHP -- CURRENT_TIMESTAMP and date() do NOT agree on this host';
        // A zone difference and a wrong clock look identical unless they are separated.
        $_cp_skew = strtotime($_cp_tz['ut'] . ' UTC') - time();
        $facts['Clock skew'] = abs($_cp_skew) <= 2
            ? $_cp_skew . 's (both agree on what UTC is)'
            : $_cp_skew . 's -- the two disagree on UTC itself. That is a clock fault, not a timezone';
    } catch (Throwable $e) {
        $facts['Database'] = 'lookup failed: ' . $e->getMessage();
    }
}

// Named explicitly rather than dumping get_loaded_extensions(): a per-version extension list can
// silently drop one of these after a PHP version switch, and nothing announces it until
// something fails to load. Dadabik will not start without pdo_mysql or mbstring.
$required = ['pdo_mysql','mysqli','mbstring','gd','zip','curl','openssl','json','fileinfo', 'ionCube Loader'];

if ($_cp_standalone) {
    // No Dadabik chrome and no w3.css available -- plain text, which also pastes cleanly.
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($facts as $k => $v) { printf("%-16s: %s\n", $k, $v); }
    echo "\n";
    foreach ($required as $e) {
        printf("ext %-10s: %s\n", $e, extension_loaded($e) ? 'yes' : 'MISSING');
    }
    echo "\n*** STANDALONE MODE IS ACTIVE ***\n"
       . "Set \$_cp_whoami_token back to '' (and delete this file) once Dadabik is installed.\n";
    return;
}

echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'"
   . " style='padding-bottom:16px'>";
echo "<div class='w3-panel w3-theme'><h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>"
   . "<b>Environment</b></h1></div>";

// w3-table does not reliably pad cells -- explicit padding, see style-guide.md sec.1
echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black'"
   . " style='width:55%;min-width:480px'>";
foreach ($facts as $k => $v) {
    echo "<tr><td style='padding:4px 8px'><b>" . htmlspecialchars($k) . "</b></td>"
       . "<td style='padding:4px 8px'>" . htmlspecialchars((string)$v) . "</td></tr>";
}
echo "</table>";

echo "<p>";
foreach ($required as $e) {
    echo "<span style='padding:2px 8px'>" . htmlspecialchars($e) . ": "
       . (extension_loaded($e) ? 'yes' : '<b>MISSING</b>') . "</span>";
}
echo "</p>";

echo "</div>";
// ===========================================================================
// OPTIONAL: custom-page globals probe. Off unless $_cp_whoami_probe is set above.
//
// WHY THIS EXISTS: Dadabik documents five globals for custom pages -- $conn,
// $current_user, $current_id_group, $current_user_is_administrator, $quote. None of
// them carries the numeric users-table id, which is what most of our own tables store
// as a foreign key (Civ IV: iv_entries.checked_by, iv_entries.user_id, both
// int unsigned). So either an undocumented global holds it, or every custom page needs
// a username->id lookup. common_start.php is ionCube-encoded, so this cannot be settled
// by reading the source. It has to be measured, in the inclusion context, which is
// exactly the context this page already runs in.
//
// METHOD: resolve the expected id the long way, then scan every scalar global and
// session value for that exact number. A name-based search would miss an undocumented
// key. A value-based search cannot.
//
// PORTABLE ACROSS INSTANCES: the users table and its field names come from Dadabik's
// own $users_table_name / $users_table_id_field / $users_table_username_field, not from
// a hardcoded prefix, so this file stays byte-identical on all deploy_whoami.sh targets.
if ($_cp_whoami_probe && !$_cp_standalone) {

    // user_identity.php, where this instance has it, was included by the gate above.
    // Every call to one of its functions below is guarded, so this block renders on an
    // instance that does not have it instead of fatalling on the first call.

    // Local to this block, _cp_-prefixed like everything else here, so nothing can
    // collide with a Dadabik global.
    $_cp_probe_h = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };
    // Never print anything credential-shaped, even to an administrator.
    $_cp_probe_secret = static function (string $name): bool {
        return (bool)preg_match('/pass|pwd|secret|token|salt|hash|api|key/i', $name);
    };

    // --- the expected answer, resolved the way a custom page has to today ---
    $_cp_probe_expected = null;
    $_cp_probe_error    = null;
    try {
        $_cp_probe_sql = 'SELECT ' . $quote . $users_table_id_field . $quote
                       . ' FROM '  . $quote . $users_table_name     . $quote
                       . ' WHERE ' . $quote . $users_table_username_field . $quote . ' = :u LIMIT 1';
        $_cp_probe_st = $conn->prepare($_cp_probe_sql);
        $_cp_probe_st->execute([':u' => $current_user]);
        $_cp_probe_v = $_cp_probe_st->fetchColumn();
        $_cp_probe_expected = ($_cp_probe_v === false) ? null : (int)$_cp_probe_v;
    } catch (Throwable $e) {
        $_cp_probe_error = $e->getMessage();
    }

    // --- value scan: is that number already sitting in a global or the session? ---
    $_cp_probe_hits = [];
    if ($_cp_probe_expected !== null) {
        foreach ($GLOBALS as $_cp_k => $_cp_v) {
            if ($_cp_k === 'GLOBALS' || $_cp_probe_secret($_cp_k)) { continue; }
            if (is_scalar($_cp_v) && !is_bool($_cp_v)
                && (string)$_cp_v === (string)$_cp_probe_expected) {
                $_cp_probe_hits[] = '$' . $_cp_k;
            }
        }
        foreach (($_SESSION ?? []) as $_cp_k => $_cp_v) {
            if ($_cp_probe_secret($_cp_k)) { continue; }
            if (is_scalar($_cp_v) && !is_bool($_cp_v)
                && (string)$_cp_v === (string)$_cp_probe_expected) {
                $_cp_probe_hits[] = "\$_SESSION['" . $_cp_k . "']";
            }
        }
    }

    // --- name scan, for context: what user-ish globals exist at all? ---
    $_cp_probe_named = [];
    foreach ($GLOBALS as $_cp_k => $_cp_v) {
        if ($_cp_k === 'GLOBALS' || !preg_match('/user|group|login|auth|current/i', $_cp_k)) { continue; }
        $_cp_probe_named[$_cp_k] = $_cp_probe_secret($_cp_k) ? '[redacted]'
            : (is_scalar($_cp_v) ? var_export($_cp_v, true) : '(' . gettype($_cp_v) . ')');
    }
    ksort($_cp_probe_named);

    $_cp_probe_sess = [];
    foreach (($_SESSION ?? []) as $_cp_k => $_cp_v) {
        $_cp_probe_sess[$_cp_k] = $_cp_probe_secret($_cp_k) ? '[redacted]'
            : (is_scalar($_cp_v) ? var_export($_cp_v, true) : '(' . gettype($_cp_v) . ')');
    }
    ksort($_cp_probe_sess);

    // --- render, in the same theme classes as the panel above ---
    echo "<div class='w3-panel w3-theme-d5 w3-text-white w3-round-xxlarge'"
       . " style='padding-bottom:16px'>";
    echo "<div class='w3-panel w3-theme'><h1 class='w3-text-white' style='text-shadow:1px 1px 0 #444'>"
       . "<b>Custom-page globals probe</b></h1></div>";

    echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black'"
       . " style='width:55%;min-width:480px'>";
    $_cp_probe_rows = [
        'Users table'      => $users_table_name,
        'Id field'         => $users_table_id_field,
        'Username field'   => $users_table_username_field,
        '$current_user'    => var_export($current_user, true),
        'Expected id'      => $_cp_probe_error !== null
                                ? 'LOOKUP FAILED: ' . $_cp_probe_error
                                : var_export($_cp_probe_expected, true),
    ];

    // The item 3 and item 4 accessors, on the instances that have them. Guarded one
    // function at a time rather than on $_cp_have_uid_lib alone, so a partial or older
    // copy of the library degrades to a missing row instead of a fatal error.
    if (function_exists('hof_current_user_id')) {
        $_cp_probe_rows['hof_current_user_id()'] = var_export(hof_current_user_id(), true);
    }
    if (function_exists('hof_current_user_id_source')) {
        $_cp_probe_rows['resolved via'] = hof_current_user_id_source();
    }
    if (function_exists('hof_user_can_vet')) {
        $_cp_probe_rows['hof_user_can_vet()'] = var_export(hof_user_can_vet(), true);
    }
    if (function_exists('hof_user_can_vet_explain')) {
        $_cp_vet = hof_user_can_vet_explain();
        $_cp_probe_rows['vet: staff_check id']  = var_export($_cp_vet['page_id'], true);
        $_cp_probe_rows['vet: groups held']     = implode(', ', $_cp_vet['groups']);
        $_cp_probe_rows['vet: multiple groups'] = var_export($_cp_vet['multiple_groups'], true);
    }
    if (!$_cp_have_uid_lib) {
        $_cp_probe_rows['user_identity.php'] = 'not on this instance -- the rows it fills are absent';
    }

    $_cp_probe_rows['$current_user_is_administrator'] =
        var_export($GLOBALS['current_user_is_administrator'] ?? null, true);
    $_cp_probe_rows['Exposed already?'] = $_cp_probe_expected === null
                                ? 'cannot tell -- no expected id'
                                : ($_cp_probe_hits ? implode(', ', $_cp_probe_hits) : 'NO -- nothing holds it');

    foreach ($_cp_probe_rows as $_cp_k => $_cp_v) {
        echo "<tr><td style='padding:4px 8px'><b>" . $_cp_probe_h($_cp_k) . "</b></td>"
           . "<td style='padding:4px 8px'>" . $_cp_probe_h($_cp_v) . "</td></tr>";
    }
    echo "</table>";

    if ($_cp_probe_hits) {
        echo "<p class='w3-text-white'><i>A match can be coincidence -- another id that happens "
           . "to share the value. Confirm by loading this page as a second user whose id differs.</i></p>";
    }

    foreach (['User-ish globals' => $_cp_probe_named, '$_SESSION' => $_cp_probe_sess] as $_cp_title => $_cp_rows) {
        echo "<h4 class='w3-text-white'>" . $_cp_probe_h($_cp_title)
           . " (" . count($_cp_rows) . ")</h4>";
        echo "<table class='w3-table w3-striped w3-bordered w3-theme-l5 w3-text-black'"
           . " style='width:55%;min-width:480px'>";
        foreach ($_cp_rows as $_cp_k => $_cp_v) {
            echo "<tr><td style='padding:4px 8px'><code>" . $_cp_probe_h($_cp_k) . "</code></td>"
               . "<td style='padding:4px 8px'>" . $_cp_probe_h($_cp_v) . "</td></tr>";
        }
        echo "</table>";
    }

    echo "</div>";
}

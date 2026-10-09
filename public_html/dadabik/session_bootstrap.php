<?php
// session_bootstrap.php - GameplanPBM - rev 002 - 9 Oct 2026 (H57)
// rev 002: after the session starts, an error handler swallows exactly the four complaints DaDaBIK's
//          include/common_start.php raises against an already-active session (measured on dev 9 Oct 2026,
//          four log lines per page view: session_name, ini_set, session_set_cookie_params, session_start
//          ignored). Those refusals are what make the 30-day settings win; the log should not pay for
//          them. Everything else falls through to the standard handler exactly as before. If DaDaBIK
//          installs its own handler before common_start.php runs, ours is replaced and the lines come
//          back: the dev measurement (RUNBOOK_h57b step 2b) is the proof either way.
// rev 001: 8 Oct 2026, first issue.
// Byte-identical on every host. Copied from Civ's 4hof/session_bootstrap.php rev 002 (12 Sep 2026),
// the shape proved on MountZion and 20i; the per-host facts come from env_bootstrap.php, which
// .user.ini names by an absolute path (auto_prepend_file), so this runs BEFORE DaDaBIK's index.php.
//
// Why: DaDaBIK's own session cookie has no lifetime (dies with the browser; whoami V17/V18,
// 8 Oct 2026) and the server-side file expires on each host's own terms (dev: Debian's sweep at
// 24 minutes idle; 20i: a 1-in-1,000 collector). A coach's login is to last $lifetime on both.
//
// What it does, in order: refuse unless env_bootstrap declared the three constants; refuse unless
// the private session directory exists and is writable (never mkdir: a store nobody chose);
// point the session at it; 30 days for the file (gc_maxlifetime) AND the cookie (cookie_lifetime);
// PHP's own collector ON for this directory (gc 1/100: nothing else sweeps a private path;
// Debian's sessionclean only knows php.ini's path); the session NAME is DaDaBIK's own for this
// host (GPBM_SESSION_NAME = $dadabik_session_name), so DaDaBIK's later session_name() call is a
// no-op rather than a collision; start it; re-issue the cookie with its expiry on every request
// and once more at shutdown, so whatever DaDaBIK sets later cannot shorten it.

$lifetime = 2592000;                 // 30 days, cookie and file alike (the two must not drift: see hof_session_gc.sh)
$path     = '/';
$samesite = 'Lax';
$httponly = true;

if (!defined('GPBM_ENV') || !defined('GPBM_SESSION_PATH') || !defined('GPBM_SESSION_NAME')) {
    error_log('session_bootstrap: GPBM_ENV / GPBM_SESSION_PATH / GPBM_SESSION_NAME not declared - check env_bootstrap.php');
    http_response_code(500);
    exit('Session configuration missing.');
}
$sessionPath = (string)GPBM_SESSION_PATH;
if (!is_dir($sessionPath) || !is_writable($sessionPath)) {
    error_log('session_bootstrap: session path not usable: ' . $sessionPath . ' (web user '
        . (function_exists('posix_getpwuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?')
        . ')');
    http_response_code(500);
    exit('Session store not usable.');
}
ini_set('session.save_path', $sessionPath);

$secure = (GPBM_ENV === 'prod');     // prod is HTTPS; forced off on http dev to avoid a login loop
$SESSION_NAME = (string)GPBM_SESSION_NAME;

ini_set('session.gc_maxlifetime', (string)$lifetime);
ini_set('session.cookie_lifetime', (string)$lifetime);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
ini_set('session.use_strict_mode', '1');

$alreadyActive = (session_status() === PHP_SESSION_ACTIVE);
session_name($SESSION_NAME);
if (!$alreadyActive) {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => $path,
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite,
    ]);
    session_start();
    if (empty($_SESSION['__prestarted'])) {
        $_SESSION['__prestarted'] = time();
        session_regenerate_id(true);
    }
}

setcookie(session_name(), session_id(), [
    'expires'  => time() + $lifetime,
    'path'     => $path,
    'secure'   => $secure,
    'httponly' => $httponly,
    'samesite' => $samesite,
]);

// rev 002: DaDaBIK's own session calls, refused because the session is already running. Only these four
// messages, only at E_WARNING / E_NOTICE; anything else returns false and goes where it always went.
set_error_handler(static function (int $errno, string $errstr): bool {
    static $expected = [
        'Session name cannot be changed when a session is active',
        'Session ini settings cannot be changed when a session is active',
        'Session cookie parameters cannot be changed when a session is active',
        'Ignoring session_start() because a session is already active',
    ];
    foreach ($expected as $e) {
        if (strpos($errstr, $e) !== false) {
            return true;
        }
    }
    return false;
}, E_WARNING | E_NOTICE);

register_shutdown_function(function () use ($lifetime, $path, $secure, $httponly, $samesite) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    }
});

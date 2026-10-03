<?php
declare(strict_types=1);

/**
 * tools/env_loader.php - GameplanPBM's .env loader and Env facade (item H41, 3 Oct 2026).
 * A copy of Civ's tools/env_loader.php (rev of 15 Sep 2026, civ2026), GPBM_ROOT renamed GPBM_ROOT.
 * Loads <project root>/.env (this file's parent directory's .env) and exposes Env::get().
 *
 * NEEDS-READ: www-data
 *   Included by served code: dadabik/include/config_custom.php. At 600 the web user cannot
 *   read it and every page fails. Kept alandev:www-data 640 (shared §12).
 */

// --- helpers
$setVar = static function (string $k, string $v): void {
    putenv("$k=$v");
    $_ENV[$k]    = $v;
    $_SERVER[$k] = $v;
};

$parseLine = static function (string $line): ?array {
    // trim, strip UTF-8 BOM if present
    $line = ltrim(rtrim($line), "\xEF\xBB\xBF");
    if ($line === '' || $line[0] === '#') return null;

    // support "export KEY=VALUE"
    if (str_starts_with($line, 'export ')) {
        $line = substr($line, 7);
    }

    // split on first "=" only
    $pos = strpos($line, '=');
    if ($pos === false) return null;

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));

    // strip surrounding single/double quotes if present
    if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
        $q = $val[0];
        if (substr($val, -1) === $q) {
            $val = substr($val, 1, -1);
        }
    }

    return [$key, $val];
};

// --- locate project root and .env path relative to THIS file
$defaultRoot = realpath(__DIR__ . '/..');
$envPath     = $defaultRoot . '/.env';

// --- 1) Load .env FIRST (so GPBM_ROOT from .env can win)
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $kv = $parseLine($line);
        if ($kv === null) continue;
        [$k, $v] = $kv;

        // If already provided by the webserver (e.g., SetEnv), keep it.
        // Otherwise allow .env to define/override.
        $existing = getenv($k);
        if ($existing === false || $existing === '') {
            $setVar($k, $v);
        }
    }
}

// --- 2) Ensure GPBM_ROOT is set (prefer .env, else fallback)
$root = getenv('GPBM_ROOT');
if ($root === false || $root === '') {
    $root = $defaultRoot;
    $setVar('GPBM_ROOT', $root);
}

// --- 3) Provide Env facade (and a no-op load()) for legacy callers
if (!class_exists('Env')) {
    final class Env {
        public static function get(string $key, $default = null) {
            $v = getenv($key);
            return ($v === false || $v === '') ? $default : $v;
        }
        public static function load($path = null): bool {
            // .env is already loaded by this file; accept legacy calls
            return true;
        }
    }
}

// --- 4) (Optional) tiny convenience helper
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        return Env::get($key, $default);
    }
}

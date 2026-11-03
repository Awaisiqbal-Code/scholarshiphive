<?php
declare(strict_types=1);

define('APP', __DIR__);
define('ROOT', dirname(__DIR__));

$CONFIG = require APP . '/config.php';
if (is_file(APP . '/config.local.php')) {
    $CONFIG = array_merge($CONFIG, require APP . '/config.local.php');
}

date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

// ---------- security headers ----------
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; media-src 'self' https://drive.google.com; frame-src 'self' https://drive.google.com https://docs.google.com; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

// ---------- base URL ----------
function base_url(): string {
    global $CONFIG;
    static $b = null;
    if ($b !== null) return $b;
    if (!empty($CONFIG['base_url'])) return $b = rtrim($CONFIG['base_url'], '/');
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = realpath(ROOT) ?: ROOT;
    $b = '';
    if ($doc !== '' && str_starts_with($root, $doc)) {
        $b = str_replace('\\', '/', substr($root, strlen($doc)));
    }
    return $b = rtrim($b, '/');
}
function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }

// ---------- session ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($https && !headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000');
}
session_name('PTPSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => base_url() === '' ? '/' : base_url() . '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

require APP . '/db.php';
require APP . '/helpers.php';
require APP . '/stages.php';
require APP . '/sheets.php';
require APP . '/forms.php';
require APP . '/auth.php';
require APP . '/files.php';
require APP . '/documents.php';
require APP . '/download.php';
require APP . '/tasks.php';
require APP . '/migrations.php';

// The database must be at the version this code expects. Pages other than sign-in / sign-out and
// Admin > System stop with a clear message instead of failing on a missing column.
if (PHP_SAPI !== 'cli' && schema_version() < SCHEMA_VERSION) {
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $open = array_map(fn($f) => realpath(ROOT . '/' . $f) ?: ROOT . '/' . $f,
        ['admin/upgrade.php', 'admin/login.php', 'officer/login.php', 'tax/login.php', 'student/login.php', 'logout.php', 'index.php']);
    $allowed = in_array($script, $open, true);
    if (!$allowed) {
        $me = current_user();
        if ($me && is_full_admin($me)) redirect('admin/upgrade.php');
        http_response_code(503);
        exit('The portal is being updated. An admin needs to open Admin > System and run the database update. Please try again shortly.');
    }
}

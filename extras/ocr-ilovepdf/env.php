<?php
declare(strict_types=1);

/**
 * Reads KEY=VALUE lines from portal/app/.env. Values are kept in memory for this request only (not put
 * into the process environment), so an edit to the file takes effect on the next page load. A variable
 * the server itself sets (cPanel / Apache SetEnv / hosting panel) wins over the file. The app folder is
 * never served to the browser (.htaccess "Require all denied"), so secrets in this file stay on the server.
 */
function load_env_file(string $path): void {
    $GLOBALS['APP_ENV_FILE'] = [];
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) continue;
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        $GLOBALS['APP_ENV_FILE'][$k] = $v;
    }
}

/** Read one setting: the server's environment first, then portal/app/.env ('' / $default when not set). */
function env(string $k, string $default = ''): string {
    $v = getenv($k);
    if ($v === false || $v === '') $v = $_SERVER[$k] ?? $GLOBALS['APP_ENV_FILE'][$k] ?? '';
    return (string)$v === '' ? $default : (string)$v;
}

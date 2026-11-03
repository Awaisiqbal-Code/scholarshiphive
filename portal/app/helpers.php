<?php
declare(strict_types=1);

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

function redirect(string $path): never {
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

// ---------- flash messages ----------
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

// ---------- input ----------
function post(string $k): string { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }
function get(string $k): string { $v = $_GET[$k] ?? ''; return is_string($v) ? trim($v) : ''; }
function valid_date(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}
function fmt_date(?string $d): string {
    if (!$d) return '';
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : $d;
}
function safe_link(?string $u): ?string {
    $u = trim((string)$u);
    return preg_match('#^https?://#i', $u) ? $u : null;
}

function random_password(int $len = 10): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function log_action(string $action, ?int $studentId = null): void {
    q('INSERT INTO activity_log (user_id, student_id, action, ip, created_at) VALUES (?,?,?,?,?)',
      [current_user()['id'] ?? null, $studentId, mb_substr($action, 0, 255), client_ip(), now()]);
}

function make_username(string $name): string {
    $p = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', '', $name)), -1, PREG_SPLIT_NO_EMPTY);
    $base = ($p[0] ?? 'student') . '.' . ($p[1] ?? 'student');
    $u = $base; $n = 1;
    while (q('SELECT 1 FROM users WHERE username = ?', [$u])->fetchColumn()) { $n++; $u = $base . $n; }
    return $u;
}

// ---------- encryption for stored account passwords (AES-256-GCM) ----------
function app_key(): string {
    global $CONFIG;
    $k = base64_decode((string)($CONFIG['app_key'] ?? ''), true);
    if ($k === false || strlen($k) !== 32) { http_response_code(500); exit('app_key in app/config.php is missing or invalid.'); }
    return $k;
}
function enc(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}
function dec(?string $stored): string {
    if (!$stored || !str_starts_with($stored, 'v1:')) return '';
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
function rs(?string $v): string { return $v === null || $v === '' ? '' : 'Rs ' . number_format((float)$v); }

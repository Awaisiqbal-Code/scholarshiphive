<?php
declare(strict_types=1);
// Instant document upload (JSON). A chosen file is uploaded and checked straight away, kept privately, and
// shown under its document as "added — not saved yet". It is attached to the student when the form is saved;
// until then it can be removed. Used by the student form (staff) and the student portal's documents panel.
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$out = function (array $j, int $code = 200): never { http_response_code($code); echo json_encode($j); exit; };
$u = current_user();
if (!$u) $out(['ok' => false, 'error' => 'You are signed out. Sign in again and add the file again.'], 401);
if (!is_post()) $out(['ok' => false, 'error' => 'Use the Add file button.'], 405);
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $out(['ok' => false, 'error' => 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.'], 413);
$t = $_POST['_csrf'] ?? '';
if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) $out(['ok' => false, 'error' => 'This page expired. Reload it and add the file again.'], 400);
$_SESSION['last'] = time();

$isStudent = $u['role'] === 'student';
if (!$isStudent && !can_manage_files($u)) $out(['ok' => false, 'error' => 'You cannot add documents.'], 403);

// Remove a file that was added but not saved yet.
if (post('action') === 'discard') {
    $m = stage_get(post('token'), $u);
    if ($m) stage_delete($m['token']);
    $out(['ok' => true]);
}

$sid = (int)post('student_id');
$country = post('country');
if ($sid) {
    $s = student_for_user($u, $sid);
    if (!$s) $out(['ok' => false, 'error' => 'Student not found.'], 404);
    $country = (string)$s['country'];
} elseif ($isStudent || !in_array($country, user_countries($u), true)) {
    $out(['ok' => false, 'error' => 'Student not found.'], 404);
}
$key = post('doc_key');
if ($key !== 'other' && !isset(country_docs($country)[$key])) $out(['ok' => false, 'error' => 'Unknown document.'], 400);

$res = stage_store($_FILES['file'] ?? [], $u, $key, $sid);
if (is_string($res)) $out(['ok' => false, 'error' => $res], 422);
$out(['ok' => true, 'token' => $res['token'], 'name' => $res['name'], 'size' => fmt_size((int)$res['size']), 'icon' => file_icon(['original_name' => $res['name'], 'mime' => ''])]);

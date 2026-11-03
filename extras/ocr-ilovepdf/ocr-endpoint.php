<?php
declare(strict_types=1);
// OCR endpoint used by the student form: upload one document, read it with iLovePDF on this server,
// map the text to the selected country's form fields and return them as JSON for the person to review.
// Nothing is saved to the student here; the form is saved only when the person presses Save.
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$out = function (array $data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

$u = current_user();
if (!$u || !(is_admin($u) || $u['role'] === 'officer')) $out(['ok' => false, 'kind' => 'auth', 'error' => 'Sign in as staff to scan documents.'], 403);
if (!is_post()) $out(['ok' => false, 'kind' => 'upload', 'error' => 'Use the Scan button on the student form.'], 405);
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $out(['ok' => false, 'kind' => 'upload', 'error' => 'The file is too large for this server. The limit is ' . fmt_size(max_upload_bytes()) . '.'], 413);
}
$t = $_POST['_csrf'] ?? '';
if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) $out(['ok' => false, 'kind' => 'upload', 'error' => 'This page expired. Reload it and try again.'], 400);
$_SESSION['last'] = time();

$country = post('country');
if (!valid_country($country) || !in_array($country, user_countries($u), true)) $out(['ok' => false, 'kind' => 'upload', 'error' => 'Choose a country you work on.'], 400);
$sid = (int)post('student_id');
if ($sid && !student_for_user($u, $sid)) $out(['ok' => false, 'kind' => 'upload', 'error' => 'Student not found.'], 404);
$docType = isset(OCR_DOC_TYPES[post('doc_type')]) ? post('doc_type') : 'auto';

// ---- the uploaded file ----
$F = $_FILES['file'] ?? null;
$err = $F['error'] ?? UPLOAD_ERR_NO_FILE;
if ($err === UPLOAD_ERR_NO_FILE) $out(['ok' => false, 'kind' => 'upload', 'error' => 'Choose a PDF, JPG or PNG file to scan.'], 400);
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $out(['ok' => false, 'kind' => 'upload', 'error' => 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.'], 413);
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$F['tmp_name'])) $out(['ok' => false, 'kind' => 'upload', 'error' => 'The upload did not complete. Try again.'], 400);
if ((int)$F['size'] <= 0) $out(['ok' => false, 'kind' => 'upload', 'error' => 'The file is empty.'], 400);
if ((int)$F['size'] > max_upload_bytes()) $out(['ok' => false, 'kind' => 'upload', 'error' => 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.'], 413);
$name = mb_substr(preg_replace('/[\x00-\x1F\x7F"<>:\/\\\\|?*]+/u', '_', basename(str_replace('\\', '/', (string)$F['name']))) ?: 'document', -200);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($F['tmp_name']) ?: '';
$ok = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!isset($ok[$mime])) $out(['ok' => false, 'kind' => 'upload', 'error' => 'Only PDF, JPG and PNG files can be scanned (this file is ' . ($mime ?: 'unknown') . ').'], 415);

// Keep a private copy for 24 hours so it can be attached to the student when the form is saved.
ocr_tmp_cleanup();
$token = bin2hex(random_bytes(16));
$keep = ocr_tmp_dir() . '/' . $token;
if (!move_uploaded_file($F['tmp_name'], $keep)) $out(['ok' => false, 'kind' => 'upload', 'error' => 'The portal cannot save files. Check that app/storage is writable.'], 500);
file_put_contents($keep . '.json', json_encode(['user' => (int)$u['id'], 'name' => $name, 'mime' => $mime, 'created' => time()]));

try {
    $res = ocr_extract_text($keep, $name, $mime);
} catch (OcrError $e) {
    ocr_tmp_delete($token);   // nothing to attach when the scan failed
    log_action('OCR failed for ' . $name . ' (' . $e->kind . ')', $sid ?: null);
    $out(['ok' => false, 'kind' => $e->kind, 'error' => $e->getMessage()], $e->kind === 'config' ? 503 : 422);
}

$form = country_fields($country);
$allowed = array_values(array_intersect(OCR_FIELDS, array_keys($form)));
$parsed = ocr_parse_fields($res['text'], $docType, $allowed);
$fields = [];
foreach ($parsed['fields'] as $k => $v) $fields[$k] = $v + ['label' => $form[$k]['label']];
$missing = array_map(fn($k) => ['key' => $k, 'label' => $form[$k]['label']], $parsed['missing']);
$docKey = match ($parsed['type']) { 'passport' => 'passport_front', 'cnic' => 'cnic_front', 'transcript' => 'bachelor', 'english' => 'english', 'cv' => 'cv', default => 'other' };

log_action('OCR scan of ' . $name . ': ' . count($fields) . ' field(s) read', $sid ?: null);
$out([
    'ok' => true,
    'type' => $parsed['type'],
    'type_label' => OCR_DOC_TYPES[$parsed['type']] ?? $parsed['type'],
    'fields' => $fields,
    'missing' => $missing,
    'text' => mb_substr($res['text'], 0, 6000),
    'chars' => mb_strlen($res['text']),
    'token' => $token,
    'file' => $name,
    'doc_key' => $docKey = (isset(country_docs($country)[$docKey]) ? $docKey : 'other'),
    'doc_label' => $docKey === 'other' ? 'Other document' : doc_label($country, $docKey),
]);

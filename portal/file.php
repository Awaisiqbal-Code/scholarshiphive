<?php
declare(strict_types=1);
// View (inline / modal) or download one student file: file.php?id=12 (view), &embed=1 (modal), or &dl=1 (download).
// Every request checks the signed-in person, whether they may see that student's file, and blocks media files on document slots.
require __DIR__ . '/app/bootstrap.php';
$u = require_signed_in();

$f = q('SELECT * FROM student_files WHERE id = ?', [(int)get('id')])->fetch();
if (!$f || !can_open_file($u, $f)) { http_response_code(404); exit('File not found.'); }

$isDocSlot = empty($f['meeting_id']) && ($f['doc_key'] ?? '') !== 'meeting';
if ($isDocSlot && is_media_file($f)) {
    http_response_code(404);
    exit('Video and audio recordings cannot be displayed as a document or offer letter.');
}

$download = get('dl') === '1' || (!is_gdrive_file($f) && !file_viewable($f));
$embed = get('embed') === '1';
$path = file_path($f);

if (is_gdrive_file($f)) {
    // A Drive document is copied into the portal the first time it is opened; after that the portal serves its own copy.
    // Only a file that cannot be copied (not shared publicly in Drive) is opened in Google Drive instead.
    if (!gdrive_cached($f)) gdrive_fetch_many([$f], microtime(true) + 25, 1);
    if (!gdrive_cached($f)) {
        $gid = gdrive_file_id($f);
        log_action(($download ? 'Downloaded' : 'Viewed') . ' Drive file ' . mb_substr($f['original_name'], 0, 120) . ' (in Google Drive)', (int)$f['student_id']);
        $target = $download && !$embed ? 'https://drive.usercontent.google.com/download?id=' . rawurlencode($gid) . '&export=download'
                                       : 'https://drive.google.com/file/d/' . rawurlencode($gid) . '/preview';
        header('Location: ' . $target, true, 302);
        exit;
    }
    $download = get('dl') === '1' || !file_viewable(['original_name' => $f['original_name'], 'stored_name' => '']);
}

if (!is_file($path)) {
    http_response_code(404);
    exit('The file is missing from storage. Ask the office to upload it again.');
}

// Verify actual file content on disk is never a video/audio when attached to a document slot
$actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: $f['mime'];
if ($isDocSlot && (str_starts_with($actualMime, 'video/') || str_starts_with($actualMime, 'audio/'))) {
    http_response_code(404);
    exit('Video and audio recordings cannot be displayed as a document or offer letter.');
}

log_action(($download ? 'Downloaded' : 'Viewed') . ' file ' . mb_substr($f['original_name'], 0, 120), (int)$f['student_id']);

$name = $f['original_name'];
$ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
$size = filesize($path);

// Replace the page headers from bootstrap: a file needs no scripts, and PDFs / media must be allowed to show.
header_remove('Content-Security-Policy');
header($actualMime === 'application/pdf' && !$download
    ? "Content-Security-Policy: frame-ancestors 'self'"   // the browser's PDF viewer needs its own scripts
    : "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: private, no-store');
header('Content-Type: ' . ($download ? 'application/octet-stream' : $actualMime));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Accept-Ranges: bytes');

// Range requests let browsers seek inside audio / video recordings.
$start = 0; $end = $size - 1;
if (!$download && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
while (ob_get_level()) ob_end_clean();

$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1 << 16, $left));
    if ($chunk === false) break;
    echo $chunk; flush();
    $left -= strlen($chunk);
}
fclose($fh);

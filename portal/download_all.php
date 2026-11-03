<?php
declare(strict_types=1);
// One student's complete file as a ZIP: "Student details.pdf" (the record as on the sheet) and every document
// file itself, in a folder per document. Google Drive files are copied into the portal first, so the ZIP holds
// the real files and never links. Staff can add the meeting recordings (&rec=1). Students get their own file.
require __DIR__ . '/app/bootstrap.php';
$u = require_signed_in();

$sid = (int)(get('id') ?: get('student_id'));
$s = student_for_user($u, $sid);
if (!$s) { http_response_code(404); exit('Student record not found.'); }
$s = q('SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id WHERE s.id = ?', [$sid])->fetch();
$recordings = get('rec') === '1' && $u['role'] !== 'student';

@set_time_limit(0);
$rows = array_map(fn($x) => $x[1], student_zip_files($sid, (string)$s['country'], $recordings));
$fetched = gdrive_fetch_many($rows, microtime(true) + 90);

log_action('Downloaded the student file (ZIP with ' . count($rows) . ' file' . (count($rows) === 1 ? '' : 's') . ($recordings ? ', with recordings' : '') . ')', $sid);
$folder = zip_clean_name($s['name'] . ' - ' . country_name($s['country']));
$zip = zip_begin($folder . '.zip');
$failed = [];
zip_student($zip, $u, $s, $folder, $recordings, $fetched, $failed);
if ($failed) $zip->addString($folder . '/Files not downloaded.txt', failed_list_text($failed));
$zip->finish();

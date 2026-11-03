<?php
declare(strict_types=1);

// =====================================================================================
// Uploaded & Google Drive-indexed student files (documents and meeting recordings)
// and meetings. Local files are stored under app/storage/files/<student id>/ with a
// random name and no extension; Google Drive files store 'gdrive:<file_id>' in
// stored_name. Every view or download goes through file.php, which checks who is
// signed in and whether they may see that student.
// =====================================================================================

/** File types that may be uploaded: extension => [mime types finfo may report, can be viewed in the browser] */
const FILE_TYPES = [
    'pdf'  => [['application/pdf'], true],
    'jpg'  => [['image/jpeg'], true],
    'jpeg' => [['image/jpeg'], true],
    'png'  => [['image/png'], true],
    'webp' => [['image/webp'], true],
    'gif'  => [['image/gif'], true],
    'mp4'  => [['video/mp4', 'application/mp4'], true],
    'webm' => [['video/webm', 'audio/webm'], true],
    'mov'  => [['video/quicktime'], false],
    'mp3'  => [['audio/mpeg', 'audio/mp3'], true],
    'm4a'  => [['audio/mp4', 'audio/x-m4a', 'video/mp4'], true],
    'ogg'  => [['audio/ogg', 'video/ogg', 'application/ogg'], true],
    'wav'  => [['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true],
    'txt'  => [['text/plain'], true],
    'doc'  => [['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'], false],
    'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], false],
    'xls'  => [['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'], false],
    'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], false],
    'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'], false],
    'zip'  => [['application/zip', 'application/x-zip-compressed'], false],
];

const MEDIA_EXTS = ['mp4', 'webm', 'mov', 'mkv', 'mp3', 'm4a', 'ogg', 'wav'];

function storage_dir(): string {
    global $CONFIG;
    $d = rtrim((string)($CONFIG['storage_dir'] ?? ''), '/\\') ?: APP . '/storage/files';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function max_upload_bytes(): int {
    global $CONFIG;
    $mb = (int)($CONFIG['max_upload_mb'] ?? 20);
    $ini = fn(string $k) => (function ($v) { $v = trim((string)$v); $n = (int)$v; return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n }; })(ini_get($k));
    $limits = array_filter([$mb << 20, $ini('upload_max_filesize'), $ini('post_max_size')]);
    return $limits ? min($limits) : ($mb << 20);
}
function fmt_size(int $b): string {
    if ($b <= 0) return 'Drive file';
    return $b >= 1 << 20 ? round($b / (1 << 20), 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}
function file_ext(string $name): string { return strtolower(pathinfo($name, PATHINFO_EXTENSION)); }
function is_gdrive_file(array $f): bool { return str_starts_with((string)($f['stored_name'] ?? ''), 'gdrive:'); }
function gdrive_file_id(array $f): string { return is_gdrive_file($f) ? substr((string)$f['stored_name'], 7) : ''; }
function is_media_file(array $f): bool {
    $ext = file_ext((string)($f['original_name'] ?? ''));
    $mime = strtolower((string)($f['mime'] ?? ''));
    return in_array($ext, MEDIA_EXTS, true) || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
}
function file_viewable(array $f): bool {
    if (is_gdrive_file($f)) return true; // Google Drive preview supports PDF, images, docs, audio, and video
    return (FILE_TYPES[file_ext($f['original_name'])][1] ?? false);
}
function file_path(array $f): string {
    if (is_gdrive_file($f)) {
        $cacheDir = storage_dir() . '/gdrive_cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
        return $cacheDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', gdrive_file_id($f));
    }
    return storage_dir() . '/' . (int)$f['student_id'] . '/' . $f['stored_name'];
}


function accept_attr(bool $allowMedia = false): string {
    $exts = array_keys(FILE_TYPES);
    if (!$allowMedia) $exts = array_values(array_diff($exts, MEDIA_EXTS));
    return implode(',', array_map(fn($e) => '.' . $e, $exts));
}

/**
 * Save one uploaded file ($_FILES entry). Returns the new student_files id, or an error message (string).
 */
function store_upload(array $file, int $studentId, string $docKey, ?int $meetingId, array $u): int|string {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return 'Choose a file to upload.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return 'The upload did not complete. Try again.';
    $size = (int)$file['size'];
    if ($size <= 0) return 'The file is empty.';
    if ($size > max_upload_bytes()) return 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.';

    $name = basename(str_replace('\\', '/', (string)$file['name']));
    $name = preg_replace('/[\x00-\x1F\x7F"<>:\/\\\\|?*]+/u', '_', $name) ?: 'file';
    $name = mb_substr($name, -200);
    $ext = file_ext($name);
    if (!isset(FILE_TYPES[$ext])) return 'This file type is not allowed. Use PDF, an image, Word, Excel, audio or video.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, FILE_TYPES[$ext][0], true)) return 'The file content does not match its type (.' . $ext . ').';

    // Strict guard: never allow video/audio recordings to be uploaded as offer letters or documents
    if (!str_starts_with($docKey, 'meeting') && $meetingId === null && (in_array($ext, MEDIA_EXTS, true) || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/'))) {
        return 'Video and audio recordings belong in the Meetings section and cannot be uploaded as a document or offer letter.';
    }

    // Duplicate document detection using SHA-256 hash
    $fileHash = @hash_file('sha256', $file['tmp_name']);
    if ($fileHash) {
        $dup = q('SELECT id, original_name FROM student_files WHERE student_id = ? AND file_hash = ?', [$studentId, $fileHash])->fetch();
        if ($dup) {
            return 'Duplicate file detected: This file has already been uploaded as "' . $dup['original_name'] . '".';
        }
    }

    $docKey = doc_key_norm($docKey);
    // Determine version for this document key
    $lastVer = (int)q('SELECT MAX(version) FROM student_files WHERE student_id = ? AND doc_key = ?', [$studentId, $docKey])->fetchColumn();
    $version = $lastVer + 1;

    $dir = storage_dir() . '/' . $studentId;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'The portal cannot save files. Check that app/storage is writable.';
    $stored = bin2hex(random_bytes(20));
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'The portal cannot save files. Check that app/storage is writable.';

    q('INSERT INTO student_files (student_id, doc_key, meeting_id, original_name, stored_name, mime, size, file_hash, version, uploaded_by, uploaded_role, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [$studentId, $docKey, $meetingId, $name, $stored, $mime, $size, $fileHash, $version, (int)$u['id'], $u['role'], now()]);
    $newFileId = (int)db()->lastInsertId();

    // Saving a file under a document marks it received: work out the sheet's documents status again.
    if ($meetingId === null && !str_starts_with($docKey, 'meeting')) refresh_student_documents_status($studentId);

    return $newFileId;
}

function delete_file_row(array $f): void {
    $p = file_path($f);
    if (is_file($p)) @unlink($p);
    q('DELETE FROM student_files WHERE id = ?', [(int)$f['id']]);
}

/** May this user see this student's file area at all? Returns the student row or null. */
function student_for_user(array $u, int $studentId): ?array {
    if ($u['role'] === 'student') {
        if ((int)$u['student_id'] !== $studentId) return null;
        return q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch() ?: null;
    }
    [$w, $p] = scope_sql($u);
    return q("SELECT s.* FROM students s WHERE s.id = ? AND $w", array_merge([$studentId], $p))->fetch() ?: null;
}

/** Staff who may upload documents, change their status and manage meetings. The tax consultant only views. */
function can_manage_files(array $u): bool { return is_admin($u) || $u['role'] === 'officer'; }

/** May this user open this file? Students only see their own documents and meetings shared with them. */
function can_open_file(array $u, array $f): bool {
    if (!student_for_user($u, (int)$f['student_id'])) return false;
    if ($u['role'] === 'student' && $f['meeting_id']) {
        return (bool)q('SELECT student_can_see FROM student_meetings WHERE id = ?', [(int)$f['meeting_id']])->fetchColumn();
    }
    return true;
}
/**
 * May this user delete this file? No: a saved file (document or meeting recording) is part of the student's record
 * and stays. A file can only be removed before it is saved. (Deleting a whole student, full admin only, still
 * removes that student's files.)
 */
function can_delete_file(array $u, array $f): bool {
    return false;
}

/** Files of one student grouped: ['docs' => [doc_key => [files]], 'meetings' => [meeting_id => [files]]] */
function student_files(int $studentId): array {
    $out = ['docs' => [], 'meetings' => []];
    foreach (q('SELECT * FROM student_files WHERE student_id = ? ORDER BY created_at DESC, id DESC', [$studentId]) as $f) {
        if ($f['meeting_id']) {
            $out['meetings'][(int)$f['meeting_id']][] = $f;
        } else {
            // Defense-in-depth: never display a video/audio recording under a document or offer letter row
            if (is_media_file($f)) continue;
            $out['docs'][doc_key_norm($f['doc_key'])][] = $f;
        }
    }
    return $out;
}
function file_url(array $f, bool $download = false, bool $embed = false): string {
    return url('file.php?id=' . (int)$f['id'] . ($download ? '&dl=1' : '') . ($embed ? '&embed=1' : ''));
}

/** View / Download / Delete buttons for one file. */
function file_buttons(array $f, array $u, string $back): string {
    $o = '<span class="fbtns">';
    if (file_viewable($f)) {
        $o .= '<a class="btn xs" href="' . h(file_url($f)) . '" data-view="' . h(file_url($f, false, true)) . '" data-open="' . h(file_url($f)) . '" data-dl="' . h(file_url($f, true)) . '" data-title="' . h($f['original_name']) . '" target="_blank" rel="noopener">View</a>';
    }
    $o .= '<a class="btn xs" href="' . h(file_url($f, true)) . '">Download</a>';
    if (can_delete_file($u, $f)) {
        $o .= '<form method="post" action="' . h(url('files.php')) . '" class="inline" data-confirm="Delete ' . h($f['original_name']) . '?">' . csrf_field()
            . '<input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_id" value="' . (int)$f['id'] . '">'
            . '<input type="hidden" name="back" value="' . h($back) . '"><button class="btn xs bad-b" type="submit">Delete</button></form>';
    }
    return $o . '</span>';
}
function file_icon(array $f): string {
    $e = file_ext($f['original_name']);
    $m = strtolower((string)($f['mime'] ?? ''));
    $t = match (true) {
        $e === 'pdf' || $m === 'application/pdf' => 'PDF',
        in_array($e, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || str_starts_with($m, 'image/') => 'IMG',
        in_array($e, ['mp4', 'webm', 'mov', 'mkv'], true) || str_starts_with($m, 'video/') => 'VID',
        in_array($e, ['mp3', 'm4a', 'ogg', 'wav'], true) || str_starts_with($m, 'audio/') => 'AUD',
        in_array($e, ['doc', 'docx'], true) || str_contains($m, 'word') => 'DOC',
        in_array($e, ['xls', 'xlsx'], true) || str_contains($m, 'excel') || str_contains($m, 'sheet') => 'XLS',
        default => strtoupper(substr($e ?: 'doc', 0, 3)),
    };
    return '<span class="ficon ficon-' . h(strtolower($t)) . '">' . h($t) . '</span>';
}
/** One file line: icon, name, size, who uploaded, buttons. */
function file_line(array $f, array $u, string $back): string {
    $meta = fmt_size((int)$f['size']) . ' · ' . fmt_date(substr($f['created_at'], 0, 10));
    if ($f['uploaded_role'] === 'student') $meta .= ' · uploaded by student';
    elseif ($f['uploaded_role'] === 'drive') $meta .= ' · synced from Drive';
    return '<div class="fline">' . file_icon($f) . '<div class="fmeta"><b title="' . h($f['original_name']) . '">' . h(mb_strimwidth($f['original_name'], 0, 60, '…')) . '</b>'
        . '<span class="m">' . h($meta) . '</span></div>' . file_buttons($f, $u, $back) . '</div>';
}

/** Open-link button for an outside link (Drive, Zoom...). */
function link_button(?string $link, string $label = '', string $cls = 'btn sm'): string {
    $l = safe_link($link);
    if (!$l) return '';
    return '<a class="' . h($cls) . '" href="' . h($l) . '" target="_blank" rel="noopener noreferrer">' . h($label ?: 'Open ' . link_kind($l)) . ' ↗</a>';
}

/**
 * Check if a Drive folder link is shared by more than one student in the database
 * (e.g. Czech Republic or Austria master folders). Shared folders must not be exposed
 * directly to a single student in the student portal.
 */
function is_shared_drive_folder(?string $link, int $studentId): bool {
    $fid = drive_id($link);
    if ($fid === '') return false;
    $like = '%' . $fid . '%';
    $n = (int)q('SELECT COUNT(*) FROM students WHERE id != ? AND (documents_link LIKE ? OR drive_link LIKE ?)', [$studentId, $like, $like])->fetchColumn();
    return $n > 0;
}

/** Meetings added in the portal for one student. Students only get the ones shared with them. */
function student_meetings(int $studentId, bool $studentView = false): array {
    return q('SELECT m.*, u.name AS created_by_name FROM student_meetings m LEFT JOIN users u ON u.id = m.created_by WHERE m.student_id = ?'
        . ($studentView ? ' AND m.student_can_see = 1' : '') . ' ORDER BY m.meeting_date IS NULL, m.meeting_date DESC, m.id DESC', [$studentId])->fetchAll();
}
/** May this user add / edit this meeting kind? Tax consultant: tax meetings only. */
function can_edit_meeting(array $u, string $kind, array $student = []): bool {
    if (is_admin($u)) return true;
    if ($u['role'] === 'tax') return $kind === 'tax';
    if ($u['role'] !== 'officer') return false;
    // The 1st and 2nd processing meetings are front-end columns, the tax meeting is the tax section.
    $team = match ($kind) { 'initial', 'second' => 'frontend', 'tax' => 'tax', default => 'basic' };
    return can_edit_team($u, $team, $student);
}

// =====================================================================================
// Google Drive Folder Indexing & Country-Aware Document / Meeting Classification
// =====================================================================================

/** Parse size string like '462 KB' or '2.1 MB' into bytes. */
function parse_size_bytes(string $sizeStr): int {
    if (!preg_match('/([\d\.]+)\s*(kb|mb|gb|b)\b/i', $sizeStr, $m)) return 0;
    $n = (float)$m[1];
    return match (strtolower($m[2])) {
        'gb' => (int)round($n * (1 << 30)),
        'mb' => (int)round($n * (1 << 20)),
        'kb' => (int)round($n * 1024),
        default => (int)round($n),
    };
}

/** Determine MIME type and ensured filename extension from a Drive item's name and raw_title. */
function drive_item_mime_and_name(string $name, string $rawTitle): array {
    $ext = file_ext($name);
    $rt = strtolower(trim($rawTitle));
    if ($ext !== '' && isset(FILE_TYPES[$ext])) {
        return [FILE_TYPES[$ext][0][0], $name];
    }
    if (preg_match('/\bvideo\s*$/i', $rt) || in_array($ext, ['mp4', 'mkv'], true)) {
        return ['video/mp4', $ext ? $name : $name . '.mp4'];
    }
    if (preg_match('/\baudio\s*$/i', $rt) || $ext === 'm4a') {
        return ['audio/mp4', $ext ? $name : $name . '.m4a'];
    }
    if (preg_match('/\bimage\s*$/i', $rt)) {
        return ['image/jpeg', $ext ? $name : $name . '.jpg'];
    }
    if (preg_match('/\bword\s*$/i', $rt)) {
        return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $ext ? $name : $name . '.docx'];
    }
    if (preg_match('/\bcompressed archive\s*$/i', $rt)) {
        return ['application/zip', $ext ? $name : $name . '.zip'];
    }
    // Default on Google Drive document folders is PDF
    return ['application/pdf', $ext ? $name : $name . '.pdf'];
}

/**
 * Strictly classify a Google Drive file into a country checklist doc_key, 'other', or '__meeting__'.
 * Guarantees:
 *  - Videos/audio/Zoom recordings ALWAYS return '__meeting__' and NEVER 'offer' or any document key.
 *  - Consent forms ALWAYS return 'consent_form' (or 'other') and NEVER 'offer'.
 *  - Internship/job/experience letters NEVER return 'offer'.
 */
function classify_drive_file(string $country, string $fileName, string $rawTitle = '', string $subfolder = ''): string {
    $fnSplit = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fileName);
    $fnRaw = strtolower(trim((string)$fnSplit));
    $rt = strtolower(trim($rawTitle));
    $sfRaw = strtolower(trim($subfolder));
    $ext = strtolower(pathinfo(trim($fileName), PATHINFO_EXTENSION));

    $fn = trim((string)preg_replace('/[\s_\-\.\(\)\[\],&]+/', ' ', $fnRaw));
    $sf = trim((string)preg_replace('/[\s_\-\.\(\)\[\],&]+/', ' ', $sfRaw));
    $full = trim($sf . ' ' . $fn);

    // 1. STRICT RULE: Video / Audio / Zoom / Meet recordings are ALWAYS '__meeting__', NEVER documents or offer letters
    if (in_array($ext, MEDIA_EXTS, true)
        || preg_match('/\b(video|audio)\s*$/i', $rt)
        || preg_match('/\b(zoom|zzom|meeting|recording|recorded)\b/i', $sf)
        || preg_match('/\b(zoom\s*meeting|video\s*recording|initial\s*meeting)\b/i', $fn)) {
        return '__meeting__';
    }

    $cdocs = country_docs($country);

    // 2. STRICT RULE: Consent forms / contracts / undertakings -> consent_form, NEVER offer
    if (preg_match('/\b(consent|concent|contract|undertaking|agreement|30\s*percent)\b/i', $full)) {
        return isset($cdocs['consent_form']) ? 'consent_form' : 'other';
    }

    // 3. Genuine University Offer / Admission Letters -> 'offer'
    $notOffer = (bool)preg_match('/\b(intern|internship|intersnhip|employment|job|experience|work|bahrain|salary|appoint|visa|embassy|bank|statement|sponsor|affidavit|tax|fbr|lor|recommendation|reference|motivation|motivational|sop|lom|intent|cv|resume|curriculum|application\s*form|app\s*form|antragsformular|uni\s*assist|uniassist|vpd|receipt|invoice|payment|voucher|fee|checklist|passport|cnic|matric|ssc|hssc|inter|bachelor|transcript|degree|marksheet|ielts|ilets|pte|moi|cimea|dov|insurance|blocked|coracle|fintiba|expatrio|aps|photo|photograph)\b/i', $full);
    if (!$notOffer) {
        if (preg_match('/\b(zulassungsbescheid|admission\s*letter|letter\s*of\s*admission|offer\s*letter|conditional\s*offer|unconditional\s*offer|acceptance\s*letter|pre\s*acceptance)\b/i', $fn)
            || (preg_match('/\boffer\b/i', $fn) && !preg_match('/\b(intern|internship|job|employment|work)\b/i', $fn))) {
            return 'offer';
        }
    }

    // 4. Enrollment summary / pre-enrollment (Italy UniverseItaly summary)
    if (isset($cdocs['enrollment']) && preg_match('/\b(universitaly|enrollment\s*summary|pre\s*enrollment|riepilogo)\b/i', $full)) {
        return 'enrollment';
    }

    // 5. Country-specific items
    if (isset($cdocs['uni_assist']) && preg_match('/\b(vpd|uni\s*assist|uniassist|evaluation\s*result)\b/i', $full)) {
        return 'uni_assist';
    }
    if (isset($cdocs['aps']) && preg_match('/\b(aps\s*cert|aps\s*verif|akademische)\b/i', $full)) {
        return 'aps';
    }
    if (isset($cdocs['blocked_account']) && preg_match('/\b(blocked\s*amount|blocked\s*account|sperrkonto|fintiba|expatrio|coracle)\b/i', $full)) {
        return 'blocked_account';
    }
    if (isset($cdocs['health_insurance']) && preg_match('/\b(insurance|krankenversicherung|tk|barmer|aok|dak|mawista|walter)\b/i', $full)) {
        return 'health_insurance';
    }
    if (isset($cdocs['flight_hotel']) && preg_match('/\b(flight|ticket|itinerary|hotel|booking|accommodation|alloggio)\b/i', $full)) {
        return 'flight_hotel';
    }
    if (isset($cdocs['cimea']) && preg_match('/\b(cimea|dichiarazione\s*di\s*valore|dov|comparability|verification)\b/i', $full)) {
        return 'cimea';
    }
    if (isset($cdocs['campus_france']) && preg_match('/\b(campus\s*france|etudes\s*en\s*france|pastel)\b/i', $full)) {
        return 'campus_france';
    }
    if (isset($cdocs['legalized']) && preg_match('/\b(superlegal|apostille|legaliz)\b/i', $full)) {
        return 'legalized';
    }
    if (isset($cdocs['visa_decision']) && preg_match('/\b(visa\s*grant|visa\s*decision|visa\s*stamp|visa\s*refus|visum)\b/i', $full)) {
        return 'visa_decision';
    }

    // 6. LOR (Recommendation letters, including common spelling variants and abbreviations)
    if (preg_match('/\b(lor\d*|recom+endation|recomandation|recom\s*letter|reference\s*letter|letter\s*of\s*rec|prof\s*rec|rl\b|refrence|leeter\s*of\s*rec)\b/i', $fn)) {
        return isset($cdocs['lor']) ? 'lor' : 'other';
    }

    // 7. SOP / Motivation letter / LOM
    if (preg_match('/\b(sop\d*|motivation|motivational|lom|ml|letter\s*of\s*motivation|statement\s*of\s*purpose|letter\s*of\s*intent|study\s*plan)\b/i', $fn)) {
        return isset($cdocs['sop']) ? 'sop' : 'other';
    }

    // 8. CV / Resume
    if (preg_match('/\b(cv\d*|eucv|resume|curriculum\s*vitae|europass|lebenslauf|biodata)\b/i', $fn)) {
        return 'cv';
    }

    // 9. Passport-size picture (before the passport rule: "passport size photo")
    if (preg_match('/\b(photo|photograph|pic|picture|passport\s*size|profile\s*pic)\b/i', $fn)) {
        return isset($cdocs['photo']) ? 'photo' : 'other';
    }

    // 10. Passport (bio and address pages)
    if (preg_match('/\b(pass+port|reisepass|pasport)\b/i', $fn)) {
        return 'passport';
    }

    // 11. CNIC / ID card / B-form / FRC / Identity (front and back)
    if (preg_match('/\b(cnic|cinc|nic|national\s*id|identity|b\s*form|form\s*b|frc|family\s*registration|birth\s*cert|birth\s*certificate|domicile)\b/i', $fn)
        || preg_match('/\b(id|i\s*d)\s*(card|f|b|front|back)\b/i', $fn)
        || preg_match('/\b(id\s*card|identity\s*card)\b/i', $fn)) {
        return 'cnic';
    }

    // 11b. Experience / internship letters, HEC attestation / equivalence, course curriculum
    if (preg_match('/\b(experience|intern|internship|intership|intersnhip|employment|job\s*letter|work\s*cert)\b/i', $fn)) {
        return isset($cdocs['experience']) ? 'experience' : 'other';
    }
    if (preg_match('/\b(hec|equivalen\w*|equilency|equivelancy|attest\w*)\b/i', $fn)) {
        return isset($cdocs['hec']) ? 'hec' : 'other';
    }
    if (preg_match('/\b(curriculum|module\s*desc\w*|course\s*desc\w*|course\s*contents?|syllabus|modulhandbuch)\b/i', $fn)) {
        return isset($cdocs['curriculum']) ? 'curriculum' : 'other';
    }

    // 11. English / IELTS / PTE / MOI
    if (preg_match('/\b(ielts|ilets|pte|toefl|duolingo|etrf|moi|english\s*prof|english\s*lang|lang\s*cert|medium\s*of\s*instruction|language\s*cert|language\s*certificates|hope\s*cert)\b/i', $fn)) {
        return 'english';
    }

    // 12. Matric (SSC / 9th / 10th / O-Level)
    if (preg_match('/\b(ssc|matric|matriculation|10th|9th|secondary\s*school|o\s*level|school\s*certificate|school\s*cert|mtrcer|mtrdmc)\b/i', $fn)
        && !preg_match('/\b(hssc|higher|inter|intermediate|11th|12th|fsc|ics|fa)\b/i', $fn)) {
        return 'matric';
    }

    // 13. Intermediate (HSSC / 11th / 12th / FSc / ICS / ICom / FA / A-Level / High School / DAE)
    if (preg_match('/\b(hssc|intermediate|intermedite|inter|fsc|ics|icom|fa|11th|12th|first\s*year|second\s*year|higher\s*sec|higher\s*education\s*cert|higher\s*marksheet|high\s*school|a\s*level|dae|intrms|hmrkseet)\b/i', $fn)) {
        return 'inter';
    }

    // 14. Bachelor / Master transcript, then degree
    if (preg_match('/\b(tran+script|teanscript|transcipt|tanscript|transcrpit|dmc|marksheet|mark\s*sheet|result\s*card)\b/i', $fn)) {
        return 'transcript';
    }
    if (preg_match('/\b(bachelor|bachelors|bs|bsc|bba|be|bcom|llb|pharm|pharmd|mphil|master|masters|ms|msc|mba|degree|provisional|convocation|graduation|university\s*degree)\b/i', $fn)) {
        return 'bachelor';
    }

    // 15. Bank statement / Financial
    if (isset($cdocs['bank']) && preg_match('/\b(bank|account\s*statement|account\s*maintenance|financial\s*statement|closing\s*balance)\b/i', $full) && !str_contains($fn, 'performance')) {
        return 'bank';
    }

    // 16. Tax documents
    if (isset($cdocs['tax_docs']) && preg_match('/\b(tax|fbr|ntn|wealth\s*statement|tax\s*return)\b/i', $full)) {
        return 'tax_docs';
    }

    // 17. Sponsor documents
    if (isset($cdocs['sponsor']) && preg_match('/\b(sponsor|affidavit\s*of\s*support|undertaking\s*by\s*sponsor)\b/i', $full)) {
        return 'sponsor';
    }

    // Subfolder fallbacks when filename is generic
    if ($sf === 'cv') return 'cv';
    if (isset($cdocs['sop']) && preg_match('/\bsop\b/i', $sf) && !preg_match('/\bcv\b/i', $sf)) return 'sop';

    return 'other';
}

/** Parse public Google Drive folder HTML into item rows. */
function parse_drive_folder_html(string $html): array {
    if ($html === '') return [];
    $items = [];
    if (!preg_match_all('/<tr[^>]*data-id="([^"]+)"[^>]*>(.*?)<\/tr>/si', $html, $matches, PREG_SET_ORDER)) return [];
    foreach ($matches as $m) {
        $itemId = trim($m[1]);
        $body = $m[2];
        $tooltips = [];
        if (preg_match_all('/data-tooltip="([^"]+)"/i', $body, $tm)) {
            foreach ($tm[1] as $t) $tooltips[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $plain = strip_tags(str_replace(['</td>', '</div>', '</span>'], "\n", $body));
        $lines = array_values(array_filter(array_map(fn($l) => trim(html_entity_decode($l, ENT_QUOTES | ENT_HTML5, 'UTF-8')), explode("\n", $plain)), fn($l) => $l !== ''));
        $rawTitle = $tooltips[0] ?? ($lines[0] ?? '');
        $hasFileExt = (bool)preg_match('/\.(pdf|jpg|jpeg|png|doc|docx|mp4|m4a|mov|mkv|zip|rar)\b/i', $rawTitle);
        $isFolder = (stripos($rawTitle, 'Shared folder') !== false) || (stripos($rawTitle, 'folder') !== false && !$hasFileExt);
        $cleanName = preg_replace('/\s+Shared folder$/i', '', $rawTitle);
        $cleanName = preg_replace('/\s+(PDF|Image|Video|Word|Audio|Compressed archive)$/i', '', (string)$cleanName);
        $cleanName = trim((string)$cleanName) ?: ($lines[0] ?? '');
        $sizeStr = '';
        foreach ($tooltips as $t) {
            if (stripos($t, 'Size:') !== false) {
                $parts = explode("\n", $t);
                $sizeStr = trim(str_ireplace('Size:', '', $parts[0]));
                break;
            }
        }
        $items[] = ['id' => $itemId, 'name' => $cleanName, 'raw_title' => $rawTitle, 'is_folder' => $isFolder, 'size' => $sizeStr];
    }
    return $items;
}

/** Fetch multiple Google Drive folder URLs in parallel using curl_multi. */
function fetch_drive_folders_multi(array $folderIds): array {
    if (!$folderIds) return [];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($folderIds as $k => $fid) {
        $url = 'https://drive.google.com/drive/folders/' . rawurlencode($fid);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.2);
    } while ($running > 0);
    $results = [];
    foreach ($handles as $k => $ch) {
        $results[$k] = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

/**
 * Sync one student's Google Drive files (either from preloaded items or by crawling their Drive folder).
 * Populates student_files, student_documents, and student_meetings (for meeting recordings).
 * Returns ['docs_added' => int, 'meetings_added' => int].
 */
function sync_student_drive(array $r, ?int $userId = null, ?array $preloadedFiles = null, ?string $directMeetingUrl = null): array {
    $sid = (int)$r['id'];
    $country = (string)($r['country'] ?? 'italy');
    $cdocs = country_docs($country);
    $ts = $r['received_date'] ? ($r['received_date'] . ' 10:00:00') : now();

    if ($preloadedFiles === null) {
        $preloadedFiles = [];
        $foldersToCrawl = [];
        foreach (['documents_link' => '', 'drive_link' => 'Zoom meeting'] as $col => $defaultSub) {
            $link = $r[$col] ?? null;
            if (!$link || is_shared_drive_folder($link, $sid)) continue;
            if (preg_match('~/file/d/([A-Za-z0-9_-]{15,})~', $link, $m)) {
                if ($col === 'drive_link') $directMeetingUrl = 'https://drive.google.com/file/d/' . $m[1] . '/view';
                continue;
            }
            $fid = drive_id($link);
            if ($fid !== '' && strlen($fid) >= 15 && !isset($foldersToCrawl[$fid])) {
                $foldersToCrawl[$fid] = $defaultSub;
            }
        }
        if ($foldersToCrawl) {
            $rootPages = fetch_drive_folders_multi(array_combine(array_keys($foldersToCrawl), array_keys($foldersToCrawl)));
            $subFolders = [];
            foreach ($rootPages as $fid => $html) {
                $defaultSub = $foldersToCrawl[$fid];
                foreach (parse_drive_folder_html($html) as $it) {
                    if ($it['is_folder']) {
                        $subFolders[$it['id']] = $it['name'];
                    } else {
                        $preloadedFiles[] = [
                            'id'        => $it['id'],
                            'name'      => $it['name'],
                            'raw_title' => $it['raw_title'],
                            'subfolder' => $defaultSub,
                            'size'      => $it['size'] ?? '',
                        ];
                    }
                }
            }
            if ($subFolders) {
                $subPages = fetch_drive_folders_multi(array_combine(array_keys($subFolders), array_keys($subFolders)));
                foreach ($subPages as $sfId => $html) {
                    $sfName = $subFolders[$sfId];
                    foreach (parse_drive_folder_html($html) as $it) {
                        if (!$it['is_folder']) {
                            $preloadedFiles[] = [
                                'id'        => $it['id'],
                                'name'      => $it['name'],
                                'raw_title' => $it['raw_title'],
                                'subfolder' => $sfName,
                                'size'      => $it['size'] ?? '',
                            ];
                        }
                    }
                }
            }
        }
    }

    $docsAdded = 0;
    $meetingsAdded = 0;
    $meetingFiles = [];
    $seenDriveIds = [];

    foreach ($preloadedFiles as $df) {
        $fid = trim((string)($df['id'] ?? ''));
        if ($fid === '' || isset($seenDriveIds[$fid])) continue;
        $seenDriveIds[$fid] = true;

        $name = trim((string)($df['name'] ?? 'file'));
        $rawTitle = trim((string)($df['raw_title'] ?? ''));
        $subfolder = trim((string)($df['subfolder'] ?? ''));
        $sizeBytes = parse_size_bytes((string)($df['size'] ?? ''));
        [$mime, $fullName] = drive_item_mime_and_name($name, $rawTitle);

        $docKey = classify_drive_file($country, $fullName, $rawTitle, $subfolder);
        if ($docKey === '__meeting__') {
            $meetingFiles[] = ['fid' => $fid, 'name' => $fullName, 'mime' => $mime, 'size' => $sizeBytes];
            continue;
        }

        $stored = 'gdrive:' . $fid;
        $exists = q('SELECT id FROM student_files WHERE student_id = ? AND stored_name = ?', [$sid, $stored])->fetchColumn();
        if (!$exists) {
            q('INSERT IGNORE INTO student_files (student_id, doc_key, meeting_id, original_name, stored_name, mime, size, uploaded_by, uploaded_role, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$sid, $docKey, null, mb_substr($fullName, 0, 250), $stored, $mime, $sizeBytes, $userId, 'drive', $ts]);
            $docsAdded++;
        }
    }

    // Handle meeting recordings & direct meeting links -> student_meetings + student_files
    if ($meetingFiles || $directMeetingUrl) {
        $recUrl = $directMeetingUrl ?: ('https://drive.google.com/file/d/' . $meetingFiles[0]['fid'] . '/view');
        $initId = (int)q("SELECT id FROM student_meetings WHERE student_id = ? AND kind = 'initial' ORDER BY id LIMIT 1", [$sid])->fetchColumn();
        $noteParts = array_filter([
            $r['initial_meeting'] ?? '',
            $r['recording_note'] ?? '',
            ($r['recorded_uploaded'] ?? '') ? 'Recording uploaded: ' . $r['recorded_uploaded'] : '',
        ]);
        $noteStr = implode(' · ', $noteParts) ?: null;
        if (!$initId) {
            // A recording in the student's Drive folder is a recorded (online) meeting; its date is not in the file
            // list, so it is left empty rather than reusing the registration date.
            q('INSERT INTO student_meetings (student_id, kind, title, meeting_date, meeting_time, status, join_link, recording_link, notes, student_can_see, meeting_type, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$sid, 'initial', MEETING_KINDS['initial'], null, null, 'Done', null, $recUrl, $noteStr, 1, $r['initial_meeting_mode'] ?? null ?: 'Zoom Meeting', $userId, $ts]);
            $initId = (int)db()->lastInsertId();
            $meetingsAdded++;
        } else {
            q('UPDATE student_meetings SET recording_link = COALESCE(recording_link, ?), status = ? WHERE id = ?', [$recUrl, 'Done', $initId]);
        }

        foreach ($meetingFiles as $mf) {
            $stored = 'gdrive:' . $mf['fid'];
            $exists = q('SELECT id FROM student_files WHERE student_id = ? AND stored_name = ?', [$sid, $stored])->fetchColumn();
            if (!$exists) {
                q('INSERT IGNORE INTO student_files (student_id, doc_key, meeting_id, original_name, stored_name, mime, size, uploaded_by, uploaded_role, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [$sid, 'meeting', $initId, mb_substr($mf['name'], 0, 250), $stored, $mf['mime'], $mf['size'], $userId, 'drive', $ts]);
                $meetingsAdded++;
            }
        }
    }

    return ['docs_added' => $docsAdded, 'meetings_added' => $meetingsAdded];
}

<?php
declare(strict_types=1);

// =====================================================================================
// Student documents: the checklist of each country, which documents are received or still
// pending (worked out from the files and ticks in the portal, never typed by hand), and the
// short-lived staging area that holds a file between choosing it and pressing Save.
//
// "Documents status" and "Pending documents" in the sheets are filled automatically from this:
//   status  = Done (Germany: Completed) when every required document is received, else Pending
//   pending = the short names of the required documents still missing ("Passport, LOR")
// =====================================================================================

/**
 * Documentation-stage checklist (what the teams list as "pending documents" in the sheets).
 * key => [label, short name used in the sheet's pending column, group]
 */
const DOC_ITEMS = [
    'passport'   => ['Passport (bio and address pages)', 'Passport', 'Identity'],
    'cnic'       => ['CNIC / B-form (front and back)', 'CNIC', 'Identity'],
    'photo'      => ['Passport-size picture', 'Picture', 'Identity'],
    'matric'     => ['Matric / O-Level certificate and DMC', 'Matric', 'Academic'],
    'inter'      => ['Intermediate / FSc / A-Level certificate and DMC', 'Inter', 'Academic'],
    'bachelor'   => ['Bachelor degree', 'BS degree', 'Academic'],
    'transcript' => ['Bachelor transcript', 'BS transcript', 'Academic'],
    'english'    => ['English proof (IELTS / PTE / MOI letter)', 'IELTS/MOI', 'Academic'],
    'hec'        => ['HEC attestation / degree equivalence', 'Equivalence', 'Academic'],
    'cv'         => ['CV', 'CV', 'Application'],
    'lor'        => ['Recommendation letters (LOR 1 and 2)', 'LOR', 'Application'],
    'sop'        => ['Motivation letter / SOP', 'SOP', 'Application'],
    'experience' => ['Experience / internship letters', 'Experience letter', 'Application'],
    'curriculum' => ['Course curriculum / module descriptions', 'Curriculum', 'Application'],
    'aps'        => ['APS certificate', 'APS', 'Application'],
    'uni_assist' => ['Uni-assist VPD / receipt', 'VPD', 'Application'],
    'legalized'  => ['Superlegalized / apostilled documents', 'Legalization', 'Legalization'],
];

/** Later-stage documents: kept in the portal and downloaded, but not part of "Documents status". */
const DOC_LATER = [
    'consent_form'     => ['Signed consent form', 'Onboarding'],
    'campus_france'    => ['Campus France dossier / receipt', 'Admission'],
    'offer'            => ['University admission / offer letter', 'Admission'],
    'enrollment'       => ['Universitaly pre-enrollment summary', 'Admission'],
    'cimea'            => ['CIMEA / DoV', 'Admission'],
    'bank'             => ['Bank statement', 'Financial'],
    'tax_docs'         => ['FBR tax returns and wealth statement', 'Financial'],
    'sponsor'          => ['Sponsor affidavit and undertaking', 'Financial'],
    'blocked_account'  => ['Blocked account confirmation', 'Financial'],
    'health_insurance' => ['Health insurance', 'Visa'],
    'flight_hotel'     => ['Flight / accommodation booking', 'Visa'],
    'visa_decision'    => ['Visa decision letter', 'Visa'],
];

/** Per country: required documentation items, optional ones, and later-stage ones. */
function country_doc_plan(string $c): array {
    return match ($c) {
        'italy'   => ['req' => ['passport', 'cnic', 'photo', 'matric', 'inter', 'bachelor', 'transcript', 'english', 'cv', 'lor'],
                      'opt' => ['hec', 'sop', 'experience'],
                      'later' => ['consent_form', 'offer', 'enrollment', 'cimea', 'bank', 'tax_docs', 'sponsor', 'health_insurance', 'flight_hotel', 'visa_decision']],
        'france'  => ['req' => ['passport', 'cnic', 'photo', 'matric', 'inter', 'bachelor', 'transcript', 'english', 'cv'],
                      'opt' => ['lor', 'sop', 'experience'],
                      'later' => ['consent_form', 'campus_france', 'offer', 'bank', 'visa_decision']],
        'germany' => ['req' => ['passport', 'cnic', 'matric', 'inter', 'bachelor', 'transcript', 'english', 'cv', 'lor', 'curriculum'],
                      'opt' => ['photo', 'sop', 'experience', 'aps', 'uni_assist'],
                      'later' => ['consent_form', 'offer', 'blocked_account', 'health_insurance', 'visa_decision']],
        'czech', 'austria' => ['req' => ['passport', 'cnic', 'matric', 'inter', 'bachelor', 'transcript'],
                      'opt' => ['english', 'cv', 'lor', 'sop', 'photo', 'legalized'],
                      'later' => ['consent_form', 'offer', 'bank', 'health_insurance', 'flight_hotel', 'visa_decision']],
        default   => ['req' => [], 'opt' => [], 'later' => []],
    };
}

/** Older keys used by earlier versions and the Drive sync (passport front / back were separate). */
const DOC_ALIASES = ['passport_front' => 'passport', 'passport_back' => 'passport', 'cnic_front' => 'cnic', 'cnic_back' => 'cnic'];

function doc_key_norm(string $k): string { return DOC_ALIASES[$k] ?? $k; }

/** Every document of a country: key => [label, group], documentation stage first. */
function country_docs(string $c): array {
    $p = country_doc_plan($c);
    $out = [];
    foreach (array_merge($p['req'], $p['opt']) as $k) $out[$k] = [DOC_ITEMS[$k][0], DOC_ITEMS[$k][2]];
    foreach ($p['later'] as $k) $out[$k] = DOC_LATER[$k];
    return $out;
}

function doc_label(string $c, string $key): string {
    $key = doc_key_norm($key);
    if ($key === 'other') return 'Other document';
    return DOC_ITEMS[$key][0] ?? DOC_LATER[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
}

/** Word the sheet uses for "all documents received". */
function docs_done_word(string $c): string { return $c === 'germany' ? 'Completed' : 'Done'; }

/** Sheet columns that are worked out from the documents and never typed in a form. */
const DOCS_COMPUTED = ['documents_status', 'pending_documents'];

/** Statuses a person can set on a document (besides uploading files to it). */
const DOC_STATUS = ['', 'Requested', 'Received', 'Verified'];

/**
 * Received / requested documents for many students at once:
 * [student id => ['have' => [key => 'file'|'tick'], 'asked' => [key => 'Y-m-d H:i:s'], 'old' => [key => n files from before the request]]]
 * A document is received when a file is saved under it, or when it is ticked "Received" (hard copy, sheet, Drive).
 * When a document is asked for (again), only files saved after that moment count: an older file that was not
 * good enough stays visible but does not make the document received.
 */
function docs_map(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $files = [];
        foreach (q("SELECT student_id, doc_key, original_name, mime, created_at FROM student_files WHERE student_id IN ($in) AND meeting_id IS NULL AND doc_key NOT LIKE 'meeting%'") as $f) {
            if (is_media_file($f)) continue;
            $files[(int)$f['student_id']][doc_key_norm($f['doc_key'])][] = (string)$f['created_at'];
        }
        $asked = [];
        foreach (q("SELECT student_id, doc_key, status, updated_at FROM student_documents WHERE student_id IN ($in)") as $d) {
            $sid = (int)$d['student_id']; $k = doc_key_norm($d['doc_key']);
            if (in_array($d['status'], ['Received', 'Verified'], true)) $out[$sid]['have'][$k] = 'tick';
            elseif ($d['status'] === 'Requested') $asked[$sid][$k] = (string)$d['updated_at'];
        }
        foreach ($files as $sid => $byKey) foreach ($byKey as $k => $times) {
            $since = $asked[$sid][$k] ?? null;
            $newer = $since === null ? $times : array_filter($times, fn($t) => $t > $since);
            if ($newer) $out[$sid]['have'][$k] ??= 'file';
            if ($since !== null && count($newer) < count($times)) $out[$sid]['old'][$k] = count($times) - count($newer);
        }
        foreach ($asked as $sid => $a) $out[$sid]['asked'] = $a;
    }
    return $out;
}

/** Keys that are pending for this student: required ones + optional ones asked for, not yet received. */
function docs_needed_keys(string $country, array $asked): array {
    $plan = country_doc_plan($country);
    $all = array_merge($plan['req'], $plan['opt']);
    return array_values(array_filter($all, fn($k) => in_array($k, $plan['req'], true) || !empty($asked[$k])));
}

/**
 * Where the documents of one student stand.
 * ['have' => [key => 'file'|'tick'], 'asked' => [...], 'needed' => [keys], 'pending' => [keys], 'status' => 'Done'|'Completed'|'Pending',
 *  'pending_text' => 'Passport, LOR', 'required' => n, 'received_required' => n]
 */
function docs_state(array $r, ?array $map = null): array {
    $sid = (int)($r['id'] ?? 0);
    $c = (string)($r['country'] ?? 'italy');
    $m = $map !== null ? ($map[$sid] ?? []) : ($sid ? (docs_map([$sid])[$sid] ?? []) : []);
    $have = $m['have'] ?? []; $asked = $m['asked'] ?? [];
    foreach ($asked as $k => $_) if (($have[$k] ?? '') === 'tick') unset($asked[$k]);
    $needed = docs_needed_keys($c, $asked);
    $pending = array_values(array_filter($needed, fn($k) => empty($have[$k])));
    return [
        'have' => $have, 'asked' => $asked, 'old' => $m['old'] ?? [], 'needed' => $needed, 'pending' => $pending,
        'status' => $pending ? 'Pending' : docs_done_word($c),
        'pending_text' => implode(', ', array_map(fn($k) => DOC_ITEMS[$k][1], $pending)),
        'required' => count($needed),
        'received_required' => count($needed) - count($pending),
    ];
}

/**
 * Re-works out "Documents status" and "Pending documents" from the documents in the portal and
 * writes them to the student (both are sheet columns), then refreshes the follow-up tasks.
 */
function refresh_student_documents_status(int $sid, bool $tasks = true): void {
    $r = q('SELECT id, country, documents_status, pending_documents FROM students WHERE id = ?', [$sid])->fetch();
    if (!$r) return;
    $st = docs_state($r);
    $pend = $st['pending_text'] !== '' ? $st['pending_text'] : null;
    if ((string)$r['documents_status'] !== $st['status'] || (string)$r['pending_documents'] !== (string)$pend) {
        q('UPDATE students SET documents_status = ?, pending_documents = ? WHERE id = ?', [$st['status'], $pend, $sid]);
    }
    if ($tasks) evaluate_student_tasks($sid);
}

function is_docs_step_complete(array $r): bool {
    return in_array(strtolower(trim((string)($r['documents_status'] ?? ''))), ['done', 'completed'], true);
}

/** Percentage of the needed documents received. */
function student_doc_completion_percentage(int $studentId, string $country): int {
    $st = docs_state(['id' => $studentId, 'country' => $country]);
    return $st['required'] ? (int)round($st['received_required'] / $st['required'] * 100) : 100;
}

/** Preload for lists (pass the result to student_doc_detect / student_documents_badges). */
function attach_student_doc_data(array $rows): array {
    return docs_map(array_map(fn($r) => (int)$r['id'], $rows));
}

/** Older shape used by lists, the student portal and the task engine. */
function student_doc_detect(array $r, ?array $map = null): array {
    $st = docs_state($r, $map);
    $c = (string)($r['country'] ?? 'italy');
    return [
        'status' => $st['pending'] ? 'pending' : 'complete',
        'missing' => array_map(fn($k) => ['key' => $k, 'label' => doc_label($c, $k), 'short' => DOC_ITEMS[$k][1], 'is_optional' => false], $st['pending']),
        'received' => array_keys($st['have']),
    ];
}

/** Compact pending / complete badge for lists. */
function student_documents_badges(array $r, ?array $map = null): string {
    $st = docs_state($r, $map);
    if (!$st['pending']) return '<span class="pill ok">Complete</span>';
    $names = array_map(fn($k) => DOC_ITEMS[$k][1], $st['pending']);
    $shown = array_slice($names, 0, 2);
    return '<span class="pill warn" title="Pending: ' . h(implode(', ', $names)) . '">' . h(implode(', ', $shown)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') . '</span>';
}

/** Attach a staged file to a student as a document. Returns the new file id or an error message. */
function store_staged_file(array $m, int $studentId, string $docKey, array $u): int|string {
    if (!is_file($m['path'])) return 'The file has expired. Add it again.';
    $hash = $m['hash'] ?: (string)@hash_file('sha256', $m['path']);
    if ($hash && ($dup = q('SELECT original_name FROM student_files WHERE student_id = ? AND file_hash = ?', [$studentId, $hash])->fetch())) {
        stage_delete($m['token']);
        return 'already saved for this student as "' . $dup['original_name'] . '"';
    }
    $dir = storage_dir() . '/' . $studentId;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'The portal cannot save files. Check that app/storage is writable.';
    $stored = bin2hex(random_bytes(20));
    if (!@rename($m['path'], $dir . '/' . $stored) && !@copy($m['path'], $dir . '/' . $stored)) return 'The portal cannot save files. Check that app/storage is writable.';
    $version = (int)q('SELECT MAX(version) FROM student_files WHERE student_id = ? AND doc_key = ?', [$studentId, $docKey])->fetchColumn() + 1;
    q('INSERT INTO student_files (student_id, doc_key, meeting_id, original_name, stored_name, mime, size, file_hash, version, uploaded_by, uploaded_role, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [$studentId, $docKey, null, $m['name'], $stored, $m['mime'], (int)$m['size'], $hash ?: null, $version, (int)$u['id'], $u['role'], now()]);
    $id = (int)db()->lastInsertId();
    stage_delete($m['token']);
    return $id;
}

/**
 * Save the documents part of a form: staged files (staged[<key>][] = token) and, for staff, the
 * "received outside the portal" / "ask the student" ticks (doc_tick[<key>] = Received | Requested | '').
 * Returns [files saved, [error messages]].
 */
function save_posted_documents(int $sid, string $country, array $u, bool $ticks): array {
    $docs = country_docs($country) + ['other' => ['Other document', '']];
    $ok = 0; $bad = []; $labels = []; $answered = [];
    $staged = $_POST['staged'] ?? [];
    if (is_array($staged)) {
        foreach ($staged as $key => $tokens) {
            $key = (string)$key;
            if (!isset($docs[$key]) || !is_array($tokens)) continue;
            foreach (array_slice($tokens, 0, 20) as $tok) {
                $m = stage_get((string)$tok, $u);
                if (!$m) { $bad[] = 'A file added under ' . doc_label($country, $key) . ' has expired. Add it again.'; continue; }
                $res = store_staged_file($m, $sid, $key, $u);
                if (is_int($res)) { $ok++; $labels[$key] = ($labels[$key] ?? 0) + 1; }
                else $bad[] = $m['name'] . ': ' . $res;
            }
        }
    }
    $cur = [];
    foreach (q('SELECT doc_key, status FROM student_documents WHERE student_id = ?', [$sid]) as $d) $cur[doc_key_norm($d['doc_key'])] = $d['status'];
    // A document that was asked for and has just received a new file is no longer "asked for": the file answers it.
    foreach ($labels as $k => $_) if (($cur[$k] ?? '') === 'Requested') { q('DELETE FROM student_documents WHERE student_id = ? AND doc_key = ?', [$sid, $k]); $cur[$k] = ''; $answered[$k] = true; }
    if ($ticks && isset($_POST['doc_tick']) && is_array($_POST['doc_tick'])) {
        foreach ($_POST['doc_tick'] as $key => $val) {
            $key = (string)$key; $val = is_string($val) ? $val : '';
            if (isset($answered[$key]) && $val === 'Requested') continue;
            if (!isset($docs[$key]) || $key === 'other' || !in_array($val, ['', 'Requested', 'Received'], true)) continue;
            $old = $cur[$key] ?? '';
            if ($old === 'Verified' && $val === 'Received') continue;
            if ($old === $val) continue;
            q('DELETE FROM student_documents WHERE student_id = ? AND doc_key = ?', [$sid, $key]);
            if ($val !== '') q('INSERT INTO student_documents (student_id, doc_key, status, updated_by, updated_at) VALUES (?,?,?,?,?)', [$sid, $key, $val, (int)$u['id'], now()]);
            log_action(doc_label($country, $key) . ': ' . match ($val) { 'Received' => 'marked received outside the portal', 'Requested' => 'asked from the student', default => ($old === 'Requested' ? 'no longer asked' : 'no longer marked received') }, $sid);
        }
    }
    foreach ($labels as $k => $n) log_action('Saved ' . $n . ' file' . ($n > 1 ? 's' : '') . ' under ' . doc_label($country, $k), $sid);
    refresh_student_documents_status($sid);
    return [$ok, $bad];
}

/**
 * Document keys named in a free-text "pending documents" note from the sheets ("BS degree /LOR", "MOI, LOR1, 2 and inter degree").
 * Returns [keys, words that matched no document].
 */
function doc_keys_from_text(string $text, string $country): array {
    $keys = []; $unmatched = [];
    foreach (preg_split('/[,;\/\n+&]+|\band\b/i', $text) as $part) {
        $p = strtolower(trim($part, " \t.-()"));
        if ($p === '' || preg_match('/^(\d+|pending|applied|\(applied\))$/', $p)) continue;
        $hit = [];
        if (preg_match('/pass\s*port|passprt/', $p)) $hit[] = 'passport';
        if (preg_match('/cnic|b-?form|id\s*card/', $p)) $hit[] = 'cnic';
        if (preg_match('/\bpic\b|picture|photo/', $p)) $hit[] = 'photo';
        if (preg_match('/matric|\bssc\b|o-?level/', $p)) $hit[] = 'matric';
        if (preg_match('/\binter\b|intermediate|\bf\.?\s?sc|\bhssc|a-?level/', $p)) $hit[] = 'inter';
        elseif (preg_match('/transcri|transcrpit|\bdmc\b/', $p)) $hit[] = 'transcript';
        elseif (preg_match('/degree|dgree|degre|\bbs\b|bachelor|graduation/', $p)) $hit[] = 'bachelor';
        if (preg_match('/ielts|\bmoi\b|english|\bpte\b|toefl/', $p)) $hit[] = 'english';
        if (preg_match('/\bcv\b|resume|curriculum\s*vitae/', $p)) $hit[] = 'cv';
        elseif (preg_match('/curriculum|module/', $p)) $hit[] = 'curriculum';
        if (preg_match('/\blor|recommendation/', $p)) $hit[] = 'lor';
        if (preg_match('/experience|intern|intership/', $p)) $hit[] = 'experience';
        if (preg_match('/equival|equilency|equivelancy|\bhec\b|attest/', $p)) $hit[] = 'hec';
        if (preg_match('/\bsop\b|motivation/', $p)) $hit[] = 'sop';
        if ($hit) { foreach ($hit as $k) $keys[$k] = true; } else { $unmatched[] = trim($part); }
    }
    $docs = country_docs($country);
    return [array_values(array_filter(array_keys($keys), fn($k) => isset($docs[$k]))), $unmatched];
}

// -------------------------------------------------------------------------------------
// Staging: a chosen file is uploaded straight away (so the person sees at once that it is
// in), kept here privately, and attached to the student only when the record is saved.
// Before Save it can be removed; after Save it is part of the record and stays.
// -------------------------------------------------------------------------------------

function stage_dir(): string {
    $d = storage_dir() . '/staged';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function stage_cleanup(): void {
    foreach (glob(stage_dir() . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 2 * 86400) @unlink($f);
}

/** Check and keep one uploaded file. Returns ['token', 'name', 'size'] or an error message. */
function stage_store(array $file, array $u, string $docKey, int $studentId): array|string {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return 'Choose a file.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) return 'The upload did not complete. Try again.';
    $size = (int)$file['size'];
    if ($size <= 0) return 'The file is empty.';
    if ($size > max_upload_bytes()) return 'The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.';
    $name = mb_substr(preg_replace('/[\x00-\x1F\x7F"<>:\/\\\\|?*]+/u', '_', basename(str_replace('\\', '/', (string)$file['name']))) ?: 'file', -200);
    $ext = file_ext($name);
    if (!isset(FILE_TYPES[$ext]) || in_array($ext, MEDIA_EXTS, true)) return 'This file type is not allowed here. Use PDF, an image, Word or Excel (recordings go in Meetings).';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, FILE_TYPES[$ext][0], true)) return 'The file content does not match its type (.' . $ext . ').';
    $hash = (string)@hash_file('sha256', $file['tmp_name']);
    if ($studentId && $hash && ($dup = q('SELECT original_name FROM student_files WHERE student_id = ? AND file_hash = ?', [$studentId, $hash])->fetch())) {
        return 'This file is already saved for this student as "' . $dup['original_name'] . '".';
    }
    stage_cleanup();
    $token = bin2hex(random_bytes(16));
    if (!move_uploaded_file($file['tmp_name'], stage_dir() . '/' . $token)) return 'The portal cannot save files. Check that app/storage is writable.';
    file_put_contents(stage_dir() . '/' . $token . '.json', json_encode(['user' => (int)$u['id'], 'name' => $name, 'mime' => $mime, 'size' => $size, 'hash' => $hash, 'doc_key' => $docKey, 'student' => $studentId, 'created' => time()]));
    return ['token' => $token, 'name' => $name, 'size' => $size];
}

/** A staged file of this person, or null. */
function stage_get(string $token, array $u): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $meta = stage_dir() . '/' . $token . '.json';
    if (!is_file($meta) || !is_file(stage_dir() . '/' . $token)) return null;
    $m = json_decode((string)file_get_contents($meta), true);
    if (!is_array($m) || (int)($m['user'] ?? 0) !== (int)$u['id']) return null;
    $m['path'] = stage_dir() . '/' . $token;
    $m['token'] = $token;
    return $m;
}

function stage_delete(string $token): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
    @unlink(stage_dir() . '/' . $token);
    @unlink(stage_dir() . '/' . $token . '.json');
}

// -------------------------------------------------------------------------------------
// The documents checklist on the student form and in the student portal.
// -------------------------------------------------------------------------------------

/** One saved file: icon, name, size, date, where it came from, View / Download. Saved files have no Remove button. */
function doc_saved_line(array $f, bool $old = false): string {
    $meta = [fmt_size((int)$f['size']), 'saved ' . fmt_date(substr((string)$f['created_at'], 0, 10))];
    if ($f['uploaded_role'] === 'drive') $meta[] = 'from the Drive folder';
    elseif ($f['uploaded_role'] === 'student') $meta[] = 'added by the student';
    if ($old) $meta[] = 'older file (before the document was asked for again)';
    $o = '<div class="fline saved' . ($old ? ' old' : '') . '">' . file_icon($f) . '<div class="fmeta"><b title="' . h($f['original_name']) . '">' . h($f['original_name']) . '</b>'
       . '<span class="m">' . h(implode(' · ', $meta)) . '</span></div><span class="fbtns">';
    if (file_viewable($f)) $o .= '<a class="btn xs" href="' . h(file_url($f)) . '" data-view="' . h(file_url($f, false, true)) . '" data-open="' . h(file_url($f)) . '" data-dl="' . h(file_url($f, true)) . '" data-title="' . h($f['original_name']) . '" target="_blank" rel="noopener">View</a>';
    $o .= '<a class="btn xs" href="' . h(file_url($f, true)) . '">Download</a><span class="lock" title="Saved files stay with the student record and cannot be removed">🔒</span></span></div>';
    return $o;
}

/**
 * Checklist with instant uploads. $files = student_files() result.
 * $o: 'form' => id of the form the inputs belong to, 'staff' => may mark received / ask for a document, 'add' => may add files.
 */
function render_docs_box(array $u, array $r, array $files, array $o): string {
    $c = (string)$r['country'];
    $sid = (int)($r['id'] ?? 0);
    $plan = country_doc_plan($c);
    $st = $sid ? docs_state($r) : docs_state(['id' => 0, 'country' => $c], []);
    $marks = [];
    if ($sid) foreach (q('SELECT doc_key, status FROM student_documents WHERE student_id = ?', [$sid]) as $d) $marks[doc_key_norm($d['doc_key'])] = $d['status'];
    $staff = !empty($o['staff']); $add = !empty($o['add']);
    $accept = h(accept_attr(false));
    $stagedBack = $o['staged'] ?? [];
    $formId = (string)($o['form'] ?? '');

    $item = function (string $k, string $label, string $short, string $sub, bool $need, bool $inStatus) use ($files, $st, $marks, $staff, $add, $accept, $stagedBack, $formId): string {
        $list = $files['docs'][$k] ?? [];
        $askedAt = $st['asked'][$k] ?? null;
        $mark = $marks[$k] ?? '';
        $nNew = $askedAt ? count(array_filter($list, fn($f) => (string)$f['created_at'] > $askedAt)) : count($list);
        $saved = $mark === 'Requested' ? $nNew : count($list);
        $have = !empty($st['have'][$k]);
        $state = $have ? 'have' : (($need || $mark === 'Requested') ? 'pending' : 'optional');
        $badge = ['have' => ['ok', 'Received'], 'pending' => ['warn', 'Pending'], 'optional' => ['', 'Optional']][$state];
        if ($have && !$list && $mark !== '') $badge[1] = $mark === 'Verified' ? 'Verified' : 'Received outside the portal';
        $h = '<div class="dk' . ($state === 'have' ? ' is-have' : ($state === 'pending' ? ' is-pending' : '')) . '" data-key="' . h($k) . '" data-short="' . h($short) . '" data-need="' . ($need ? 1 : 0) . '" data-in-status="' . ($inStatus ? 1 : 0) . '"'
           . ' data-saved="' . $saved . '" data-saved-all="' . count($list) . '" data-saved-new="' . ($mark === 'Requested' ? $nNew : 0) . '" data-mark="' . h($mark) . '">'
           . '<div class="dk-h"><span class="dk-dot" aria-hidden="true"></span><div class="dk-t"><b>' . h($label) . '</b><small>' . h($sub) . '</small></div>'
           . '<span class="pill dk-badge ' . $badge[0] . '">' . h($badge[1]) . '</span></div>';
        $back = $stagedBack[$k] ?? [];   // the badge and the summary are updated by app.js when the page opens
        $h .= '<div class="dk-files"' . ($list || $back ? '' : ' hidden') . '>';
        foreach ($list as $f) $h .= doc_saved_line($f, $askedAt !== null && (string)$f['created_at'] <= $askedAt);
        foreach ($back as $m) {
            $h .= '<div class="fline staged" data-token="' . h($m['token']) . '">' . file_icon(['original_name' => $m['name'], 'mime' => $m['mime']])
                . '<div class="fmeta"><b title="' . h($m['name']) . '">' . h($m['name']) . '</b><span class="m">' . h(fmt_size((int)$m['size'])) . ' · <span class="new-t">Added — saved when you press Save</span></span></div>'
                . '<span class="fbtns"><button type="button" class="btn xs dk-remove" title="Remove this file (it is not saved yet)">✕ Remove</button></span>'
                . '<input type="hidden" name="staged[' . h($k) . '][]" value="' . h($m['token']) . '"' . ($formId ? ' form="' . h($formId) . '"' : '') . '></div>';
        }
        $h .= '</div>';
        if ($add || ($staff && $inStatus)) {
            $h .= '<div class="dk-a">';
            if ($add) $h .= '<label class="btn xs dk-add">＋ Add file<input type="file" class="dk-input" multiple accept="' . $accept . '"></label>';
            if ($staff && $inStatus) {
                $opts = ['' => 'Status from the files', 'Received' => 'Received outside the portal', 'Requested' => 'Still needed (ask again)'];
                if ($mark === 'Verified') $opts = ['Verified' => 'Verified'] + $opts;
                $h .= '<label class="dk-mark-l"><span class="sr-only">' . h($label) . ' status</span><select class="dk-mark" name="doc_tick[' . h($k) . ']">';
                foreach ($opts as $v => $l) $h .= '<option value="' . h($v) . '"' . ($mark === $v ? ' selected' : '') . '>' . h($l) . '</option>';
                $h .= '</select></label>';
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    };

    $out = '<div class="docs-box" data-endpoint="' . h(url('upload.php')) . '" data-csrf="' . h(csrf_token()) . '" data-student="' . $sid . '" data-country="' . h($c) . '"'
         . ' data-form="' . h($o['form'] ?? '') . '" data-done="' . h(docs_done_word($c)) . '">';
    $pct = $st['required'] ? round($st['received_required'] / $st['required'] * 100) : 100;
    $out .= '<div class="docs-sum"><div class="docs-sum-h"><span>Documents status</span> <span class="pill ' . ($st['pending'] ? 'warn' : 'ok') . '" data-docs-status>' . h($st['status']) . '</span>'
          . '<span class="muted"><b data-docs-count>' . $st['received_required'] . ' of ' . $st['required'] . '</b> needed documents received</span></div>'
          . '<div class="prog"><i data-docs-bar style="width:' . $pct . '%"></i></div>'
          . '<div class="docs-pend"><span>Pending documents</span> <b data-docs-pending class="' . ($st['pending'] ? '' : 'ok-t') . '">' . h($st['pending_text'] ?: 'Nothing — every needed document is received') . '</b></div>'
          . '<p class="muted small">Worked out from the files below and goes into the sheet\'s "Documents status" and "Pending documents" columns. '
          . ($add ? 'A file you add shows at once; it is kept when you press <b>Save</b> and can be removed until then. Saved files cannot be removed.' : '') . '</p></div>';

    $groups = [];
    foreach (array_merge($plan['req'], $plan['opt']) as $k) $groups[DOC_ITEMS[$k][2]][] = $k;
    foreach ($groups as $g => $keys) {
        $out .= '<div class="dk-group"><h4>' . h($g) . '</h4><div class="dk-grid">';
        foreach ($keys as $k) {
            $need = in_array($k, $plan['req'], true);
            $out .= $item($k, DOC_ITEMS[$k][0], DOC_ITEMS[$k][1], $need ? 'Needed' : 'Optional — needed only when asked for', $need, true);
        }
        $out .= '</div></div>';
    }
    $later = [];
    foreach ($plan['later'] as $k) $later[DOC_LATER[$k][1]][] = $k;
    if ($later) {
        $out .= '<div class="dk-group later"><h4>Later stages <small class="muted">(admission, finances, visa — not part of the documents status)</small></h4><div class="dk-grid">';
        foreach ($later as $g => $keys) foreach ($keys as $k) $out .= $item($k, DOC_LATER[$k][0], DOC_LATER[$k][0], $g, false, false);
        $out .= '</div></div>';
    }
    // "Other": files of no checklist document (and of documents that are no longer on this country's list)
    $docsAll = country_docs($c);
    $other = $files['docs']['other'] ?? [];
    foreach ($files['docs'] as $k => $fl) if ($k !== 'other' && !isset($docsAll[$k])) $other = array_merge($other, $fl);
    $out .= '<div class="dk-group"><h4>Other documents</h4><div class="dk-grid">'
          . $item('other', 'Other supporting documents', 'Other', 'Anything that is not on the list', false, false) . '</div></div>';
    if ($other !== ($files['docs']['other'] ?? [])) {
        // documents saved under keys this country does not list: show them read-only under "Other"
        $extra = array_udiff($other, $files['docs']['other'] ?? [], fn($a, $b) => $a['id'] <=> $b['id']);
        $out .= '<div class="dk-extra">' . implode('', array_map(fn($f) => doc_saved_line($f), $extra)) . '</div>';
    }
    return $out . '</div>';
}

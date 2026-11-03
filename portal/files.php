<?php
declare(strict_types=1);
// Actions on a student's documents and meetings (save documents, meetings, recordings, tasks, Drive sync). POST only.
// Used from the staff student record and from the student portal. Every action checks scope again.
require __DIR__ . '/app/bootstrap.php';
$u = require_signed_in();
if (!is_post()) { http_response_code(405); exit('Use the buttons on the student page.'); }

// A file bigger than post_max_size arrives with an empty form: say so instead of "form expired".
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('The file is too large. The limit is ' . fmt_size(max_upload_bytes()) . '.', 'bad');
    redirect(PORTALS[$u['role']]['home']);
}
csrf_check();

$action = post('action');
$anchor = in_array(post('back'), ['docs', 'meetings', 'tasks', 'tasks_section', 'processing'], true) ? post('back') : 'docs';
if ($anchor === 'tasks') $anchor = 'tasks_section';
$back = function (int $sid) use ($u, $anchor): never {
    redirect(($u['role'] === 'student' ? 'student/index.php' : portal_dir($u) . '/student.php?id=' . $sid . ($anchor === 'docs' ? '&step=documents' : '')) . '#' . $anchor);
};
$deny = function (): never { http_response_code(403); exit('Not allowed.'); };

// ---------------- saved files are never deleted ----------------
if ($action === 'delete_file') {
    $f = q('SELECT * FROM student_files WHERE id = ?', [(int)post('file_id')])->fetch();
    if (!$f || !can_open_file($u, $f)) { http_response_code(404); exit('File not found.'); }
    flash('Saved files stay with the student record and cannot be removed.', 'warn');
    $back((int)$f['student_id']);
}

$sid = (int)post('student_id');
$s = student_for_user($u, $sid);
if (!$s) { http_response_code(404); exit('Student not found.'); }

/** Normalise $_FILES[$k] (single or multiple) into a list of file arrays. */
$filesOf = function (string $k): array {
    if (empty($_FILES[$k])) return [];
    $F = $_FILES[$k];
    if (!is_array($F['name'])) return [$F];
    $out = [];
    foreach ($F['name'] as $i => $_) $out[] = ['name' => $F['name'][$i], 'type' => $F['type'][$i], 'tmp_name' => $F['tmp_name'][$i], 'error' => $F['error'][$i], 'size' => $F['size'][$i]];
    return array_values(array_filter($out, fn($x) => $x['error'] !== UPLOAD_ERR_NO_FILE));
};

// ---------------- documents: keep the files added in the checklist (student portal) ----------------
if ($action === 'docs_save') {
    $isStaff = can_manage_files($u);
    if (!$isStaff && $u['role'] !== 'student') $deny();
    [$ok, $msgs] = save_posted_documents($sid, (string)$s['country'], $u, $isStaff);
    if ($msgs) flash('Some files were not saved: ' . implode(' · ', $msgs), 'bad');
    flash($ok ? $ok . ' file' . ($ok > 1 ? 's' : '') . ' saved.' : 'Nothing new to save. Add a file under a document first.', $ok ? 'ok' : 'warn');
    $back($sid);
}

// ---------------- conditional meetings ----------------
if ($action === 'meeting_save' || $action === 'meeting_delete') {
    $id = (int)post('id');
    $m = $id ? q('SELECT * FROM student_meetings WHERE id = ? AND student_id = ?', [$id, $sid])->fetch() : null;
    if ($id && !$m) { http_response_code(404); exit('Meeting not found.'); }
    if ($m && !can_edit_meeting($u, $m['kind'], $s)) $deny();

    if ($action === 'meeting_delete') {
        // A meeting that has recordings saved keeps them: such a meeting is not deleted.
        if (q('SELECT 1 FROM student_files WHERE meeting_id = ? LIMIT 1', [$id])->fetchColumn()) {
            flash('This meeting has a recording saved, so it stays. Change its status (for example to Cancelled) instead.', 'warn');
            $back($sid);
        }
        q('DELETE FROM student_meetings WHERE id = ?', [$id]);
        log_action('Deleted meeting ' . $m['title'], $sid);
        evaluate_student_tasks($sid);
        flash('Meeting deleted.');
        $back($sid);
    }

    $kind = post('kind');
    if (!isset(MEETING_KINDS[$kind])) $kind = 'other';
    if (!can_edit_meeting($u, $kind, $s)) $deny();
    $title = mb_substr(post('title'), 0, 150) ?: MEETING_KINDS[$kind];
    $date = post('meeting_date'); $time = post('meeting_time');
    $status = in_array(post('status'), MEETING_STATUS, true) ? post('status') : 'Scheduled';

    // How the meeting is held: Face to Face, Online (Zoom / Meet) or Phone call. It must be chosen.
    $mType = post('meeting_type');
    $location = mb_substr(post('location'), 0, 150) ?: null;
    $staffMember = mb_substr(post('staff_member'), 0, 150) ?: null;
    $recAvail = post('recording_available') === 'Yes' ? 'Yes' : 'No';
    $recStatus = in_array(post('recording_upload_status'), ['Pending', 'Uploaded', 'Exception Authorized'], true) ? post('recording_upload_status') : 'Pending';
    $excReason = mb_substr(post('exception_reason'), 0, 1000) ?: null;
    $join = '';
    $rec = '';
    if ($mType === 'Zoom Meeting') { $join = post('join_link'); $rec = post('recording_link_zoom'); }
    elseif ($mType === 'On-Call' && $recAvail === 'Yes') { $rec = post('recording_link_call'); }

    $errors = [];
    if (!isset(MEETING_TYPES[$mType])) $errors[] = 'Choose how the meeting is held: face to face, online or phone call.';
    if ($date === '') $errors[] = 'Enter the meeting date.';
    elseif (!valid_date($date)) $errors[] = 'Meeting date is not a valid date.';
    if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Meeting time is not valid.';
    if ($join !== '' && !safe_link($join)) $errors[] = 'Meeting link must start with https://';
    if ($rec !== '' && !safe_link($rec)) $errors[] = 'Recording link must start with https://';
    if ($mType === 'Zoom Meeting' && $status === 'Done') {
        $hasMediaUpload = (bool)array_filter($filesOf('meeting_files'), fn($f) => in_array(file_ext((string)$f['name']), MEDIA_EXTS, true));
        $hasOldMedia = $m && (bool)q("SELECT 1 FROM student_files WHERE meeting_id = ? LIMIT 1", [(int)$m['id']])->fetchColumn();
        if ($rec === '' && !$hasMediaUpload && !$hasOldMedia && !($recStatus === 'Exception Authorized' && $excReason)) {
            $errors[] = 'An online meeting marked Done needs its recording (a link or an uploaded file), or an approved exception with the reason.';
        }
        if ($rec !== '' || $hasMediaUpload) $recStatus = 'Uploaded';
    }
    if ($mType !== 'Zoom Meeting') { $recStatus = null; $excReason = null; }
    if ($mType !== 'Face-to-Face') { $location = null; $staffMember = null; }
    $excAuthBy = ($recStatus === 'Exception Authorized' && $excReason) ? (int)$u['id'] : null;

    if ($errors) { flash(implode(' ', $errors), 'bad'); $back($sid); }

    $data = [
        $kind, $title, $date, $time ?: null, $status,
        $join !== '' ? mb_substr($join, 0, 500) : null,
        $rec !== '' ? mb_substr($rec, 0, 500) : null,
        mb_substr(post('notes'), 0, 5000) ?: null,
        post('student_can_see') === '1' ? 1 : 0,
        $mType, $location, $staffMember, $mType === 'On-Call' ? $recAvail : null, $recStatus, $excReason, $excAuthBy,
    ];
    if ($m) {
        q('UPDATE student_meetings SET kind=?, title=?, meeting_date=?, meeting_time=?, status=?, join_link=?, recording_link=?, notes=?, student_can_see=?, meeting_type=?, location=?, staff_member=?, recording_available=?, recording_upload_status=?, exception_reason=?, exception_authorized_by=?, updated_at=? WHERE id = ?',
          array_merge($data, [now(), $id]));
        log_action('Updated meeting: ' . $title . ' (' . meeting_type_label($mType) . ', ' . $status . ')', $sid);
    } else {
        q('INSERT INTO student_meetings (kind, title, meeting_date, meeting_time, status, join_link, recording_link, notes, student_can_see, meeting_type, location, staff_member, recording_available, recording_upload_status, exception_reason, exception_authorized_by, student_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          array_merge($data, [$sid, (int)$u['id'], now()]));
        $id = (int)db()->lastInsertId();
        log_action('Added meeting: ' . $title . ' (' . meeting_type_label($mType) . ', ' . $status . ')', $sid);
    }

    // Keep the student's sheet columns for this meeting in step (done Yes/No, how it was held, date).
    if (isset(MEETING_SUMMARY[$kind])) {
        [$doneCol, $modeCol, $dateCol] = MEETING_SUMMARY[$kind];
        $set = [$modeCol => $mType];
        if ($status === 'Done') $set[$doneCol] = 'Yes';
        elseif (in_array($status, ['Missed', 'Cancelled'], true) && !meeting_held((string)($s[$doneCol] ?? ''))) $set[$doneCol] = 'No';
        if ($dateCol) $set[$dateCol] = $date;
        if ($kind === 'initial' && $mType === 'Face-to-Face' && $status === 'Done' && in_array(strtolower(trim((string)($s['recorded_uploaded'] ?? ''))), ['', 'no'], true)) $set['recorded_uploaded'] = 'Yes, face to face';
        if ($kind === 'tax' && $rec !== '') $set['tax_recording_link'] = $rec;
        $set['updated_at'] = now();
        $set['updated_by'] = (int)$u['id'];
        q('UPDATE students SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?', array_merge(array_values($set), [$sid]));
    }

    $bad = [];
    foreach (array_slice($filesOf('meeting_files'), 0, 5) as $file) {
        $res = store_upload($file, $sid, 'meeting', $id, $u);
        if (!is_int($res)) $bad[] = $file['name'] . ': ' . $res;
    }
    if ($bad) flash(implode(' · ', $bad), 'bad');

    evaluate_student_tasks($sid);
    flash('Meeting saved.');
    $back($sid);
}

// ---------------- task completion action ----------------
if ($action === 'task_complete') {
    if (!can_manage_files($u)) $deny();
    $taskId = (int)post('task_id');
    $t = q('SELECT * FROM student_tasks WHERE id = ? AND student_id = ?', [$taskId, $sid])->fetch();
    if ($t) {
        q("UPDATE student_tasks SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $taskId]);
        log_action('Completed task: ' . $t['title'], $sid);
        flash('Task marked completed.');
    }
    $back($sid);
}

// ---------------- sync from Google Drive ----------------
if ($action === 'sync_drive') {
    if (!can_manage_files($u)) $deny();
    $res = sync_student_drive($s, (int)$u['id']);
    // Copy the student's Drive documents into the portal now, so they open and download as real files.
    $drive = q("SELECT * FROM student_files WHERE student_id = ? AND stored_name LIKE 'gdrive:%' AND meeting_id IS NULL", [$sid])->fetchAll();
    $got = gdrive_fetch_many($drive, microtime(true) + 25);
    $copied = count(array_filter($got, fn($x) => $x === true));
    $private = count(array_filter($got, fn($x) => is_string($x) && str_contains($x, 'not shared')));
    log_action('Checked the Drive folder (' . $res['docs_added'] . ' new document file(s), ' . $res['meetings_added'] . ' recording(s))', $sid);
    flash('Drive folder checked: ' . $res['docs_added'] . ' new document file' . ($res['docs_added'] === 1 ? '' : 's') . ($res['meetings_added'] ? ', ' . $res['meetings_added'] . ' recording(s)' : '')
        . '. ' . $copied . ' of ' . count($drive) . ' Drive document' . (count($drive) === 1 ? ' is' : 's are') . ' copied into the portal'
        . ($private ? '; ' . $private . ' cannot be copied because they are not shared as "Anyone with the link"' : '') . '.', $private ? 'warn' : 'ok');
    refresh_student_documents_status($sid);
    $back($sid);
}

http_response_code(400);
exit('Unknown action.');

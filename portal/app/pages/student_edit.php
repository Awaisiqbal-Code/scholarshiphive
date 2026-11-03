<?php
declare(strict_types=1);
// =====================================================================================
// Student record: add / view / edit. Used by admin/student.php, officer/student.php and tax/student.php.
// The form is built from the selected country's sheet (app/sheets.php): every column of that country's
// sheets is asked exactly once, validated, saved to the database and exported back with the same columns.
// Expects $u.
// =====================================================================================

$admin = is_admin($u);
$fullAdmin = is_full_admin($u);
$tax = $u['role'] === 'tax';
$base = portal_dir($u);
$isNew = !$tax && (get('new') === '1' || post('mode') === 'new');
$id = (int)(get('id') ?: post('id'));
$r = $isNew ? [] : find_student($u, $id);
$savedRow = $r;   // as stored, before any typed values are shown again

if ($isNew) {
    $mine = user_countries($u);
    if (!$mine) { http_response_code(403); exit('You have no countries assigned. Ask an admin to add one in Staff.'); }
    $country = get('c') ?: post('country');
    if (!in_array($country, $mine, true)) $country = in_array('italy', $mine, true) ? 'italy' : $mine[0];
    $r = ['country' => $country, 'process_status' => 'Active'];
    if ($u['role'] === 'officer') $r[($u['team'] ?? '') === 'backend' ? 'back_officer_id' : 'officer_id'] = (int)$u['id'];
}
$country = valid_country((string)$r['country']) ? $r['country'] : 'italy';
$groups = country_groups($country);

/** Sections the tax consultant may open (read-only except the tax meeting fields). */
$taxSections = ['Student profile', 'Meetings', 'Documents', 'Visa filing', 'Admission & visa'];
$fieldAccess = function (string $section, string $key, array $def) use ($u, $tax, $taxSections, &$r): array {
    $see = can_see_field($u, $def['team'], $def['type'], $r) && (!$tax || in_array($section, $taxSections, true));
    return [$see, $see && can_edit_team($u, $def['team'], $r)];
};

$errors = [];      // key => message
$warnings = [];
$posted = [];      // submitted values to show again when there are errors

// ---------- delete student (full admin only) ----------
if (is_post() && post('action') === 'delete') {
    csrf_check();
    if (!$fullAdmin) { http_response_code(403); exit('Only a full admin can delete a student.'); }
    if (post('confirm') !== 'yes') { flash('Tick the box to confirm deletion.', 'bad'); redirect("admin/student.php?id=$id"); }
    foreach (q('SELECT * FROM student_files WHERE student_id = ?', [$id])->fetchAll() as $f) delete_file_row($f);
    foreach (['student_tasks', 'student_meetings', 'student_documents', 'applications', 'import_notes'] as $t) q("DELETE FROM $t WHERE student_id = ?", [$id]);
    q('DELETE FROM users WHERE student_id = ?', [$id]);
    q('DELETE FROM students WHERE id = ?', [$id]);
    log_action('Deleted student ' . $r['name'] . ' (' . country_name($country) . ')', null);
    flash('Student deleted.');
    redirect('admin/students.php?c=' . $country);
}

// ---------- save ----------
if (is_post() && in_array(post('action'), ['save', 'save_draft'], true)) {
    csrf_check();
    $isDraft = post('action') === 'save_draft';
    $limits = column_limits('students');
    $data = [];

    // Country change (admins, existing record): the record keeps every value; the form switches to the new sheet.
    $newCountry = post('country');
    if (!$isNew && $admin && $newCountry !== '' && $newCountry !== $country && valid_country($newCountry) && in_array($newCountry, user_countries($u), true)) {
        $data['country'] = $newCountry;
    }

    $fieldsToCheck = [];
    foreach ($groups as $title => $g) foreach ($g['fields'] as $k => $def) $fieldsToCheck[$k] = [$title, $def];
    if (!$isNew) foreach (extra_fields_with_data($r) as $k => $def) $fieldsToCheck[$k] = ['Other saved details', $def];

    foreach ($fieldsToCheck as $k => [$section, $def]) {
        if (in_array($k, DOCS_COMPUTED, true)) continue;   // worked out from the documents, never typed
        [$see, $edit] = $fieldAccess($section, $k, $def);
        if (!$see || !$edit) continue;
        $type = $def['type'];

        if ($type === 'front_officer' || $type === 'back_officer') {
            if (!$admin) continue;   // officers are assigned automatically / by an admin
            if (!array_key_exists($k, $_POST)) continue;
            $oid = (int)post($k);
            if ($oid > 0 && !q("SELECT 1 FROM users WHERE id = ? AND role = 'officer'", [$oid])->fetchColumn()) { $errors[$k] = 'Choose an officer from the list.'; continue; }
            $data[$k] = $oid ?: null;
            if ($def['req'] && !$oid && !$isDraft && ($isNew || !empty($r[$k]))) $errors[$k] = $def['label'] . ' is required.';
            continue;
        }
        if ($type === 'secret') {
            $v = post($k);
            if ($v !== '') {
                if (mb_strlen($v) > 200) { $errors[$k] = $def['label'] . ' is too long.'; continue; }
                $data[$k] = enc($v);
            } elseif (post($k . '__clear') === '1') {
                $data[$k] = null;
            }
            continue;
        }
        if (!$isNew && !array_key_exists($k, $_POST)) continue;   // field not on the page: leave it as it is

        $cur = isset($r[$k]) ? (string)$r[$k] : null;
        [$val, $err] = validate_field($k, $def, post($k), $cur, $limits[$k] ?? null);
        $posted[$k] = $val ?? '';
        if ($err) { $errors[$k] = $err; continue; }

        if ($def['req'] && $val === null && !($isDraft && $k !== 'name')) {
            if ($isNew || ($cur !== null && trim($cur) !== '')) { $errors[$k] = $def['label'] . ' is required.'; continue; }
            $warnings[] = $def['label'];   // older record that never had it: allowed, but flagged
        }
        $data[$k] = $val;
    }
    if ($isNew && empty($data['name'])) $errors['name'] = $errors['name'] ?? 'Enter the student name.';

    // Meetings: when a meeting is marked as held, say how it was held (face to face, online or phone).
    foreach (MEETING_SUMMARY as $kind => [$doneCol, $modeCol]) {
        if (!array_key_exists($doneCol, $data) && !array_key_exists($modeCol, $data)) continue;
        $done = $data[$doneCol] ?? ($r[$doneCol] ?? null);
        $mode = array_key_exists($modeCol, $data) ? $data[$modeCol] : ($r[$modeCol] ?? null);
        $form = country_fields($country);
        if (isset($form[$modeCol]) && meeting_held((string)$done) && !$mode && !$isDraft) {
            $touched = (string)($data[$doneCol] ?? $r[$doneCol] ?? '') !== (string)($r[$doneCol] ?? '');
            if ($isNew || $touched) $errors[$modeCol] = 'Choose how the ' . strtolower(MEETING_KINDS[$kind]) . ' was held: face to face, online or phone.';
            else $warnings[] = 'how the ' . strtolower(MEETING_KINDS[$kind]) . ' was held';
        }
    }

    // Officers: officers who add a student are assigned to it automatically.
    if ($isNew && !$admin) $data[($u['team'] ?? '') === 'backend' ? 'back_officer_id' : 'officer_id'] = (int)$u['id'];

    if (!$errors) {
        sync_derived_fields($data, $r);
        $data['is_draft'] = $isDraft ? 1 : 0;
        $data['updated_by'] = (int)$u['id'];
        if ($isNew) {
            $data['country'] = $country;
            $data['process_status'] = $data['process_status'] ?? 'Active';
            $data['entered_by'] = (int)$u['id'];
            $data['created_at'] = now();
            $data['sr_no'] = (int)q('SELECT COALESCE(MAX(sr_no), 0) + 1 FROM students WHERE country = ?', [$country])->fetchColumn();
            $cols = array_keys($data);
            q('INSERT INTO students (' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
            $id = (int)db()->lastInsertId();
            log_action('Added ' . country_name($country) . ' student ' . $data['name'] . ($isDraft ? ' (draft)' : ''), $id);
        } else {
            // Record what changed, field by field, for the activity log.
            $changed = [];
            foreach ($data as $k => $v) {
                if (in_array($k, ['updated_by', 'is_draft'], true)) continue;
                $old = $r[$k] ?? null;
                if ((string)$old === (string)$v) continue;
                $lbl = $fieldsToCheck[$k][1]['label'] ?? (FIELDS[$k][0] ?? $k);
                $changed[] = str_ends_with($k, '_enc') ? "$lbl changed" : $lbl . ': ' . mb_strimwidth((string)$old, 0, 25, '…') . ' → ' . mb_strimwidth((string)$v, 0, 25, '…');
            }
            $data['updated_at'] = now();
            [$w, $p] = scope_sql($u, 'students');
            q('UPDATE students SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data))) . " WHERE id = ? AND $w", array_merge(array_values($data), [$id], $p));
            if ($changed) log_action('Updated ' . count($changed) . ' field(s): ' . implode('; ', $changed), $id);
        }

        // Documents: files added in the Documents section (already uploaded and checked; kept now) and the
        // "received outside the portal" / "ask for it" marks. Status and pending list are then worked out again.
        $fileMsgs = []; $fileOk = 0;
        if (can_manage_files($u)) [$fileOk, $fileMsgs] = save_posted_documents($id, $isNew ? $country : (string)($data['country'] ?? $country), $u, true);
        if (!empty($data['documents_link']) && ($data['documents_link'] ?? null) !== ($r['documents_link'] ?? null)) {
            $latest = q('SELECT * FROM students WHERE id = ?', [$id])->fetch();
            if ($latest) { sync_student_drive($latest, (int)$u['id']); refresh_student_documents_status($id, false); }
        }
        evaluate_student_tasks($id);

        flash($isDraft ? 'Draft saved. Complete the required fields when you have them.' : ($isNew ? 'Student added.' : 'Changes saved.'));
        if ($fileOk) flash($fileOk . ' document file' . ($fileOk > 1 ? 's' : '') . ' saved.');
        if ($fileMsgs) flash('Some files were not saved: ' . implode(' · ', $fileMsgs), 'bad');
        if ($warnings) flash('Still missing on this record (required by the sheet): ' . implode(', ', array_unique($warnings)) . '.', 'warn');
        $step = preg_replace('/[^a-z0-9_-]/', '', post('active_step'));
        redirect("$base/student.php?id=$id" . ($step ? '&step=' . $step : ''));
    }
    // Show the form again with what was typed (passwords are never echoed back).
    foreach ($posted as $k => $v) $r[$k] = $v;
    foreach (['officer_id', 'back_officer_id'] as $k) if (array_key_exists($k, $data)) $r[$k] = $data[$k];
}

// ---------- page data ----------
// Files added before a save that did not go through (a field had an error) are shown again, still unsaved.
$stagedBack = [];
if (is_post() && isset($_POST['staged']) && is_array($_POST['staged'])) {
    foreach ($_POST['staged'] as $k => $toks) if (is_array($toks)) foreach ($toks as $t) if ($m = stage_get((string)$t, $u)) $stagedBack[(string)$k][] = $m;
}
$files = $isNew ? ['docs' => [], 'meetings' => []] : student_files($id);
$meetings = $isNew ? [] : student_meetings($id);
$tasks = $isNew ? [] : student_tasks_list($id);
if (!$isNew) $r = attach_app_counts([$r])[0];
$login = (!$isNew && $admin) ? q('SELECT id, username, active, last_login FROM users WHERE student_id = ?', [$id])->fetch() : null;
$apps = (!$isNew && !$tax) ? q('SELECT * FROM applications WHERE student_id = ? ORDER BY list_name, id', [$id])->fetchAll() : [];
$canApps = !$tax && can_edit_team($u, 'backend', $r);
$notesRows = (!$isNew && $admin) ? q('SELECT * FROM import_notes WHERE student_id = ? ORDER BY resolved, id', [$id])->fetchAll() : [];
$pendingTasksCount = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
$extra = $isNew ? [] : extra_fields_with_data($r);
$history = $isNew ? [] : q('SELECT a.action, a.created_at, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.student_id = ? ORDER BY a.id DESC LIMIT 15', [$id])->fetchAll();
$lastBy = (!$isNew && !empty($r['updated_by'])) ? q('SELECT name FROM users WHERE id = ?', [(int)$r['updated_by']])->fetchColumn() : null;
$allUploaded = $isNew ? [] : q('SELECT * FROM student_files WHERE student_id = ? ORDER BY created_at DESC', [$id])->fetchAll();
$oldRecordings = array_filter($allUploaded, fn($f) => in_array($f['doc_key'], ['meeting_initial', 'meeting_tax'], true) && !$f['meeting_id']);
$officers = [
    'front_officer' => officer_choices($country, 'frontend', isset($r['officer_id']) ? (int)$r['officer_id'] : null),
    'back_officer'  => officer_choices($country, 'backend', isset($r['back_officer_id']) ? (int)$r['back_officer_id'] : null),
];

// Steps: one per section of the sheet form, plus documents.
$steps = [];
foreach ($groups as $title => $g) {
    $visible = [];
    foreach ($g['fields'] as $k => $def) { [$see] = $fieldAccess($title, $k, $def); if ($see) $visible[$k] = $def; }
    if ($visible || $title === 'Documents') $steps[preg_replace('/[^a-z0-9]+/', '-', strtolower($title))] = [$title, $visible];
}
if ($extra && !$tax) $steps['other'] = ['Other saved details', $extra];
$activeStep = preg_replace('/[^a-z0-9-]/', '', get('step') ?: post('active_step'));
if (!isset($steps[$activeStep])) $activeStep = array_key_first($steps);
if ($errors) {   // open the first section that has a problem
    foreach ($steps as $sk => [, $fl]) if (array_intersect_key($errors, $fl)) { $activeStep = $sk; break; }
}

page_start($isNew ? 'New student' : $r['name'], $u, PORTALS[$u['role']]['label']);
?>
<p class="small"><a href="<?= h(url($base . '/students.php?c=' . $country)) ?>">← <?= h(country_name($country)) ?> students</a></p>

<div class="page-h">
  <div>
    <div class="eyebrow"><span class="cc"><?= h(COUNTRIES[$country]['code']) ?></span> <?= h(country_name($country)) ?> · form built from the <?= h(COUNTRIES[$country]['sheet']) ?></div>
    <h1 style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <?= $isNew ? 'New student' : h($r['name']) ?>
      <?php if (!$isNew): ?><span class="pill <?= ($r['process_status'] ?? '') === 'Stopped' ? 'bad' : 'ok' ?>"><?= h($r['process_status'] ?: 'Active') ?></span><?php endif; ?>
      <?php if (!empty($r['is_draft'])): ?><span class="pill warn">Draft</span><?php endif; ?>
    </h1>
    <?php if (!$isNew): $ns = next_stage($r); ?>
      <p><?= progress_dots($r) ?> <?= ($r['process_status'] ?? '') === 'Stopped' ? '<span class="pill bad">Process stopped</span>' : ($ns ? 'Current step: <b>' . h($ns) . '</b> · ' . h(stage_phase($r)) : '<b>All tracked steps done</b>') ?></p>
      <p class="muted small">
        Front end: <?= h($r['officer_name'] ?? 'Not assigned') ?> · Back end: <?= h($r['back_officer_name'] ?? 'Not assigned') ?>
        · Documents: <b><?= h((string)($r['documents_status'] ?? '')) ?: '—' ?></b><?= !empty($r['pending_documents']) ? ' <span class="muted">(pending: ' . h((string)$r['pending_documents']) . ')</span>' : '' ?>
        <?php if ($pendingTasksCount): ?> · <a class="pill warn" href="#tasks_section"><?= $pendingTasksCount ?> task(s) pending</a><?php endif; ?>
        <?php if (!empty($r['updated_at'])): ?> · Last updated <?= h(fmt_date(substr((string)$r['updated_at'], 0, 10))) ?><?= $lastBy ? ' by ' . h((string)$lastBy) : '' ?><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <div class="row-actions">
    <?php if (!$isNew): ?>
      <a class="btn sm primary" href="<?= h(url('download_all.php?id=' . (int)$id)) ?>" title="A ZIP with every document file of this student and a PDF of the student's details">⬇ Download student file</a>
      <a class="btn sm" href="<?= h(url($base . '/dossier.php?id=' . (int)$id)) ?>" target="_blank">Print summary</a>
      <a class="btn sm" href="#meetings">Meetings (<?= count($meetings) ?>)</a>
      <?php if (!$tax): ?><a class="btn sm" href="#applications">Universities (<?= count($apps) ?>)</a><?php endif; ?>
      <?php if ($admin): ?><a class="btn sm" href="<?= h(url('admin/preview.php?id=' . (int)$id)) ?>">Student portal view</a><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash bad" role="alert"><b>Nothing was saved yet. Please fix <?= count($errors) ?> field(s):</b>
    <ul class="notes"><?php foreach ($errors as $k => $msg): ?><li><a href="#f_<?= h($k) ?>" data-goto-field="<?= h($k) ?>"><?= h($msg) ?></a></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($notesRows && array_filter($notesRows, fn($x) => !$x['resolved'])): ?>
  <div class="flash warn"><b>Check from the sheet import:</b>
    <ul class="notes"><?php foreach ($notesRows as $nr) if (!$nr['resolved']): ?><li><?= $nr['field'] ? '<b>' . h(FIELDS[$nr['field']][0] ?? $nr['field']) . ':</b> ' : '' ?><?= h($nr['note']) ?> <span class="muted">(<?= h($nr['sheet']) ?><?= $nr['ref'] ? ', ' . h($nr['ref']) : '' ?>)</span></li><?php endif; ?></ul>
    <a href="<?= h(url('admin/datacheck.php?c=' . $country)) ?>">Open data check</a></div>
<?php endif; ?>

<form method="post" id="student_main_form" class="stack student-form" autocomplete="off" enctype="multipart/form-data" novalidate data-country="<?= h($country) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="active_step" id="active_step_input" value="<?= h($activeStep) ?>">

  <?php if ($isNew || ($admin && !$tax)): ?>
  <div class="panel country-pick">
    <label class="f"><span><b>Country</b> <?= $isNew ? '(changing it reloads the form for that country\'s sheet)' : '(admins can move a student to another country; the form then follows that country\'s sheet)' ?></span>
      <select name="country" id="country_selector" <?= $isNew ? 'data-reload-country="' . h(url($base . '/student.php?new=1&c=')) . '"' : '' ?>>
        <?php foreach (user_countries($u) as $cKey): ?>
          <option value="<?= h($cKey) ?>" <?= $cKey === $country ? 'selected' : '' ?>><?= h(country_name($cKey)) ?> (<?= h(COUNTRIES[$cKey]['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <?php endif; ?>

  <nav class="stepper-nav" aria-label="Form sections">
    <?php $n = 0; foreach ($steps as $sk => [$title, $fl]): $n++;
        $bad = (bool)array_intersect_key($errors, $fl); ?>
      <button type="button" class="btn sm <?= $sk === $activeStep ? 'primary' : 'ghost' ?> step-nav-btn<?= $bad ? ' has-err' : '' ?>" data-step="<?= h($sk) ?>"><?= $n ?>. <?= h($title) ?><?= $bad ? ' ⚠' : '' ?></button>
    <?php endforeach; ?>
  </nav>

  <?php $keys = array_keys($steps); foreach ($steps as $sk => [$title, $fl]): $pos = array_search($sk, $keys, true); ?>
  <div class="workflow-step-pane" id="pane_<?= h($sk) ?>" data-step="<?= h($sk) ?>" <?= $sk === $activeStep ? '' : 'hidden' ?>>
    <fieldset>
      <legend><?= h($title) ?></legend>
      <?php if ($sk === 'other'): ?><p class="muted small">These details were saved earlier but are not columns of the <?= h(country_name($country)) ?> sheet. They are kept so nothing is lost; they are not asked on new records.</p><?php endif; ?>

      <?php if ($title === 'Meetings'):
          // Group the meeting columns into one card per meeting.
          $cards = [
              'initial' => ['1st meeting · initial recorded meeting (front end & counselor)', ['initial_meeting', 'initial_meeting_mode', 'recorded_uploaded', 'drive_link', 'recording_note']],
              'tax'     => ['Tax consultant meeting', ['tax_meeting', 'tax_meeting_mode', 'meeting_priority', 'meeting_date', 'tax_recording_link', 'tax_remarks']],
              'second'  => ['2nd processing meeting with client', ['second_meeting', 'second_meeting_mode', 'follow_up_2']],
          ];
          foreach ($cards as $kind => [$ctitle, $ckeys]):
              $present = array_intersect_key($fl, array_flip($ckeys));
              if (!$present) continue; ?>
          <div class="meet-card" data-kind="<?= h($kind) ?>">
            <div class="meet-card-h"><b><?= h($ctitle) ?></b> <span class="pill"><?= h(meeting_summary_text($r, $kind)) ?></span></div>
            <p class="muted small f2f-note" data-f2f-note="<?= h($kind) ?>" hidden>Face to face: no recording is needed for this meeting.</p>
            <div class="fields">
            <?php foreach ($ckeys as $k): if (!isset($present[$k])) continue; [, $edit] = $fieldAccess($title, $k, $fl[$k]);
                $hideF2f = in_array($k, ['recorded_uploaded', 'drive_link', 'tax_recording_link'], true); ?>
              <?= $hideF2f ? '<div class="f-wrap" data-f2f-hide="' . h($kind) . '">' : '' ?>
              <?= render_field($k, $fl[$k], ['value' => $r[$k] ?? '', 'error' => $errors[$k] ?? null, 'edit' => $edit, 'country' => $country, 'officers' => [], 'strict' => $isNew || trim((string)($savedRow[$k] ?? '')) !== '']) ?>
              <?= $hideF2f ? '</div>' : '' ?>
            <?php endforeach; ?>
            </div>
            <?php if ($kind === 'initial' && $oldRecordings): ?>
              <div class="files small"><?php foreach ($oldRecordings as $f) echo file_line($f, $u, 'meetings'); ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach;
          $rest = array_diff_key($fl, array_flip(array_merge(...array_map(fn($c) => $c[1], array_values($cards)))));
          if ($rest): ?><div class="fields"><?php foreach ($rest as $k => $def): [, $edit] = $fieldAccess($title, $k, $def); ?>
            <?= render_field($k, $def, ['value' => $r[$k] ?? '', 'error' => $errors[$k] ?? null, 'edit' => $edit, 'country' => $country, 'strict' => $isNew || trim((string)($savedRow[$k] ?? '')) !== '']) ?>
          <?php endforeach; ?></div><?php endif; ?>
          <?php if (!$isNew): ?><p class="small"><a href="#meetings">Schedule a meeting, add notes or upload a recording in the Meetings panel below ↓</a></p><?php endif; ?>

      <?php else: ?>
        <div class="fields">
        <?php foreach ($fl as $k => $def): if (in_array($k, DOCS_COMPUTED, true)) continue; [, $edit] = $fieldAccess($title, $k, $def);
            if (!$admin && in_array($def['type'], ['front_officer', 'back_officer'], true)) $edit = false; ?>
          <?= render_field($k, $def, ['value' => $r[$k] ?? '', 'error' => $errors[$k] ?? null, 'edit' => $edit, 'country' => $country, 'officers' => $officers[$def['type']] ?? [], 'strict' => $isNew || trim((string)($savedRow[$k] ?? '')) !== '']) ?>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($title === 'Documents'): ?>
        <div class="sub-h" id="docs"><h3 class="sub">Documents checklist</h3>
          <?php if (!$isNew && can_manage_files($u) && safe_link($r['documents_link'] ?? null) && !is_shared_drive_folder($r['documents_link'], $id)): ?>
            <button type="submit" form="sync_drive_form" class="btn sm" title="Look in the student's Google Drive folder for files that are not in the portal yet">↻ Check the Drive folder for new files</button>
          <?php endif; ?></div>
        <?= render_docs_box($u, $r + ['id' => $isNew ? 0 : $id], $files, ['form' => 'student_main_form', 'staff' => can_manage_files($u), 'add' => can_manage_files($u), 'staged' => $stagedBack]) ?>
        <p class="muted small">Video and audio recordings go in the Meetings panel.</p>
      <?php endif; ?>

      <div class="step-footer-actions">
        <?php if ($pos > 0): ?><button type="button" class="btn ghost step-nav-btn" data-step="<?= h($keys[$pos - 1]) ?>">← <?= h($steps[$keys[$pos - 1]][0]) ?></button><?php else: ?><span></span><?php endif; ?>
        <?php if ($pos < count($keys) - 1): ?><button type="button" class="btn ghost step-nav-btn" data-step="<?= h($keys[$pos + 1]) ?>"><?= h($steps[$keys[$pos + 1]][0]) ?> →</button><?php endif; ?>
      </div>
    </fieldset>
  </div>
  <?php endforeach; ?>

  <div class="save-bar">
    <?php /* "Save" comes first in the page so that pressing Enter in a field saves (it is the form's default button). */ ?>
    <button class="btn primary" type="submit" name="action" value="save"><?= $isNew ? 'Save new student' : 'Save changes' ?></button>
    <?php if (!$tax): ?><button class="btn ghost" type="submit" name="action" value="save_draft" formnovalidate title="Saves without the other required fields (the name is still needed)">Save as draft</button><?php endif; ?>
    <span class="muted small" id="dirty_note" hidden>Unsaved changes</span>
    <a class="btn ghost" href="<?= h(url($base . '/students.php?c=' . $country)) ?>">Cancel</a>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" action="<?= h(url('files.php')) ?>" id="sync_drive_form" hidden><?= csrf_field() ?>
    <input type="hidden" name="action" value="sync_drive"><input type="hidden" name="back" value="docs"><input type="hidden" name="student_id" value="<?= (int)$id ?>"></form>
  <?php $studentMode = false; include APP . '/pages/_meetings.php'; ?>

  <?php if (!$tax): ?>
  <section class="panel" id="applications">
    <div class="panel-h">
      <div><h2>Universities and programmes</h2><p><?= count($apps) ?> listed · the university lists from the <?= h(country_name($country)) ?> sheet</p></div>
      <?php if ($canApps): ?><a class="btn sm primary" href="<?= h(url($base . '/application.php?student=' . (int)$id)) ?>">+ Add university</a><?php endif; ?>
    </div>
    <?php if (!$apps): ?>
      <div class="empty">No universities listed yet.</div>
    <?php else: $lastList = null; ?>
    <div class="tbl"><table>
      <thead><tr><th>University</th><th>Programme</th><th>Type</th><th>Deadline</th><th>Fee</th><th>Status</th><th>Result</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($apps as $a):
          if ($a['list_name'] !== $lastList): $lastList = $a['list_name']; ?><tr class="grp"><td colspan="8"><?= h($a['list_name'] ?: 'Applications') ?></td></tr><?php endif; ?>
        <tr>
          <td><b><?= h($a['university']) ?></b></td><td><?= h($a['program']) ?></td><td><?= h($a['app_type']) ?></td>
          <td class="small"><?= h($a['deadline']) ?></td><td class="small"><?= h($a['fee']) ?></td><td class="small"><?= h($a['status']) ?></td>
          <td><?= pill($a['result']) ?></td>
          <td><?php if ($canApps): ?><a class="btn xs" href="<?= h(url($base . '/application.php?id=' . (int)$a['id'])) ?>">Edit</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel" id="tasks_section">
    <div class="panel-h"><div><h2>Follow-up tasks</h2><p>Created and closed automatically from the record (consent, documents, meetings, visa appointment).</p></div>
      <span class="pill <?= $pendingTasksCount ? 'warn' : 'ok' ?>"><?= $pendingTasksCount ?> pending</span></div>
    <?php if (!$tasks): ?><div class="empty">No follow-up tasks.</div><?php else: ?>
      <div class="tbl"><table>
        <thead><tr><th>Task</th><th>Assigned</th><th>Due</th><th>Priority</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $tItem): $isPend = $tItem['status'] === 'pending'; ?>
          <tr>
            <td><b><?= h($tItem['title']) ?></b><?php if ($tItem['description']): ?><div class="muted small"><?= h($tItem['description']) ?></div><?php endif; ?></td>
            <td><?= h($tItem['assigned_to_name'] ?: ucfirst((string)($tItem['assigned_team'] ?? 'Staff'))) ?></td>
            <td><?= $tItem['due_date'] ? h(fmt_date($tItem['due_date'])) : '—' ?></td>
            <td><span class="pill <?= match ($tItem['priority']) { 'high' => 'bad', 'medium' => 'warn', default => '' } ?>"><?= h($tItem['priority']) ?></span></td>
            <td><span class="pill <?= $isPend ? 'warn' : 'ok' ?>"><?= h($tItem['status']) ?></span></td>
            <td><?php if ($isPend && !$tax): ?>
              <form method="post" action="<?= h(url('files.php')) ?>" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="task_complete"><input type="hidden" name="back" value="tasks">
                <input type="hidden" name="student_id" value="<?= (int)$id ?>"><input type="hidden" name="task_id" value="<?= (int)$tItem['id'] ?>">
                <button type="submit" class="btn xs ok-b">Mark done</button></form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <?php if ($history && !$tax): ?>
  <section class="panel" id="history">
    <div class="panel-h"><div><h2>Recent changes</h2><p>Who updated this record and what changed (full list in Admin › Activity).</p></div></div>
    <div class="list"><?php foreach ($history as $hrow): ?>
      <div class="li"><div><b><?= h($hrow['name'] ?? 'System') ?></b><div class="m"><?= h($hrow['action']) ?></div></div><span class="small muted num"><?= h(fmt_date(substr($hrow['created_at'], 0, 10)) . ' ' . substr($hrow['created_at'], 11, 5)) ?></span></div>
    <?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php if ($admin): ?>
  <section class="panel">
    <div class="panel-h"><h2>Student portal account</h2><p>Login for the student to follow their steps, universities and documents.</p></div>
    <?php $cred = $_SESSION['new_cred'] ?? null; if ($cred && ($cred[0] ?? '') === $r['name']): unset($_SESSION['new_cred']); ?>
      <div class="cred" role="status"><b>New login for <?= h($cred[0]) ?></b>
        <div>Portal: <code><?= h(url('student/login.php')) ?></code></div>
        <div>Username: <code><?= h($cred[1]) ?></code> · Password: <code><?= h($cred[2]) ?></code></div>
        <p class="small">Send this to the student now. It is shown only once; they choose their own password at first sign-in.</p></div>
    <?php endif; ?>
    <?php if ($login): ?>
      <p>Username: <code><?= h($login['username']) ?></code> · <?= $login['active'] ? '<span class="pill ok">Active</span>' : '<span class="pill bad">Disabled</span>' ?> · Last sign-in: <?= $login['last_login'] ? h(fmt_date(substr($login['last_login'], 0, 10))) : 'never' ?></p>
    <?php else: ?><p class="muted">No student login created yet.</p><?php endif; ?>
    <form method="post" action="<?= h(url('admin/logins.php')) ?>" class="row-actions"><?= csrf_field() ?>
      <input type="hidden" name="student_id" value="<?= (int)$id ?>">
      <button class="btn sm" name="action" value="reset"><?= $login ? 'Reset password' : 'Create login' ?></button>
      <?php if ($login): ?><button class="btn sm" name="action" value="toggle"><?= $login['active'] ? 'Disable' : 'Enable' ?></button><?php endif; ?>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($fullAdmin): ?>
  <section class="panel danger">
    <div class="panel-h"><h2>Delete student</h2><p>Permanently removes the record, its documents, meetings, universities and login.</p></div>
    <form method="post" class="row-actions" data-confirm="Delete <?= h($r['name']) ?> permanently?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="check"><input type="checkbox" name="confirm" value="yes"> I understand this cannot be undone</label>
      <button class="btn sm bad-b" type="submit">Delete student</button>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php page_end(); ?>

<?php
declare(strict_types=1);
// Meetings panel: the meetings log of one student plus the meetings recorded only as sheet columns.
// Used on the staff student page ($studentMode = false) and in the student portal ($studentMode = true).
// Expects $r, $u, $meetings, $files, $studentMode.

$sheet = sheet_meetings($r, $meetings);
$canAdd = !$studentMode && ($u['role'] === 'tax' || can_manage_files($u));
$t = today();
$recFolder = safe_link($r['drive_link'] ?? null);
if ($recFolder && drive_id($recFolder) === drive_id($r['documents_link'] ?? null)) $recFolder = null;
if ($studentMode && $recFolder && is_shared_drive_folder($recFolder, (int)$r['id'])) $recFolder = null;

$meetForm = function (?array $m) use ($r, $u): string {
    $v = fn($k, $d = '') => h((string)($m[$k] ?? $d));
    $mType = (string)($m['meeting_type'] ?? '');
    $uniq = $m ? 'e' . (int)$m['id'] : 'new';
    $kinds = array_filter(MEETING_KINDS, fn($k) => can_edit_meeting($u, $k, $r), ARRAY_FILTER_USE_KEY);
    $sel = fn(string $name, array $opts, string $cur) => implode('', array_map(fn($k, $l) => '<option value="' . h($k) . '"' . ($cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts));

    $o = '<form method="post" action="' . h(url('files.php')) . '" enctype="multipart/form-data" class="stack mform" data-meet-form="' . $uniq . '">' . csrf_field()
       . '<input type="hidden" name="action" value="meeting_save"><input type="hidden" name="back" value="meetings">'
       . '<input type="hidden" name="student_id" value="' . (int)$r['id'] . '"><input type="hidden" name="id" value="' . (int)($m['id'] ?? 0) . '">'
       . '<div class="fields">'
       . '<label class="f"><span>Meeting <b class="req">*</b></span><select name="kind">' . $sel('kind', $kinds, (string)($m['kind'] ?? '')) . '</select></label>'
       . '<label class="f"><span>How is it held? <b class="req">*</b></span><select name="meeting_type" class="meet-type-select" required><option value="">Choose…</option>' . $sel('meeting_type', MEETING_TYPES, $mType) . '</select></label>'
       . '<label class="f"><span>Date <b class="req">*</b></span><input type="date" name="meeting_date" value="' . $v('meeting_date') . '" required></label>'
       . '<label class="f"><span>Time</span><input type="time" name="meeting_time" value="' . $v('meeting_time') . '"></label>'
       . '<label class="f"><span>Status</span><select name="status">' . $sel('status', array_combine(MEETING_STATUS, MEETING_STATUS), (string)($m['status'] ?? 'Scheduled')) . '</select></label>'
       . '<label class="f"><span>Title (optional)</span><input name="title" value="' . $v('title') . '" placeholder="Leave empty to use the meeting name"></label>'

       // Face to face
       . '<div class="mgroup" data-for="Face-to-Face">'
       . '<label class="f"><span>Location</span><input name="location" value="' . $v('location') . '" placeholder="e.g. Lahore office"></label>'
       . '<label class="f"><span>Staff member</span><input name="staff_member" value="' . $v('staff_member', $u['name']) . '"></label>'
       . '<p class="muted small wide">Face to face: no recording is needed.</p></div>'

       // Online
       . '<div class="mgroup" data-for="Zoom Meeting">'
       . '<label class="f wide"><span>Meeting link (Zoom / Google Meet)</span><input type="url" name="join_link" value="' . $v('join_link') . '" placeholder="https://..."></label>'
       . '<label class="f wide"><span>Recording link (needed when marked Done)</span><input type="url" name="recording_link_zoom" value="' . ($mType === 'Zoom Meeting' ? $v('recording_link') : '') . '" placeholder="https://drive.google.com/..."></label>'
       . '<label class="f"><span>Recording</span><select name="recording_upload_status">' . $sel('rus', ['Pending' => 'Not uploaded yet', 'Uploaded' => 'Uploaded', 'Exception Authorized' => 'Not recorded (approved exception)'], (string)($m['recording_upload_status'] ?? 'Pending')) . '</select></label>'
       . '<label class="f wide"><span>Reason there is no recording (for an approved exception)</span><input name="exception_reason" value="' . $v('exception_reason') . '"></label></div>'

       // Phone
       . '<div class="mgroup" data-for="On-Call">'
       . '<label class="f"><span>Call recorded?</span><select name="recording_available">' . $sel('ra', ['No' => 'No', 'Yes' => 'Yes'], (string)($m['recording_available'] ?? 'No')) . '</select></label>'
       . '<label class="f wide"><span>Call recording link (optional)</span><input type="url" name="recording_link_call" value="' . ($mType === 'On-Call' ? $v('recording_link') : '') . '" placeholder="https://..."></label></div>'

       . '<label class="f wide"><span>Notes / minutes</span><textarea name="notes" rows="2">' . $v('notes') . '</textarea></label>'
       . '<label class="f wide"><span>Recording or minutes file (optional, audio / video / PDF)</span><input type="file" name="meeting_files[]" multiple accept="' . h(accept_attr(true)) . '"></label>'
       . '<label class="check f wide"><input type="checkbox" name="student_can_see" value="1"' . (($m['student_can_see'] ?? 1) ? ' checked' : '') . '> Show this meeting, its notes and files in the student portal</label>'
       . '</div><p class="muted small">Saving a meeting of type 1st meeting, tax consultant or 2nd processing meeting also updates the matching sheet columns of this student (done Yes/No, how it was held, date).</p>'
       . '<div class="row-actions"><button class="btn sm primary" type="submit">' . ($m ? 'Save meeting' : 'Add meeting') . '</button></div></form>';
    return $o;
};

$statusCls = fn(string $s) => match ($s) { 'Done', 'Attended', 'Booked' => 'ok', 'Missed', 'Not done', 'Cancelled' => 'bad', 'Scheduled', 'Pending' => 'acc', default => '' };
?>
<section class="panel meet-panel" id="meetings">
  <div class="panel-h">
    <div><h2>Meetings</h2><p><?= count($meetings) ?> in the meetings log<?= $sheet ? ' · ' . count($sheet) . ' recorded in the sheet columns' : '' ?></p></div>
    <div class="row-actions"><?= link_button($recFolder, 'Open recording folder', 'btn sm primary') ?></div>
  </div>

  <?php if ($canAdd): ?>
  <details class="addbox"><summary class="btn sm">+ Add or schedule a meeting</summary><?= $meetForm(null) ?></details>
  <?php endif; ?>

  <?php if (!$meetings && !$sheet): ?>
    <div class="empty">No meetings recorded yet.</div>
  <?php endif; ?>

  <div class="mlist">
  <?php foreach ($meetings as $m):
      $mf = $files['meetings'][(int)$m['id']] ?? [];
      $upcoming = $m['status'] === 'Scheduled' && $m['meeting_date'] && $m['meeting_date'] >= $t;
      $mType = (string)($m['meeting_type'] ?? '');
  ?>
    <article class="mcard<?= $upcoming ? ' is-next' : '' ?>">
      <div class="mdate">
        <?php if ($m['meeting_date']): ?><b><?= h(date('d', strtotime($m['meeting_date']))) ?></b><span><?= h(date('M Y', strtotime($m['meeting_date']))) ?></span><?php else: ?><span>No date</span><?php endif; ?>
        <?php if ($m['meeting_time']): ?><small><?= h($m['meeting_time']) ?></small><?php endif; ?>
      </div>
      <div class="mbody">
        <div class="mtitle">
          <b><?= h($m['title']) ?></b>
          <span class="pill <?= $mType === '' ? 'warn' : '' ?>"><?= h($mType === '' ? 'Format not set' : meeting_type_label($mType)) ?></span>
          <span class="pill <?= $statusCls($m['status']) ?>"><?= h($m['status']) ?></span>
          <?php if ($upcoming && $m['meeting_date'] === $t): ?><span class="pill warn">Today</span><?php endif; ?>
          <?php if (!$studentMode && !$m['student_can_see']): ?><span class="pill">Hidden from student</span><?php endif; ?>
        </div>
        <div class="m">
          <?= h(MEETING_KINDS[$m['kind']] ?? 'Meeting') ?>
          <?php if ($mType === 'Face-to-Face' && !empty($m['location'])): ?> · <?= h($m['location']) ?><?php endif; ?>
          <?php if ($mType === 'Face-to-Face' && !empty($m['staff_member']) && !$studentMode): ?> · with <?= h($m['staff_member']) ?><?php endif; ?>
          <?php if ($mType === 'Zoom Meeting' && !empty($m['recording_upload_status']) && !$studentMode): ?> · Recording: <?= h(['Pending' => 'not uploaded yet', 'Uploaded' => 'uploaded', 'Exception Authorized' => 'not recorded (approved)'][$m['recording_upload_status']] ?? $m['recording_upload_status']) ?><?php endif; ?>
          <?php if (!$studentMode && !empty($m['created_by_name'])): ?> · logged by <?= h($m['created_by_name']) ?><?php endif; ?>
        </div>
        <?php if (safe_link($m['join_link']) || safe_link($m['recording_link'])): ?>
        <div class="row-actions mt">
          <?php if (safe_link($m['join_link'])) echo link_button($m['join_link'], $m['status'] === 'Scheduled' ? 'Join meeting' : 'Meeting link', $m['status'] === 'Scheduled' ? 'btn sm primary' : 'btn sm'); ?>
          <?php if (safe_link($m['recording_link'])) echo link_button($m['recording_link'], 'Open recording', 'btn sm'); ?>
        </div>
        <?php endif; ?>
        <?php if ($mf): ?><div class="files"><?php foreach ($mf as $f) echo file_line($f, $u, 'meetings'); ?></div><?php endif; ?>
        <?php if ($m['notes']): ?><p class="small mnotes"><?= nl2br(h($m['notes'])) ?></p><?php endif; ?>
        <?php if (!$studentMode && can_edit_meeting($u, $m['kind'], $r)): ?>
          <details class="addbox"><summary class="small">Edit meeting</summary><?= $meetForm($m) ?>
            <?php if (!$mf): ?>
            <form method="post" action="<?= h(url('files.php')) ?>" class="row-actions" data-confirm="Delete this meeting?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="meeting_delete"><input type="hidden" name="back" value="meetings">
              <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn xs bad-b" type="submit">Delete meeting</button></form>
            <?php else: ?><p class="muted small">This meeting has a saved recording, so it cannot be deleted.</p><?php endif; ?>
          </details>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>

  <?php if ($sheet): ?><div class="dphase">From the sheet columns</div><?php endif; ?>
  <?php foreach ($sheet as $s): ?>
    <article class="mcard sheet">
      <div class="mdate"><?php if ($s['date']): ?><b><?= h(date('d', strtotime($s['date']))) ?></b><span><?= h(date('M Y', strtotime($s['date']))) ?></span><?php else: ?><span>—</span><?php endif; ?></div>
      <div class="mbody">
        <div class="mtitle"><b><?= h($s['title']) ?></b>
          <?php if (!empty($s['mode'])): ?><span class="pill"><?= h(meeting_type_label($s['mode'])) ?></span><?php elseif (in_array($s['kind'], ['initial', 'tax', 'second'], true) && $s['status'] === 'Done' && !$studentMode): ?><span class="pill warn">Format not set</span><?php endif; ?>
          <span class="pill <?= $statusCls($s['status']) ?>"><?= h($s['status']) ?></span></div>
        <?php if ($s['note'] && !$studentMode): ?><div class="m"><?= h($s['note']) ?></div><?php endif; ?>
        <?php if ($s['link'] && (!$studentMode || !is_shared_drive_folder($s['link'], (int)$r['id']))): ?><div class="row-actions mt"><?= link_button($s['link'], link_kind($s['link']) === 'Zoom' ? 'Open Zoom meeting' : 'Open recording') ?></div><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>

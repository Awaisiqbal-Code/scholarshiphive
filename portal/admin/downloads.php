<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require_once APP . '/backup.php';

$u = require_role('admin');
$mine = user_countries($u);
$fullAdmin = is_full_admin($u);
$bkOk = backup_supported() && $fullAdmin;

$action = get('action') ?: post('action');

function bk_json(array $d): void { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode($d); exit; }

// ---- Student backup (ZIP) actions: full admins only, nothing here changes any record or document ----
if (in_array($action, ['start_backup', 'backup_overview', 'save_schedule', 'run_schedule', 'download_backup'], true)) {
    if (!$fullAdmin) { http_response_code(403); exit('Only a full admin can take backups.'); }
}
if ($action === 'start_backup' && is_post()) {
    csrf_check();
    $type = (string)post('type');
    if (!isset(BACKUP_TYPES[$type])) bk_json(['ok' => false, 'error' => 'Unknown backup type.']);
    $job = backup_start($type, 'manual', null, (int)$u['id'], post('rec') === '1');
    log_action('Started ' . $type . ' student backup');
    bk_json(['ok' => true, 'job' => $job['id'], 'reused' => !empty($job['reused'])]);
}
if ($action === 'backup_overview') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stepped = false;
    $jobs = [];
    foreach (glob(backup_dir('jobs') . '/*.json') ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        if (!$stepped && in_array($j['state'] ?? '', ['queued', 'running'], true) && (getenv('VERCEL') || (time() - (int)($j['heartbeat'] ?? $j['created'] ?? 0) >= 3))) {
            $stepped = true;
            backup_run_job((string)$j['id'], true);
            $j = backup_job_read((string)$j['id']) ?? $j;
        }
        $age = time() - (int)($j['created'] ?? 0);
        if (backup_job_alive($j) || ($age < 3600 && in_array($j['state'], ['done', 'failed'], true)) || (in_array($j['state'], ['queued', 'running'], true) && $age < 3600)) {
            if (in_array($j['state'], ['queued', 'running'], true) && !backup_job_alive($j)) {
                $j['state'] = 'failed'; $j['error'] = 'The background worker stopped or could not start. Check that PHP command line works (php -v) and try again.';
            }
            $jobs[] = ['id' => $j['id'], 'type' => $j['type'], 'trigger' => $j['trigger'], 'state' => $j['state'], 'phase' => $j['phase'], 'pct' => $j['pct'],
                       'students_total' => $j['students_total'], 'students_done' => $j['students_done'], 'files_total' => $j['files_total'], 'files_done' => $j['files_done'],
                       'files_failed' => $j['files_failed'], 'error' => $j['error'] ?? null, 'file' => $j['file'] ?? null, 'history_id' => $j['history_id'] ?? null, 'created' => $j['created']];
        }
    }
    usort($jobs, fn($x, $y) => $y['created'] <=> $x['created']);
    bk_json(['ok' => true, 'jobs' => array_slice($jobs, 0, 6)]);
}
if ($action === 'save_schedule' && is_post()) {
    csrf_check();
    try {
        backup_save_schedule((int)post('schedule_id'), trim((string)post('schedule_time')), (string)post('export_type'), (bool)post('enabled'), (bool)post('rec'));
        log_action('Changed backup schedule ' . (int)post('schedule_id'));
        flash('Schedule ' . (int)post('schedule_id') . ' saved.');
    } catch (InvalidArgumentException $e) { flash($e->getMessage(), 'bad'); }
    redirect('admin/downloads.php');
}
if ($action === 'run_schedule' && is_post()) {
    csrf_check();
    $sid = (int)post('schedule_id');
    foreach (backup_schedules() as $s) if ((int)$s['id'] === $sid) {
        $job = backup_start((string)$s['export_type'], 'manual', $sid, (int)$u['id'], (bool)$s['include_recordings']);
        flash(!empty($job['reused']) ? 'A ' . $s['export_type'] . ' backup is already running; showing its progress.' : 'Backup started. You can leave this page; progress is shown below.');
    }
    redirect('admin/downloads.php');
}
if ($action === 'download_backup') {
    $h = q("SELECT * FROM export_history WHERE id = ? AND file_name LIKE '%.zip'", [(int)get('id')])->fetch();
    $path = $h ? backup_zip_path($h) : null;
    if (!$path) { flash('That backup file is no longer on the server.', 'bad'); redirect('admin/downloads.php'); }
    log_action('Downloaded backup ' . $h['file_name']);
    @set_time_limit(0);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Security-Policy');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    $fh = fopen($path, 'rb');
    while ($fh && !feof($fh)) { echo fread($fh, 1 << 20); flush(); }
    if ($fh) fclose($fh);
    exit;
}

if ($bkOk) { try { backup_tick(); } catch (Throwable $e) { error_log('backup tick: ' . $e->getMessage()); } }   // also checks the schedules whenever an admin opens this page

$schedules = $bkOk ? backup_schedules() : [];
$history = $bkOk ? backup_history(15) : [];
$counts = [];
if ($bkOk) foreach (array_keys(BACKUP_TYPES) as $t) $counts[$t] = count(backup_student_ids($t));
$pktNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi'));

page_start('Student Data Backup', $u, PORTALS[$u['role']]['label']);
?>

<div class="page-h">
  <div>
    <div class="eyebrow">Backup · Pakistan time <?= h($pktNow->format('h:i A, d M Y')) ?></div>
    <h1>Student Data Backup</h1>
    <p class="muted">One ZIP with a folder per student: their information (JSON), a details PDF and the <b>real documents and photos</b> (Google Drive files are copied in).
      No CSV or Excel. Passwords are never included.</p>
  </div>
</div>

<?php if (!$fullAdmin): ?>
  <div class="flash warn" role="alert">Only a full admin can take backups.</div>
<?php endif; ?>

<section class="panel" id="bk_panel" data-csrf="<?= h(csrf_token()) ?>" data-url="<?= h(url('admin/downloads.php')) ?>" data-dl="<?= h(url('admin/downloads.php?action=download_backup&id=')) ?>">
  <div class="panel-h"><div><h2>Download a backup now</h2>
    <p>The backup is built in the background (you can leave this page). When it is ready it appears under “Backups”. Students are chosen by Pakistan time: records created or changed today / this week (Mon–Sun).</p></div></div>
  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:14px;">
    <?php foreach ([['daily', "Today's Student Data", 'Students created or updated today (record, applications, documents, meetings).'],
                    ['weekly', 'Weekly Student Data', 'Students created or updated this week, Monday to Sunday.'],
                    ['complete', 'Complete Student Data', 'Every student, with every document. Large: allow a while.']] as [$t, $title, $desc]): ?>
      <div style="border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:16px; background:var(--surface,#fff);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;"><b style="color:var(--head,#12304b);"><?= h($title) ?></b>
          <span class="pill"><?= (int)($counts[$t] ?? 0) ?> students</span></div>
        <p class="small muted" style="margin:8px 0 12px;"><?= h($desc) ?></p>
        <button class="btn primary sm" data-start="<?= h($t) ?>" <?= $bkOk ? '' : 'disabled' ?>>⬇ Create <?= h(strtolower($title)) ?> ZIP</button>
      </div>
    <?php endforeach; ?>
  </div>
  <label class="check" style="margin-top:12px;"><input type="checkbox" id="bk_rec"> <span>Also include meeting recordings <span class="muted small">(videos: the ZIP becomes much larger; if off, they are listed in the report)</span></span></label>
  <div id="bk_jobs" style="margin-top:14px; display:flex; flex-direction:column; gap:10px;"></div>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>Automatic backups (two schedules)</h2>
    <p>Both schedules run on their own, even on the same day. Pakistan time. The newest <?= (int)BACKUP_KEEP_SCHEDULED ?> scheduled backups of each type are kept; older scheduled ZIPs are removed to save disk.</p></div></div>
  <?php if ($bkOk): ?>
  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:16px;">
  <?php foreach ($schedules as $s): $sid = (int)$s['id'];
        $st = (string)($s['last_status'] ?? ''); ?>
    <div style="border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:16px; background:var(--sunk,#f6f8fb);">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <b style="color:var(--head,#12304b);"><?= h($s['name']) ?></b>
        <span class="pill <?= $s['enabled'] ? 'ok' : 'bad' ?>"><?= $s['enabled'] ? 'Enabled' : 'Disabled' ?></span>
      </div>
      <form method="post" class="stack" style="margin-top:10px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <label class="f"><span>Time (PKT)</span><input type="time" name="schedule_time" value="<?= h($s['schedule_time']) ?>" required></label>
          <label class="f"><span>Backup type</span><select name="export_type">
            <?php foreach (BACKUP_TYPES as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $s['export_type'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <label class="check"><input type="checkbox" name="enabled" value="1" <?= $s['enabled'] ? 'checked' : '' ?>> <span>Enabled</span></label>
        <label class="check"><input type="checkbox" name="rec" value="1" <?= !empty($s['include_recordings']) ? 'checked' : '' ?>> <span>Include meeting recordings</span></label>
        <div><button class="btn sm">Save</button></div>
      </form>
      <div class="small" style="margin-top:10px; line-height:1.7;">
        <div><b>Last status:</b> <span class="pill <?= $st === 'success' ? 'ok' : ($st === 'running' ? '' : ($st ? 'bad' : '')) ?>"><?= h($st ?: 'never run') ?></span> <span class="muted"><?= h((string)($s['last_message'] ?? '')) ?></span></div>
        <div><b>Last successful run:</b> <?= $s['last_success_at'] ? h($s['last_success_at']) . ' PKT' : '<span class="muted">never</span>' ?></div>
        <div><b>Next run:</b> <?= h(backup_next_run($s)) ?></div>
        <?php if (!empty($s['last_file'])): $lh = q('SELECT id FROM export_history WHERE file_name = ?', [$s['last_file']])->fetchColumn(); ?>
          <div><b>Last file:</b> <?php if ($lh): ?><a href="<?= h(url('admin/downloads.php?action=download_backup&id=' . (int)$lh)) ?>">⬇ <?= h($s['last_file']) ?></a><?php else: ?><span class="muted"><?= h($s['last_file']) ?> (removed)</span><?php endif; ?></div>
        <?php endif; ?>
      </div>
      <form method="post" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="run_schedule"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
        <button class="btn primary sm">▶ Run now</button></form>
    </div>
  <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin-top:12px;">Schedules are started by <code>php portal/backup_worker.php tick</code>, run every minute by Windows Task Scheduler or cron (one-time setup: <code>scripts/install_backup_scheduler.ps1</code>).
     The schedule settings live in the database, so they survive restarts; a run missed while the computer was off starts when it is back (up to 6 hours late). Opening this page also checks the schedules.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>Backups</h2><p>Stored in a private folder outside the website; downloads need an admin sign-in.</p></div></div>
  <?php if (!$history): ?>
    <p class="muted" style="padding:14px;">No backups yet.</p>
  <?php else: ?>
  <div class="tbl"><table>
    <thead><tr><th>Created (PKT)</th><th>Type</th><th>Started by</th><th class="num">Students</th><th class="num">Files</th><th class="num">Not copied</th><th class="num">Size</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): $has = backup_zip_path($h) !== null; ?>
      <tr>
        <td class="mono"><?= h($h['created_at']) ?></td>
        <td><span class="pill"><?= h(BACKUP_TYPES[$h['export_type']] ?? $h['export_type']) ?></span></td>
        <td class="small"><?= $h['schedule_id'] ? 'Schedule ' . (int)$h['schedule_id'] . ' · ' . h($h['trigger_type']) : 'Manual' ?></td>
        <td class="num"><?= (int)$h['row_count'] ?></td>
        <td class="num"><?= (int)$h['files_ok'] ?></td>
        <td class="num"><?= (int)$h['files_failed'] ? '<b style="color:var(--bad,#b42318)">' . (int)$h['files_failed'] . '</b>' : '0' ?></td>
        <td class="num"><?= h(round((int)$h['file_size'] / 1048576, 1)) ?> MB</td>
        <td style="text-align:right;"><?= $has ? '<a class="btn sm primary" href="' . h(url('admin/downloads.php?action=download_backup&id=' . (int)$h['id'])) . '">⬇ Download ZIP</a>' : '<span class="muted small">removed</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="small muted" style="margin-top:8px;">“Not copied” = Google Drive files that are not shared as “Anyone with the link” (or no longer exist). They are listed with student, file name and reason in <code>_Backup_Report/Files_Not_Retrieved.txt</code> inside each ZIP.</p>
  <?php endif; ?>
</section>

<script>
(function () {
  var panel = document.getElementById('bk_panel'); if (!panel) return;
  var box = document.getElementById('bk_jobs'), csrf = panel.getAttribute('data-csrf'), url = panel.getAttribute('data-url'), dl = panel.getAttribute('data-dl'), timer = null, seenActive = false;
  var names = { daily: "Today's", weekly: 'Weekly', complete: 'Complete' };
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render(jobs) {
    var active = false, html = '';
    jobs.forEach(function (j) {
      var live = j.state === 'queued' || j.state === 'running'; if (live) active = true;
      var tone = j.state === 'failed' ? 'var(--bad,#b42318)' : (j.state === 'done' ? 'var(--ok,#17703a)' : 'var(--brand,#0f5d9e)');
      html += '<div style="border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:12px; background:#fff;">'
        + '<div style="display:flex; justify-content:space-between; gap:8px;"><b>' + esc(names[j.type] || j.type) + ' backup' + (j.trigger === 'scheduled' ? ' (scheduled)' : '') + '</b>'
        + '<span style="color:' + tone + '; font-weight:700;">' + esc(j.state === 'done' ? 'Ready' : j.state === 'failed' ? 'Failed' : j.pct + '%') + '</span></div>'
        + '<div class="prog" style="margin:8px 0;"><i style="width:' + (j.state === 'failed' ? 100 : j.pct) + '%; background:' + tone + ';"></i></div>'
        + '<div class="small muted">' + esc(j.state === 'failed' ? (j.error || j.phase) : j.phase)
        + (j.students_total ? ' · ' + j.students_done + '/' + j.students_total + ' students' : '')
        + (j.files_total ? ' · ' + j.files_done + '/' + j.files_total + ' Drive files copied' : '') + '</div>'
        + (j.state === 'done' ? '<div class="small" style="margin-top:8px; display:flex; justify-content:space-between; align-items:center; gap:8px;"><span>Finished: <b>' + esc(j.file) + '</b>' + (j.files_failed ? ' · ' + j.files_failed + ' file(s) could not be copied (see report in ZIP)' : '') + '</span>' + (j.history_id ? '<a class="btn sm primary" href="' + esc(dl + j.history_id) + '">⬇ Download ZIP</a>' : '') + '</div>' : '')
        + '</div>';
    });
    box.innerHTML = html;
    if (active) seenActive = true;
    return active;
  }
  function poll() {
    fetch(url + '?action=backup_overview', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var active = render(d.jobs || []);
      if (active) { timer = setTimeout(poll, 2000); }
      else if (seenActive) { setTimeout(function () { location.reload(); }, 1200); }
    }).catch(function () { timer = setTimeout(poll, 4000); });
  }
  Array.prototype.forEach.call(panel.querySelectorAll('[data-start]'), function (b) {
    b.addEventListener('click', function () {
      var fd = new FormData(); fd.append('_csrf', csrf); fd.append('action', 'start_backup'); fd.append('type', b.getAttribute('data-start'));
      if (document.getElementById('bk_rec').checked) fd.append('rec', '1');
      b.disabled = true;
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        b.disabled = false;
        if (!d.ok) { box.innerHTML = '<div class="flash bad">' + esc(d.error) + '</div>'; return; }
        seenActive = true; clearTimeout(timer); poll();
      }).catch(function () { b.disabled = false; box.innerHTML = '<div class="flash bad">Could not start. Reload the page and try again.</div>'; });
    });
  });
  poll();
})();
</script>

<?php page_end(); ?>

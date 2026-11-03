<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require_once APP . '/export.php';
require_once APP . '/xlsx.php';
require_once APP . '/fast_export.php';

$u = require_role('admin');
$mine = user_countries($u);

// Trigger any due schedules if accessed at the right time
export_check_and_run_schedules();

$action = get('action') ?: post('action');

// ---- Fast direct CSV download ----
if ($action === 'fast_csv') {
    $type = (string)(get('type') ?: 'complete');
    $country = (string)(get('country') ?: '');
    if (!in_array($type, ['daily', 'weekly', 'complete'], true)) $type = 'complete';
    if ($country !== '' && !in_array($country, $mine, true)) $country = '';
    export_stream_to_browser($type, $country);
    exit;
}

// ---- Save schedule configuration ----
if ($action === 'save_schedule' && is_post()) {
    csrf_check();
    $id = (int)post('schedule_id');
    $time = trim((string)post('schedule_time'));
    $type = (string)post('export_type');
    $enabled = (int)(post('enabled') ? 1 : 0);
    export_update_schedule($id, $time, $type, $enabled);
    flash("Schedule $id configuration updated successfully.");
    redirect('admin/downloads.php');
}

// ---- Manual Run Now for a schedule ----
if ($action === 'run_schedule' && is_post()) {
    csrf_check();
    $id = (int)post('schedule_id');
    $schedules = export_get_schedules();
    $target = null;
    foreach ($schedules as $s) {
        if ((int)$s['id'] === $id) { $target = $s; break; }
    }
    if ($target) {
        $res = export_generate_file((string)$target['export_type'], 'manual', $id);
        flash("Manual run complete: Generated '{$res['file_name']}' ({$res['row_count']} student records exported).");
    } else {
        flash("Invalid schedule ID.", "bad");
    }
    redirect('admin/downloads.php');
}

// ---- Download generated file from history ----
if ($action === 'download_history_file') {
    $hid = (int)get('id');
    $row = q("SELECT * FROM export_history WHERE id = ?", [$hid])->fetch();
    if (!$row) {
        flash("Export file record not found.", "bad");
        redirect('admin/downloads.php');
    }
    $file = (string)$row['file_path'];
    if (!is_file($file)) {
        $file = export_storage_dir() . '/' . $row['file_name'];
    }
    if (!is_file($file)) {
        flash("The requested export file is no longer on the server.", "bad");
        redirect('admin/downloads.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($file);
    exit;
}

// ---- Legacy Drive Zip helper functions ----
function job_dir(): string { $d = storage_dir() . '/jobs'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function job_load(string $id, array $u): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || !is_file(job_dir() . "/$id.json")) return null;
    $j = json_decode((string)file_get_contents(job_dir() . "/$id.json"), true);
    return is_array($j) && (int)($j['user'] ?? 0) === (int)$u['id'] ? $j : null;
}
function job_students(array $u, array $job): array {
    [$w, $p] = scope_sql($u);
    $in = implode(',', array_fill(0, count($job['countries']), '?'));
    return q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id
              WHERE s.country IN ($in) AND $w ORDER BY s.country, s.sr_no IS NULL, s.sr_no, s.id", array_merge($job['countries'], $p))->fetchAll();
}
function job_drive_files(array $students, bool $rec): array {
    $ids = array_map(fn($s) => (int)$s['id'], $students);
    if (!$ids) return [];
    $rows = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (q("SELECT * FROM student_files WHERE stored_name LIKE 'gdrive:%' AND student_id IN (" . implode(',', $chunk) . ') ORDER BY student_id, id') as $f) {
            $isRec = !empty($f['meeting_id']) || str_starts_with((string)$f['doc_key'], 'meeting') || is_media_file($f);
            if ($isRec && !$rec) continue;
            $rows[] = $f;
        }
    }
    return $rows;
}
function job_counts(array $files): array {
    $copied = 0; $failed = 0; $left = [];
    foreach ($files as $f) {
        if (gdrive_cached($f)) $copied++;
        elseif (gdrive_recent_failure($f) !== null) $failed++;
        else $left[] = $f;
    }
    return [$copied, $failed, $left];
}

// Legacy ZIP actions
if ($action === 'prepare' && is_post()) {
    csrf_check();
    header('Content-Type: application/json');
    $countries = array_values(array_intersect((array)($_POST['countries'] ?? []), $mine));
    if (!$countries) { echo json_encode(['ok' => false, 'error' => 'Tick at least one country.']); exit; }
    $id = bin2hex(random_bytes(12));
    foreach (glob(job_dir() . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 2 * 86400) @unlink($old);
    $job = ['user' => (int)$u['id'], 'countries' => $countries, 'rec' => post('rec') === '1', 'created' => time()];
    file_put_contents(job_dir() . "/$id.json", json_encode($job));
    $students = job_students($u, $job);
    [$copied, $failed, $left] = job_counts(job_drive_files($students, $job['rec']));
    echo json_encode(['ok' => true, 'job' => $id, 'students' => count($students), 'total' => $copied + $failed + count($left), 'copied' => $copied, 'failed' => $failed, 'left' => count($left)]);
    exit;
}

if ($action === 'step' && is_post()) {
    csrf_check();
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $job = job_load(post('job'), $u);
    if (!$job) { echo json_encode(['ok' => false, 'error' => 'This download expired. Start again.']); exit; }
    @set_time_limit(120);
    $files = job_drive_files(job_students($u, $job), $job['rec']);
    [, , $left] = job_counts($files);
    if ($left) gdrive_fetch_many(array_slice($left, 0, 160), microtime(true) + 20);
    [$copied, $failed, $left] = job_counts($files);
    echo json_encode(['ok' => true, 'total' => count($files), 'copied' => $copied, 'failed' => $failed, 'left' => count($left)]);
    exit;
}

if ($action === 'zip') {
    $job = job_load(get('job'), $u);
    if (!$job) { flash('This download expired. Start again.', 'warn'); redirect('admin/downloads.php'); }
    $students = job_students($u, $job);
    $fetched = [];
    foreach (job_drive_files($students, $job['rec']) as $f) if (!gdrive_cached($f)) $fetched[(int)$f['id']] = gdrive_recent_failure($f) ?? 'not copied from Google Drive yet';
    log_action('Downloaded all students of ' . implode(', ', array_map('country_name', $job['countries'])) . ' (' . count($students) . ' students' . ($job['rec'] ? ', with recordings' : '') . ')');
    $root = 'ScholarshipHive students ' . date('Y-m-d');
    $zip = zip_begin($root . '.zip');
    $failed = [];
    $used = [];
    foreach ($job['countries'] as $c) {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
            xlsx_write(array_map(fn($t) => ['headers' => $t['headers'], 'rows' => $t['rows']], export_workbook($u, $c, false)), $tmp);
            $zip->addFile("$root/" . country_name($c) . '/' . country_name($c) . ' - all students.xlsx', $tmp, time());
            @unlink($tmp);
        }
    }
    foreach ($students as $s) {
        $cn = country_name((string)$s['country']);
        $name = zip_clean_name((string)$s['name']) ?: 'Student ' . (int)$s['id'];
        $folder = "$root/$cn/$name";
        if (isset($used[mb_strtolower($folder)])) $folder .= ' (' . (int)$s['id'] . ')';
        $used[mb_strtolower($folder)] = true;
        zip_student($zip, $u, $s, $folder, $job['rec'], $fetched, $failed);
    }
    $zip->addString("$root/Files not downloaded.txt", $failed ? failed_list_text($failed) : "Every file listed in the portal is in this download.\r\n");
    $zip->finish();
    exit;
}

// Data for page rendering
$counts = export_get_counts();
$schedules = export_get_schedules();
$history = export_get_history(10);
$tz = new DateTimeZone('Asia/Karachi');
$pktNow = new DateTime('now', $tz);

page_start('Student Data Export & Scheduler', $u, PORTALS[$u['role']]['label']);
?>

<div class="page-h">
  <div>
    <div class="eyebrow">Data Export & Automated Scheduler</div>
    <h1>Student Data Export & Scheduler</h1>
    <p class="muted">Instant high-speed CSV downloads, incremental Daily & Weekly filters, and automated twice-daily export scheduler (Pakistan Time: Asia/Karachi · <?= $pktNow->format('h:i A, d M Y') ?>).</p>
  </div>
</div>

<!-- ================= 1. FAST DIRECT EXPORTS ================= -->
<section class="panel" style="margin-bottom:24px;">
  <div class="panel-h">
    <div>
      <h2 style="display:flex; align-items:center; gap:8px;">
        <span>⚡ Extremely Fast Data Download</span>
        <span class="badge" style="background:var(--ok-soft,#ddf2e4); color:var(--ok,#17703a); border:1px solid rgba(23,112,58,0.2); font-size:0.75rem;">Sub-Second Streaming</span>
      </h2>
      <p>Stream filtered student data instantly to your browser with 100% complete field details, UTF-8 Excel formatting, and zero wait time.</p>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-top:8px;">
    
    <!-- Today's Data -->
    <div style="background:var(--surface,#fff); border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <span style="font-weight:700; color:var(--head,#12304b); font-size:1.05rem;">📅 Today's Data</span>
          <span class="pill" style="background:var(--accent-soft,#e2f0f9); color:var(--brand,#0f5d9e); font-weight:700;"><?= (int)$counts['daily'] ?> <?= (int)$counts['daily'] === 1 ? 'record' : 'records' ?></span>
        </div>
        <p style="font-size:0.85rem; color:var(--muted,#56687a); line-height:1.45; margin-bottom:14px;">
          Only records created or updated today in Pakistan Time (Asia/Karachi). Ideal for daily audit and processing sync.
        </p>
      </div>
      <a href="<?= h(url('admin/downloads.php?action=fast_csv&type=daily')) ?>" class="btn primary sm" style="text-align:center; justify-content:center; text-decoration:none; font-weight:600; padding:8px 12px;">
        ⬇ Download Today's Data (CSV)
      </a>
    </div>

    <!-- Weekly Data -->
    <div style="background:var(--surface,#fff); border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <span style="font-weight:700; color:var(--head,#12304b); font-size:1.05rem;">📆 Weekly Data</span>
          <span class="pill" style="background:var(--accent-soft,#e2f0f9); color:var(--brand,#0f5d9e); font-weight:700;"><?= (int)$counts['weekly'] ?> <?= (int)$counts['weekly'] === 1 ? 'record' : 'records' ?></span>
        </div>
        <p style="font-size:0.85rem; color:var(--muted,#56687a); line-height:1.45; margin-bottom:14px;">
          All records created or modified since Monday of the current week. Perfect for weekly team reviews and visa updates.
        </p>
      </div>
      <a href="<?= h(url('admin/downloads.php?action=fast_csv&type=weekly')) ?>" class="btn primary sm" style="text-align:center; justify-content:center; text-decoration:none; font-weight:600; padding:8px 12px;">
        ⬇ Download Weekly Data (CSV)
      </a>
    </div>

    <!-- Complete Data -->
    <div style="background:var(--surface,#fff); border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <span style="font-weight:700; color:var(--head,#12304b); font-size:1.05rem;">📦 Complete Data</span>
          <span class="pill" style="background:var(--ok-soft,#ddf2e4); color:var(--ok,#17703a); font-weight:700;"><?= (int)$counts['complete'] ?> students</span>
        </div>
        <p style="font-size:0.85rem; color:var(--muted,#56687a); line-height:1.45; margin-bottom:14px;">
          Full student database across all destination countries with complete academic, contact, meeting, and university applications.
        </p>
      </div>
      <a href="<?= h(url('admin/downloads.php?action=fast_csv&type=complete')) ?>" class="btn primary sm" style="text-align:center; justify-content:center; text-decoration:none; font-weight:600; padding:8px 12px;">
        ⬇ Download Complete Data (CSV)
      </a>
    </div>

  </div>

  <!-- Country filter optional export -->
  <div style="margin-top:20px; padding-top:14px; border-top:1px solid var(--line,#d8e1ea); display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;">
    <span style="font-size:0.85rem; font-weight:600; color:var(--head,#12304b);">Download by Destination Country:</span>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
      <?php foreach ($mine as $cc): ?>
        <a href="<?= h(url('admin/downloads.php?action=fast_csv&type=complete&country=' . urlencode($cc))) ?>" class="btn sm" style="text-decoration:none; font-size:0.8rem; padding:4px 10px;">
          <span class="cc"><?= h(COUNTRIES[$cc]['code']) ?></span> <?= h(country_name($cc)) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= 2. AUTOMATIC DOWNLOAD SCHEDULER ================= -->
<section class="panel" style="margin-bottom:24px;">
  <div class="panel-h">
    <div>
      <h2>⏰ Automated Download Scheduler (Twice Daily)</h2>
      <p>Configure two automated export times per day (e.g. Morning 09:00 PKT & Evening 18:00 PKT). Both run independently and save securely into portal history.</p>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap:20px; margin-top:10px;">
    <?php foreach ($schedules as $s): $sid = (int)$s['id']; $nextRun = export_next_execution($s); ?>
      <div style="background:var(--sunk,#f8fafc); border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:20px;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
          <div>
            <h3 style="margin:0; font-size:1.05rem; color:var(--head,#12304b);"><?= h($s['name']) ?></h3>
            <span style="font-size:0.75rem; color:var(--muted,#56687a);">Schedule ID: #<?= $sid ?></span>
          </div>
          <span class="pill <?= !empty($s['enabled']) ? 'ok' : 'bad' ?>" style="font-size:0.75rem; font-weight:700;">
            <?= !empty($s['enabled']) ? '● ACTIVE' : '○ DISABLED' ?>
          </span>
        </div>

        <!-- Schedule Settings Form -->
        <form method="post" action="<?= h(url('admin/downloads.php')) ?>" style="display:flex; flex-direction:column; gap:12px; margin-bottom:16px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_schedule">
          <input type="hidden" name="schedule_id" value="<?= $sid ?>">

          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
            <div>
              <label style="display:block; font-size:0.78rem; font-weight:600; color:var(--muted,#56687a); margin-bottom:4px;">Execution Time (PKT)</label>
              <input type="time" name="schedule_time" value="<?= h($s['schedule_time']) ?>" required style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--line,#d8e1ea); font-weight:600;">
            </div>
            <div>
              <label style="display:block; font-size:0.78rem; font-weight:600; color:var(--muted,#56687a); margin-bottom:4px;">Export Type</label>
              <select name="export_type" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--line,#d8e1ea); font-weight:600;">
                <option value="daily" <?= $s['export_type'] === 'daily' ? 'selected' : '' ?>>Today's Data (Daily)</option>
                <option value="weekly" <?= $s['export_type'] === 'weekly' ? 'selected' : '' ?>>Weekly Data</option>
                <option value="complete" <?= $s['export_type'] === 'complete' ? 'selected' : '' ?>>Complete Data</option>
              </select>
            </div>
          </div>

          <label class="check" style="font-size:0.85rem; font-weight:600; color:var(--head,#12304b); cursor:pointer;">
            <input type="checkbox" name="enabled" value="1" <?= !empty($s['enabled']) ? 'checked' : '' ?>>
            <span>Enable this automated schedule</span>
          </label>

          <button type="submit" class="btn sm" style="align-self:flex-start; font-weight:600;">
            💾 Save Configuration
          </button>
        </form>

        <div style="background:var(--surface,#fff); border:1px solid var(--line,#d8e1ea); border-radius:8px; padding:12px; font-size:0.82rem; margin-bottom:14px; display:flex; flex-direction:column; gap:6px;">
          <div><b>Next Scheduled Run:</b> <span style="color:var(--brand,#0f5d9e); font-weight:600;"><?= h($nextRun) ?></span></div>
          <div><b>Last Run:</b> <?= $s['last_run_at'] ? h($s['last_run_at']) . ' PKT' : '<span class="muted">Never</span>' ?></div>
          <?php if (!empty($s['last_file'])): ?>
            <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><b>Last File:</b> <code style="font-size:0.78rem;"><?= h($s['last_file']) ?></code></div>
          <?php endif; ?>
        </div>

        <!-- Manual Run Now Button -->
        <form method="post" action="<?= h(url('admin/downloads.php')) ?>" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="run_schedule">
          <input type="hidden" name="schedule_id" value="<?= $sid ?>">
          <button type="submit" class="btn sm primary" style="width:100%; justify-content:center; font-weight:600;" title="Execute this export schedule right now">
            ⚡ Run Schedule <?= $sid ?> Now (Manual)
          </button>
        </form>

      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ================= 3. SCHEDULER EXECUTION HISTORY ================= -->
<section class="panel" style="margin-bottom:24px;">
  <div class="panel-h">
    <div>
      <h2>📜 Export History & Generated Files</h2>
      <p>Stored records of recent automatic and manual exports with instant secure download links.</p>
    </div>
  </div>

  <?php if (!$history): ?>
    <div style="padding:30px; text-align:center; color:var(--muted,#56687a);">
      No exports generated yet. Click <b>"Run Schedule Now"</b> above or use direct downloads.
    </div>
  <?php else: ?>
    <div class="tbl">
      <table>
        <thead>
          <tr>
            <th>Date & Time (PKT)</th>
            <th>Export Type</th>
            <th>Trigger</th>
            <th>Records</th>
            <th>File Size</th>
            <th>Status</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="mono" style="font-size:0.82rem; font-weight:600;"><?= h($h['created_at']) ?></td>
              <td>
                <span class="badge" style="text-transform:uppercase; font-size:0.75rem; font-weight:700; background:var(--accent-soft,#e2f0f9); color:var(--brand,#0f5d9e);">
                  <?= h($h['export_type']) ?>
                </span>
              </td>
              <td style="font-size:0.85rem; font-weight:500;">
                <?= $h['schedule_id'] ? ('Schedule ' . (int)$h['schedule_id'] . ' (' . h($h['trigger_type']) . ')') : 'Manual Export' ?>
              </td>
              <td class="num" style="font-weight:700;"><?= (int)$h['row_count'] ?></td>
              <td class="num" style="font-size:0.82rem; color:var(--muted,#56687a);"><?= h(fmt_size((int)$h['file_size'])) ?></td>
              <td><span class="pill ok" style="font-size:0.75rem;">✓ Completed</span></td>
              <td style="text-align:right;">
                <a href="<?= h(url('admin/downloads.php?action=download_history_file&id=' . (int)$h['id'])) ?>" class="btn sm primary" style="font-size:0.78rem; padding:3px 10px; text-decoration:none;">
                  ⬇ Download CSV
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ================= 4. LEGACY DOCUMENT ARCHIVE & DRIVE SYNC ================= -->
<details class="reveal" style="margin-top:20px; border:1px solid var(--line,#d8e1ea); border-radius:10px; padding:16px; background:var(--surface,#fff);">
  <summary style="font-weight:700; color:var(--head,#12304b); cursor:pointer;">
    📦 Legacy: Full Document Archive & Google Drive ZIP Export
  </summary>
  <div style="margin-top:14px; font-size:0.88rem; color:var(--muted,#56687a);">
    <p>This legacy feature copies linked student Google Drive files into the portal and compiles individual PDF profile dossiers for each student into a multi-gigabyte ZIP archive.</p>
    
    <div id="dl_panel" data-csrf="<?= h(csrf_token()) ?>" data-url="<?= h(url('admin/downloads.php')) ?>" style="margin-top:12px;">
      <form id="dl_form" class="stack">
        <fieldset class="plain"><legend class="small muted">Filter Countries for ZIP</legend>
          <div class="radios">
          <?php foreach ($mine as $c): ?>
            <label class="check"><input type="checkbox" name="countries[]" value="<?= h($c) ?>" checked> <span><?= h(country_name($c)) ?></span></label>
          <?php endforeach; ?>
          </div>
        </fieldset>
        <label class="check"><input type="checkbox" name="rec" value="1"> <span>Also include meeting recordings <span class="muted small">(large video files)</span></span></label>
        <div class="row-actions"><button type="submit" class="btn sm" id="dl_start">Prepare Full ZIP Archive</button></div>
      </form>
      <div id="dl_progress" hidden style="margin-top:12px;">
        <p><b id="dl_title">Copying documents from Google Drive into the portal…</b></p>
        <div class="prog"><i id="dl_bar" style="width:0%"></i></div>
        <p class="small muted" id="dl_text"></p>
      </div>
    </div>
  </div>
</details>

<script>
(function () {
  var panel = document.getElementById('dl_panel'), form = document.getElementById('dl_form');
  if (!panel || !form) return;
  var bar = document.getElementById('dl_bar'), text = document.getElementById('dl_text'), title = document.getElementById('dl_title');
  function post(data) {
    data.append('_csrf', panel.getAttribute('data-csrf'));
    return fetch(panel.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function show(j, students) {
    var done = j.copied + j.failed, pct = j.total ? Math.round(done / j.total * 100) : 100;
    bar.style.width = pct + '%';
    text.textContent = (students ? students + ' students · ' : '') + j.copied + ' of ' + j.total + ' Drive files copied' + (j.failed ? ' · ' + j.failed + ' failed' : '') + (j.left ? ' · ' + j.left + ' remaining' : '');
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form); fd.append('action', 'prepare');
    document.getElementById('dl_start').disabled = true;
    document.getElementById('dl_progress').hidden = false;
    post(fd).then(function (j) {
      if (!j.ok) { title.textContent = j.error; document.getElementById('dl_start').disabled = false; return; }
      var students = j.students;
      show(j, students);
      (function step() {
        if (j.left === 0) {
          title.textContent = 'Ready. Your download is starting…';
          bar.style.width = '100%';
          window.location = panel.getAttribute('data-url') + '?action=zip&job=' + j.job;
          setTimeout(function () { document.getElementById('dl_start').disabled = false; }, 4000);
          return;
        }
        var s = new FormData(); s.append('action', 'step'); s.append('job', j.job);
        post(s).then(function (k) {
          if (!k.ok) { title.textContent = k.error; return; }
          k.job = j.job; j = k; show(k, students); step();
        }).catch(function () { title.textContent = 'Retrying…'; setTimeout(step, 3000); });
      })();
    }).catch(function () { title.textContent = 'Could not start. Reload and try again.'; document.getElementById('dl_start').disabled = false; });
  });
})();
</script>

<?php page_end(); ?>

<?php
declare(strict_types=1);
// Download every student at once: one ZIP with a folder per country and a folder per student holding
// "Student details.pdf" and all of that student's document files (the files themselves, never links),
// plus each country's sheet as Excel. Google Drive files are first copied into the portal, in rounds
// that show progress; the ZIP is then written straight to the browser.
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require_once APP . '/export.php';
require_once APP . '/xlsx.php';
$u = require_role('admin');
$mine = user_countries($u);

function job_dir(): string { $d = storage_dir() . '/jobs'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function job_load(string $id, array $u): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || !is_file(job_dir() . "/$id.json")) return null;
    $j = json_decode((string)file_get_contents(job_dir() . "/$id.json"), true);
    return is_array($j) && (int)($j['user'] ?? 0) === (int)$u['id'] ? $j : null;
}
/** Students of the job, in sheet order per country. */
function job_students(array $u, array $job): array {
    [$w, $p] = scope_sql($u);
    $in = implode(',', array_fill(0, count($job['countries']), '?'));
    return q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id
              WHERE s.country IN ($in) AND $w ORDER BY FIELD(s.country, $in), s.sr_no IS NULL, s.sr_no, s.id", array_merge($job['countries'], $p, $job['countries']))->fetchAll();
}
/** Drive files of the job's students. */
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

$action = get('action') ?: post('action');

// ---- 1. start a download job ----
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

// ---- 2. copy the next Drive files into the portal (about 20 seconds per round) ----
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

// ---- 3. the ZIP ----
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
        // the country's sheet, as exported from the portal (no passwords)
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

// ---- page ----
$counts = [];
[$w, $p] = scope_sql($u);
foreach (q("SELECT s.country, COUNT(*) n FROM students s WHERE $w GROUP BY s.country", $p) as $x) $counts[$x['country']] = (int)$x['n'];
$cache = q("SELECT COUNT(*) n, COALESCE(SUM(size), 0) b FROM student_files WHERE meeting_id IS NULL AND stored_name NOT LIKE 'gdrive:%'")->fetch();
page_start('Download all students', $u, PORTALS[$u['role']]['label']);
?>
<div class="page-h"><div>
  <h1>Download all students</h1>
  <p class="muted">One ZIP with a folder for each student: <b>Student details.pdf</b> (the record as on the sheet) and every document file itself, sorted into a folder per document. Each country's sheet is included as Excel.</p>
</div></div>

<section class="panel" id="dl_panel" data-csrf="<?= h(csrf_token()) ?>" data-url="<?= h(url('admin/downloads.php')) ?>">
  <div class="panel-h"><div><h2>What to include</h2><p>Passwords are never included.</p></div></div>
  <form id="dl_form" class="stack">
    <fieldset class="plain"><legend class="small muted">Countries</legend>
      <div class="radios">
      <?php foreach ($mine as $c): ?>
        <label class="check"><input type="checkbox" name="countries[]" value="<?= h($c) ?>" checked> <span><?= h(country_name($c)) ?> <span class="muted small">(<?= (int)($counts[$c] ?? 0) ?>)</span></span></label>
      <?php endforeach; ?>
      </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="rec" value="1"> <span>Also include meeting recordings <span class="muted small">(videos — the download becomes several GB larger)</span></span></label>
    <div class="row-actions"><button type="submit" class="btn primary" id="dl_start">⬇ Prepare and download</button></div>
  </form>
  <div id="dl_progress" hidden>
    <p><b id="dl_title">Copying documents from Google Drive into the portal…</b></p>
    <div class="prog"><i id="dl_bar" style="width:0%"></i></div>
    <p class="small muted" id="dl_text"></p>
    <p class="small muted">You can keep this page open and do other work in another tab. Files copied once are kept in the portal, so the next download is quicker.</p>
  </div>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>About Google Drive files</h2></div></div>
  <p class="small">Documents that were linked from the students' Google Drive folders are copied into the portal before the ZIP is made, so the ZIP holds the real files. A Drive file that is not shared as <b>"Anyone with the link"</b> cannot be copied; those are named in <b>Files not downloaded.txt</b> inside the ZIP (with the student and the document) so they can be shared or uploaded in the portal.</p>
  <p class="small muted">Files uploaded in the portal: <?= (int)$cache['n'] ?> (<?= h(fmt_size((int)$cache['b'])) ?>).</p>
</section>

<script>
(function () {
  var panel = document.getElementById('dl_panel'), form = document.getElementById('dl_form');
  var bar = document.getElementById('dl_bar'), text = document.getElementById('dl_text'), title = document.getElementById('dl_title');
  function post(data) {
    data.append('_csrf', panel.getAttribute('data-csrf'));
    return fetch(panel.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function show(j, students) {
    var done = j.copied + j.failed, pct = j.total ? Math.round(done / j.total * 100) : 100;
    bar.style.width = pct + '%';
    text.textContent = (students ? students + ' students · ' : '') + j.copied + ' of ' + j.total + ' Google Drive files copied' + (j.failed ? ' · ' + j.failed + ' cannot be copied (not shared)' : '') + (j.left ? ' · ' + j.left + ' to go' : '');
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
        }).catch(function () { title.textContent = 'The connection was interrupted. Trying again…'; setTimeout(step, 3000); });
      })();
    }).catch(function () { title.textContent = 'Could not start. Reload the page and try again.'; document.getElementById('dl_start').disabled = false; });
  });
})();
</script>
<?php page_end(); ?>

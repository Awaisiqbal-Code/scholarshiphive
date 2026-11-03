<?php
declare(strict_types=1);
// Student portal view. Expects $u, $sid (student id) and $preview (true when an admin is looking).

// The student id comes only from the signed-in account, never from the URL.
// The full row is read so the steps can be worked out, but only student-safe values are printed:
// never refunds, dues, installments, discrepancies, remarks, notes or account passwords.
$r = q('SELECT s.*, o.name AS officer_name, b.name AS back_officer_name
        FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id
        WHERE s.id = ?', [$sid])->fetch();
if (!$r) {
    if ($preview) { http_response_code(404); exit('Student not found.'); }
    logout_now(); flash('Your file could not be found. Contact the office.', 'bad'); redirect('student/login.php');
}
$r = attach_app_counts([$r])[0];
$docStatus = [];
foreach (q('SELECT doc_key, status FROM student_documents WHERE student_id = ?', [$sid]) as $d) $docStatus[$d['doc_key']] = $d['status'];
$files = student_files((int)$sid);
$meetings = student_meetings((int)$sid, true);
$apps = q('SELECT university, program, deadline, result FROM applications WHERE student_id = ? ORDER BY id', [$sid])->fetchAll();

$S = row_stages($r);
$ns = next_stage($r);
$cur = current_index($r);
$done = done_count($r);
$total = count($S);
if (($r['process_status'] ?? '') === 'Stopped') $head = ['File on hold', 'Your processing is stopped at the moment. Your counselor will contact you about the next step.'];
elseif ($r['visa'] === 'Granted') $head = ['Visa granted', 'Congratulations. Your processing officer will share the next steps for travel.'];
elseif ($r['visa'] === 'Refused') $head = ['Visa refused', 'Your processing officer will contact you about the options.'];
elseif ($ns) { $head = [$ns, $S[$cur][2]]; }
else $head = ['All recorded steps done', 'Every step we track for your country is complete. Your processing officer will share what comes next.'];
$upcoming = array_values(array_filter(due_list([$r]), fn($d) => $d['date'] >= today()));

page_start($preview ? 'Student portal preview' : 'My file', $u, $preview ? PORTALS['admin']['label'] : PORTALS['student']['label']);
if ($preview): ?>
<div class="flash warn">Admin preview: this is exactly what <b><?= h($r['name']) ?></b> sees in the student portal. <a href="<?= h(url('admin/student.php?id=' . $sid)) ?>">Back to the record</a></div>
<?php endif; ?>
<section class="panel hero-step">
  <div class="eyebrow"><span class="cc"><?= h(COUNTRIES[$r['country']]['code']) ?></span> Study in <?= h(country_name($r['country'])) ?> · Where your file is now<?= $cur !== null ? ' · ' . h($S[$cur][1]) : '' ?></div>
  <h1><?= h($head[0]) ?></h1>
  <p class="muted"><?= h($head[1]) ?></p>
  <div class="prog"><i style="width:<?= $total ? round($done / $total * 100, 1) : 0 ?>%"></i></div>
  <div class="num small muted"><?= $done ?> of <?= $total ?> steps complete</div>
  <?php
    $detStudent = student_doc_detect($r);
    if (!empty($detStudent['missing']) && $detStudent['status'] !== 'complete'):
        $needText = implode(', ', array_map(fn($m) => $m['label'] . (!empty($m['is_optional']) ? ' (Optional)' : ''), $detStudent['missing']));
  ?>
    <div class="flash warn"><b>Documents we still need:</b> <?= h($needText) ?></div>
  <?php elseif ($r['pending_documents'] && !in_array($r['documents_status'], ['Done', 'Completed'], true)): ?>
    <div class="flash warn"><b>Documents we still need:</b> <?= h($r['pending_documents']) ?></div>
  <?php endif; ?>
  <?php if ($r['financial_verified'] !== 'Yes' && $r['financial_missing']): ?><div class="flash warn"><b>Financial documents still needed:</b> <?= h($r['financial_missing']) ?></div><?php endif; ?>
  <?php if ($upcoming): ?><div class="flash acc"><b>Upcoming:</b> <?= h($upcoming[0]['what']) ?> on <?= h(fmt_date($upcoming[0]['date'])) ?></div><?php endif; ?>
</section>

<div class="grid2">
  <section class="panel">
    <div class="panel-h"><h2>Your steps</h2></div>
    <ol class="tl">
    <?php $lastPhase = '';
    foreach ($S as $i => $s):
        if ($s[1] !== $lastPhase): $lastPhase = $s[1]; ?><li class="phase"><?= h($s[1]) ?></li><?php endif;
        $st = stage_done($r, $i) ? 'done' : ($i === $cur ? 'now' : ($cur !== null && $i < $cur ? 'open' : 'later')); ?>
      <li class="<?= $st ?>"><span class="dot"></span>
        <div><b><?= h($s[0]) ?></b><div class="m"><?= h($s[2]) ?></div></div>
        <span class="pill <?= ['done' => 'ok', 'now' => 'acc', 'open' => 'warn', 'later' => ''][$st] ?>"><?= ['done' => 'Done', 'now' => 'In progress', 'open' => 'Still open', 'later' => 'Upcoming'][$st] ?></span></li>
    <?php endforeach; ?>
    </ol>
  </section>

  <div class="stack">
    <?php if ($apps): ?>
    <section class="panel">
      <div class="panel-h"><h2>Your universities</h2><p>Updated by your processing officer</p></div>
      <div class="list">
      <?php foreach ($apps as $a): ?>
        <div class="li"><div><b><?= h($a['university']) ?></b><div class="m"><?= h($a['program']) ?><?= $a['deadline'] ? ' · Deadline ' . h($a['deadline']) : '' ?></div></div><?= $a['result'] ? pill($a['result']) : '<span class="pill">Not updated yet</span>' ?></div>
      <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
    <section class="panel">
      <div class="panel-h"><h2>Your team</h2></div>
      <div class="list">
        <div class="li"><span>Counselor</span><b><?= h($r['counselor'] ?: 'Not assigned') ?></b></div>
        <?php if ($r['officer_name']): ?><div class="li"><span>Front-end officer</span><b><?= h($r['officer_name']) ?></b></div><?php endif; ?>
        <div class="li"><span>Processing officer</span><b><?= h($r['back_officer_name'] ?: 'Not assigned') ?></b></div>
        <?php $taxName = ($r['country'] === 'italy' || !empty($r['tax_meeting'])) ? q("SELECT name FROM users WHERE role = 'tax' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn() : false; ?>
        <?php if ($taxName): ?>
          <div class="li"><span>Tax consultant</span><b><?= h((string)$taxName) ?><?= !empty($r['tax_meeting_mode']) ? ' · ' . h(meeting_type_label($r['tax_meeting_mode'])) : '' ?></b></div>
        <?php endif; ?>
        <?php if ($r['branch']): ?><div class="li"><span>Branch</span><b><?= h($r['branch']) ?></b></div><?php endif; ?>
        <?php if ($r['intake']): ?><div class="li"><span>Intake</span><b><?= h($r['intake']) ?></b></div><?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php
$studentMode = true;
include APP . '/pages/_documents.php';
include APP . '/pages/_meetings.php';
page_end();

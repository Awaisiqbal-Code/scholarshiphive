<?php
declare(strict_types=1);
// Enhanced Multi-Country Dashboard for Admin and Officers
// Includes 9 CRM metrics: Total Students, New Registrations, Documents Pending,
// Meetings Pending, Applications Processing, Offers Received, Visa Processing,
// Overdue Tasks, and Outstanding Payments.

$admin = is_admin($u);
$base = $admin ? 'admin' : 'officer';
$c = pick_country($u);
[$w, $p] = scope_sql($u);
$all = q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id WHERE $w ORDER BY s.country, s.id", $p)->fetchAll();
$all = attach_app_counts($all);
$counts = ['' => count($all)];
foreach ($all as $r) $counts[$r['country']] = ($counts[$r['country']] ?? 0) + 1;
$rows = $c === '' ? $all : array_values(array_filter($all, fn($r) => $r['country'] === $c));

$n = count($rows);
$cnt = fn(callable $f, ?array $set = null) => count(array_filter($set ?? $rows, $f));
$stopped = $cnt(fn($r) => ($r['process_status'] ?? '') === 'Stopped');
$active = $n - $stopped;

// 9 Comprehensive CRM Metrics
$newRegs = $cnt(fn($r) => !empty($r['received_date']) && strtotime($r['received_date']) >= strtotime('-60 days'));
$docsPending = $cnt(fn($r) => !stage_key_done($r, 'docs'));
$offers = $cnt(fn($r) => stage_key_done($r, 'offer'));
$granted = $cnt(fn($r) => ($r['visa'] ?? '') === 'Granted');
$refused = $cnt(fn($r) => ($r['visa'] ?? '') === 'Refused');
$visaProcessing = $cnt(fn($r) => !empty($r['appointment_date']) || !empty($r['visa_submitted_date']) || ($r['visa'] ?? '') === 'Pending');
$appsProcessing = $cnt(fn($r) => ($r['apps_total'] ?? 0) > 0 || !empty($r['application_status']));
$paymentsOutstanding = $cnt(fn($r) => (!empty($r['balance_due']) && (float)$r['balance_due'] > 0) || !empty($r['dues']));
$taxDone = $cnt(fn($r) => ($r['tax_meeting'] ?? '') === 'Yes');

// Tasks and overdue followups
$due = due_list($rows);
$dueNow = count(array_filter($due, fn($d) => $d['date'] <= today()));
$scopeIds = array_map(fn($r) => (int)$r['id'], $rows);   // only the students this person sees
$tNow = today();
$overdueTasksCount = $scopeIds ? (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'pending' AND (due_date <= ? OR due_date IS NULL) AND student_id IN (" . implode(',', $scopeIds) . ")", [$tNow])->fetchColumn() : 0;

$title = $c ? country_name($c) . ' Portal' : ($admin ? 'All Countries Management' : 'Hello, ' . $u['name']);
page_start($title, $u, PORTALS[$u['role']]['label']);
country_bar($u, $c, $base . '/index.php', $counts);
?>
<div class="page-h">
  <div>
    <div class="eyebrow"><?= $c ? h(COUNTRIES[$c]['sheet'] ?? 'Country Processing') : ($admin ? 'ScholarshipHive Automated Student CRM' : (($u['team'] ?? '') === 'backend' ? 'Back-end Team: Admission and Visa Processing' : 'Front-end Team: Onboarding, Meetings & Operations')) ?></div>
    <h1><?= h($title) ?></h1>
  </div>
  <div class="row-actions">
    <a class="btn sm" href="<?= h(url($base . '/students.php' . ($c ? '?c=' . $c : ''))) ?>">Open Student List</a>
    <a class="btn sm primary" href="<?= h(url($base . '/student.php?new=1' . ($c ? '&c=' . $c : ''))) ?>">+ Add Student</a>
  </div>
</div>

<!-- 9 Comprehensive Dashboard Metrics Grid -->
<div class="kpis" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:12px; margin-bottom:24px;">
  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php' . ($c ? '?c=' . $c : ''))) ?>" title="View all <?= $n ?> students">
      <div class="l">Total Students ↗</div>
      <div class="v"><?= $n ?></div>
    </a>
    <div class="s">
      <a href="<?= h(url($base . '/students.php?status=Active' . ($c ? '&c=' . $c : ''))) ?>"><?= $active ?> active</a> · 
      <a href="<?= h(url($base . '/students.php?status=Stopped' . ($c ? '&c=' . $c : ''))) ?>"><?= $stopped ?> stopped</a>
    </div>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=new_regs' . ($c ? '&c=' . $c : ''))) ?>" title="Recent student registrations">
      <div class="l">New Registrations ↗</div>
      <div class="v"><?= $newRegs ?></div>
      <div class="s">Recent enrollments</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=docs_pending' . ($c ? '&c=' . $c : ''))) ?>" title="Students with incomplete documents">
      <div class="l">Documents Pending ↗</div>
      <div class="v <?= $docsPending ? 'warn-t' : '' ?>"><?= $docsPending ?></div>
      <div class="s">Missing checklist items</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=due' . ($c ? '&c=' . $c : ''))) ?>" title="Meetings due or overdue">
      <div class="l">Meetings Pending ↗</div>
      <div class="v <?= $dueNow ? 'bad-t' : '' ?>"><?= $dueNow ?></div>
      <div class="s">Scheduled or follow-up due</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=apps_processing' . ($c ? '&c=' . $c : ''))) ?>" title="Applications currently submitted / in process">
      <div class="l">Applications In Process ↗</div>
      <div class="v"><?= $appsProcessing ?></div>
      <div class="s">Submitted to universities</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=offers_received' . ($c ? '&c=' . $c : ''))) ?>" title="Offers or admission letters received">
      <div class="l">Offers Received ↗</div>
      <div class="v" style="color:var(--ok, #4ade80);"><?= $offers ?></div>
      <div class="s">Admission secured</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=visa_processing' . ($c ? '&c=' . $c : ''))) ?>" title="Students in visa processing stage">
      <div class="l">Visa Processing ↗</div>
      <div class="v"><?= $visaProcessing ?></div>
      <div class="s"><?= $granted ?> granted · <?= $refused ?> refused</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=tasks_overdue' . ($c ? '&c=' . $c : ''))) ?>" title="Automated tasks requiring staff attention">
      <div class="l">Overdue Tasks ↗</div>
      <div class="v <?= $overdueTasksCount ? 'bad-t' : '' ?>"><?= $overdueTasksCount ?></div>
      <div class="s">Automated system tasks</div>
    </a>
  </div>

  <div class="kpi kpi-interactive">
    <a class="kpi-main" href="<?= h(url($base . '/students.php?kpi=payments_due' . ($c ? '&c=' . $c : ''))) ?>" title="Outstanding consultancy payments">
      <div class="l">Outstanding Payments ↗</div>
      <div class="v <?= $paymentsOutstanding ? 'warn-t' : '' ?>"><?= $paymentsOutstanding ?></div>
      <div class="s">Installments due</div>
    </a>
  </div>
</div>

<?php if ($admin && $c === ''): ?>
<section>
  <div class="panel-h"><h2>Country Portals &amp; Workflows</h2><p>Italy, Germany, France, Czech Republic and Austria</p></div>
  <div class="cards">
  <?php foreach (COUNTRIES as $code => $meta):
      $set = array_values(array_filter($all, fn($r) => $r['country'] === $code));
      $m = count($set); $st = stages($code); ?>
    <a class="ccard" href="<?= h(url('admin/index.php?c=' . $code)) ?>">
      <div class="ccard-h"><span class="cc big"><?= h($meta['code']) ?></span><div><b><?= h($meta['name']) ?></b><small><?= h($meta['sheet']) ?></small></div></div>
      <div class="ccard-n"><span class="num"><?= $m ?></span> student<?= $m === 1 ? '' : 's' ?><?php $sp = count(array_filter($set, fn($r) => ($r['process_status'] ?? '') === 'Stopped')); if ($sp): ?> · <?= $sp ?> stopped<?php endif; ?></div>
      <div class="mini">
        <?php foreach ($st as $i => $s): $done = count(array_filter($set, fn($r) => stage_done($r, $i))); ?>
          <span title="<?= h($s[0]) ?>: <?= $done ?>/<?= $m ?>"><?= h($s[0]) ?> <b><?= $done ?></b></span>
        <?php endforeach; ?>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:24px;">
  <div class="panel-h">
    <div><h2>Operational Follow-ups &amp; Upcoming Milestones</h2><p><?= count($due) ?> follow-ups tracked across all students</p></div>
  </div>
  <?php if (!$due): ?>
    <div class="empty">No pending follow-ups. All scheduled meetings and visa appointments are up to date.</div>
  <?php else: ?>
    <div class="tbl"><table>
      <thead><tr><th>Student</th><th>Country</th><th>Action Required</th><th>Target Date</th><th>Processor</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($due, 0, 15) as $d):
          $rStudent = $d['r'];
          $isPast = $d['date'] < today();
      ?>
        <tr>
          <td><a href="<?= h(url($base . '/student.php?id=' . (int)$rStudent['id'])) ?>"><b><?= h($rStudent['name']) ?></b></a></td>
          <td><span class="cc"><?= h(COUNTRIES[$rStudent['country']]['code'] ?? 'INT') ?></span> <?= h(country_name($rStudent['country'])) ?></td>
          <td><b><?= h($d['what']) ?></b></td>
          <td><span class="<?= $isPast ? 'bad-t' : '' ?>"><?= h(fmt_date($d['date'])) ?></span></td>
          <td><?= h($rStudent['officer_name'] ?: ($rStudent['back_officer_name'] ?: 'Not assigned')) ?></td>
          <td><span class="pill <?= $isPast ? 'bad' : 'warn' ?>"><?= $isPast ? 'Overdue' : 'Due Soon' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php
page_end();

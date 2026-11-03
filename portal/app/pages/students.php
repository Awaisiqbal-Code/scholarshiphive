<?php
declare(strict_types=1);
// Student list for admin, officers and the tax consultant. Expects $u. ?c= limits to one country.

$admin = is_admin($u);
$base = portal_dir($u);
$c = pick_country($u);
[$w, $p] = scope_sql($u);

$fq = get('q'); $fo = get('officer'); $fs = get('stage'); $fr = get('refund'); $fp = get('phase'); $fst = get('status');
$fkpi = get('kpi'); $fstage_done = get('stage_done'); $fcounselor = get('counselor');
$fbreakdown = get('breakdown'); $fbval = get('val');

$where = [$w]; $params = $p;
if ($c !== '') { $where[] = 's.country = ?'; $params[] = $c; }
if ($fq !== '') { $where[] = '(s.name LIKE ? OR s.counselor LIKE ? OR s.remarks LIKE ? OR s.back_remarks LIKE ?)'; $like = '%' . $fq . '%'; array_push($params, $like, $like, $like, $like); }
if ($admin && $fo !== '') {
    if ($fo === 'none') $where[] = '(s.officer_id IS NULL AND s.back_officer_id IS NULL)';
    else { $where[] = '(s.officer_id = ? OR s.back_officer_id = ?)'; $params[] = (int)$fo; $params[] = (int)$fo; }
}
if ($fr !== '') { $where[] = 's.refund = ?'; $params[] = $fr; }
if ($fst === 'Stopped') $where[] = "s.process_status = 'Stopped'";
elseif ($fst === 'Active') $where[] = "(s.process_status IS NULL OR s.process_status <> 'Stopped')";

if ($fcounselor !== '') { $where[] = 's.counselor = ?'; $params[] = $fcounselor; }

if ($fbreakdown !== '' && in_array($fbreakdown, ['counselor', 'refund', 'documents_status', 'intake', 'applied_for', 'legalization_status', 'embassy_status', 'initial_meeting', 'client_type'], true)) {
    if ($fbval === 'Not set in sheet' || $fbval === '') {
        $where[] = "(s.$fbreakdown IS NULL OR s.$fbreakdown = '')";
    } else {
        $where[] = "s.$fbreakdown = ?";
        $params[] = $fbval;
    }
}

if ($fkpi === 'visa_granted') $where[] = "s.visa = 'Granted'";
elseif ($fkpi === 'visa_refused') $where[] = "s.visa = 'Refused'";
elseif ($fkpi === 'tax_done') $where[] = "s.tax_meeting = 'Yes'";
elseif ($fkpi === 'tax_not_done' || $fkpi === 'tax_notdone') $where[] = "s.tax_meeting = 'No'";

$rows = q('SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.country, s.sr_no IS NULL, s.sr_no, s.id', $params)->fetchAll();
$rows = attach_app_counts($rows);

// Dynamic filters relying on application counts, stage calculations, and follow-ups
if ($fkpi === 'docs_complete') {
    $rows = array_values(array_filter($rows, fn($r) => stage_key_done($r, 'docs')));
} elseif ($fkpi === 'docs_pending') {
    $rows = array_values(array_filter($rows, fn($r) => !stage_key_done($r, 'docs')));
} elseif ($fkpi === 'new_regs') {
    $rows = array_values(array_filter($rows, fn($r) => !empty($r['received_date']) && strtotime($r['received_date']) >= strtotime('-60 days')));
} elseif ($fkpi === 'apps_processing') {
    $rows = array_values(array_filter($rows, fn($r) => ($r['apps_total'] ?? 0) > 0 || !empty($r['application_status'])));
} elseif ($fkpi === 'visa_processing') {
    $rows = array_values(array_filter($rows, fn($r) => !empty($r['appointment_date']) || !empty($r['visa_submitted_date']) || ($r['visa'] ?? '') === 'Pending'));
} elseif ($fkpi === 'payments_due') {
    $rows = array_values(array_filter($rows, fn($r) => (!empty($r['balance_due']) && (float)$r['balance_due'] > 0) || !empty($r['dues'])));
} elseif ($fkpi === 'tasks_overdue') {
    $rows = array_values(array_filter($rows, fn($r) => student_pending_tasks_count((int)$r['id']) > 0));
} elseif ($fkpi === 'offers_received') {
    $rows = array_values(array_filter($rows, fn($r) => stage_key_done($r, 'offer')));
} elseif ($fkpi === 'due') {
    $t = today();
    $rows = array_values(array_filter($rows, fn($r) => count(array_filter(due_list([$r]), fn($d) => $d['date'] <= $t)) > 0));
}

if ($fstage_done !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($fstage_done) {
        foreach (stages($r['country']) as $s) {
            if (strcasecmp($s[0], $fstage_done) === 0) return ($s[3])($r);
        }
        return false;
    }));
}

if ($fp !== '') $rows = array_values(array_filter($rows, fn($r) => stage_phase($r) === $fp));
if ($fs !== '') $rows = array_values(array_filter($rows, fn($r) => $fs === 'closed' ? next_stage($r) === null : next_stage($r) === $fs));

$activeFilterLabel = match (true) {
    $fkpi === 'docs_complete'   => 'Documents complete',
    $fkpi === 'docs_pending'    => 'Documents pending',
    $fkpi === 'new_regs'        => 'New registrations',
    $fkpi === 'apps_processing' => 'Applications in process',
    $fkpi === 'visa_processing' => 'Visa processing',
    $fkpi === 'payments_due'    => 'Outstanding payments due',
    $fkpi === 'tasks_overdue'   => 'Overdue tasks',
    $fkpi === 'offers_received' => 'Offers received',
    $fkpi === 'visa_granted'    => 'Visa granted',
    $fkpi === 'visa_refused'    => 'Visa refused',
    $fkpi === 'tax_done'        => 'Tax meetings done',
    $fkpi === 'tax_not_done' || $fkpi === 'tax_notdone' => 'Tax meetings marked not done',
    $fkpi === 'due'             => 'Follow-ups due (today or overdue)',
    $fstage_done !== ''         => 'Stage completed: ' . $fstage_done,
    $fcounselor !== ''          => 'Counselor: ' . $fcounselor,
    $fbreakdown !== ''          => ucfirst(str_replace('_', ' ', $fbreakdown)) . ': ' . ($fbval ?: 'Not set'),
    default                     => null,
};

$total = count($rows);
$per = 50; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)get('page') ?: 1));
$rows = array_slice($rows, ($page - 1) * $per, $per);
$docPreload = attach_student_doc_data($rows);
$officers = $admin ? q("SELECT id, name, team FROM users WHERE role = 'officer' ORDER BY team DESC, name")->fetchAll() : [];
[$w2, $p2] = scope_sql($u);
$refunds = q("SELECT DISTINCT s.refund FROM students s WHERE $w2" . ($c ? ' AND s.country = ' . db()->quote($c) : '') . " AND s.refund IS NOT NULL ORDER BY s.refund", $p2)->fetchAll(PDO::FETCH_COLUMN);
$qs = function (array $over) { return '?' . http_build_query(array_merge($_GET, $over)); };
$counts = ['' => (int)q("SELECT COUNT(*) FROM students s WHERE $w2", $p2)->fetchColumn()];
foreach (q("SELECT s.country, COUNT(*) n FROM students s WHERE $w2 GROUP BY s.country", $p2) as $x) $counts[$x['country']] = (int)$x['n'];

$apps = function (array $r): string {
    if (empty($r['apps_total'])) return '';
    $parts = [];
    foreach (['Accepted' => 'ok', 'Conditional offer' => 'warn', 'Applied' => 'acc', 'Rejected' => 'bad'] as $k => $cls)
        if (!empty($r['apps'][$k])) $parts[] = '<span class="pill ' . $cls . '">' . (int)$r['apps'][$k] . ' ' . h(strtolower($k)) . '</span>';
    return '<span class="muted small">' . (int)$r['apps_total'] . ' listed</span> ' . implode(' ', $parts);
};

$milestoneBadges = function (array $r, bool $withDocs = true) use (&$docPreload): string {
    $out = [];
    $m1 = $r['initial_meeting'] ?? '';
    if (meeting_held($m1)) {
        $mode = meeting_type_label($r['initial_meeting_mode'] ?? null);
        $out[] = '<span class="pill ok" title="Initial meeting held' . ($mode ? ': ' . h($mode) : '') . '">1st Mtg: ' . ($mode ? h(explode(' ', $mode)[0] === 'Face' ? 'Face to Face' : explode(' ', $mode)[0]) : 'Done') . '</span>';
    } elseif ($m1 === 'No') {
        $out[] = '<span class="pill bad" title="Initial meeting marked No">1st Mtg: Not done</span>';
    } else {
        $out[] = '<span class="pill" title="Initial meeting pending">1st Mtg: Pending</span>';
    }

    $m2 = $r['second_meeting'] ?? '';
    if ($m2 === 'Yes') {
        $out[] = '<span class="pill ok" title="2nd processing meeting completed">2nd Mtg: Done</span>';
    } elseif ($m2 === 'No') {
        $out[] = '<span class="pill bad" title="2nd processing meeting marked No">2nd Mtg: Not done</span>';
    } else {
        $out[] = '<span class="pill" title="2nd processing meeting pending">2nd Mtg: Pending</span>';
    }

    $tx = $r['tax_meeting'] ?? '';
    if ($tx === 'Yes') {
        $mode = meeting_type_label($r['tax_meeting_mode'] ?? null);
        $out[] = '<span class="pill ok" title="Tax meeting held' . ($mode ? ': ' . h($mode) : '') . '">Tax: ' . ($mode ? h(explode(' ', $mode)[0] === 'Face' ? 'Face to Face' : explode(' ', $mode)[0]) : 'Done') . '</span>';
    } elseif ($tx === 'No') {
        $out[] = '<span class="pill" title="Tax meeting marked not done">Tax: Not done</span>';
    } else {
        $out[] = '<span class="pill" title="Tax meeting pending">Tax: Pending</span>';
    }

    if ($withDocs) {
        $docDet = student_doc_detect($r, $docPreload);
        if ($docDet['status'] === 'complete') {
            $out[] = '<span class="pill ok" title="Documents status: Complete">Docs: Complete</span>';
        } else {
            $missingNames = array_map(fn($m) => $m['short'], $docDet['missing']);
            $out[] = '<span class="pill warn" title="Pending documents: ' . h(implode(', ', $missingNames)) . '">Docs: ' . count($missingNames) . ' pending</span>';
        }
    }

    return '<div class="mstones">' . implode('', $out) . '</div>';
};
$docCell = fn($r) => student_documents_badges($r, $docPreload);
$extra = match ($c) {
    'italy'   => ['GPA' => fn($r) => h((string)$r['gpa']), 'Documents' => $docCell, 'Shortlisting' => fn($r) => pill($r['shortlisting'])],
    'france'  => ['GPA' => fn($r) => h((string)$r['gpa']), 'Documents' => $docCell, 'Campus France' => fn($r) => pill($r['campus_france']), 'Shortlisting' => fn($r) => pill($r['shortlisting']), 'Last contacted' => fn($r) => '<span class="num">' . h(fmt_date($r['back_remarks_date'])) . '</span>'],
    'czech'   => ['GPA' => fn($r) => h((string)$r['gpa']), 'Documents' => $docCell, 'Admission' => fn($r) => h(mb_strimwidth((string)$r['admission_university'], 0, 40, '…')), 'Applications' => $apps],
    'austria' => ['GPA' => fn($r) => h((string)$r['gpa']), 'Documents' => $docCell, 'Applications' => $apps, 'Last contacted' => fn($r) => '<span class="num">' . h(fmt_date($r['back_remarks_date'])) . '</span>'],
    'germany' => ['GPA' => fn($r) => h((string)$r['gpa']), 'Documents' => $docCell, 'Intake' => fn($r) => h($r['intake']), 'Admission' => fn($r) => pill($r['application_status']), 'Embassy' => fn($r) => pill($r['embassy_status']), 'Visa' => fn($r) => pill($r['visa'])],
    default   => ['Country' => fn($r) => '<span class="cc">' . h(COUNTRIES[$r['country']]['code'] ?? '') . '</span> ' . h(country_name($r['country'])), 'Documents' => $docCell],
};

page_start($c ? country_name($c) . ' students' : ($admin || $u['role'] === 'tax' ? 'Students' : 'My students'), $u, PORTALS[$u['role']]['label']);
country_bar($u, $c, $base . '/students.php', $counts);
?>
<section class="panel">
  <div class="panel-h">
    <div><h1><?= $c ? h(country_name($c)) . ' students' : ($u['role'] === 'officer' ? 'My students' : 'All students') ?></h1><p><?= $u['role'] === 'officer' ? 'Only the students assigned to you.' : 'Filter by country, officer, stage or current step.' ?></p></div>
    <div class="row-actions">
      <?php if ($u['role'] !== 'tax'): ?>
        <a class="btn sm" href="<?= h(url($base . '/sheet.php' . ($c ? '?c=' . $c : ''))) ?>">Sheet view</a>
        <?php if ($c): ?><a class="btn sm" href="<?= h(url($base . '/export.php?' . http_build_query(['c' => $c, 'format' => class_exists('ZipArchive') ? 'xlsx' : 'csv']))) ?>">Download Excel</a>
        <?php else: ?><a class="btn sm" href="<?= h(url($base . '/export.php')) ?>">Export…</a><?php endif; ?>
      <?php endif; ?>
      <?php if ($u['role'] !== 'tax'): ?><a class="btn sm primary" href="<?= h(url($base . '/student.php?new=1' . ($c ? '&c=' . $c : ''))) ?>">Add student</a><?php endif; ?>
    </div>
  </div>

  <?php if ($activeFilterLabel !== null): ?>
  <div class="filter-banner">
    <div><b>Active filter:</b> <span class="filter-tag"><?= h($activeFilterLabel) ?></span> · <span class="num"><?= $total ?></span> matching student<?= $total === 1 ? '' : 's' ?></div>
    <a class="clear-btn" href="<?= h(url($base . '/students.php' . ($c ? '?c=' . $c : ''))) ?>">Clear filter ✕</a>
  </div>
  <?php endif; ?>

  <form method="get" class="toolbar">
    <?php if ($c): ?><input type="hidden" name="c" value="<?= h($c) ?>"><?php endif; ?>
    <?php if ($fkpi): ?><input type="hidden" name="kpi" value="<?= h($fkpi) ?>"><?php endif; ?>
    <?php if ($fstage_done): ?><input type="hidden" name="stage_done" value="<?= h($fstage_done) ?>"><?php endif; ?>
    <?php if ($fcounselor): ?><input type="hidden" name="counselor" value="<?= h($fcounselor) ?>"><?php endif; ?>
    <?php if ($fbreakdown): ?><input type="hidden" name="breakdown" value="<?= h($fbreakdown) ?>"><input type="hidden" name="val" value="<?= h($fbval) ?>"><?php endif; ?>

    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search name, counselor, remarks" aria-label="Search">
    <?php if ($admin): ?>
    <select name="officer" aria-label="Officer" data-autosubmit>
      <option value="">All officers</option>
      <?php foreach ($officers as $o): ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?> (<?= $o['team'] === 'backend' ? 'back end' : 'front end' ?>)</option><?php endforeach; ?>
      <option value="none" <?= $fo === 'none' ? 'selected' : '' ?>>No officer assigned</option>
    </select>
    <?php endif; ?>
    <select name="status" aria-label="Process status" data-autosubmit>
      <option value="">Active and stopped</option>
      <?php foreach (['Active', 'Stopped'] as $v): ?><option <?= $fst === $v ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
    </select>
    <?php if ($c): $phases = array_values(array_unique(array_column(stages($c), 1))); ?>
    <select name="phase" aria-label="Stage" data-autosubmit>
      <option value="">Any stage</option>
      <?php foreach (array_merge($phases, ['Closed', 'Stopped']) as $ph): ?><option <?= $fp === $ph ? 'selected' : '' ?>><?= h($ph) ?></option><?php endforeach; ?>
    </select>
    <select name="stage" aria-label="Current step" data-autosubmit>
      <option value="">Any current step</option>
      <?php foreach (stages($c) as $s): ?><option <?= $fs === $s[0] ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
      <option value="closed" <?= $fs === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>
    <?php endif; ?>
    <select name="refund" aria-label="Refund status" data-autosubmit>
      <option value="">Any refund status</option>
      <?php foreach ($refunds as $v): ?><option <?= $fr === $v ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit">Apply</button>
    <span class="count num"><?= $total ?> students</span>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">No students match. <a href="<?= h(url($base . '/students.php' . ($c ? '?c=' . $c : ''))) ?>">Clear all filters</a><?= $u['role'] !== 'tax' ? ' or add a student' : '' ?>.</div>
  <?php elseif ($fkpi === 'due'): ?>
  <div class="tbl"><table>
    <thead><tr>
      <th>#</th>
      <th>Student</th>
      <th style="min-width:180px">Due Task / Meeting</th>
      <th>Due Date</th>
      <th style="min-width:210px">All Meetings &amp; Documents</th>
      <th>Counselor</th>
      <th>Officer</th>
      <th>Refund</th>
      <th>Current step</th>
      <?php if ($admin): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $ns = next_stage($r);
        $dList = due_list([$r]);
        $t = today();
        $matchDue = array_values(array_filter($dList, fn($d) => $d['date'] <= $t));
        $dueItem = $matchDue[0] ?? ($dList[0] ?? null);
        $dueWhat = $dueItem['what'] ?? 'Follow-up';
        $dueDate = $dueItem['date'] ?? null;
    ?>
      <tr<?= $r['process_status'] === 'Stopped' ? ' class="stopped"' : '' ?>>
        <td class="num muted"><?= h((string)$r['sr_no']) ?></td>
        <td>
          <a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
          <?php if (!empty($r['contact_no'])): ?><small class="student-phone"><a href="tel:<?= h($r['contact_no']) ?>"><?= h($r['contact_no']) ?></a></small><?php endif; ?>
        </td>
        <td><span class="pill acc" style="font-weight:700"><?= h($dueWhat) ?></span></td>
        <td class="num">
          <?php if ($dueDate): ?>
            <?= h(fmt_date($dueDate)) ?>
            <?php if ($dueDate === $t): ?>
              <span class="pill warn">Today</span>
            <?php elseif ($dueDate < $t): ?>
              <?php $days = max(1, (int)round((strtotime($t) - strtotime($dueDate)) / 86400)); ?>
              <span class="pill bad" title="Overdue by <?= $days ?> day<?= $days === 1 ? '' : 's' ?>">Overdue (<?= $days ?>d)</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="small"><?= $milestoneBadges($r) ?></td>
        <td><?= h($r['counselor']) ?></td>
        <td class="small"><?= h(implode(' / ', array_filter([$r['officer_name'], $r['back_officer_name']])) ?: '—') ?></td>
        <td><?= pill($r['refund'], in_array($r['refund'], ['Non-Refundable', 'Refund Initiated'], true) ? 'bad' : '') ?></td>
        <td><?php if ($r['process_status'] === 'Stopped'): ?><span class="pill bad">Process stopped</span><?php elseif ($ns): ?><span class="pill acc"><?= h($ns) ?></span><?php else: ?><span class="pill ok">Closed</span><?php endif; ?></td>
        <?php if ($admin): ?><td><a class="btn sm" href="<?= h(url('admin/preview.php?id=' . $r['id'])) ?>">Student view</a></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif (in_array($fkpi, ['tax_done', 'tax_not_done', 'tax_notdone'], true)): ?>
  <div class="tbl"><table>
    <thead><tr>
      <th>#</th>
      <th>Student</th>
      <th>Tax Meeting Status</th>
      <th>Tax Meeting Date</th>
      <th style="min-width:210px">All Meetings &amp; Documents</th>
      <th>Counselor</th>
      <th>Officer</th>
      <th>Refund</th>
      <th>Current step</th>
      <?php if ($admin): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $ns = next_stage($r); ?>
      <tr<?= $r['process_status'] === 'Stopped' ? ' class="stopped"' : '' ?>>
        <td class="num muted"><?= h((string)$r['sr_no']) ?></td>
        <td>
          <a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
          <?php if (!empty($r['contact_no'])): ?><small class="student-phone"><a href="tel:<?= h($r['contact_no']) ?>"><?= h($r['contact_no']) ?></a></small><?php endif; ?>
        </td>
        <td>
          <?php if ($r['tax_meeting'] === 'Yes'): ?>
            <span class="pill ok">Done</span>
          <?php elseif ($r['tax_meeting'] === 'No'): ?>
            <span class="pill">Not done</span>
          <?php else: ?>
            <span class="muted small">Pending</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= h(fmt_date($r['meeting_date'] ?: ($r['follow_up_3'] ?? null))) ?: '<span class="muted">—</span>' ?></td>
        <td class="small"><?= $milestoneBadges($r) ?></td>
        <td><?= h($r['counselor']) ?></td>
        <td class="small"><?= h(implode(' / ', array_filter([$r['officer_name'], $r['back_officer_name']])) ?: '—') ?></td>
        <td><?= pill($r['refund'], in_array($r['refund'], ['Non-Refundable', 'Refund Initiated'], true) ? 'bad' : '') ?></td>
        <td><?php if ($r['process_status'] === 'Stopped'): ?><span class="pill bad">Process stopped</span><?php elseif ($ns): ?><span class="pill acc"><?= h($ns) ?></span><?php else: ?><span class="pill ok">Closed</span><?php endif; ?></td>
        <?php if ($admin): ?><td><a class="btn sm" href="<?= h(url('admin/preview.php?id=' . $r['id'])) ?>">Student view</a></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php else: ?>
  <div class="tbl"><table>
    <thead><tr>
      <th>#</th>
      <th>Student</th>
      <th>Counselor</th>
      <th>Officer</th>
      <th>Refund</th>
      <?php foreach ($extra as $t => $_): ?><th><?= h($t) ?></th><?php endforeach; ?>
      <th>Meetings</th>
      <th>Progress</th>
      <th>Current step</th>
      <?php if ($admin): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $ns = next_stage($r); ?>
      <tr<?= $r['process_status'] === 'Stopped' ? ' class="stopped"' : '' ?>>
        <td class="num muted"><?= h((string)$r['sr_no']) ?></td>
        <td>
          <a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
          <?php if (!empty($r['contact_no'])): ?><small class="student-phone"><a href="tel:<?= h($r['contact_no']) ?>"><?= h($r['contact_no']) ?></a></small><?php endif; ?>
        </td>
        <td><?= h($r['counselor']) ?></td>
        <td class="small"><?= h(implode(' / ', array_filter([$r['officer_name'], $r['back_officer_name']])) ?: '—') ?></td>
        <td><?= pill($r['refund'], in_array($r['refund'], ['Non-Refundable', 'Refund Initiated'], true) ? 'bad' : '') ?></td>
        <?php foreach ($extra as $t => $fn): ?><td<?= $t === 'Documents' ? ' class="small doc-cell"' : '' ?>><?= $fn($r) ?></td><?php endforeach; ?>
        <td class="small"><?= $milestoneBadges($r, false) ?></td>
        <td><?= progress_dots($r) ?></td>
        <td><?php if ($r['process_status'] === 'Stopped'): ?><span class="pill bad">Process stopped</span><?php elseif ($ns): ?><span class="pill acc"><?= h($ns) ?></span><?php else: ?><span class="pill ok">Closed</span><?php endif; ?></td>
        <?php if ($admin): ?><td><a class="btn sm" href="<?= h(url('admin/preview.php?id=' . $r['id'])) ?>">Student view</a></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a class="btn sm" href="<?= h($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="num">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn sm" href="<?= h($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
<?php page_end();

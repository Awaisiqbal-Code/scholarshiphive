<?php
declare(strict_types=1);
// Tax consultant meetings. Used by tax/index.php and admin/tax.php. Expects $u.

$admin = is_admin($u);
$tax = $u['role'] === 'tax';
$canEdit = $admin || $tax || (($u['team'] ?? '') === 'frontend');
$base = $tax ? 'tax' : 'admin';
$c = pick_country($u);
$fq = trim(get('q'));
$tab = get('tab');
if ($tab === '') $tab = 'upcoming'; // Default to upcoming meetings

$current_url = ($base === 'tax' ? 'tax/index.php' : 'admin/tax.php') . '?' . http_build_query(array_filter(['c' => $c, 'q' => $fq, 'tab' => $tab]));

// ---------- Quick edit: the tax meeting columns of the sheet (done, how it was held, priority, date, notes) ----------
if (is_post() && post('action') === 'tax_quick_save') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Not allowed.'); }
    $sid = (int)post('student_id');
    [$sw, $sp] = scope_sql($u);
    $st = q("SELECT s.* FROM students s WHERE s.id = ? AND $sw", array_merge([$sid], $sp))->fetch();
    if (!$st) { flash('Student not found.', 'bad'); redirect($current_url); }

    $tm = post('tax_meeting');   $tm = in_array($tm, ['Yes', 'No'], true) ? $tm : null;
    $pr = post('meeting_priority'); $pr = in_array($pr, ['Yes', 'No'], true) ? $pr : null;
    $mode = post('tax_meeting_mode'); $mode = isset(MEETING_TYPES[$mode]) ? $mode : null;
    $md = post('meeting_date');
    $notes = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['tax_remarks'] ?? ''))), 0, 5000) ?: null;
    $err = [];
    if ($md !== '' && !valid_date($md)) $err[] = 'The meeting date is not a valid date.';
    if ($tm === 'Yes' && !$mode) $err[] = 'Choose how the meeting was held: face to face, online or phone call.';
    if ($err) { flash(implode(' ', $err), 'bad'); redirect($current_url); }
    $md = $md === '' ? null : $md;

    q('UPDATE students SET tax_meeting = ?, tax_meeting_mode = ?, meeting_priority = ?, meeting_date = ?, tax_remarks = ?, updated_at = ?, updated_by = ? WHERE id = ?',
      [$tm, $mode, $pr, $md, $notes, now(), (int)$u['id'], $sid]);

    // Keep the meetings log in step with the sheet columns.
    $sm = q("SELECT id FROM student_meetings WHERE student_id = ? AND kind = 'tax' ORDER BY id DESC LIMIT 1", [$sid])->fetch();
    $status = $tm === 'Yes' ? 'Done' : ($tm === 'No' && $md && $md < today() ? 'Missed' : 'Scheduled');
    if ($sm) {
        q('UPDATE student_meetings SET meeting_date = ?, status = ?, notes = ?, meeting_type = ?, updated_at = ? WHERE id = ?', [$md, $status, $notes, $mode, now(), $sm['id']]);
    } elseif ($md || $tm) {
        q("INSERT INTO student_meetings (student_id, kind, title, meeting_date, status, notes, student_can_see, meeting_type, created_by, created_at) VALUES (?, 'tax', ?, ?, ?, ?, 1, ?, ?, ?)",
          [$sid, MEETING_KINDS['tax'], $md, $status, $notes, $mode, (int)$u['id'], now()]);
    }
    log_action('Tax meeting: ' . ($tm ?? 'not set') . ($mode ? ' · ' . meeting_type_label($mode) : '') . ($md ? ' · ' . fmt_date($md) : '') . ($pr === 'Yes' ? ' · priority' : ''), $sid);
    evaluate_student_tasks($sid);
    flash('Saved the tax meeting for ' . $st['name'] . '.');
    redirect($current_url);
}

// ---------- Data Query ----------
[$sw, $sp] = scope_sql($u);
$where = [$sw]; $params = $sp;
if ($c) { $where[] = 's.country = ?'; $params[] = $c; }
if ($fq !== '') { $where[] = '(s.name LIKE ? OR s.counselor LIKE ?)'; $params[] = "%$fq%"; $params[] = "%$fq%"; }
$all = q('SELECT s.id, s.name, s.country, s.counselor, s.process_status, s.tax_meeting, s.tax_meeting_mode, s.initial_meeting_mode, s.meeting_priority, s.meeting_date, s.follow_up_3, s.tax_remarks,
                 s.initial_meeting, s.follow_up_1, s.second_meeting, s.follow_up_2, s.documents_status, s.pending_documents, s.docs_checklist,
                 s.contact_no, s.intake, o.name AS officer_name, b.name AS back_officer_name
          FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY s.name', $params)->fetchAll();

$counts = ['' => 0];
foreach (q('SELECT country, COUNT(*) n FROM students WHERE tax_meeting IS NOT NULL OR meeting_date IS NOT NULL OR meeting_priority IS NOT NULL GROUP BY country') as $x) {
    $counts[$x['country']] = (int)$x['n'];
    $counts[''] += (int)$x['n'];
}

$hasTax = fn($r) => $r['tax_meeting'] !== null || $r['meeting_date'] !== null || $r['meeting_priority'] !== null || $r['follow_up_3'] !== null || $r['tax_remarks'] !== null;
$rows = array_values(array_filter($all, $hasTax));
$t = today();
$when = fn($r) => $r['meeting_date'] ?: $r['follow_up_3'];

$done      = array_values(array_filter($rows, fn($r) => $r['tax_meeting'] === 'Yes'));
$open      = array_values(array_filter($rows, fn($r) => $r['tax_meeting'] !== 'Yes' && $r['process_status'] !== 'Stopped'));
$upcoming  = array_values(array_filter($open, fn($r) => $when($r) && $when($r) >= $t));
$missed    = array_values(array_filter($open, fn($r) => $when($r) && $when($r) < $t));
$nodate    = array_values(array_filter($open, fn($r) => !$when($r)));
$priority  = array_values(array_filter($open, fn($r) => $r['meeting_priority'] === 'Yes'));
$todayList = array_values(array_filter($upcoming, fn($r) => $when($r) === $t));
$notDone   = array_values(array_filter($rows, fn($r) => $r['tax_meeting'] === 'No'));

usort($upcoming, fn($a, $b) => strcmp((string)$when($a), (string)$when($b)));
usort($todayList, fn($a, $b) => strcmp((string)$when($a), (string)$when($b)));
usort($missed, fn($a, $b) => strcmp((string)$when($b), (string)$when($a)));
usort($done, fn($a, $b) => strcmp((string)$b['meeting_date'], (string)$a['meeting_date']));
usort($priority, function ($a, $b) use ($when) {
    $wa = $when($a); $wb = $when($b);
    if ($wa && $wb) return strcmp($wa, $wb);
    if ($wa) return -1;
    if ($wb) return 1;
    return strcmp($a['name'], $b['name']);
});

$todayN = count($todayList);

// Helper for generating tab URLs while preserving country and query
$tabUrl = function (string $tName) use ($base, $c, $fq): string {
    $params = ['tab' => $tName];
    if ($c) $params['c'] = $c;
    if ($fq !== '') $params['q'] = $fq;
    return url($base . ($base === 'tax' ? '/index.php' : '/tax.php') . '?' . http_build_query($params));
};

page_start('Tax consultant meetings', $u, PORTALS[$u['role']]['label']);
country_bar($u, $c, $base . ($base === 'tax' ? '/index.php' : '/tax.php'), $counts);

$table = function (array $list, string $dateLabel, bool $showNotes = true) use ($base, $when, $t, $canEdit): void {
    if (!$list) {
        echo '<div class="empty">No matching records found.</div>';
        return;
    } ?>
    <div class="tbl"><table>
      <thead><tr>
        <th style="min-width:180px"><?= h($dateLabel) ?></th>
        <th>Student</th>
        <th>Country</th>
        <th>Counselor / Officer</th>
        <th style="min-width:210px">All Meetings &amp; Documents</th>
        <th>Tax Priority</th>
        <th>Tax Status</th>
        <?= $showNotes ? '<th>Remarks &amp; Notes</th>' : '' ?>
        <?php if ($canEdit): ?><th style="text-align:right">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($list as $r):
          $d = $when($r);
          $taskType = !empty($r['follow_up_3']) ? 'Tax Meeting Follow-up' : 'Tax Consultant Meeting';
          $isM1Done = meeting_held($r['initial_meeting'] ?? '');
          $isM2Done = ($r['second_meeting'] ?? '') === 'Yes';
          $isDocsDone = in_array($r['documents_status'] ?? '', ['Done', 'Completed'], true);
          $hasPendingDocs = !empty($r['pending_documents']);

          $editJson = htmlspecialchars(json_encode([
              'id' => (int)$r['id'],
              'name' => $r['name'],
              'country' => $r['country'],
              'tax_meeting' => (string)($r['tax_meeting'] ?? ''),
              'tax_meeting_mode' => (string)($r['tax_meeting_mode'] ?? ''),
              'meeting_priority' => (string)($r['meeting_priority'] ?? ''),
              'meeting_date' => (string)($r['meeting_date'] ?? ''),
              'follow_up_3' => (string)($r['follow_up_3'] ?? ''),
              'tax_remarks' => (string)($r['tax_remarks'] ?? ''),
              'initial_meeting' => (string)($r['initial_meeting'] ?? ''),
              'second_meeting' => (string)($r['second_meeting'] ?? ''),
              'documents_status' => (string)($r['documents_status'] ?? ''),
              'pending_documents' => (string)($r['pending_documents'] ?? ''),
              'profile_url' => url($base . '/student.php?id=' . $r['id']),
          ]), ENT_QUOTES, 'UTF-8');
      ?>
        <tr>
          <!-- Task & Scheduled Date (clearly showing WHAT meeting it is and overdue days) -->
          <td>
            <div style="font-weight:700;font-size:13px;color:var(--head);margin-bottom:3px">
              <span class="pill acc" title="Meeting type: <?= h($taskType) ?>"><?= h($taskType) ?></span>
            </div>
            <div class="num" style="font-size:12.5px;display:flex;align-items:center;gap:6px">
              <?php if ($d): ?>
                <span><?= h(fmt_date($d)) ?></span>
                <?php if ($d === $t): ?>
                  <span class="pill warn">Today</span>
                <?php elseif ($d < $t && $r['tax_meeting'] !== 'Yes'): ?>
                  <?php $days = max(1, (int)round((strtotime($t) - strtotime($d)) / 86400)); ?>
                  <span class="pill bad" title="Overdue by <?= $days ?> day<?= $days === 1 ? '' : 's' ?>">Overdue (<?= $days ?>d)</span>
                <?php else: ?>
                  <span class="pill ok">Scheduled</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted">No date set</span>
              <?php endif; ?>
            </div>
          </td>

          <!-- Student & Contact -->
          <td>
            <a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
            <?php if (!empty($r['contact_no'])): ?>
              <small class="student-phone"><a href="tel:<?= h($r['contact_no']) ?>" class="mono" title="Call or WhatsApp"><?= h($r['contact_no']) ?></a></small>
            <?php endif; ?>
          </td>

          <!-- Country -->
          <td><span class="cc"><?= h(COUNTRIES[$r['country']]['code'] ?? '') ?></span></td>

          <!-- Counselor / Officer -->
          <td>
            <div><?= h($r['counselor'] ?? '—') ?></div>
            <small class="muted"><?= h($r['officer_name'] ?? $r['back_officer_name'] ?? 'No officer') ?></small>
          </td>

          <!-- All Meetings & Documents Status (Milestones) -->
          <td class="small">
            <div style="display:flex;flex-wrap:wrap;gap:4px">
              <!-- Initial Meeting -->
              <?php if ($isM1Done): ?>
                <span class="pill ok" title="Initial meeting held<?= $r['initial_meeting_mode'] ? ': ' . h(meeting_type_label($r['initial_meeting_mode'])) : '' ?>">1st Mtg: Done</span>
              <?php elseif (($r['initial_meeting'] ?? '') === 'No'): ?>
                <span class="pill bad" title="Initial meeting marked No">1st Mtg: Not done</span>
              <?php else: ?>
                <span class="pill" title="Initial meeting pending">1st Mtg: Pending</span>
              <?php endif; ?>

              <!-- 2nd Processing Meeting -->
              <?php if ($isM2Done): ?>
                <span class="pill ok" title="2nd processing meeting completed">2nd Mtg: Done</span>
              <?php elseif (($r['second_meeting'] ?? '') === 'No'): ?>
                <span class="pill bad" title="2nd processing meeting marked No">2nd Mtg: Not done</span>
              <?php else: ?>
                <span class="pill" title="2nd processing meeting pending">2nd Mtg: Pending</span>
              <?php endif; ?>

              <!-- Documents Status & Missing Docs -->
              <?php if ($isDocsDone): ?>
                <span class="pill ok" title="Documents status: Complete">Docs: Done</span>
              <?php elseif ($hasPendingDocs): ?>
                <span class="pill warn" title="Pending documents: <?= h((string)$r['pending_documents']) ?>">Docs: <?= h(mb_strimwidth((string)$r['pending_documents'], 0, 16, '…')) ?></span>
              <?php elseif (!empty($r['documents_status'])): ?>
                <span class="pill acc" title="Documents status: <?= h((string)$r['documents_status']) ?>">Docs: <?= h(mb_strimwidth((string)$r['documents_status'], 0, 16, '…')) ?></span>
              <?php else: ?>
                <span class="pill" title="Documents pending">Docs: Pending</span>
              <?php endif; ?>
            </div>
          </td>

          <!-- Priority -->
          <td><?= $r['meeting_priority'] === 'Yes' ? '<span class="pill warn">Priority</span>' : ($r['meeting_priority'] === 'No' ? '<span class="muted small">Normal</span>' : '<span class="muted small">—</span>') ?></td>

          <!-- Tax Status -->
          <td>
            <?php if ($r['tax_meeting'] === 'Yes'): ?>
              <span class="pill ok">Done</span>
              <?php if ($r['tax_meeting_mode']): ?><div class="small muted"><?= h(meeting_type_label($r['tax_meeting_mode'])) ?></div><?php else: ?><div class="small warn-t">Format not set</div><?php endif; ?>
            <?php elseif ($r['tax_meeting'] === 'No'): ?>
              <span class="pill">Not done</span>
            <?php else: ?>
              <span class="muted small">Pending</span>
            <?php endif; ?>
          </td>

          <!-- Notes -->
          <?php if ($showNotes): ?>
            <td class="small wrapcell"><?= !empty($r['tax_remarks']) ? h((string)$r['tax_remarks']) : '<span class="muted">—</span>' ?></td>
          <?php endif; ?>

          <!-- Actions -->
          <?php if ($canEdit): ?>
            <td style="text-align:right;white-space:nowrap">
              <button type="button" class="btn xs js-tax-edit" data-student="<?= $editJson ?>" title="Quick edit meeting status and dates">Edit ✎</button>
              <a class="btn xs ghost" href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>" title="Open full profile">Profile ↗</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
<?php };
?>

<div class="page-h">
  <div>
    <div class="eyebrow">Tax consultant · <?= h($tax ? $u['name'] : (implode(', ', q("SELECT name FROM users WHERE role = 'tax' AND active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)) ?: 'not set up yet')) ?></div>
    <h1><?= $u['role'] === 'tax' ? 'Hello, ' . h($u['name']) : 'Tax consultant meetings' ?></h1>
    <p class="muted">Who has met the tax consultant, who is booked, and who is still waiting. Click any card below to filter matching records directly with full meeting and document details.</p>
  </div>
  <form method="get" class="toolbar">
    <?php if ($c): ?><input type="hidden" name="c" value="<?= h($c) ?>"><?php endif; ?>
    <?php if ($tab): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search student or counselor" aria-label="Search">
    <button class="btn sm">Search</button>
    <?php if ($fq !== ''): ?>
      <a href="<?= $tabUrl($tab) ?>" class="btn sm ghost">Clear search</a>
    <?php endif; ?>
  </form>
</div>

<!-- 5 KPI Cards: Fully Interactive with exact counts and direct active state -->
<div class="kpis">
  <a class="kpi kpi-interactive <?= $tab === 'done' ? 'is-active' : '' ?>" href="<?= $tabUrl('done') ?>" title="View <?= count($done) ?> completed meetings">
    <div class="l">Meetings done ↗</div>
    <div class="v"><?= count($done) ?></div>
    <div class="s">Marked "Yes" in the sheet</div>
  </a>

  <div class="kpi kpi-interactive <?= ($tab === 'upcoming' || $tab === 'today') ? 'is-active' : '' ?>">
    <a class="kpi-main" href="<?= $tabUrl('upcoming') ?>" title="View <?= count($upcoming) ?> upcoming meetings">
      <div class="l">Upcoming ↗</div>
      <div class="v"><?= count($upcoming) ?></div>
    </a>
    <div class="s">
      <a href="<?= $tabUrl('today') ?>" title="View <?= $todayN ?> meetings scheduled for today" style="<?= $tab === 'today' ? 'font-weight:700;color:var(--brand);' : '' ?>"><?= $todayN ?> today</a>
    </div>
  </div>

  <a class="kpi kpi-interactive <?= $tab === 'missed' ? 'is-active' : '' ?>" href="<?= $tabUrl('missed') ?>" title="View <?= count($missed) ?> overdue meetings">
    <div class="l">Date passed, not done ↗</div>
    <div class="v <?= $missed ? 'bad-t' : '' ?>"><?= count($missed) ?></div>
    <div class="s">Needs a new date</div>
  </a>

  <a class="kpi kpi-interactive <?= $tab === 'nodate' ? 'is-active' : '' ?>" href="<?= $tabUrl('nodate') ?>" title="View <?= count($nodate) ?> meetings without a date">
    <div class="l">Not done, no date ↗</div>
    <div class="v"><?= count($nodate) ?></div>
    <div class="s">To be scheduled</div>
  </a>

  <a class="kpi kpi-interactive <?= $tab === 'priority' ? 'is-active' : '' ?>" href="<?= $tabUrl('priority') ?>" title="View <?= count($priority) ?> priority meetings">
    <div class="l">Priority, not done ↗</div>
    <div class="v"><?= count($priority) ?></div>
    <div class="s">Meeting Priority = Yes</div>
  </a>
</div>

<!-- Tab Navigation Bar for Quick Filtering -->
<div class="tax-tab-bar">
  <a href="<?= $tabUrl('upcoming') ?>" class="tab-pill <?= $tab === 'upcoming' ? 'active' : '' ?>">Upcoming <span class="num"><?= count($upcoming) ?></span></a>
  <a href="<?= $tabUrl('today') ?>" class="tab-pill <?= $tab === 'today' ? 'active' : '' ?>">Today <span class="num"><?= $todayN ?></span></a>
  <a href="<?= $tabUrl('missed') ?>" class="tab-pill <?= $tab === 'missed' ? 'active' : '' ?>">Date passed, not done <span class="num"><?= count($missed) ?></span></a>
  <a href="<?= $tabUrl('nodate') ?>" class="tab-pill <?= $tab === 'nodate' ? 'active' : '' ?>">No date yet <span class="num"><?= count($nodate) ?></span></a>
  <a href="<?= $tabUrl('priority') ?>" class="tab-pill <?= $tab === 'priority' ? 'active' : '' ?>">Priority, not done <span class="num"><?= count($priority) ?></span></a>
  <a href="<?= $tabUrl('done') ?>" class="tab-pill <?= $tab === 'done' ? 'active' : '' ?>">Meetings done <span class="num"><?= count($done) ?></span></a>
  <a href="<?= $tabUrl('notdone') ?>" class="tab-pill <?= $tab === 'notdone' ? 'active' : '' ?>">All not done <span class="num"><?= count($notDone) ?></span></a>
  <a href="<?= $tabUrl('all') ?>" class="tab-pill <?= $tab === 'all' ? 'active' : '' ?>">Show all sections</a>
</div>

<?php if ($fq !== ''): ?>
  <div class="filter-banner">
    <div>Search filter: <b>"<?= h($fq) ?>"</b> · <b><?= count($rows) ?></b> students match</div>
    <a href="<?= $tabUrl($tab) ?>" class="clear-btn">[Clear search ✕]</a>
  </div>
<?php endif; ?>

<!-- Record Tables by Selected Tab -->
<?php if ($tab === 'done'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Meetings done</h2>
      <p><?= count($done) ?> students marked "Yes" · Most recent first</p>
    </div>
    <?php $table($done, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'upcoming'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Upcoming meetings</h2>
      <p><?= count($upcoming) ?> scheduled for today or later (<?= $todayN ?> today) · Earliest first</p>
    </div>
    <?php $table($upcoming, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'today'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Today's meetings</h2>
      <p><?= count($todayList) ?> scheduled for today (<?= fmt_date($t) ?>)</p>
    </div>
    <?php $table($todayList, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'missed'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Date passed, meeting not done</h2>
      <p><?= count($missed) ?> meetings overdue · Most recent first</p>
    </div>
    <?php $table($missed, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'nodate'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Not done, no date yet</h2>
      <p><?= count($nodate) ?> students awaiting a meeting date</p>
    </div>
    <?php $table($nodate, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'priority'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>Priority meetings, not done</h2>
      <p><?= count($priority) ?> priority students awaiting completion</p>
    </div>
    <?php $table($priority, 'Task / Event & Date'); ?>
  </section>

<?php elseif ($tab === 'notdone'): ?>
  <section class="panel">
    <div class="panel-h">
      <h2>All marked not done</h2>
      <p><?= count($notDone) ?> students marked "No" in the sheet</p>
    </div>
    <?php $table($notDone, 'Task / Event & Date'); ?>
  </section>

<?php else: // 'all' sections stacked ?>
  <section class="panel">
    <div class="panel-h"><h2>Upcoming meetings</h2><p>Not done yet, date today or later · <?= count($upcoming) ?></p></div>
    <?php $table($upcoming, 'Task / Event & Date'); ?>
  </section>
  <section class="panel">
    <div class="panel-h"><h2>Date passed, meeting not done</h2><p>Most recent first · <?= count($missed) ?></p></div>
    <?php $table($missed, 'Task / Event & Date'); ?>
  </section>
  <section class="panel">
    <div class="panel-h"><h2>Priority meetings, not done</h2><p><?= count($priority) ?> priority students awaiting completion</p></div>
    <?php $table($priority, 'Task / Event & Date'); ?>
  </section>
  <div class="grid2">
    <section class="panel">
      <div class="panel-h"><h2>Meetings done</h2><p><?= count($done) ?> students</p></div>
      <?php $table($done, 'Task / Event & Date', true); ?>
    </section>
    <section class="panel">
      <div class="panel-h"><h2>Not done, no date yet</h2><p><?= count($nodate) ?> students</p></div>
      <?php $table($nodate, 'Task / Event & Date'); ?>
    </section>
  </div>
<?php endif; ?>

<!-- Quick Edit Modal Dialog with Student Milestones and Document Management -->
<div id="tax-edit-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="tax-modal-title" style="display:none">
  <div class="modal-window">
    <div class="modal-hdr">
      <div>
        <div class="eyebrow" id="tax-modal-eyebrow">Tax Consultant Meeting</div>
        <h2 id="tax-modal-title" style="margin:0">Quick Edit</h2>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeTaxModal()" aria-label="Close">✕</button>
    </div>

    <!-- Student Milestones Overview -->
    <div class="modal-milestones" style="background:var(--sunk);border:1px solid var(--line);border-radius:10px;padding:12px;margin-top:12px;display:grid;gap:6px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Student Milestones &amp; Documents</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center" id="tax-modal-pills">
        <span id="tax-modal-p-m1" class="pill">1st Mtg: —</span>
        <span id="tax-modal-p-m2" class="pill">2nd Mtg: —</span>
        <span id="tax-modal-p-docs" class="pill">Docs: —</span>
      </div>
      <div id="tax-modal-p-pending" style="font-size:12px;color:var(--warn);font-weight:600"></div>
    </div>

    <form method="post" action="<?= h(url($current_url)) ?>" id="tax-edit-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tax_quick_save">
      <input type="hidden" name="student_id" id="tax-student-id" value="">

      <div class="fields" style="margin-top:14px">
        <div class="f">
          <span>Tax meeting status</span>
          <select name="tax_meeting" id="tax-field-status">
            <option value="">Pending / Not recorded</option>
            <option value="Yes">Yes (Completed)</option>
            <option value="No">No (Not Done)</option>
          </select>
        </div>

        <div class="f">
          <span>Tax meeting priority</span>
          <select name="meeting_priority" id="tax-field-priority">
            <option value="">Normal</option>
            <option value="Yes">Yes (Priority)</option>
            <option value="No">No</option>
          </select>
        </div>

        <div class="f">
          <span>Tax meeting date (held or planned)</span>
          <input type="date" name="meeting_date" id="tax-field-date">
        </div>

        <div class="f wide">
          <span>How was / will the meeting be held? <b class="req">*</b> (needed when done)</span>
          <div class="radios" id="tax-field-mode">
            <?php foreach (MEETING_TYPES as $mk => $ml): ?><label class="check"><input type="radio" name="tax_meeting_mode" value="<?= h($mk) ?>"> <?= h($ml) ?></label><?php endforeach; ?>
          </div>
        </div>

        <div class="f wide">
          <span>Tax remarks &amp; instructions</span>
          <textarea name="tax_remarks" id="tax-field-remarks" rows="3" placeholder="Consultant notes, instructions, financial docs needed..."></textarea>
        </div>
      </div>

      <div class="modal-ftr">
        <button type="submit" class="btn primary">Save changes</button>
        <a href="#" id="tax-profile-link" class="btn ghost">Open full profile ↗</a>
        <button type="button" class="btn" onclick="closeTaxModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openTaxModal(data) {
  var modal = document.getElementById('tax-edit-modal');
  if (!modal) return;
  document.getElementById('tax-student-id').value = data.id || '';
  document.getElementById('tax-modal-title').textContent = data.name || 'Student';
  document.getElementById('tax-modal-eyebrow').textContent = (data.country ? data.country.toUpperCase() : '') + ' · Meeting & Document Milestones';
  document.getElementById('tax-field-status').value = data.tax_meeting || '';
  document.getElementById('tax-field-priority').value = data.meeting_priority || '';
  document.getElementById('tax-field-date').value = data.meeting_date || '';
  document.querySelectorAll('#tax-field-mode input').forEach(function (r) { r.checked = (r.value === (data.tax_meeting_mode || '')); });
  document.getElementById('tax-field-remarks').value = data.tax_remarks || '';

  // Update modal milestones pills
  var m1El = document.getElementById('tax-modal-p-m1');
  if (m1El) {
    var im = String(data.initial_meeting || '').toLowerCase();
    var isM1 = im === 'yes' || im === 'meeting on call' || im === 'meeting in office';
    m1El.className = 'pill ' + (isM1 ? 'ok' : (data.initial_meeting === 'No' ? 'bad' : ''));
    m1El.textContent = '1st Mtg: ' + (data.initial_meeting || 'Pending');
  }

  var m2El = document.getElementById('tax-modal-p-m2');
  if (m2El) {
    var isM2 = data.second_meeting === 'Yes';
    m2El.className = 'pill ' + (isM2 ? 'ok' : (data.second_meeting === 'No' ? 'bad' : ''));
    m2El.textContent = '2nd Mtg: ' + (data.second_meeting || 'Pending');
  }

  var docsEl = document.getElementById('tax-modal-p-docs');
  if (docsEl) {
    var isDocs = data.documents_status === 'Done' || data.documents_status === 'Completed';
    docsEl.className = 'pill ' + (isDocs ? 'ok' : (data.pending_documents ? 'warn' : ''));
    docsEl.textContent = 'Docs: ' + (data.documents_status || (data.pending_documents ? 'Pending' : '—'));
  }

  var pendingEl = document.getElementById('tax-modal-p-pending');
  if (pendingEl) {
    if (data.pending_documents) {
      pendingEl.textContent = 'Missing / Pending: ' + data.pending_documents;
      pendingEl.style.display = 'block';
    } else {
      pendingEl.textContent = '';
      pendingEl.style.display = 'none';
    }
  }

  var profLink = document.getElementById('tax-profile-link');
  if (profLink) {
    profLink.href = data.profile_url || '#';
  }
  modal.style.display = 'flex';
}

function closeTaxModal() {
  var modal = document.getElementById('tax-edit-modal');
  if (modal) modal.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.js-tax-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      try {
        var data = JSON.parse(this.getAttribute('data-student'));
        openTaxModal(data);
      } catch (e) {
        console.error('Failed to parse student data', e);
      }
    });
  });

  var modal = document.getElementById('tax-edit-modal');
  if (modal) {
    modal.addEventListener('click', function(e) {
      if (e.target === modal) closeTaxModal();
    });
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTaxModal();
  });
});
</script>

<?php page_end();

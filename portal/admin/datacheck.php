<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'resolve_all_fixed' && !is_full_admin($u)) { http_response_code(403); exit('Only a full admin can bulk-resolve notes.'); }
    if ($action === 'resolve_all_fixed') {
        q("UPDATE import_notes SET resolved = 1 WHERE note LIKE '%assigned to Hamza%' OR note LIKE '%treated as empty%' OR note LIKE '%Centralized Sheet link kept%' OR note LIKE '%Informed Counselor%'");
        log_action('Resolved verified data check notes in bulk');
        redirect('admin/datacheck.php?' . http_build_query(array_filter(['c' => post('c'), 'show' => post('show')])));
    }
    $nid = (int)post('id');
    if ($nid > 0 && !is_full_admin($u)) {
        $nc = q('SELECT country FROM import_notes WHERE id = ?', [$nid])->fetchColumn();
        if ($nc && !in_array($nc, user_countries($u), true)) { http_response_code(403); exit('Not allowed.'); }
    }
    if ($nid > 0) {
        q('UPDATE import_notes SET resolved = 1 - resolved WHERE id = ?', [$nid]);
        log_action('Toggled data check note ' . $nid);
    }
    redirect('admin/datacheck.php?' . http_build_query(array_filter(['c' => post('c'), 'show' => post('show')])));
}

$c = pick_country($u);
$show = get('show');
$where = ['1=1']; $params = [];
if ($c) { $where[] = 'n.country = ?'; $params[] = $c; }
elseif (!is_full_admin($u)) { $mine = user_countries($u); $where[] = 'n.country IN (' . implode(',', array_fill(0, count($mine), '?')) . ')'; $params = array_merge($params, $mine); }
if ($show !== 'all') $where[] = 'n.resolved = 0';
$rows = q('SELECT n.*, s.name AS student_name, s.sr_no AS student_sr FROM import_notes n LEFT JOIN students s ON s.id = n.student_id WHERE ' . implode(' AND ', $where) . ' ORDER BY n.country, n.sheet, n.id', $params)->fetchAll();
$counts = ['' => 0];
foreach (q('SELECT country, COUNT(*) n FROM import_notes WHERE resolved = 0 GROUP BY country') as $x) { $counts[(string)$x['country']] = (int)$x['n']; $counts[''] += (int)$x['n']; }

page_start('Data check', $u, PORTALS[$u['role']]['label']);
country_bar($u, $c, 'admin/datacheck.php', $counts);

function describe_mistake(string $note, ?string $field): array {
    $icon = '⚠️';
    $category = 'Data Discrepancy';
    $desc = $note;

    if (stripos($note, 'not a real date') !== false || stripos($note, 'could not be read as a date') !== false) {
        $icon = '📅';
        $category = 'Invalid Date Format';
        $desc = 'Sheet contained an invalid date string or typo: ' . $note;
    } elseif (stripos($note, 'future date') !== false) {
        $icon = '⏳';
        $category = 'Future Date';
        $desc = 'Date listed in sheet is in the future: ' . $note;
    } elseif (stripos($note, 'Portal had the name') !== false || stripos($note, 'latest sheet says') !== false) {
        $icon = '🔤';
        $category = 'Name Difference';
        $desc = 'Slight variation between sheet and portal name: ' . $note;
    } elseif (stripos($note, 'coloured red') !== false) {
        $icon = '🔴';
        $category = 'Red Marked Cell';
        $desc = 'Cell was highlighted red in the spreadsheet: ' . $note;
    } elseif (stripos($note, 'treated as empty') !== false || stripos($note, 'repeats the column title') !== false) {
        $icon = '🏷️';
        $category = 'Column Header Value';
        $desc = 'Column header was repeated in data cell (cleaned): ' . $note;
    } elseif (stripos($note, 'assigned to Hamza') !== false || stripos($note, 'Processor cell is empty') !== false) {
        $icon = '👤';
        $category = 'Processor Assignment';
        $desc = 'Processor was blank in sheet (assigned to country processing officer): ' . $note;
    } elseif (stripos($note, 'Email cell says') !== false) {
        $icon = '✉️';
        $category = 'Pending Email';
        $desc = 'Email field had placeholder "Pending" instead of an address: ' . $note;
    } elseif (stripos($note, 'Other sheet says') !== false) {
        $icon = '⚖️';
        $category = 'Value Conflict';
        $desc = 'Discrepancy between two different sheets: ' . $note;
    } elseif (stripos($note, 'Centralized Sheet link kept') !== false) {
        $icon = '📁';
        $category = 'Drive Folder Link';
        $desc = 'Google Drive link verification note: ' . $note;
    }

    return [$icon, $category, $desc];
}
?>
<div class="page-h">
  <div>
    <div class="eyebrow">Data Verification & Error Reports</div>
    <h1 style="display:flex; align-items:center; gap:10px;">
      <span>Data Check & Discrepancies</span>
      <span class="badge" style="font-size:0.5em; background:var(--accent-soft, #e2f0f9); color:var(--brand, #0f5d9e); border:1px solid var(--line, #d8e1ea);"><?= count($rows) ?> items</span>
    </h1>
    <p class="muted">List of all sheet import notes, format warnings, and discrepancies across country spreadsheets. Click <b>"✏️ Edit Student"</b> to correct any record directly, or click <b>"✓ Mark Resolved"</b> when verified.</p>
  </div>
  <div class="row-actions" style="display:flex; gap:8px; align-items:center;">
    <form method="post" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resolve_all_fixed">
      <input type="hidden" name="c" value="<?= h($c) ?>">
      <input type="hidden" name="show" value="<?= h($show) ?>">
      <button type="submit" class="btn sm" style="background:var(--ok-soft, #ddf2e4); border:1px solid var(--ok, #17703a); color:var(--ok, #17703a); font-weight:600;" title="Bulk resolve all verified and fixed items">✓ Clear Verified Items</button>
    </form>
    <a class="btn sm<?= $show !== 'all' ? ' primary' : '' ?>" href="?<?= h(http_build_query(array_filter(['c' => $c]))) ?>">Pending Only</a>
    <a class="btn sm<?= $show === 'all' ? ' primary' : '' ?>" href="?<?= h(http_build_query(array_filter(['c' => $c, 'show' => 'all']))) ?>">Include Resolved</a>
  </div>
</div>

<section class="panel">
  <?php if (!$rows): ?>
    <div class="empty" style="padding:40px; text-align:center; color:var(--muted, #56687a);">
      <div style="font-size:2rem; margin-bottom:10px;">🎉</div>
      <b>All clear! No pending issues found.</b>
      <p style="margin-top:5px; font-size:0.9rem;">There are no open data verification notes for this filter.</p>
    </div>
  <?php else: ?>
  <div class="tbl">
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th style="width:90px;">Country</th>
          <th style="width:180px;">Student Name</th>
          <th style="width:140px;">Field</th>
          <th>Issue & Details</th>
          <th style="width:180px;">Source Reference</th>
          <th style="width:200px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $n): 
        [$icon, $category, $desc] = describe_mistake($n['note'], $n['field']);
      ?>
        <tr<?= $n['resolved'] ? ' class="stopped" style="opacity:0.6;"' : '' ?>>
          <td>
            <?= $n['country'] ? '<span class="cc" style="font-weight:700; text-transform:uppercase;">' . h(COUNTRIES[$n['country']]['code'] ?? $n['country']) . '</span>' : '<span class="muted">-</span>' ?>
          </td>
          <td>
            <?php if ($n['student_id']): ?>
              <a href="<?= h(url('admin/student.php?id=' . $n['student_id'])) ?>" style="font-weight:700; color:var(--brand, #0f5d9e); text-decoration:none;">
                <?= h($n['student_name'] ?: 'Student #' . $n['student_id']) ?>
              </a>
              <?php if (!empty($n['student_sr'])): ?>
                <div style="font-size:0.75rem; color:var(--muted, #56687a); font-weight:600;">Sr #<?= (int)$n['student_sr'] ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted" style="font-weight:600;">General Row</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.85rem;">
            <span style="display:inline-block; padding:3px 9px; border-radius:6px; background:var(--sunk, #e8edf2); color:var(--head, #12304b); font-weight:600; border:1px solid var(--line, #d8e1ea);">
              <?= h($n['field'] ? (FIELDS[$n['field']][0] ?? $n['field']) : 'General') ?>
            </span>
          </td>
          <td class="wrapcell">
            <div style="display:flex; align-items:flex-start; gap:8px;">
              <span style="font-size:1.1rem; line-height:1;"><?= $icon ?></span>
              <div>
                <div style="font-size:0.78rem; font-weight:800; color:var(--brand, #0f5d9e); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:3px;">
                  <?= h($category) ?>
                </div>
                <div style="font-size:0.9rem; color:var(--fg, #1e2a36); line-height:1.45; font-weight:500;">
                  <?= h($desc) ?>
                </div>
              </div>
            </div>
          </td>
          <td class="small muted" style="font-size:0.82rem; line-height:1.35;">
            <div style="color:var(--head, #12304b); font-weight:600;"><?= h($n['sheet']) ?></div>
            <?php if ($n['ref']): ?>
              <div style="color:var(--muted, #56687a); font-weight:500;"><?= h($n['ref']) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <div style="display:flex; gap:6px; justify-content:flex-end; align-items:center;">
              <?php if ($n['student_id']): ?>
                <a href="<?= h(url('admin/student.php?id=' . $n['student_id'])) ?>" class="btn sm" style="font-weight:600; text-decoration:none; padding:4px 10px; font-size:0.78rem; border-radius:6px;" title="Open student form to edit directly">
                  ✏️ Edit Student
                </a>
              <?php endif; ?>
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <input type="hidden" name="c" value="<?= h($c) ?>">
                <input type="hidden" name="show" value="<?= h($show) ?>">
                <button type="submit" class="btn sm <?= $n['resolved'] ? '' : 'primary' ?>" style="font-size:0.78rem; padding:4px 10px; border-radius:6px;">
                  <?= $n['resolved'] ? 'Reopen' : '✓ Mark Resolved' ?>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php page_end();

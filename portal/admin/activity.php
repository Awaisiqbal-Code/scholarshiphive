<?php
declare(strict_types=1);
// Activity log: who changed what and when (manual updates by the team, uploads, meetings, exports).
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');

$fu = (int)get('user');
$fq = get('q');
$from = valid_date(get('from')) ? get('from') : '';
$to = valid_date(get('to')) ? get('to') : '';
$where = ['1=1']; $params = [];
if (!is_full_admin($u)) {   // sub-admins: their countries' students and their own actions
    $mine = user_countries($u);
    $where[] = '(s.country IN (' . implode(',', array_fill(0, count($mine), '?')) . ') OR a.user_id = ?)';
    $params = array_merge($params, $mine, [(int)$u['id']]);
}
if ($fu) { $where[] = 'a.user_id = ?'; $params[] = $fu; }
if ($fq !== '') { $where[] = '(s.name LIKE ? OR a.action LIKE ?)'; $params[] = "%$fq%"; $params[] = "%$fq%"; }
if ($from) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to) { $where[] = 'a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$page = max(1, (int)get('page'));
$per = 100;
$sql = 'FROM activity_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN students s ON s.id = a.student_id WHERE ' . implode(' AND ', $where);
$total = (int)q("SELECT COUNT(*) $sql", $params)->fetchColumn();
$rows = q("SELECT a.*, u.name AS who, u.role, s.name AS student, s.country $sql ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
$people = q("SELECT id, name, role FROM users WHERE role IN ('admin','subadmin','officer','tax') ORDER BY name")->fetchAll();

page_start('Activity', $u, PORTALS[$u['role']]['label']);
?>
<h1>Activity</h1>
<p class="muted">Every change made in the portal: student records (with the fields that changed), documents, meetings, logins and exports.</p>
<section class="panel">
  <form method="get" class="toolbar">
    <select name="user" data-autosubmit aria-label="Person"><option value="">Everyone</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $fu === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Student or action" aria-label="Search">
    <label class="small">From <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="small">To <input type="date" name="to" value="<?= h($to) ?>"></label>
    <button class="btn sm">Filter</button>
    <span class="count num"><?= $total ?> entries</span>
  </form>
  <div class="tbl"><table>
    <thead><tr><th>When</th><th>Who</th><th>Student</th><th>What</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td class="num small"><?= h(fmt_date(substr($a['created_at'], 0, 10)) . ' ' . substr($a['created_at'], 11, 5)) ?></td>
        <td><?= h($a['who'] ?? '—') ?></td>
        <td><?php if ($a['student_id'] && $a['student']): ?><a href="<?= h(url('admin/student.php?id=' . (int)$a['student_id'])) ?>"><?= h($a['student']) ?></a> <span class="cc"><?= h(COUNTRIES[$a['country']]['code'] ?? '') ?></span><?php endif; ?></td>
        <td class="wrapcell small"><?= h($a['action']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
    <p class="row-actions"><?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="btn sm <?= $i === $page ? 'primary' : '' ?>" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a><?php endfor; ?></p>
  <?php endif; ?>
</section>
<?php page_end();

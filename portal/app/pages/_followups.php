<?php
// Expects $due (from due_list), $base ('admin' | 'officer' | 'tax'), $admin (bool)
if (!$due): ?>
  <div class="empty">No follow-ups pending.</div>
<?php else: ?>
  <div class="list">
  <?php foreach (array_slice($due, 0, 12) as $d):
      $t = today();
      $cls = $d['date'] < $t ? 'bad' : ($d['date'] === $t ? 'warn' : 'acc');
      $lbl = $d['date'] < $t ? 'Overdue · ' : ($d['date'] === $t ? 'Today · ' : '');
      $who = $d['what'] === 'Visa appointment' ? ($d['r']['back_officer_name'] ?? null) : ($d['r']['officer_name'] ?? $d['r']['back_officer_name'] ?? null); ?>
    <a class="li" href="<?= h(url($base . '/student.php?id=' . $d['r']['id'])) ?>">
      <div>
        <b><?= h($d['r']['name']) ?></b>
        <div class="m">
          <?= h(country_name($d['r']['country'])) ?> · <span class="pill acc" style="font-weight:700"><?= h($d['what']) ?></span>
          <?php if (!empty($d['r']['pending_documents'])): ?>
            · <span class="pill warn" title="Pending: <?= h($d['r']['pending_documents']) ?>">Docs: <?= h(mb_strimwidth((string)$d['r']['pending_documents'], 0, 18, '…')) ?></span>
          <?php endif; ?>
          <?= $admin ? ' · ' . h($who ?? 'No officer') : '' ?>
        </div>
      </div>
      <div style="text-align:right">
        <span class="pill <?= $cls ?>"><?= $lbl . h(fmt_date($d['date'])) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
  <?php if (count($due) > 12): ?><p class="muted small"><a href="<?= h(url($base . '/students.php?kpi=due' . (!empty($c) ? '&c=' . $c : ''))) ?>"><b>View all <?= count($due) ?> follow-ups →</b></a> (<?= count($due) - 12 ?> more not shown)</p><?php endif; ?>
<?php endif;

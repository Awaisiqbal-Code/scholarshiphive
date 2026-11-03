<?php
declare(strict_types=1);
// On-screen view of a country's records in the same columns as its sheet (passwords never shown).
// Expects $u.
require_once APP . '/export.php';
$base = portal_dir($u);
$mine = user_countries($u);
$c = in_array(get('c'), $mine, true) ? get('c') : ($mine[0] ?? '');
if ($c === '') { http_response_code(403); exit('No country assigned.'); }
$book = export_workbook($u, $c, false, true);
$titles = array_keys($book);
$tab = in_array(get('tab'), $titles, true) ? get('tab') : $titles[0];
$t = $book[$tab];

page_start(country_name($c) . ' sheet view', $u, PORTALS[$u['role']]['label']);
country_bar($u, $c, $base . '/sheet.php');
?>
<div class="page-h"><div>
  <div class="eyebrow"><span class="cc"><?= h(COUNTRIES[$c]['code']) ?></span> <?= h(COUNTRIES[$c]['sheet']) ?></div>
  <h1><?= h(country_name($c)) ?> · sheet view</h1>
  <p class="muted">The portal's records in the sheet's own columns. Click a name to open and edit the record. Passwords are never shown here.</p></div>
  <div class="row-actions"><a class="btn sm primary" href="<?= h(url($base . '/export.php')) ?>">Export…</a></div></div>
<nav class="countries" aria-label="Sheet tabs">
  <?php foreach ($titles as $tt): ?><a href="<?= h(url($base . '/sheet.php?' . http_build_query(['c' => $c, 'tab' => $tt]))) ?>" <?= $tt === $tab ? 'aria-current="page"' : '' ?>><?= h($tt) ?> <span class="n"><?= count($book[$tt]['rows']) ?></span></a><?php endforeach; ?>
</nav>
<section class="panel">
  <?php if (!$t['rows']): ?><div class="empty">No rows on this tab yet.</div><?php else: ?>
  <div class="tbl" style="max-height:70vh"><table class="sheet-tbl">
    <thead><tr><?php foreach ($t['headers'] as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($t['rows'] as $i => $row): ?><tr>
      <?php foreach ($row as $ci => $cell): $txt = export_text($cell); $hd = strtolower((string)$t['headers'][$ci]); ?>
        <td title="<?= h($txt) ?>"><?php if (in_array($hd, ['student name', 'name'], true) && $txt !== ''): ?><a href="<?= h(url($base . '/student.php?id=' . $t['ids'][$i])) ?>"><b><?= h($txt) ?></b></a><?php elseif (safe_link($txt)): ?><a href="<?= h($txt) ?>" target="_blank" rel="noopener noreferrer">link ↗</a><?php else: ?><?= h($txt) ?><?php endif; ?></td>
      <?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php page_end();

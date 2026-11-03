<?php
declare(strict_types=1);
// Export a country's students as an Excel workbook with the same tabs and columns as that country's
// sheet (or one tab as CSV). Admins / sub-admins export their countries, officers their own students.
// Stored account passwords are exported only by a full admin who ticks the box (POST + CSRF).
// Expects $u.

require_once APP . '/export.php';
require_once APP . '/xlsx.php';
$base = portal_dir($u);
$mine = user_countries($u);
$c = get('c') ?: post('c');
$format = get('format') ?: post('format');
$withPw = false;
if (is_post()) {
    csrf_check();
    $withPw = is_full_admin($u) && post('passwords') === '1';
}

if ($c !== '' && in_array($c, $mine, true) && in_array($format, ['xlsx', 'csv'], true)) {
    $book = export_workbook($u, $c, $withPw);
    $stamp = date('Y-m-d');
    log_action('Exported ' . country_name($c) . ' (' . strtoupper($format) . ($withPw ? ', with passwords' : '') . ')');
    if ($format === 'xlsx' && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        xlsx_write(array_map(fn($t) => ['headers' => $t['headers'], 'rows' => $t['rows']], $book), $tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . country_name($c) . ' processing sheet ' . $stamp . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    // CSV: one tab (the first unless ?tab= names another)
    $titles = array_keys($book);
    $tab = in_array(get('tab') ?: post('tab'), $titles, true) ? (get('tab') ?: post('tab')) : $titles[0];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . country_name($c) . ' - ' . preg_replace('/[^A-Za-z0-9 &()-]/', '', $tab) . ' ' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $book[$tab]['headers']);
    foreach ($book[$tab]['rows'] as $row) fputcsv($out, array_map(fn($x) => export_text($x, true), $row));
    fclose($out);
    exit;
}

page_start('Export', $u, PORTALS[$u['role']]['label']);
?>
<h1>Export to Excel</h1>
<p class="muted">Each country downloads as one workbook with the same tabs and columns, in the same order, as that country's processing sheet,
  so it can be compared with or pasted into the sheet. <?= $u['role'] === 'officer' ? 'You export your own students.' : '' ?></p>
<?php if (!class_exists('ZipArchive')): ?><div class="flash warn">The PHP zip extension is not enabled on this server, so downloads are CSV (one tab at a time).</div><?php endif; ?>
<div class="grid2">
<?php foreach ($mine as $cc): $tabs = array_keys(country_sheet_tabs($cc)); ?>
  <section class="panel">
    <div class="panel-h"><div><h2><span class="cc"><?= h(COUNTRIES[$cc]['code']) ?></span> <?= h(country_name($cc)) ?></h2><p><?= h(COUNTRIES[$cc]['sheet']) ?> · tabs: <?= h(implode(', ', $tabs)) ?></p></div></div>
    <form method="post" class="row-actions"><?= csrf_field() ?>
      <input type="hidden" name="c" value="<?= h($cc) ?>">
      <button class="btn primary" name="format" value="xlsx">Download Excel (.xlsx)</button>
      <select name="tab" aria-label="Tab for CSV"><?php foreach ($tabs as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select>
      <button class="btn" name="format" value="csv">Download this tab as CSV</button>
      <a class="btn ghost" href="<?= h(url($base . '/sheet.php?c=' . $cc)) ?>">View on screen</a>
      <?php if (is_full_admin($u)): ?><label class="check small"><input type="checkbox" name="passwords" value="1"> Include stored account passwords (keep the file private)</label><?php endif; ?>
    </form>
  </section>
<?php endforeach; ?>
</div>
<?php page_end();

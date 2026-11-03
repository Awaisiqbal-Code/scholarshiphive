<?php
declare(strict_types=1);

$admin = is_admin($u);
$base = portal_dir($u);
$id = (int)get('id');
$r = find_student($u, $id);
if (!$r) {
    http_response_code(404);
    exit('Student record not found.');
}

$country = $r['country'] ?? 'italy';
$cMeta = COUNTRIES[$country] ?? ['name' => ucfirst($country), 'code' => strtoupper(substr($country, 0, 2))];
$cChecklist = country_docs($country);
$files = student_files($id);
$meetings = student_meetings($id);
$tasks = student_tasks_list($id);
$apps = q('SELECT * FROM applications WHERE student_id = ? ORDER BY list_name, id', [$id])->fetchAll();

// Collect all uploaded documents
$uploadedFiles = q('SELECT * FROM student_files WHERE student_id = ? ORDER BY created_at DESC', [$id])->fetchAll();
$filesByDocKey = [];
$meetingMediaFiles = [];
foreach ($uploadedFiles as $f) {
    if (is_media_file($f) || str_starts_with($f['doc_key'], 'meeting')) {
        $meetingMediaFiles[] = $f;
    } else {
        $filesByDocKey[doc_key_norm($f['doc_key'])][] = $f;
    }
}

// Documents: one key per document; received / pending as worked out by app/documents.php.
$docState = docs_state($r);
$docPercent = $docState['required'] ? (int)round($docState['received_required'] / $docState['required'] * 100) : 100;

// The country form's sections, limited to what this person may see (passwords are shown only as "Saved").
$dossierGroups = [];
foreach (country_groups($country) as $title => $g) {
    foreach ($g['fields'] as $k => $def) {
        if ($def['type'] === 'secret' || !can_see_field($u, $def['team'], $def['type'], $r)) continue;
        if ($u['role'] === 'tax' && !in_array($title, ['Student profile', 'Meetings', 'Documents', 'Visa filing', 'Admission & visa'], true)) continue;
        $dossierGroups[$title][$k] = $def;
    }
}

page_start($r['name'] . ' – Complete Dossier', $u, PORTALS[$u['role']]['label']);
?>

<style>
/* Print summary: theme colours (light and dark), fits the page width, prints on white. */
.dossier-wrap{max-width:980px;margin:0 auto 40px;display:grid;gap:18px;width:100%}
.dossier-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;background:var(--sunk);border:1px solid var(--line);border-radius:12px}
.dossier-actions>div{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dossier-card{background:var(--surface);color:var(--fg);border:1px solid var(--line);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);min-width:0}
.dossier-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;border-bottom:2px solid var(--brand-2);padding-bottom:16px;margin-bottom:18px}
.dossier-title{font-size:24px;font-weight:800;margin:0 0 4px;color:var(--head)}
.dossier-section-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--brand);margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.dossier-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:10px}
.dossier-item{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:9px 12px;display:grid;gap:2px;min-width:0}
.dossier-item .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:700}
.dossier-item .val{font-size:14px;color:var(--head);overflow-wrap:anywhere}
.dossier-tbl{overflow-x:auto;border:1px solid var(--line);border-radius:10px}
.dossier-table{width:100%;border-collapse:collapse}
.dossier-table th{background:var(--sunk);text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--line)}
.dossier-table td{padding:9px 12px;font-size:13px;border-bottom:1px solid var(--line);color:var(--fg);white-space:normal;vertical-align:top}
.dossier-table td b{color:var(--head)}
.dossier-file{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:2px 0}
.dossier-file span{overflow-wrap:anywhere;min-width:0}
.badge-ok,.badge-warn{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11.5px;font-weight:700;white-space:nowrap}
.badge-ok{background:var(--ok-soft);color:var(--ok)}
.badge-warn{background:var(--warn-soft);color:var(--warn)}
@media print{
  body{background:#fff!important;color:#111!important}
  .dossier-actions,header,nav,.flash,.btn,.foot{display:none!important}
  .dossier-wrap{max-width:100%!important;margin:0!important}
  .dossier-card{background:#fff!important;color:#111!important;border:1px solid #ccc!important;box-shadow:none!important;break-inside:avoid}
  .dossier-item{background:#f8fafc!important;border-color:#e2e8f0!important}
  .dossier-item .val,.dossier-table td,.dossier-title{color:#0f172a!important}
  .dossier-table th{background:#f1f5f9!important;color:#475569!important}
}
</style>

<div class="dossier-wrap">

  <!-- TOP ACTION BAR -->
  <div class="dossier-actions">
    <div>
      <a class="btn sm ghost" href="<?= h(url($base . '/student.php?id=' . $id)) ?>">← Back to Student Profile</a>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <a class="btn sm" href="<?= h(url('download_all.php?id=' . $id)) ?>">⬇ Download student file (ZIP)</a>
      <button type="button" class="btn sm primary" onclick="window.print();">🖨️ Print / Save as PDF</button>
    </div>
  </div>

  <!-- EXECUTIVE SUMMARY & HEADER CARD -->
  <div class="dossier-card">
    <div class="dossier-header">
      <div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
          <span class="cc" style="font-size:12px; padding:3px 8px; border-radius:4px;"><?= h($cMeta['code']) ?></span>
          <span style="font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted);"><?= h($cMeta['name']) ?> Track · ScholarshipHive CRM Dossier</span>
        </div>
        <h1 class="dossier-title"><?= h($r['name']) ?></h1>
        <p class="muted small" style="margin:0;">Reference ID: #SH-<?= (int)$r['id'] ?> · Generated on <?= date('F d, Y · H:i') ?></p>
      </div>
      <div style="text-align:right;">
        <span class="pill <?= ($r['process_status'] ?? '') === 'Stopped' ? 'bad' : 'ok' ?>" style="font-size:13px; padding:4px 12px;">Status: <?= h($r['process_status'] ?: 'Active') ?></span>
        <div style="margin-top:8px; font-size:12px; color:var(--muted);">Documents: <b><?= h($docState['status']) ?></b> (<?= $docState['received_required'] ?> of <?= $docState['required'] ?> needed)</div>
      </div>
    </div>

    <!-- STUDENT PROFILE: the country sheet's profile columns -->
    <h3 class="dossier-section-title">1. Student profile</h3>
    <div class="dossier-grid">
      <?php foreach ($dossierGroups['Student profile'] ?? [] as $k => $def): ?>
        <div class="dossier-item"><span class="lbl"><?= h($def['label']) ?></span><b class="val"><?= h(field_display($k, $def, $r) ?: '—') ?></b></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CENTRAL DOCUMENT INVENTORY -->
  <div class="dossier-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <h3 class="dossier-section-title" style="margin:0; border:none;">2. Documents</h3>
      <?php if (!empty($r['documents_link'])): ?>
        <a class="btn xs" href="<?= h($r['documents_link']) ?>" target="_blank" rel="noopener">📁 Open Student Drive Folder ↗</a>
      <?php endif; ?>
    </div>
    <p class="muted small" style="margin-bottom:14px;"><?= h($cMeta['name']) ?> checklist<?= $docState['pending_text'] ? ' · Pending: <b>' . h($docState['pending_text']) . '</b>' : ' · every needed document is received' ?></p>

    <div class="dossier-tbl"><table class="dossier-table">
      <thead>
        <tr>
          <th>Document</th>
          <th>Requirement</th>
          <th>Status</th>
          <th>Files</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cChecklist as $dk => [$dlbl, $dphase]):
            $needed = in_array($dk, $docState['needed'], true);
            $hasFiles = !empty($filesByDocKey[$dk]);
            $received = !empty($docState['have'][$dk]);
        ?>
          <tr>
            <td><b><?= h($dlbl) ?></b></td>
            <td><span class="muted small"><?= $needed ? 'Needed' : h($dphase ?: 'Optional') ?></span></td>
            <td>
              <?php if ($received): ?>
                <span class="badge-ok">✓ Received<?= $hasFiles ? '' : ' (outside the portal)' ?></span>
              <?php elseif ($needed): ?>
                <span class="badge-warn">Pending</span>
              <?php else: ?>
                <span class="pill">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php foreach ($filesByDocKey[$dk] ?? [] as $fRow): ?>
                <div class="dossier-file"><span><?= h($fRow['original_name']) ?> <span class="muted small">(<?= h(fmt_size((int)$fRow['size'])) ?>)</span></span>
                  <a class="btn xs" href="<?= h(file_url($fRow, true)) ?>">Download</a></div>
              <?php endforeach; ?>
              <?php if (!$hasFiles): ?><span class="muted small">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!empty($filesByDocKey['other'])): ?>
          <tr>
            <td><b>Other supporting documents</b></td>
            <td><span class="muted small">Other</span></td>
            <td><span class="badge-ok">✓ Received</span></td>
            <td>
              <?php foreach ($filesByDocKey['other'] as $fRow): ?>
                <div class="dossier-file"><span><?= h($fRow['original_name']) ?> <span class="muted small">(<?= h(fmt_size((int)$fRow['size'])) ?>)</span></span>
                  <a class="btn xs" href="<?= h(file_url($fRow, true)) ?>">Download</a></div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Every other section of the country form, in the sheet's order -->
  <?php $sn = 2; foreach ($dossierGroups as $title => $fl): if ($title === 'Student profile' || !$fl) continue; $sn++; ?>
  <div class="dossier-card">
    <h3 class="dossier-section-title"><?= $sn ?>. <?= h($title) ?></h3>
    <div class="dossier-grid">
      <?php foreach ($fl as $k => $def): $val = field_display($k, $def, $r); if (in_array($def['type'], ['textarea'], true)) continue; ?>
        <div class="dossier-item"><span class="lbl"><?= h($def['label']) ?></span><b class="val"><?= h($val !== '' ? $val : '—') ?></b></div>
      <?php endforeach; ?>
    </div>
    <?php foreach ($fl as $k => $def): if ($def['type'] !== 'textarea' || empty($r[$k])) continue; ?>
      <div style="margin-top:10px; font-size:13px;"><b><?= h($def['label']) ?>:</b> <?= nl2br(h((string)$r[$k])) ?></div>
    <?php endforeach; ?>
    <?php if ($title === 'Meetings' && $meetings): ?>
      <div class="dossier-tbl" style="margin-top:12px"><table class="dossier-table"><thead><tr><th>Meeting</th><th>Date</th><th>How</th><th>Status</th><th>Recording</th></tr></thead><tbody>
      <?php foreach ($meetings as $mRow): ?>
        <tr><td><?= h($mRow['title']) ?></td><td><?= h(fmt_date($mRow['meeting_date'])) ?></td><td><?= h(meeting_type_label($mRow['meeting_type'] ?? '') ?: 'Not set') ?></td><td><?= h($mRow['status']) ?></td>
            <td><?= safe_link($mRow['recording_link']) ? '<a href="' . h($mRow['recording_link']) . '" target="_blank" rel="noopener">Open ↗</a>' : '—' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($apps)): ?>
  <div class="dossier-card">
    <h3 class="dossier-section-title"><?= $sn + 1 ?>. Universities and programmes</h3>
    <div class="dossier-tbl"><table class="dossier-table">
      <thead><tr><th>University</th><th>Programme</th><th>Deadline</th><th>Status</th><th>Result</th></tr></thead>
      <tbody>
        <?php foreach ($apps as $aRow): ?>
          <tr><td><b><?= h($aRow['university']) ?></b></td><td><?= h($aRow['program']) ?></td><td><?= h($aRow['deadline']) ?></td><td><?= h($aRow['status']) ?></td><td><?= pill($aRow['result']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

</div>

<?php page_end(); ?>

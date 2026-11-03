<?php
declare(strict_types=1);
// Documents panel of the student portal (and the admin's preview of it). Expects $u, $r (student row), $files (student_files()).
// The student sees which documents are received and which are still needed, opens and downloads them, and adds
// their own files: a chosen file shows at once and can be removed until "Save documents" is pressed. Saved files stay.

$canAdd = $u['role'] === 'student' && (int)$u['student_id'] === (int)$r['id'];
$nFiles = array_sum(array_map('count', $files['docs']));
?>
<section class="panel docs-panel" id="docs">
  <div class="panel-h">
    <div><h2>Documents</h2>
      <p><?= h(country_name($r['country'])) ?> checklist · <?= $nFiles ?> file<?= $nFiles === 1 ? '' : 's' ?> in the portal</p></div>
    <?php if ($nFiles > 0): ?>
      <div class="row-actions"><a class="btn sm" href="<?= h(url('download_all.php?id=' . (int)$r['id'])) ?>" title="A ZIP with all your document files">⬇ Download all my documents</a></div>
    <?php endif; ?>
  </div>
  <?php if ($canAdd): ?>
  <form method="post" action="<?= h(url('files.php')) ?>" id="docs_form" class="stack"><?= csrf_field() ?>
    <input type="hidden" name="action" value="docs_save"><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
    <p class="muted small">PDF, images, Word or Excel · up to <?= h(fmt_size(max_upload_bytes())) ?> per file.</p>
    <?= render_docs_box($u, $r, $files, ['form' => 'docs_form', 'staff' => false, 'add' => true]) ?>
    <div class="save-bar"><button class="btn primary" type="submit">Save documents</button><span class="muted small">Files you add are kept when you press Save. After that they stay in your file.</span></div>
  </form>
  <?php else: ?>
    <?= render_docs_box($u, $r, $files, ['staff' => false, 'add' => false]) ?>
  <?php endif; ?>
</section>

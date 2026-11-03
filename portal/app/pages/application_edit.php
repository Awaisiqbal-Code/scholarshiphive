<?php
declare(strict_types=1);
// Add / edit / delete one university application. Admin and back-end officers. Expects $u.

const APP_RESULTS = ['', 'Not applied', 'Pending', 'Applied', 'Conditional offer', 'Accepted', 'Rejected'];
const APP_FIELDS = [
    'list_name'    => ['List', 'text'],
    'university'   => ['University', 'text'],
    'program'      => ['Programme / track', 'text'],
    'app_type'     => ['Application type (Direct / Uni-assist / VPD)', 'text'],
    'deadline'     => ['Deadline', 'text'],
    'starting'     => ['Starting', 'text'],
    'requirements' => ['Language requirement', 'text'],
    'language'     => ['Language / note', 'text'],
    'fee'          => ['Application fee', 'text'],
    'nostrification_status' => ['Nostrification status', 'text'],
    'nostrification_fee'    => ['Nostrification fee', 'text'],
    'applied_date' => ['Applied date', 'text'],
    'portal_id'    => ['Portal ID (university portal)', 'text'],
    'status'       => ['Status (as written by the team)', 'text'],
    'result'       => ['Result', 'select'],
    'link'         => ['Portal / programme link', 'url'],
    'submitted_by' => ['Submitted by', 'text'],
    'notes'        => ['Remarks / additional requirements', 'textarea'],
];
/** The columns each country's university list has (Result is always shown: it drives the offer counts). */
function app_fields_for(string $c): array {
    $keys = match ($c) {
        'france'           => ['university', 'program', 'status', 'notes', 'language', 'link', 'result'],
        'czech', 'austria' => ['university', 'program', 'fee', 'nostrification_status', 'nostrification_fee', 'starting', 'deadline', 'applied_date', 'status', 'link', 'result'],
        'germany'          => ['list_name', 'university', 'program', 'app_type', 'deadline', 'requirements', 'notes', 'portal_id', 'status', 'submitted_by', 'link', 'result'],
        default            => ['university', 'program', 'app_type', 'deadline', 'fee', 'applied_date', 'status', 'link', 'notes', 'result'],
    };
    return array_intersect_key(APP_FIELDS, array_flip($keys));
}

$base = portal_dir($u);
if (!(is_admin($u) || $u['role'] === 'officer')) { http_response_code(403); exit('Only admins and processing officers change university applications.'); }
$aid = (int)(get('id') ?: post('id'));
$a = $aid ? q('SELECT * FROM applications WHERE id = ?', [$aid])->fetch() : null;
if ($aid && !$a) { http_response_code(404); exit('Not found.'); }
$sid = $a ? (int)$a['student_id'] : (int)(get('student') ?: post('student'));
$st = find_student($u, $sid);   // scope check
if (!can_edit_team($u, 'backend', $st)) { http_response_code(403); exit('University applications are kept by the back-end processor of this student.'); }
$errors = [];

if (is_post()) {
    csrf_check();
    if (post('action') === 'delete' && $a) {
        q('DELETE FROM applications WHERE id = ?', [$aid]);
        log_action('Deleted application ' . $a['university'], $sid);
        flash('University removed.');
        redirect("$base/student.php?id=$sid#applications");
    }
    $data = [];
    foreach (app_fields_for($st['country']) as $k => [$label, $type]) {
        $v = mb_substr(post($k), 0, $type === 'textarea' ? 3000 : 500);
        if ($type === 'select' && !in_array($v, APP_RESULTS, true)) $v = (string)($a['result'] ?? '');
        if ($type === 'url' && $v !== '' && !safe_link($v)) $errors[] = $label . ' must start with https://';
        $data[$k] = $v === '' ? null : $v;
    }
    if (empty($data['university']) && empty($data['program'])) $errors[] = 'Enter the university or the programme.';
    if (!$a && !isset($data['list_name'])) $data['list_name'] = $st['country'] === 'france' ? 'Programme shortlist' : 'University applications';
    if (!$errors) {
        $data['updated_by'] = (int)$u['id'];
        if ($a) {
            $data['updated_at'] = now();
            q('UPDATE applications SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data))) . ' WHERE id = ?', array_merge(array_values($data), [$aid]));
            log_action('Updated application ' . $data['university'], $sid);
        } else {
            $data['student_id'] = $sid; $data['created_at'] = now();
            q('INSERT INTO applications (' . implode(',', array_map(fn($c) => "`$c`", array_keys($data))) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
            log_action('Added application ' . $data['university'], $sid);
        }
        flash('University saved.');
        redirect("$base/student.php?id=$sid#applications");
    }
    $a = array_merge($a ?: [], $data);
}

page_start($a ? 'Edit university' : 'Add university', $u, PORTALS[$u['role']]['label']);
?>
<p class="small"><a href="<?= h(url("$base/student.php?id=$sid#applications")) ?>">← <?= h($st['name']) ?></a></p>
<div class="narrow-l">
<section class="panel">
  <h1><?= $aid ? 'Edit university' : 'Add university' ?></h1>
  <p class="muted"><?= h($st['name']) ?> · <?= h(country_name($st['country'])) ?></p>
  <?php if ($errors): ?><div class="flash bad" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="student" value="<?= $sid ?>">
    <div class="fields">
    <?php foreach (app_fields_for($st['country']) as $k => [$label, $type]): $v = (string)($a[$k] ?? ($k === 'list_name' && !$a ? (str_contains(strtolower((string)$st['intake']), 'summer') ? 'Summer 2027 list' : (str_contains(strtolower((string)$st['intake']), 'winter') ? 'Winter 2026 list' : 'University applications')) : '')); ?>
      <label class="f<?= in_array($type, ['textarea', 'url'], true) || in_array($k, ['program', 'university', 'status'], true) ? ' wide' : '' ?>"><span><?= h($label) ?></span>
      <?php if ($type === 'select'): ?>
        <select name="<?= $k ?>"><?php foreach (APP_RESULTS as $ov): ?><option value="<?= h($ov) ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ov === '' ? 'Not set' : h($ov) ?></option><?php endforeach; ?></select>
      <?php elseif ($type === 'textarea'): ?>
        <textarea name="<?= $k ?>" rows="3"><?= h($v) ?></textarea>
      <?php else: ?>
        <input name="<?= $k ?>" value="<?= h($v) ?>"<?= $type === 'url' ? ' type="url" placeholder="https://"' : '' ?>>
      <?php endif; ?>
      </label>
    <?php endforeach; ?>
    </div>
    <p class="muted small">"Result" drives the offer counts. Set Accepted or Conditional offer only when the university has actually sent one.</p>
    <div class="row-actions"><button class="btn primary" type="submit">Save</button><a class="btn ghost" href="<?= h(url("$base/student.php?id=$sid#applications")) ?>">Cancel</a></div>
  </form>
  <?php if ($aid): ?>
  <form method="post" class="row-actions" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="action" value="delete">
    <button class="btn sm bad-b" type="submit">Remove this university</button>
  </form>
  <?php endif; ?>
</section>
</div>
<?php page_end();

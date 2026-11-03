<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
$full = is_full_admin($u);

// A sub-admin manages processing officers only, and only for the countries they look after.
$myCountries = $full ? array_keys(COUNTRIES) : user_countries($u);
$rolesICanAdd = $full ? ['officer' => 'Processing officer', 'subadmin' => 'Sub-admin', 'tax' => 'Tax consultant', 'admin' => 'Admin']
                      : ['officer' => 'Processing officer'];
$pickCountries = fn() => array_values(array_filter((array)($_POST['countries'] ?? []), fn($x) => is_string($x) && valid_country($x) && in_array($x, $myCountries, true)));

/** May the signed-in person manage this account? */
$canManage = function (array $t) use ($u, $full, $myCountries): bool {
    if ($full) return true;
    if ($t['role'] !== 'officer') return false;
    $theirs = array_filter(explode(',', (string)$t['countries']));
    return !$theirs || (bool)array_intersect($theirs, $myCountries);
};

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'add') {
        $name = mb_substr(post('name'), 0, 150);
        $username = strtolower(post('username'));
        $role = post('role');
        $team = $role === 'officer' ? post('team') : null;
        $ctr = in_array($role, ['officer', 'subadmin'], true) ? implode(',', $pickCountries()) : null;
        if ($name === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $username) || !isset($rolesICanAdd[$role]) || ($role === 'officer' && !in_array($team, ['frontend', 'backend'], true))) {
            flash('Enter a name, a username of 3 to 40 letters, numbers, dots or dashes, and a role you may create.', 'bad');
        } elseif (!$full && $ctr === '') {
            flash('Choose at least one of your countries for this officer.', 'bad');
        } elseif (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetchColumn()) {
            flash('That username is already taken.', 'bad');
        } else {
            $pw = random_password(12);
            q('INSERT INTO users (name, username, password_hash, role, team, countries, active, must_change_password, created_at) VALUES (?,?,?,?,?,?,1,1,?)',
              [$name, $username, password_hash($pw, PASSWORD_DEFAULT), $role, $team, $ctr ?: null, now()]);
            log_action("Added $role $username");
            $_SESSION['new_cred'] = [$name, $username, $pw, $role];
        }
    } elseif ($action === 'countries') {
        $id = (int)post('id');
        $t = q("SELECT * FROM users WHERE id = ? AND role IN ('officer','subadmin')", [$id])->fetch();
        if (!$t || !$canManage($t)) { http_response_code(403); exit('Not allowed.'); }
        $ctr = $pickCountries();
        if (!$full) {   // keep the officer's other countries that this sub-admin does not manage
            $keep = array_diff(array_filter(explode(',', (string)$t['countries'])), $myCountries);
            $ctr = array_values(array_unique(array_merge($keep, $ctr)));
        }
        q('UPDATE users SET countries = ? WHERE id = ?', [implode(',', $ctr) ?: null, $id]);
        log_action('Changed countries for ' . $t['username'] . ' to ' . (implode(', ', $ctr) ?: 'all/none'));
        flash('Countries saved.');
    } elseif (in_array($action, ['reset', 'toggle'], true)) {
        $id = (int)post('id');
        $t = q("SELECT * FROM users WHERE id = ? AND role IN ('admin','subadmin','officer','tax')", [$id])->fetch();
        if (!$t) { http_response_code(404); exit('Not found.'); }
        if (!$canManage($t) && (int)$t['id'] !== (int)$u['id']) { http_response_code(403); exit('Not allowed.'); }
        if ($action === 'reset') {
            $pw = random_password(12);
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            log_action('Reset password for ' . $t['username']);
            $_SESSION['new_cred'] = [$t['name'], $t['username'], $pw, $t['role']];
        } else {
            if ((int)$t['id'] === (int)$u['id']) flash('You cannot disable your own account.', 'bad');
            elseif ($t['role'] === 'admin' && $t['active'] && (int)q("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1) flash('At least one admin must stay active.', 'bad');
            else {
                q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
                log_action(($t['active'] ? 'Disabled ' : 'Enabled ') . $t['username']);
                flash($t['active'] ? 'Account disabled. They are signed out on their next click.' : 'Account enabled.');
            }
        }
    }
    redirect('admin/staff.php');
}

$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);
$people = q("SELECT u.*, (SELECT COUNT(*) FROM students s WHERE s.officer_id = u.id OR s.back_officer_id = u.id) AS n FROM users u WHERE role IN ('admin','subadmin','officer','tax') ORDER BY FIELD(role,'admin','subadmin','tax','officer'), name")->fetchAll();
$roleName = fn(array $p) => match ($p['role']) { 'admin' => 'Admin', 'subadmin' => 'Sub-admin', 'tax' => 'Tax consultant', default => ($p['team'] === 'backend' ? 'Back-end officer' : 'Front-end officer') };

page_start('Staff and admins', $u, PORTALS[$u['role']]['label']);
?>
<h1>Staff and admins</h1>

<?php if ($cred): ?>
<div class="cred" role="status">
  <b>Login for <?= h($cred[0]) ?> (<?= h(['officer' => 'processing officer', 'tax' => 'tax consultant', 'admin' => 'admin', 'subadmin' => 'sub-admin'][$cred[3]] ?? $cred[3]) ?>)</b>
  <div>Portal: <code><?= h(url(($cred[3] === 'subadmin' ? 'admin' : $cred[3]) . '/login.php')) ?></code></div>
  <div>Username: <code><?= h($cred[1]) ?></code> · Password: <code><?= h($cred[2]) ?></code></div>
  <p class="small">Copy this now. The password is shown only once. They will be asked to choose a new one when they sign in.</p>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h"><h2>People with staff access</h2>
    <p>Officers see only their own students. The tax consultant sees every student but edits only the tax meeting section.
       <b>Sub-admins</b> use the admin portal for their countries (all countries if none are ticked): they add and edit students,
       assign officers, manage student logins, run the data check and export, and add or reset processing officers.
       Only a full admin manages admins and sub-admins, deletes students and exports stored passwords.</p></div>
  <div class="tbl"><table>
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Countries</th><th>Students</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $manage = $canManage($p) || (int)$p['id'] === (int)$u['id']; ?>
      <tr>
        <td><b><?= h($p['name']) ?></b><?= (int)$p['id'] === (int)$u['id'] ? ' <span class="pill">You</span>' : '' ?></td>
        <td class="mono"><?= h($p['username']) ?></td>
        <td><?= h($roleName($p)) ?></td>
        <td><?php if (in_array($p['role'], ['officer', 'subadmin'], true)): $mine = array_filter(explode(',', (string)$p['countries'])); ?>
          <?php if ($canManage($p)): ?>
          <details class="reveal"><summary><?= h($mine ? implode(', ', array_map('country_name', $mine)) : ($p['role'] === 'subadmin' ? 'All countries' : 'None set')) ?></summary>
          <form method="post" class="stack small"><?= csrf_field() ?><input type="hidden" name="action" value="countries"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <?php foreach ($myCountries as $cc): ?><label class="check"><input type="checkbox" name="countries[]" value="<?= h($cc) ?>" <?= in_array($cc, $mine, true) ? 'checked' : '' ?>> <?= h(country_name($cc)) ?></label><?php endforeach; ?>
            <button class="btn sm">Save countries</button></form></details>
          <?php else: ?><span class="small"><?= h($mine ? implode(', ', array_map('country_name', $mine)) : 'All countries') ?></span><?php endif; ?>
          <?php else: ?><span class="muted small">All</span><?php endif; ?></td>
        <td class="num"><?= $p['role'] === 'officer' ? '<a href="' . h(url('admin/students.php?officer=' . $p['id'])) . '" title="View assigned students"><b>' . (int)$p['n'] . '</b></a>' : '' ?></td>
        <td class="num"><?= $p['last_login'] ? h(fmt_date(substr($p['last_login'], 0, 10))) : 'Never' ?></td>
        <td><span class="pill <?= $p['active'] ? 'ok' : 'bad' ?>"><?= $p['active'] ? 'Active' : 'Disabled' ?></span></td>
        <td><?php if ($manage): ?><form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn sm" name="action" value="reset">Reset password</button>
          <?php if ((int)$p['id'] !== (int)$u['id']): ?><button class="btn sm" name="action" value="toggle"><?= $p['active'] ? 'Disable' : 'Enable' ?></button><?php endif; ?>
        </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="panel-h"><h2>Add a person</h2><p>A strong password is created and shown once.<?= $full ? '' : ' As a sub-admin you can add processing officers for your countries.' ?></p></div>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="f"><span>Full name</span><input name="name" required maxlength="150"></label>
    <label class="f"><span>Username</span><input name="username" required pattern="[a-z0-9._\-]{3,40}" placeholder="e.g. ayesha" autocomplete="off"></label>
    <label class="f"><span>Role</span><select name="role"><?php foreach ($rolesICanAdd as $rk => $rl): ?><option value="<?= h($rk) ?>"><?= h($rl) ?></option><?php endforeach; ?></select></label>
    <label class="f"><span>Team (officers only)</span><select name="team"><option value="frontend">Front end</option><option value="backend">Back end (admission and visa)</option></select></label>
    <div class="f wide"><span>Countries (officers and sub-admins; a sub-admin with none ticked covers all countries)</span><div class="row-actions"><?php foreach ($myCountries as $cc): ?><label class="check"><input type="checkbox" name="countries[]" value="<?= h($cc) ?>"> <?= h(country_name($cc)) ?></label><?php endforeach; ?></div></div>
    <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Add person</button></div>
  </form>
</section>
<?php page_end();

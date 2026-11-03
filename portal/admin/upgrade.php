<?php
declare(strict_types=1);
// Admin > System: database update and server checks. Full admins only.
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
if (!is_full_admin($u)) { http_response_code(403); exit('Only a full admin can open this page.'); }

$ran = null; $error = null;
if (is_post() && post('action') === 'migrate') {
    csrf_check();
    try {
        $ran = run_migrations(db());
        log_action('Ran database update (' . count($ran) . ' change(s))');
    } catch (PDOException $e) {
        error_log('Migration failed: ' . $e->getMessage());
        $error = 'The update stopped: ' . $e->getMessage() . ' — take a backup, then contact your developer with this message.';
    }
}

$checks = [
    ['PHP version 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
    ['cURL extension (downloads Google Drive documents)', extension_loaded('curl'), extension_loaded('curl') ? 'Installed' : 'Missing: enable it in cPanel > Select PHP Version > Extensions'],
    ['Zip extension (needed for Excel export)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'Installed' : 'Missing: Excel export falls back to CSV'],
    ['OpenSSL (stored passwords)', extension_loaded('openssl'), extension_loaded('openssl') ? 'Installed' : 'Missing'],
    ['Fileinfo (upload checks)', class_exists('finfo'), class_exists('finfo') ? 'Installed' : 'Missing'],
    ['Storage folder writable', is_writable(storage_dir()), storage_dir()],
];
try { $version = (int)(q("SELECT v FROM app_settings WHERE k = 'schema_version'")->fetchColumn() ?: 0); } catch (PDOException $e) { $version = 0; }

page_start('System', $u, PORTALS[$u['role']]['label']);
?>
<h1>System</h1>
<?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($ran !== null): ?>
  <div class="flash ok" role="status"><b>Database update finished.</b>
    <?php if ($ran): ?><ul class="notes"><?php foreach ($ran as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul><?php else: ?> Everything was already up to date.<?php endif; ?></div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h"><div><h2>Database update</h2>
    <p>Version in use: <b><?= (int)$version ?></b> · version this code needs: <b><?= SCHEMA_VERSION ?></b>.
       The update only adds columns and tables and widens short columns; it never deletes data. Take a database backup first (cPanel &gt; Backup).</p></div></div>
  <form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="action" value="migrate">
    <button class="btn primary" type="submit"><?= $version < SCHEMA_VERSION ? 'Run database update now' : 'Run again (safe)' ?></button></form>
</section>

<section class="panel">
  <div class="panel-h"><h2>Server checks</h2></div>
  <div class="tbl"><table><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>
  <?php foreach ($checks as [$label, $ok, $detail]): ?>
    <tr><td><?= h($label) ?></td><td><span class="pill <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'Needs attention' ?></span></td><td class="small"><?= h($detail) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php page_end();

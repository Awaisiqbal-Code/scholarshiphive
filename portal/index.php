<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require APP . '/layout.php';

$u = current_user();
if ($u && isset(PORTALS[$u['role']])) {
    redirect(PORTALS[$u['role']]['home']);
}

$portal = in_array(get('role'), ['student', 'admin', 'officer', 'tax'], true) ? get('role') : 'student';
require APP . '/pages/login.php';

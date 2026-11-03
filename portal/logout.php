<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (is_post()) {
    csrf_check();
    $u = current_user();
    if ($u) log_action('Signed out');
    $role = $u['role'] ?? null;
    logout_now();
    flash('You are signed out.');
    redirect($role ? $role . '/login.php' : 'index.php');
}
redirect('index.php');

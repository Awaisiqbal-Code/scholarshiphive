<?php
declare(strict_types=1);

const PORTALS = [
    'admin'    => ['label' => 'Admin portal',              'home' => 'admin/index.php'],
    'subadmin' => ['label' => 'Sub-admin portal',          'home' => 'admin/index.php'],
    'officer' => ['label' => 'Processing officer portal', 'home' => 'officer/index.php'],
    'tax'     => ['label' => 'Tax consultant portal',     'home' => 'tax/index.php'],
    'student' => ['label' => 'Student portal',            'home' => 'student/index.php'],
];

/** Admin or sub-admin: both use the admin portal. Sub-admins are limited to their countries (if set). */
function is_admin(array $u): bool { return in_array($u['role'] ?? '', ['admin', 'subadmin'], true); }
/** Full admin only: manages admins and sub-admins, deletes students, exports stored passwords. */
function is_full_admin(array $u): bool { return ($u['role'] ?? '') === 'admin'; }

const ROLE_LABELS = ['admin' => 'Admin', 'subadmin' => 'Sub-admin', 'tax' => 'Tax consultant', 'student' => 'Student'];
function role_label(array $u): string {
    if (($u['role'] ?? '') === 'officer') return ($u['team'] ?? '') === 'backend' ? 'Back-end officer' : 'Front-end officer';
    return ROLE_LABELS[$u['role'] ?? ''] ?? '';
}

/** Folder of this person's portal (sub-admins use the admin portal). */
function portal_dir(array $u): string { return $u['role'] === 'subadmin' ? 'admin' : $u['role']; }

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (empty($_SESSION['uid'])) return $u = null;
    $row = q('SELECT id, name, username, role, team, countries, student_id, active, must_change_password FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
    if (!$row || !(int)$row['active'] || $row['role'] !== ($_SESSION['role'] ?? '')) {
        logout_now();
        return $u = null;
    }
    return $u = $row;
}

function logout_now(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}

/** Too many failed attempts for this username or this IP in the last 15 minutes? */
function login_blocked(string $username): bool {
    global $CONFIG;
    $since = date('Y-m-d H:i:s', time() - 900);
    $max = (int)($CONFIG['max_attempts'] ?? 8);
    $byUser = (int)q('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND created_at > ?', [$username, $since])->fetchColumn();
    $byIp   = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $since])->fetchColumn();
    return $byUser >= $max || $byIp >= $max * 6;
}

/** Returns null on success, or an error message. */
function attempt_login(?string $portal, string $username, string $password): ?string {
    $username = strtolower(trim($username));
    if ($username === '' || $password === '') return 'Enter your username and password.';
    if (login_blocked($username)) return 'Too many failed attempts. Wait 15 minutes and try again.';

    // Auto-detect role by unique username, supporting optional role filter with graceful fallback
    if ($portal && in_array($portal, ['admin', 'officer', 'tax', 'student'], true)) {
        $roles = $portal === 'admin' ? ['admin', 'subadmin'] : [$portal];
        $u = q('SELECT * FROM users WHERE username = ? AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ') AND active = 1', array_merge([$username], $roles))->fetch();
        if (!$u) {
            $u = q('SELECT * FROM users WHERE username = ? AND active = 1', [$username])->fetch();
        }
    } else {
        $u = q('SELECT * FROM users WHERE username = ? AND active = 1', [$username])->fetch();
    }
    // Always run password_verify so response time does not reveal whether the user exists.
    $hash = $u['password_hash'] ?? '$2y$10$.1OYb90Oa7kSNbTKtiu/8ulPcyEaxnDNGlUkgqKLMI1tbcsf.USZu';
    $ok = password_verify($password, $hash) && $u;
    if (!$ok) {
        q('INSERT INTO login_attempts (ip, username, created_at) VALUES (?,?,?)', [client_ip(), $username, now()]);
        return 'Username or password is not correct.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('DELETE FROM login_attempts WHERE username = ?', [$username]);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], 'last' => time(), 'csrf' => bin2hex(random_bytes(32))];
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    log_action('Signed in');
    return null;
}

/** Gatekeeper for every protected page. */
function require_role(string $role, bool $allowPasswordChange = false): array {
    global $CONFIG;
    $idle = 60 * (int)($CONFIG['idle_minutes'] ?? 30);
    if (!empty($_SESSION['last']) && time() - $_SESSION['last'] > $idle) {
        logout_now();
        flash('You were signed out after a period of inactivity.', 'warn');
        redirect($role . '/login.php');
    }
    $u = current_user();
    if (!$u) redirect($role . '/login.php');
    $allowed = $role === 'admin' ? ['admin', 'subadmin'] : [$role];
    if (!in_array($u['role'], $allowed, true)) {
        // Signed in to a different portal: never show this one.
        http_response_code(403);
        redirect(PORTALS[$u['role']]['home']);
    }
    $_SESSION['last'] = time();
    if ((int)$u['must_change_password'] && !$allowPasswordChange) redirect('account/password.php');
    return $u;
}

/** For pages any signed-in role may use (change password). */
function require_any(): array {
    $u = current_user();
    if (!$u) redirect('index.php');
    $_SESSION['last'] = time();
    return $u;
}

/** Any signed-in role, with the same idle sign-out and forced password change as require_role(). */
function require_signed_in(): array {
    global $CONFIG;
    $idle = 60 * (int)($CONFIG['idle_minutes'] ?? 30);
    if (!empty($_SESSION['last']) && time() - $_SESSION['last'] > $idle) {
        logout_now();
        flash('You were signed out after a period of inactivity.', 'warn');
        redirect('index.php');
    }
    $u = current_user();
    if (!$u) redirect('index.php');
    $_SESSION['last'] = time();
    if ((int)$u['must_change_password']) redirect('account/password.php');
    return $u;
}

/** SQL scope for staff: admin and the tax consultant see all students, officers only their own. */
function scope_sql(array $u, string $alias = 's'): array {
    if ($u['role'] === 'admin' || $u['role'] === 'tax') return ['1=1', []];
    if ($u['role'] === 'subadmin') {
        $list = subadmin_countries($u);
        if (!$list) return ['1=1', []];
        return ["$alias.country IN (" . implode(',', array_fill(0, count($list), '?')) . ')', $list];
    }
    if ($u['role'] === 'officer') return ["($alias.officer_id = ? OR $alias.back_officer_id = ?)", [(int)$u['id'], (int)$u['id']]];
    return ['1=0', []];
}

/** Load one student the current staff member may see, or stop with 404. */
function find_student(array $u, int $id): array {
    [$w, $p] = scope_sql($u);
    $r = q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id WHERE s.id = ? AND $w", array_merge([$id], $p))->fetch();
    if (!$r) { http_response_code(404); exit('Student not found.'); }
    return $r;
}

/** Countries this person works on: admin and tax consultant all; officers their own list plus countries of their students. */
/** Countries a sub-admin is limited to ([] = all countries). */
function subadmin_countries(array $u): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($u['countries'] ?? ''))), 'valid_country'));
}

function user_countries(array $u): array {
    if ($u['role'] === 'admin' || $u['role'] === 'tax') return array_keys(COUNTRIES);
    if ($u['role'] === 'subadmin') return subadmin_countries($u) ?: array_keys(COUNTRIES);
    if ($u['role'] !== 'officer') return [];
    $list = array_filter(array_map('trim', explode(',', (string)($u['countries'] ?? ''))));
    foreach (q('SELECT DISTINCT country FROM students WHERE officer_id = ? OR back_officer_id = ?', [(int)$u['id'], (int)$u['id']]) as $r) $list[] = $r['country'];
    $list = array_values(array_unique(array_filter($list, 'valid_country')));
    usort($list, fn($a, $b) => array_search($a, array_keys(COUNTRIES)) <=> array_search($b, array_keys(COUNTRIES)));
    return $list;
}

/** Country from ?c=, limited to the countries this person may open. '' means all of theirs. */
function pick_country(array $u): string {
    $c = get('c');
    return ($c !== '' && in_array($c, user_countries($u), true)) ? $c : '';
}

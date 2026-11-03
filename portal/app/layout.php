<?php
declare(strict_types=1);

function nav_items(string $role): array {
    return match ($role) {
        'admin'   => ['admin/index.php' => 'Overview', 'admin/students.php' => 'Students', 'admin/tax.php' => 'Tax meetings',
                      'admin/datacheck.php' => 'Data check', 'admin/downloads.php' => 'Download all', 'admin/staff.php' => 'Staff',
                      'admin/logins.php' => 'Student logins', 'admin/activity.php' => 'Activity', 'admin/upgrade.php' => 'System'],
        'subadmin' => ['admin/index.php' => 'Overview', 'admin/students.php' => 'Students', 'admin/tax.php' => 'Tax meetings',
                      'admin/datacheck.php' => 'Data check', 'admin/downloads.php' => 'Download all', 'admin/staff.php' => 'Staff',
                      'admin/logins.php' => 'Student logins', 'admin/activity.php' => 'Activity'],
        'officer' => ['officer/index.php' => 'Dashboard', 'officer/students.php' => 'My students', 'officer/sheet.php' => 'Sheet view'],
        'tax'     => ['tax/index.php' => 'Meetings', 'tax/students.php' => 'All students'],
        'student' => ['student/index.php' => 'My file'],
        default   => [],
    };
}

function brand_mark(): string {
    // Shield with a honeycomb cell and a graduation cap: the ScholarshipHive motif.
    return '<svg class="mark" viewBox="0 0 40 44" aria-hidden="true"><path d="M20 2 37 8v13c0 10.5-7.2 18.6-17 21C10.2 39.6 3 31.5 3 21V8Z" fill="var(--brand)"/>'
        . '<path d="M20 12.5 27 16.5v8L20 28.5l-7-4v-8Z" fill="none" stroke="var(--sky)" stroke-width="1.8"/>'
        . '<path d="M11 15.2 20 11l9 4.2-9 4.2Z" fill="#fff"/><path d="M15 17.6v3.2c0 1.4 2.3 2.6 5 2.6s5-1.2 5-2.6v-3.2l-5 2.3Z" fill="#fff" opacity=".85"/></svg>';
}

function wordmark(string $sub = ''): string {
    return '<div class="brand">' . brand_mark() . '<div><div class="wm">ScholarshipHive <i>Edutancy</i></div><small>' . h($sub ?: 'Processing Portal') . '</small></div></div>';
}

function page_start(string $title, ?array $u = null, string $portalLabel = ''): void {
    global $CONFIG;
    $self = ltrim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', strlen(base_url())), '/');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h($CONFIG['app_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,700;1,400&display=swap">
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<script src="<?= h(url('assets/app.js')) ?>" defer></script>
</head>
<body>
<?php if ($u): ?>
<header class="top">
  <div class="top-in">
    <?= wordmark($portalLabel) ?>
    <nav class="tabs" aria-label="Main">
      <?php foreach (nav_items($u['role']) as $href => $label): ?>
        <a href="<?= h(url($href)) ?>" <?= $self === $href ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= h(url('account/password.php')) ?>" <?= $self === 'account/password.php' ? 'aria-current="page"' : '' ?>>Password</a>
    </nav>
    <div class="who">
      <span class="role"><?= h(role_label($u)) ?></span>
      <b><?= h($u['name']) ?></b>
      <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn sm ghost" type="submit">Sign out</button></form>
    </div>
  </div>
</header>
<?php endif; ?>
<div class="wrap">
<main>
<?php foreach (take_flash() as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>" role="status"><?= h($msg) ?></div>
<?php endforeach;
}

function page_end(): void { ?>
</main>
<footer class="foot">ScholarshipHive Edutancy · Guiding every student's journey with clarity, honesty and expertise.</footer>
</div>
</body>
</html>
<?php }

/** Row of country portals. $page is the page the links open (e.g. 'admin/index.php'). */
function country_bar(array $u, string $current, string $page, array $counts = []): void {
    $list = user_countries($u);
    if (count($list) < 2 && $u['role'] === 'officer') return;
    echo '<nav class="countries" aria-label="Country portals">';
    echo '<a href="' . h(url($page)) . '"' . ($current === '' ? ' aria-current="page"' : '') . '><span class="cc">ALL</span>All countries' . (isset($counts['']) ? ' <span class="n">' . (int)$counts[''] . '</span>' : '') . '</a>';
    foreach ($list as $c) {
        echo '<a href="' . h(url($page . '?c=' . $c)) . '"' . ($current === $c ? ' aria-current="page"' : '') . '><span class="cc">' . h(COUNTRIES[$c]['code']) . '</span>' . h(country_name($c))
           . (isset($counts[$c]) ? ' <span class="n">' . (int)$counts[$c] . '</span>' : '') . '</a>';
    }
    echo '</nav>';
}

function pill_class(string $v): string {
    $l = strtolower(trim($v));
    return match (true) {
        in_array($l, ['granted', 'verified', 'yes', 'done', 'completed', 'accepted', 'received', 'offer received', 'superlegalization complete'], true) => 'ok',
        in_array($l, ['refused', 'non-refundable', 'no', 'rejected', 'stopped', 'stop', 'refund initiated'], true) => 'bad',
        in_array($l, ['pending', 'requested', 'applied', 'conditional offer', 'mofa attested / legalization pending', 'attestation not complete'], true) => 'warn',
        default => 'acc',
    };
}
function pill(?string $v, ?string $cls = null): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    return '<span class="pill ' . h($cls ?? pill_class($v)) . '">' . h(mb_strimwidth($v, 0, 60, '…')) . '</span>';
}

function progress_dots(array $r): string {
    $s = row_stages($r);
    $o = '<span class="steps" title="' . done_count($r) . ' of ' . count($s) . ' steps">';
    foreach ($s as $i => $_) $o .= '<i class="' . (stage_done($r, $i) ? 'd' : '') . '"></i>';
    return $o . '</span>';
}

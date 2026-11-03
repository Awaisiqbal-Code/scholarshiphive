<?php
declare(strict_types=1);

/**
 * ScholarshipHive Vercel Cron Endpoint
 */

require_once __DIR__ . '/../portal/app/bootstrap.php';
require_once __DIR__ . '/../portal/app/fast_export.php';

$token = $_GET['token'] ?? '';
$configuredToken = getenv('CRON_SECRET') ?: 'scholarshiphive_cron_secret_2026';

if (PHP_SAPI !== 'cli' && $token !== $configuredToken) {
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$ran = export_check_and_run_schedules();

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'timezone' => 'Asia/Karachi',
    'executed_count' => count($ran),
    'runs' => $ran
]);

<?php
declare(strict_types=1);

/**
 * ScholarshipHive Automated Background Cron Runner
 * Can be triggered via command line (CLI) or via HTTP GET request.
 * Runs due export schedules for Morning and Evening slots in Asia/Karachi timezone.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/fast_export.php';

// If called via HTTP, optional token check or admin check
$token = $_GET['token'] ?? '';
$configuredToken = getenv('CRON_SECRET') ?: 'scholarshiphive_cron_secret_2026';

if (PHP_SAPI !== 'cli' && $token !== $configuredToken) {
    // If not matching token, require logged-in admin
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

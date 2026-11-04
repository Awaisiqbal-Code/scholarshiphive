<?php
declare(strict_types=1);

// Command line only:
//   php portal/backup_worker.php run <job id>   build one queued backup (started by the admin page / scheduler)
//   php portal/backup_worker.php tick           start every schedule that is due (run this every minute: cron / Task Scheduler)
//   php portal/backup_worker.php daemon         same as tick, in a loop every 30 s
//   php portal/backup_worker.php status         schedules, jobs and the latest backups
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }

require __DIR__ . '/app/bootstrap.php';
require_once APP . '/backup.php';
while (ob_get_level()) ob_end_clean();

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'run':
        backup_run_job((string)($argv[2] ?? ''));
        break;
    case 'tick':
        $s = backup_tick();
        echo $s ? 'Started ' . count($s) . " backup(s): " . implode(', ', array_map(fn($j) => $j['type'] . ' ' . $j['id'], $s)) . "\n" : "Nothing due.\n";
        break;
    case 'daemon':
        echo "Backup scheduler running (Asia/Karachi). Ctrl+C to stop.\n";
        while (true) { try { backup_tick(); } catch (Throwable $e) { error_log('backup tick: ' . $e->getMessage()); } sleep(30); }
    case 'status':
        foreach (backup_schedules() as $s) {
            echo "[{$s['id']}] {$s['name']}  {$s['schedule_time']} PKT  {$s['export_type']}  " . ($s['enabled'] ? 'ENABLED' : 'disabled') . "\n"
               . "    last: " . ($s['last_status'] ?: '-') . ' ' . ($s['last_run_at'] ?: '') . '  file: ' . ($s['last_file'] ?: '-') . '  next: ' . backup_next_run($s) . "\n";
        }
        foreach (backup_history(5) as $h) echo "  #{$h['id']} {$h['created_at']} {$h['export_type']} {$h['trigger_type']} {$h['file_name']} students={$h['row_count']} files={$h['files_ok']} missing={$h['files_failed']} " . round($h['file_size'] / 1048576, 1) . " MB\n";
        break;
    default:
        echo "Usage: php backup_worker.php run <job>|tick|daemon|status\n";
}

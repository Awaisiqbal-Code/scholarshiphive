<?php
declare(strict_types=1);

/**
 * High-Performance Fast Data Export & Scheduler Engine
 * Supports streaming CSV responses, Asia/Karachi timezone filtering,
 * persistent schedule configuration, and secure file delivery.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function export_storage_dir(): string {
    $dir = APP . '/storage/exports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_writable($dir)) {
        $tmp = sys_get_temp_dir() . '/scholarshiphive_exports';
        if (!is_dir($tmp)) @mkdir($tmp, 0750, true);
        return $tmp;
    }
    return $dir;
}

function ensure_export_tables(): void {
    static $done = false;
    if ($done) return;

    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        db()->exec("
            CREATE TABLE IF NOT EXISTS export_schedules (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                schedule_time TEXT NOT NULL DEFAULT '09:00',
                export_type TEXT NOT NULL DEFAULT 'daily',
                enabled INTEGER NOT NULL DEFAULT 1,
                last_run_at TEXT NULL,
                last_status TEXT NULL,
                last_file TEXT NULL,
                updated_at TEXT NULL
            );
            CREATE TABLE IF NOT EXISTS export_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                schedule_id INTEGER NULL,
                export_type TEXT NOT NULL,
                file_name TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                row_count INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'completed',
                trigger_type TEXT NOT NULL DEFAULT 'scheduled',
                created_at TEXT NOT NULL
            );
        ");
    } else {
        db()->exec("
            CREATE TABLE IF NOT EXISTS export_schedules (
                id INT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                schedule_time VARCHAR(5) NOT NULL DEFAULT '09:00',
                export_type VARCHAR(20) NOT NULL DEFAULT 'daily',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_run_at DATETIME NULL,
                last_status VARCHAR(20) NULL,
                last_file VARCHAR(255) NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS export_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schedule_id INT NULL,
                export_type VARCHAR(20) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_size INT NOT NULL DEFAULT 0,
                row_count INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'completed',
                trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // Default schedules check
    $st = db()->query("SELECT id FROM export_schedules WHERE id IN (1, 2)");
    $existing = $st->fetchAll(PDO::FETCH_COLUMN);
    $nowStr = date('Y-m-d H:i:s');

    if (!in_array(1, $existing)) {
        q("INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at) VALUES (1, 'Schedule 1 (Morning Export)', '09:00', 'daily', 1, ?)", [$nowStr]);
    }
    if (!in_array(2, $existing)) {
        q("INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at) VALUES (2, 'Schedule 2 (Evening Export)', '18:00', 'daily', 1, ?)", [$nowStr]);
    }

    $done = true;
}

/**
 * Returns Pakistan (Asia/Karachi) date boundaries.
 */
function export_date_boundaries(string $type): array {
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);

    if ($type === 'daily') {
        $start = (clone $now)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (clone $now)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        return [$start, $end];
    } elseif ($type === 'weekly') {
        $monday = (clone $now)->modify('this week monday');
        $start = $monday->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (clone $now)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        return [$start, $end];
    }
    return [null, null];
}

/**
 * Compute real-time counts for Today, Weekly, and Complete in Asia/Karachi timezone.
 */
function export_get_counts(string $country = ''): array {
    ensure_export_tables();
    [$dStart, $dEnd] = export_date_boundaries('daily');
    [$wStart, $wEnd] = export_date_boundaries('weekly');

    $cFilter = $country !== '' ? ' AND country = ' . db()->quote($country) : '';

    $complete = (int)q("SELECT COUNT(*) FROM students WHERE 1=1 $cFilter")->fetchColumn();

    $daily = (int)q("SELECT COUNT(*) FROM students WHERE 
        ((created_at >= ? AND created_at <= ?) OR (updated_at IS NOT NULL AND updated_at >= ? AND updated_at <= ?)) $cFilter",
        [$dStart, $dEnd, $dStart, $dEnd])->fetchColumn();

    $weekly = (int)q("SELECT COUNT(*) FROM students WHERE 
        ((created_at >= ? AND created_at <= ?) OR (updated_at IS NOT NULL AND updated_at >= ? AND updated_at <= ?)) $cFilter",
        [$wStart, $wEnd, $wStart, $wEnd])->fetchColumn();

    return ['daily' => $daily, 'weekly' => $weekly, 'complete' => $complete];
}

function export_headers_def(): array {
    return [
        'id'                     => 'Student ID',
        'sr_no'                  => 'Sr #',
        'name'                   => 'Student Name',
        'country'                => 'Country',
        'branch'                 => 'Branch',
        'counselor'              => 'Counselor',
        'front_officer'          => 'Front-end Officer',
        'back_officer'           => 'Back-end Officer',
        'process_status'         => 'Process Status',
        'intake'                 => 'Intake',
        'received_date'          => 'Received Date',
        'study_level'            => 'Study Level',
        'applied_for'            => 'Applied Degree/Course',
        'last_degree'            => 'Last Degree',
        'gpa'                    => 'GPA / Marks',
        'ielts'                  => 'IELTS / Test Score',
        'contact_no'             => 'Contact Number',
        'student_email'          => 'Student Email',
        'recovery_email'         => 'Recovery Email',
        'portal_id'              => 'Portal ID',
        'client_type'            => 'Client Type',
        'total_package_fee'      => 'Total Package Fee',
        'total_paid'             => 'Total Paid',
        'balance_due'            => 'Balance Due',
        'payment_status'         => 'Payment Status',
        'initial_meeting'        => 'Initial Meeting',
        'initial_meeting_mode'   => 'Initial Meeting Mode',
        'second_meeting'         => 'Second Meeting',
        'second_meeting_mode'    => 'Second Meeting Mode',
        'tax_meeting'            => 'Tax Meeting',
        'tax_meeting_mode'       => 'Tax Meeting Mode',
        'meeting_priority'       => 'Meeting Priority',
        'meeting_date'           => 'Meeting Date',
        'shortlisting'           => 'Shortlisting Status',
        'documents_status'       => 'Documents Status',
        'admission_university'   => 'Admission University',
        'application_status'     => 'Application Status',
        'offer'                  => 'Offer Status',
        'enrollment'             => 'Enrollment Status',
        'visa'                   => 'Visa Status',
        'appointment_status'     => 'Appointment Status',
        'appointment_date'       => 'Appointment Date',
        'visa_decision_date'     => 'Visa Decision Date',
        'drive_link'             => 'Google Drive Folder',
        'applications_count'     => 'Applications Count',
        'applications_summary'   => 'Applications Summary',
        'created_at'             => 'Record Created (PKT)',
        'updated_at'             => 'Record Updated (PKT)',
    ];
}

function export_sanitize_cell($val): string {
    if ($val === null) return '';
    $s = trim((string)$val);
    if (preg_match('/^[=+\-@\t\r]/', $s)) {
        return "'" . $s;
    }
    return $s;
}

/**
 * Builds the prepared query and parameters for export.
 */
function export_query_statement(string $type, string $country = ''): PDOStatement {
    ensure_export_tables();
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isSqlite = ($driver === 'sqlite');

    [$start, $end] = export_date_boundaries($type);
    $where = [];
    $params = [];

    if ($country !== '') {
        $where[] = 's.country = ?';
        $params[] = $country;
    }

    if ($start && $end) {
        $where[] = '((s.created_at >= ? AND s.created_at <= ?) OR (s.updated_at IS NOT NULL AND s.updated_at >= ? AND s.updated_at <= ?))';
        $params[] = $start;
        $params[] = $end;
        $params[] = $start;
        $params[] = $end;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $appSummarySql = $isSqlite
        ? "(SELECT GROUP_CONCAT(COALESCE(a.university, '') || ' (' || COALESCE(a.program, '') || ' - ' || COALESCE(a.result, 'Pending') || ')', '; ') FROM applications a WHERE a.student_id = s.id)"
        : "(SELECT GROUP_CONCAT(CONCAT(COALESCE(a.university, ''), ' (', COALESCE(a.program, ''), ' - ', COALESCE(a.result, 'Pending'), ')') SEPARATOR '; ') FROM applications a WHERE a.student_id = s.id)";

    $sql = "
    SELECT 
        s.id, s.sr_no, s.name, s.country, s.branch, s.counselor,
        o.name AS front_officer, b.name AS back_officer, s.process_status,
        s.intake, s.received_date, s.study_level, s.applied_for, s.last_degree, s.gpa, s.ielts,
        s.contact_no, s.student_email, s.recovery_email, s.portal_id, s.client_type,
        s.total_package_fee, s.total_paid, s.balance_due, s.payment_status,
        s.initial_meeting, s.initial_meeting_mode, s.second_meeting, s.second_meeting_mode,
        s.tax_meeting, s.tax_meeting_mode, s.meeting_priority, s.meeting_date,
        s.shortlisting, s.documents_status, s.admission_university, s.application_status,
        s.offer, s.enrollment, s.visa, s.appointment_status, s.appointment_date, s.visa_decision_date,
        s.drive_link, s.created_at, s.updated_at,
        (SELECT COUNT(*) FROM applications a WHERE a.student_id = s.id) AS applications_count,
        $appSummarySql AS applications_summary
    FROM students s
    LEFT JOIN users o ON o.id = s.officer_id
    LEFT JOIN users b ON b.id = s.back_officer_id
    $whereSql
    ORDER BY s.country, s.sr_no IS NULL, s.sr_no, s.id
    ";

    return q($sql, $params);
}

/**
 * Direct Instant Streaming Response to browser (Completes in milliseconds).
 */
function export_stream_to_browser(string $type, string $country = ''): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // Prevent locking other requests
    }

    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);
    $timestamp = $now->format('Ymd_His');
    $typeLabel = ucfirst($type);
    $countryLabel = $country !== '' ? ('_' . ucfirst($country)) : '';
    $filename = "ScholarshipHive_Students_{$typeLabel}{$countryLabel}_{$timestamp}.csv";

    // Clean output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Write UTF-8 BOM so Microsoft Excel correctly renders all special characters
    fwrite($out, "\xEF\xBB\xBF");

    $headers = export_headers_def();
    fputcsv($out, array_values($headers));

    $st = export_query_statement($type, $country);
    $keys = array_keys($headers);

    $cnt = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $cnt++;
        $row = [];
        foreach ($keys as $k) {
            $row[] = export_sanitize_cell($r[$k] ?? '');
        }
        fputcsv($out, $row);
        if ($cnt % 50 === 0) {
            fflush($out);
        }
    }

    fclose($out);
    exit;
}

/**
 * Generate export file and save to secure storage, logging to history.
 */
function export_generate_file(string $type, string $triggerType = 'manual', ?int $scheduleId = null): array {
    ensure_export_tables();
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);
    $timestamp = $now->format('Ymd_His');
    $typeLabel = ucfirst($type);
    $filename = "ScholarshipHive_Students_{$typeLabel}_{$timestamp}.csv";
    $dir = export_storage_dir();
    $filepath = $dir . '/' . $filename;

    $out = fopen($filepath, 'w');
    if (!$out) {
        throw new RuntimeException("Could not write export file to $filepath");
    }

    fwrite($out, "\xEF\xBB\xBF");
    $headers = export_headers_def();
    fputcsv($out, array_values($headers));

    $st = export_query_statement($type);
    $keys = array_keys($headers);
    $rowCount = 0;

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        $row = [];
        foreach ($keys as $k) {
            $row[] = export_sanitize_cell($r[$k] ?? '');
        }
        fputcsv($out, $row);
    }
    fclose($out);

    $filesize = (int)filesize($filepath);
    $nowDbStr = $now->format('Y-m-d H:i:s');

    q("INSERT INTO export_history (schedule_id, export_type, file_name, file_path, file_size, row_count, status, trigger_type, created_at)
       VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?)",
       [$scheduleId, $type, $filename, $filepath, $filesize, $rowCount, $triggerType, $nowDbStr]);

    if ($scheduleId) {
        q("UPDATE export_schedules 
           SET last_run_at = ?, last_status = 'success', last_file = ?, updated_at = ?
           WHERE id = ?",
           [$nowDbStr, $filename, $nowDbStr, $scheduleId]);
    }

    return [
        'success'    => true,
        'file_name'  => $filename,
        'file_path'  => $filepath,
        'file_size'  => $filesize,
        'row_count'  => $rowCount,
        'created_at' => $nowDbStr,
    ];
}

/**
 * Safe idempotent execution of scheduled jobs.
 */
function export_check_and_run_schedules(): array {
    ensure_export_tables();
    $schedules = q("SELECT * FROM export_schedules WHERE enabled = 1")->fetchAll();
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);
    $todayDate = $now->format('Y-m-d');

    $executed = [];
    foreach ($schedules as $s) {
        $schedTime = trim((string)($s['schedule_time'] ?? '09:00'));
        $lastRun = (string)($s['last_run_at'] ?? '');

        // Prevent duplicate execution on the same date
        if (str_starts_with($lastRun, $todayDate)) {
            continue;
        }

        // Check if current PKT time has reached or passed the scheduled time (within 15 minutes)
        [$sh, $sm] = array_map('intval', explode(':', $schedTime));
        $due = (clone $now)->setTime($sh, $sm, 0);
        $diffSeconds = $now->getTimestamp() - $due->getTimestamp();

        if ($diffSeconds >= 0 && $diffSeconds <= 900) {
            $res = export_generate_file($s['export_type'] ?: 'daily', 'scheduled', (int)$s['id']);
            $executed[] = $res;
        }
    }
    return $executed;
}

function export_get_schedules(): array {
    ensure_export_tables();
    return q("SELECT * FROM export_schedules ORDER BY id")->fetchAll();
}

function export_update_schedule(int $id, string $time, string $type, int $enabled): void {
    ensure_export_tables();
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $time = '09:00';
    }
    if (!in_array($type, ['daily', 'weekly', 'complete'], true)) {
        $type = 'daily';
    }
    $nowStr = date('Y-m-d H:i:s');
    q("UPDATE export_schedules SET schedule_time = ?, export_type = ?, enabled = ?, updated_at = ? WHERE id = ?",
      [$time, $type, $enabled ? 1 : 0, $nowStr, $id]);
}

function export_get_history(int $limit = 15): array {
    ensure_export_tables();
    return q("SELECT h.*, s.name AS schedule_name FROM export_history h LEFT JOIN export_schedules s ON s.id = h.schedule_id ORDER BY h.id DESC LIMIT ?", [$limit])->fetchAll();
}

/**
 * Calculate next scheduled execution time for display.
 */
function export_next_execution(array $s): string {
    if (empty($s['enabled'])) {
        return 'Disabled';
    }
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);
    $schedTime = trim((string)($s['schedule_time'] ?? '09:00'));
    [$h, $m] = array_map('intval', explode(':', $schedTime));

    $targetToday = (clone $now)->setTime($h, $m, 0);
    $lastRun = (string)($s['last_run_at'] ?? '');
    $todayDate = $now->format('Y-m-d');

    if ($now < $targetToday && !str_starts_with($lastRun, $todayDate)) {
        return 'Today at ' . $schedTime . ' PKT';
    }
    return 'Tomorrow at ' . $schedTime . ' PKT';
}

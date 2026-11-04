<?php
declare(strict_types=1);

// =====================================================================================
// Complete student backup: one organised ZIP with a folder per student holding the student's
// information as JSON, a details PDF and the REAL document files (Google Drive files are copied
// first, exactly like the existing "Download all" does). No CSV / Excel.
//
//  - Runs in a background worker (portal/backup_worker.php) so the browser never freezes;
//    progress is kept in a small job file and polled by the admin page.
//  - The ZIP is streamed to a temporary file (never held in memory), checked, then renamed.
//  - Backups live OUTSIDE the web folder (../private_backups) and are only served by an
//    admin-only, logged download action.
//  - Nothing in the portal's records or original documents is changed or deleted.
//  - Two schedules (table export_schedules) are started by `php portal/backup_worker.php tick`
//    (run every minute by cron / Windows Task Scheduler) or `... daemon`; both can run the same day.
// =====================================================================================

const BACKUP_TYPES = ['daily' => "Today's", 'weekly' => 'Weekly', 'complete' => 'Complete'];
const BACKUP_CATCHUP_SECONDS = 6 * 3600;   // a schedule missed while the machine was off still runs, up to 6 h late
const BACKUP_KEEP_SCHEDULED = 7;           // newest scheduled backups kept per type
const BACKUP_JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

function backup_supported(): bool { return true; }

function backup_dir(string $sub = ''): string {
    global $CONFIG;
    static $base = null;
    if ($base === null) {
        $base = rtrim((string)($CONFIG['backup_dir'] ?? ''), '/\\');
        if ($base === '') {
            $parent = dirname(ROOT);
            $base = (is_dir($parent) && is_writable($parent)) ? $parent . '/private_backups' : storage_dir() . '/backups';
        }
        if (!is_dir($base)) @mkdir($base, 0750, true);
        if (!is_dir($base) || !is_writable($base)) {
            $base = storage_dir() . '/backups';
            if (!is_dir($base)) @mkdir($base, 0750, true);
        }
        if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $d = $sub === '' ? $base : $base . '/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

// ---------------------------------------------------------------- tables
function backup_ensure_tables(): void {
    static $done = false;
    if ($done) return;
    $sqlite = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $txt = $sqlite ? 'TEXT' : 'VARCHAR(255)';
    db()->exec("CREATE TABLE IF NOT EXISTS export_schedules (
        id INT PRIMARY KEY, name $txt NOT NULL, schedule_time VARCHAR(5) NOT NULL DEFAULT '09:00',
        export_type VARCHAR(20) NOT NULL DEFAULT 'daily', enabled INT NOT NULL DEFAULT 1,
        last_run_at DATETIME NULL, last_status VARCHAR(20) NULL, last_file $txt NULL, updated_at DATETIME NULL)$tail");
    db()->exec("CREATE TABLE IF NOT EXISTS export_history (
        id $pk, schedule_id INT NULL, export_type VARCHAR(20) NOT NULL, file_name $txt NOT NULL, file_path $txt NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0, row_count INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'completed',
        trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled', created_at DATETIME NOT NULL)$tail");
    foreach ([['export_schedules', 'last_started_at', 'DATETIME NULL'], ['export_schedules', 'last_success_at', 'DATETIME NULL'],
              ['export_schedules', 'last_message', 'TEXT NULL'], ['export_schedules', 'include_recordings', 'INT NOT NULL DEFAULT 0'],
              ['export_history', 'files_ok', 'INT NOT NULL DEFAULT 0'], ['export_history', 'files_failed', 'INT NOT NULL DEFAULT 0']] as [$t, $c, $def]) {
        try { db()->query("SELECT $c FROM $t LIMIT 0"); }
        catch (PDOException $e) { db()->exec("ALTER TABLE $t ADD COLUMN $c $def"); }
    }
    $have = db()->query('SELECT id FROM export_schedules')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([1 => ['Schedule 1 (Morning backup)', '09:00', 'daily'], 2 => ['Schedule 2 (Evening backup)', '18:00', 'weekly']] as $id => [$name, $time, $type]) {
        if (!in_array($id, array_map('intval', $have), true)) {
            db()->prepare('INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at) VALUES (?,?,?,?,1,?)')->execute([$id, $name, $time, $type, now()]);
        }
    }
    $done = true;
}

// ---------------------------------------------------------------- which students (Asia/Karachi)
/** [start, end] as 'Y-m-d H:i:s' in Pakistan time, or [null, null] for complete. */
function backup_period(string $type, ?DateTimeImmutable $at = null): array {
    $at = $at ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi'));
    $at = $at->setTimezone(new DateTimeZone('Asia/Karachi'));
    if ($type === 'daily') return [$at->setTime(0, 0, 0)->format('Y-m-d H:i:s'), $at->setTime(23, 59, 59)->format('Y-m-d H:i:s')];
    if ($type === 'weekly') {
        $mon = $at->modify('monday this week')->setTime(0, 0, 0);
        return [$mon->format('Y-m-d H:i:s'), $mon->modify('+6 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s')];
    }
    return [null, null];
}

/** Students created or changed (record, applications, files, meetings, tasks, followups) in the period; everyone for 'complete'. */
function backup_student_ids(string $type, ?DateTimeImmutable $at = null): array {
    [$a, $b] = backup_period($type, $at);
    $sql = 'SELECT s.id FROM students s';
    $p = [];
    if ($a !== null) {
        $in = fn(string $col) => "($col >= ? AND $col <= ?)";
        $w = [$in('s.created_at'), $in('s.updated_at')];
        foreach ([['applications', 'a', ['created_at', 'updated_at']], ['student_files', 'f', ['created_at']],
                  ['student_meetings', 'm', ['created_at', 'updated_at']], ['student_tasks', 't', ['updated_at']], ['student_followups', 'u', ['updated_at']]] as [$tbl, $al, $cols]) {
            $w[] = "EXISTS (SELECT 1 FROM $tbl $al WHERE $al.student_id = s.id AND (" . implode(' OR ', array_map(fn($c) => $in("$al.$c"), $cols)) . '))';
        }
        $sql .= ' WHERE ' . implode(' OR ', $w);
        // (start, end) pairs per condition: students 2, applications 2, files 1, meetings 2, tasks 1, followups 1
        foreach ([2, 2, 1, 2, 1, 1] as $pairs) for ($i = 0; $i < $pairs; $i++) { $p[] = $a; $p[] = $b; }
    }
    return array_map('intval', q($sql . ' ORDER BY s.country, s.sr_no IS NULL, s.sr_no, s.id', $p)->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------------------------------------------------------- schedules and history
function backup_schedules(): array { backup_ensure_tables(); return q('SELECT * FROM export_schedules ORDER BY id')->fetchAll(); }

function backup_save_schedule(int $id, string $time, string $type, bool $enabled, bool $rec): void {
    backup_ensure_tables();
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new InvalidArgumentException('Time must be HH:MM.');
    if (!isset(BACKUP_TYPES[$type])) throw new InvalidArgumentException('Unknown backup type.');
    q('UPDATE export_schedules SET schedule_time = ?, export_type = ?, enabled = ?, include_recordings = ?, updated_at = ? WHERE id = ?',
      [$time, $type, $enabled ? 1 : 0, $rec ? 1 : 0, now(), $id]);
}

function backup_history(int $limit = 20): array {
    backup_ensure_tables();
    return q("SELECT * FROM export_history WHERE file_name LIKE '%.zip' ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll();
}

/** Next run of a schedule as text (Pakistan time). */
function backup_next_run(array $s): string {
    if (empty($s['enabled'])) return 'Disabled';
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTimeImmutable('now', $tz);
    [$h, $m] = array_map('intval', explode(':', (string)$s['schedule_time']));
    $due = $now->setTime($h, $m, 0);
    $claimedToday = str_starts_with((string)($s['last_started_at'] ?? ''), $now->format('Y-m-d'));
    if (!$claimedToday && $now < $due) return 'Today at ' . $s['schedule_time'] . ' PKT';
    if (!$claimedToday && $now->getTimestamp() - $due->getTimestamp() <= BACKUP_CATCHUP_SECONDS) return 'Due now (starts on the next scheduler check)';
    return 'Tomorrow at ' . $s['schedule_time'] . ' PKT';
}

/** Start every schedule that is due and not yet started today. Safe to call as often as you like. */
function backup_tick(): array {
    backup_ensure_tables();
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTimeImmutable('now', $tz);
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $started = [];
    foreach (backup_schedules() as $s) {
        if (!(int)$s['enabled']) continue;
        [$h, $m] = array_map('intval', explode(':', (string)$s['schedule_time']));
        $late = $now->getTimestamp() - $now->setTime($h, $m, 0)->getTimestamp();
        if ($late < 0 || $late > BACKUP_CATCHUP_SECONDS) continue;
        // Atomic claim: only one process can move last_started_at into today, so no duplicate run.
        $st = db()->prepare('UPDATE export_schedules SET last_started_at = ? WHERE id = ? AND (last_started_at IS NULL OR last_started_at < ?)');
        $st->execute([$now->format('Y-m-d H:i:s'), (int)$s['id'], $dayStart]);
        if ($st->rowCount() !== 1) continue;
        $job = backup_start((string)$s['export_type'], 'scheduled', (int)$s['id'], 0, (bool)$s['include_recordings']);
        $started[] = $job;
    }
    return $started;
}

// ---------------------------------------------------------------- jobs
function backup_job_file(string $id): string { return backup_dir('jobs') . '/' . $id . '.json'; }
function backup_job_read(string $id): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || !is_file(backup_job_file($id))) return null;
    $j = json_decode((string)@file_get_contents(backup_job_file($id)), true);
    return is_array($j) ? $j : null;
}
function backup_job_write(array $job): void {
    $job['heartbeat'] = time();
    $tmp = backup_job_file($job['id']) . '.tmp';
    file_put_contents($tmp, json_encode($job));
    @rename($tmp, backup_job_file($job['id']));
}
/** A job still working: queued/running and alive (the worker updates a heartbeat). */
function backup_job_alive(array $j): bool {
    if (!in_array($j['state'] ?? '', ['queued', 'running'], true)) return false;
    return time() - (int)($j['heartbeat'] ?? 0) < ($j['state'] === 'queued' ? 45 : 180);
}
function backup_active_job(string $type): ?array {
    foreach (glob(backup_dir('jobs') . '/*.json') ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && ($j['type'] ?? '') === $type && backup_job_alive($j)) return $j;
    }
    return null;
}

function backup_php_cli(): string {
    global $CONFIG;
    if (!empty($CONFIG['php_cli'])) return (string)$CONFIG['php_cli'];
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY !== '') return PHP_BINARY;
    foreach ([PHP_BINDIR . '/php', PHP_BINDIR . '/php.exe', '/usr/bin/php', '/usr/local/bin/php'] as $c) if (is_file($c)) return $c;
    return 'php';
}

/** Queue a backup and start its background worker. Returns the job (an existing live job of the same type is reused: no duplicates). */
function backup_start(string $type, string $trigger, ?int $scheduleId, int $userId, bool $recordings = false, ?string $at = null): array {
    backup_ensure_tables();
    if (!isset(BACKUP_TYPES[$type])) throw new InvalidArgumentException('Unknown backup type.');
    if ($live = backup_active_job($type)) { $live['reused'] = true; return $live; }
    $id = bin2hex(random_bytes(12));
    $job = ['id' => $id, 'type' => $type, 'trigger' => $trigger, 'schedule_id' => $scheduleId, 'user' => $userId, 'recordings' => $recordings, 'at' => $at,
            'state' => 'queued', 'phase' => 'Waiting for the background worker', 'pct' => 0, 'students_total' => 0, 'students_done' => 0,
            'files_total' => 0, 'files_done' => 0, 'files_failed' => 0, 'created' => time(), 'heartbeat' => time()];
    backup_job_write($job);
    if ($scheduleId) q('UPDATE export_schedules SET last_status = ?, last_message = ? WHERE id = ?', ['running', 'Started ' . now(), $scheduleId]);
    $php = backup_php_cli();
    $worker = ROOT . '/backup_worker.php';
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $cmd = 'start /B "" "' . $php . '" "' . $worker . '" run ' . $id . ' > NUL 2>&1';
        $h = @popen($cmd, 'r'); if ($h) pclose($h);
    } else {
        @exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' run ' . $id . ' > /dev/null 2>&1 &');
    }
    return $job;
}

// ---------------------------------------------------------------- folder layout
function backup_student_folder(array $s): string {
    $n = preg_replace('/[^\p{L}\p{N}]+/u', '_', zip_clean_name((string)$s['name'])) ?? '';
    $n = trim($n, '_');
    return sprintf('Student_%03d_%s', (int)$s['id'], mb_substr($n !== '' ? $n : 'Student', 0, 60));
}

/** [folder inside the student folder, optional sub-folder] for a document key. Existing document categories are kept as sub-folders. */
function backup_doc_target(string $key): array {
    $k = doc_key_norm($key);
    $label = trim(preg_replace('/\s+/', ' ', str_replace('/', ' or ', (string)(DOC_ITEMS[$k][0] ?? DOC_LATER[$k][0] ?? ''))));
    return match (true) {
        $k === 'passport' => ['Documents/Passport', ''],
        $k === 'cnic'     => ['Documents/CNIC', ''],
        $k === 'photo'    => ['Images', ''],
        in_array($k, ['matric', 'inter', 'bachelor', 'transcript', 'english', 'hec', 'curriculum', 'aps', 'uni_assist', 'legalized'], true) => ['Documents/Academic_Documents', $label],
        in_array($k, ['cv', 'lor', 'sop', 'experience'], true) => ['Documents/Application_Documents', $label],
        in_array($k, ['offer', 'enrollment', 'campus_france', 'cimea'], true) => ['Documents/Offer_Letter', $label],
        in_array($k, ['bank', 'tax_docs', 'sponsor', 'blocked_account'], true) => ['Documents/Financial_Documents', $label],
        in_array($k, ['health_insurance', 'flight_hotel', 'visa_decision'], true) => ['Documents/Visa_Documents', $label],
        $k === 'consent_form' => ['Documents/Other_Documents', 'Signed consent form'],
        default => ['Documents/Other_Documents', ''],
    };
}

const BACKUP_EMPTY_DIRS = ['Basic_Information', 'Personal_Information', 'Education', 'Documents/Passport', 'Documents/CNIC', 'Documents/Academic_Documents',
    'Documents/Offer_Letter', 'Documents/Visa_Documents', 'Documents/Other_Documents', 'Images'];
const BACKUP_BASIC = ['id', 'country', 'sr_no', 'name', 'process_status', 'intake', 'received_date', 'branch', 'counselor', 'client_type', 'officer_id', 'back_officer_id',
    'entered_by', 'created_at', 'updated_at', 'updated_by', 'is_draft', 'draft_step', 'import_key'];
const BACKUP_PERSONAL = ['contact_no', 'student_email', 'recovery_email', 'portal_id', 'consent'];
const BACKUP_EDUCATION = ['study_level', 'applied_for', 'last_degree', 'gpa', 'ielts', 'shortlisted_course', 'admission_university'];

function backup_is_secret(string $col): bool {
    return str_ends_with($col, '_enc') || (FIELDS[$col][1] ?? '') === 'secret' || $col === 'password_hash';
}

function backup_rows(string $table, array $ids, string $order = 'id', string $cols = '*'): array {
    $out = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (q("SELECT $cols FROM $table WHERE student_id IN ($in) ORDER BY $order", $chunk) as $r) $out[(int)$r['student_id']][] = $r;
    }
    return $out;
}

function backup_pick(array $row, array $keys): array {
    $o = [];
    foreach ($keys as $k) if (array_key_exists($k, $row)) $o[$k] = $row[$k];
    return $o;
}

function backup_labelled(array $row, array $keys, string $country): array {
    $fields = country_fields($country);
    $o = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) continue;
        $o[$k] = ['label' => $fields[$k]['label'] ?? (FIELDS[$k][0] ?? ucfirst(str_replace('_', ' ', $k))), 'value' => $row[$k]];
    }
    return $o;
}

// ---------------------------------------------------------------- the worker
function backup_progress(array &$job, string $phase, ?int $pct = null): void {
    $job['phase'] = $phase;
    if ($pct !== null) $job['pct'] = $pct;
    backup_job_write($job);
}

function backup_run_job(string $jobId, bool $singleStep = false): void {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    ignore_user_abort(true);
    if (!$singleStep) { while (ob_get_level()) ob_end_clean(); }
    $job = backup_job_read($jobId);
    if (!$job || !in_array($job['state'], $singleStep ? ['queued', 'running'] : ['queued'], true)) return;
    $lock = fopen(backup_dir('locks') . '/' . $job['type'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (!$singleStep) {
            $job['state'] = 'failed'; $job['error'] = 'Another ' . $job['type'] . ' backup is already running.'; backup_job_write($job);
            backup_finish_schedule($job, 'skipped', null, $job['error']);
        }
        if ($lock) fclose($lock);
        return;
    }
    $part = backup_dir('tmp') . '/' . $jobId . '.zip.part';
    $sink = null;
    $stepping = false;
    try {
        $firstRun = ($job['state'] === 'queued');
        $job['state'] = 'running';
        if ($firstRun) {
            $job['started'] = time();
            backup_progress($job, 'Selecting students', 1);
        }
        $ids = backup_student_ids($job['type'], !empty($job['at']) ? new DateTimeImmutable($job['at'], new DateTimeZone('Asia/Karachi')) : null);
        $job['students_total'] = count($ids);
        $admin = ['id' => 0, 'role' => 'admin', 'team' => null, 'countries' => null, 'name' => 'Backup', 'username' => 'backup'];

        // ---- all rows, fetched in a few queries (no per-student query storms)
        if ($firstRun) backup_progress($job, 'Reading student records', 3);
        $students = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id
                        LEFT JOIN users b ON b.id = s.back_officer_id WHERE s.id IN ($in)", $chunk) as $r) $students[(int)$r['id']] = $r;
        }
        $apps = backup_rows('applications', $ids, 'list_name, id');
        $meetings = backup_rows('student_meetings', $ids, 'id');
        $tasks = backup_rows('student_tasks', $ids, 'id');
        $followups = backup_rows('student_followups', $ids, 'id');
        $progress = backup_rows('student_progress_logs', $ids, 'id');
        $notes = backup_rows('import_notes', $ids, 'id');
        $docState = backup_rows('student_documents', $ids, 'doc_key');
        $activity = backup_rows('activity_log', $ids, 'id', 'id, student_id, user_id, action, created_at');
        $files = backup_rows('student_files', $ids, 'doc_key, created_at, id');

        // ---- the real files: copy missing Google Drive files into the portal cache (parallel, in rounds)
        $driveRows = [];
        $fileCount = 0;
        foreach ($files as $list) foreach ($list as $f) {
            $isRec = !empty($f['meeting_id']) || str_starts_with((string)$f['doc_key'], 'meeting') || is_media_file($f);
            if ($isRec && !$job['recordings']) continue;
            $fileCount++;
            if (is_gdrive_file($f)) $driveRows[gdrive_file_id($f)] = $f;
        }
        $job['files_total'] = $fileCount;
        if ($firstRun) {
            foreach ($driveRows as $f) if (!gdrive_cached($f)) @unlink(gdrive_fail_path($f));   // retry earlier failures once per backup (cache marker only)
            backup_progress($job, 'Copying documents from Google Drive (0 of ' . count($driveRows) . ')', 3);
        }
        $stale = (int)($job['stale'] ?? 0);
        while (true) {
            $left = array_values(array_filter($driveRows, fn($f) => !gdrive_cached($f) && gdrive_recent_failure($f) === null));
            if (!$left || $stale >= 3) break;
            if (getenv('VERCEL') && ($free = @disk_free_space(backup_dir())) !== false && $free < 120 * 1048576) break;
            $before = count($left);
            gdrive_fetch_many(array_slice($left, 0, $singleStep ? 80 : 120), microtime(true) + ($singleStep ? 10 : 40), 12);
            $left = array_filter($driveRows, fn($f) => !gdrive_cached($f) && gdrive_recent_failure($f) === null);
            $stale = count($left) === $before ? $stale + 1 : 0;
            $job['stale'] = $stale;
            $done = count($driveRows) - count($left);
            $job['files_done'] = $done;
            backup_progress($job, 'Copying documents from Google Drive (' . $done . ' of ' . count($driveRows) . ')', 3 + (int)(55 * $done / max(1, count($driveRows))));
            if ($singleStep && $left && $stale < 3) {
                $stepping = true;
                return;
            }
        }

        // ---- build the ZIP in a temporary file
        backup_progress($job, 'Building the ZIP', 60);
        $sink = fopen($part, 'wb');
        if (!$sink) throw new RuntimeException('Cannot write to ' . backup_dir('tmp'));
        $zip = new ZipOut();
        $zip->sink = $sink;
        $failed = []; $index = []; $filesOk = 0; $recsSkipped = [];
        $n = 0;
        foreach ($ids as $sid) {
            $s = $students[$sid] ?? null;
            if (!$s) { $failed[] = ['student_id' => $sid, 'student' => '?', 'file' => '(student record)', 'reason' => 'student not found']; continue; }
            $n++;
            $country = (string)$s['country'];
            $root = backup_student_folder($s);
            foreach (BACKUP_EMPTY_DIRS as $d) $zip->addDir("$root/$d");

            $clean = [];
            foreach ($s as $col => $val) if (!backup_is_secret((string)$col)) $clean[$col] = $val;
            $grouped = []; $used = array_merge(BACKUP_BASIC, BACKUP_PERSONAL, BACKUP_EDUCATION, ['officer_name', 'back_officer_name']);
            foreach (country_groups($country) as $title => $g) {
                $keys = array_values(array_filter(array_keys($g['fields']), fn($k) => !in_array($k, $used, true) && array_key_exists($k, $clean)));
                if ($keys) $grouped[$title] = backup_labelled($clean, $keys, $country);
                $used = array_merge($used, $keys);
            }
            $other = array_values(array_diff(array_keys($clean), $used));
            if ($other) $grouped['Other fields'] = backup_labelled($clean, $other, $country);

            $basic = backup_pick($clean, BACKUP_BASIC) + ['front_officer' => $s['officer_name'], 'back_officer' => $s['back_officer_name']];
            $zip->addString("$root/Basic_Information/basic_information.json", json_encode($basic, BACKUP_JSON), time());
            $zip->addString("$root/Personal_Information/personal_information.json", json_encode(backup_labelled($clean, BACKUP_PERSONAL, $country), BACKUP_JSON), time());
            $zip->addString("$root/Education/education_information.json", json_encode(backup_labelled($clean, BACKUP_EDUCATION, $country), BACKUP_JSON), time());
            $zip->addString("$root/Processing_Details/processing_details.json", json_encode($grouped, BACKUP_JSON), time());
            $zip->addString("$root/Applications/applications.json", json_encode($apps[$sid] ?? [], BACKUP_JSON), time());
            $zip->addString("$root/Meetings/meetings.json", json_encode($meetings[$sid] ?? [], BACKUP_JSON), time());
            $zip->addString("$root/Tasks_And_Followups/tasks_and_followups.json", json_encode(['tasks' => $tasks[$sid] ?? [], 'followups' => $followups[$sid] ?? [], 'progress_logs' => $progress[$sid] ?? []], BACKUP_JSON), time());
            $zip->addString("$root/Documents/Document_Checklist.json", json_encode($docState[$sid] ?? [], BACKUP_JSON), time());
            try { $zip->addString("$root/Student_Details.pdf", student_details_pdf($admin, $s), time()); }
            catch (Throwable $e) { $failed[] = ['student_id' => $sid, 'student' => $s['name'], 'file' => 'Student_Details.pdf', 'reason' => 'could not be generated: ' . $e->getMessage()]; }

            // documents / images: the original files, original names
            $fileInfo = []; $sFail = 0;
            foreach ($files[$sid] ?? [] as $f) {
                $isRec = !empty($f['meeting_id']) || str_starts_with((string)$f['doc_key'], 'meeting') || is_media_file($f);
                $info = ['id' => (int)$f['id'], 'document' => $f['doc_key'], 'original_name' => $f['original_name'], 'mime' => $f['mime'], 'uploaded_at' => $f['created_at'],
                         'uploaded_by_role' => $f['uploaded_role'], 'version' => $f['version'] ?? null, 'meeting_id' => $f['meeting_id']];
                if (is_gdrive_file($f)) { $info['drive_file_id'] = gdrive_file_id($f); $info['drive_link'] = 'https://drive.google.com/file/d/' . gdrive_file_id($f) . '/view'; }
                if ($isRec && !$job['recordings']) {
                    $info['status'] = 'recording not included (option off)'; $recsSkipped[] = [$s['name'], $f['original_name']];
                    $fileInfo[] = $info; continue;
                }
                if ($isRec) { $dir = 'Meetings/Recordings'; $sub = ''; } else { [$dir, $sub] = backup_doc_target((string)$f['doc_key']); }
                $path = "$root/$dir/" . ($sub !== '' ? zip_clean_name($sub) . '/' : '') . $f['original_name'];
                $real = file_path($f);
                $why = null;
                if (is_gdrive_file($f) && !gdrive_cached($f)) $why = gdrive_recent_failure($f) ?? 'not copied from Google Drive (no result)';
                elseif (!is_file($real)) $why = 'the file is missing from the portal storage';
                if ($why === null) {
                    $ok = $zip->addFile($path, $real, strtotime((string)$f['created_at']) ?: null);
                    if (!$ok) $why = 'could not be read from storage (unreadable, or larger than 4 GB)';
                }
                if ($why !== null) {
                    $sFail++;
                    $failed[] = ['student_id' => $sid, 'student' => $s['name'], 'file' => $f['original_name'], 'document' => doc_label($country, (string)$f['doc_key']), 'reason' => $why,
                                 'drive_link' => $info['drive_link'] ?? null];
                    $info['status'] = 'NOT RETRIEVED: ' . $why;
                } else {
                    $filesOk++; $info['status'] = 'included'; $info['size_bytes'] = (int)filesize($real);
                }
                $fileInfo[] = $info;
            }
            $links = ['student_drive_folder' => $s['drive_link'] ?? null, 'student_documents_folder' => $s['documents_link'] ?? null, 'recording_note' => $s['recording_note'] ?? null,
                      'meeting_links' => array_map(fn($m) => ['title' => $m['title'], 'join_link' => $m['join_link'], 'recording_link' => $m['recording_link']], $meetings[$sid] ?? []),
                      'application_links' => array_map(fn($a) => ['university' => $a['university'], 'link' => $a['link']], $apps[$sid] ?? []), 'files' => $fileInfo];
            $zip->addString("$root/Documents/Document_Links_And_Files.json", json_encode($links, BACKUP_JSON), time());
            $mine = array_values(array_filter($failed, fn($x) => ($x['student_id'] ?? 0) === $sid));
            if ($mine) $zip->addString("$root/Documents/Files_Not_Retrieved.txt", implode("\r\n", array_map(fn($x) => $x['file'] . ' - ' . $x['reason'], $mine)) . "\r\n", time());

            $record = ['backup' => ['type' => $job['type'], 'created_at' => now(), 'note' => 'Stored account passwords are never exported.'],
                       'student' => $clean + ['officer_name' => $s['officer_name'], 'back_officer_name' => $s['back_officer_name']],
                       'applications' => $apps[$sid] ?? [], 'meetings' => $meetings[$sid] ?? [], 'tasks' => $tasks[$sid] ?? [], 'followups' => $followups[$sid] ?? [],
                       'progress_logs' => $progress[$sid] ?? [], 'import_notes' => $notes[$sid] ?? [], 'document_checklist' => $docState[$sid] ?? [],
                       'files' => $fileInfo, 'activity_history' => $activity[$sid] ?? []];
            $zip->addString("$root/Student_Record.json", json_encode($record, BACKUP_JSON), time());
            $index[] = ['student_id' => $sid, 'name' => $s['name'], 'country' => $country, 'folder' => $root, 'files_listed' => count($fileInfo), 'files_not_retrieved' => $sFail];

            $job['students_done'] = $n; $job['files_failed'] = count($failed);
            if ($n % 5 === 0 || $n === count($ids)) backup_progress($job, 'Building the ZIP: student ' . $n . ' of ' . count($ids), 60 + (int)(33 * $n / max(1, count($ids))));
        }

        // ---- report: nothing is skipped silently
        $summary = ['type' => $job['type'], 'period_pkt' => backup_period($job['type'], !empty($job['at']) ? new DateTimeImmutable($job['at'], new DateTimeZone('Asia/Karachi')) : null), 'created_at_pkt' => now(), 'students' => count($index), 'students_selected' => count($ids),
                    'files_included' => $filesOk, 'files_not_retrieved' => count(array_filter($failed, fn($x) => $x['file'] !== 'Student_Details.pdf')),
                    'recordings_not_included' => count($recsSkipped), 'passwords' => 'never exported', 'index' => $index];
        $zip->addString('_Backup_Report/Backup_Summary.json', json_encode($summary, BACKUP_JSON), time());
        $zip->addString('_Backup_Report/Files_Not_Retrieved.json', json_encode($failed, BACKUP_JSON), time());
        $txt = "Files that could not be put in this backup\r\n=========================================\r\n";
        $txt .= $failed ? '' : "None. Every file listed in the portal is in this backup.\r\n";
        foreach ($failed as $x) $txt .= "\r\n" . $x['student'] . ' (ID ' . $x['student_id'] . ")\r\n  - " . $x['file'] . ' - ' . $x['reason'] . (!empty($x['drive_link']) ? "\r\n    " . $x['drive_link'] : '') . "\r\n";
        if ($recsSkipped) $txt .= "\r\nMeeting recordings not included (" . count($recsSkipped) . "): the option 'include meeting recordings' was off; they are listed in each student's Student_Record.json.\r\n";
        $zip->addString('_Backup_Report/Files_Not_Retrieved.txt', $txt, time());
        $zip->addString('_Backup_Report/README.txt', "ScholarshipHive student backup\r\nOne folder per student: Student_<id>_<name>. Each holds JSON information, Student_Details.pdf and the original documents in Documents/ and Images/.\r\nGoogle Drive files are copied as they are; files that are not shared (\"Anyone with the link\") cannot be copied and are listed in Files_Not_Retrieved.txt.\r\nStored account passwords are never included.\r\n", time());
        $zip->finish();
        $entries = $zip->entries();
        fclose($sink); $sink = null;

        // ---- verify, then publish
        backup_progress($job, 'Checking the ZIP', 96);
        $chk = new ZipArchive();
        $rc = $chk->open($part, ZipArchive::CHECKCONS);
        if ($rc !== true || $chk->numFiles !== $entries) { $why = $rc === true ? "entry count {$chk->numFiles} != $entries" : "ZipArchive error $rc"; if ($rc === true) $chk->close(); throw new RuntimeException('The ZIP failed its check (' . $why . ')'); }
        $chk->close();
        $name = 'Student_Data_Backup_' . ucfirst($job['type']) . '_' . date('Ymd_His') . '.zip';
        $final = backup_dir('zips') . '/' . $name;
        if (!rename($part, $final)) throw new RuntimeException('Could not save the finished backup.');
        $size = (int)filesize($final);
        $failedFiles = (int)$summary['files_not_retrieved'];
        q("INSERT INTO export_history (schedule_id, export_type, file_name, file_path, file_size, row_count, status, trigger_type, created_at, files_ok, files_failed) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$job['schedule_id'], $job['type'], $name, $final, $size, count($index), 'completed', $job['trigger'], now(), $filesOk, $failedFiles]);
        $histId = (int)db()->lastInsertId();
        $job += ['history_name' => $name];
        $job['state'] = 'done'; $job['file'] = $name; $job['history_id'] = $histId; $job['size'] = $size; $job['files_ok'] = $filesOk; $job['files_failed'] = $failedFiles; $job['students_done'] = count($index);
        backup_progress($job, 'Finished', 100);
        backup_finish_schedule($job, 'success', $name, $failedFiles ? "$failedFiles file(s) could not be copied (see _Backup_Report)" : 'All files included');
        backup_prune();
    } catch (Throwable $e) {
        if (is_resource($sink)) fclose($sink);
        $job['state'] = 'failed'; $job['error'] = $e->getMessage();
        backup_progress($job, 'Failed: ' . $e->getMessage());
        backup_finish_schedule($job, 'failed', null, $e->getMessage());
        error_log('Backup job ' . $jobId . ' failed: ' . $e->getMessage());
    } finally {
        if (!$stepping && is_file($part)) @unlink($part);   // temporary file only
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function backup_finish_schedule(array $job, string $status, ?string $file, string $message): void {
    if (empty($job['schedule_id'])) return;
    if ($status === 'success') {
        q('UPDATE export_schedules SET last_status = ?, last_file = ?, last_run_at = ?, last_success_at = ?, last_message = ? WHERE id = ?', ['success', $file, now(), now(), $message, (int)$job['schedule_id']]);
    } else {
        q('UPDATE export_schedules SET last_status = ?, last_run_at = ?, last_message = ? WHERE id = ?', [$status, now(), $message, (int)$job['schedule_id']]);
    }
}

/** Housekeeping of OUR OWN generated files: old job files, stale temp ZIPs, and scheduled backups beyond the newest few per type. */
function backup_prune(): void {
    foreach (glob(backup_dir('jobs') . '/*.json*') ?: [] as $f) if (filemtime($f) < time() - 7 * 86400) @unlink($f);
    foreach (glob(backup_dir('tmp') . '/*.part') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
    foreach (array_keys(BACKUP_TYPES) as $type) {
        $rows = q("SELECT id, file_path FROM export_history WHERE trigger_type = 'scheduled' AND export_type = ? AND file_name LIKE '%.zip' ORDER BY id DESC", [$type])->fetchAll();
        foreach (array_slice($rows, BACKUP_KEEP_SCHEDULED) as $r) {
            $p = realpath((string)$r['file_path']);
            if ($p && str_starts_with($p, realpath(backup_dir('zips')) ?: '#')) @unlink($p);
            q('DELETE FROM export_history WHERE id = ?', [(int)$r['id']]);
        }
    }
}

/** Absolute path of a finished backup, only if it is really inside the private backup folder. */
function backup_zip_path(array $hist): ?string {
    $dir = realpath(backup_dir('zips'));
    $p = realpath((string)$hist['file_path']);
    return ($dir && $p && is_file($p) && str_starts_with($p, $dir . DIRECTORY_SEPARATOR) && str_ends_with($p, '.zip')) ? $p : null;
}

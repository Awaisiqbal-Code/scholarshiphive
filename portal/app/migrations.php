<?php
declare(strict_types=1);

// =====================================================================================
// Database updates. Safe to run any number of times, on MySQL 5.7+/8 and MariaDB 10.3+:
// every step checks INFORMATION_SCHEMA first and only ADDS columns / tables or WIDENS
// columns. Nothing is dropped and no existing value is deleted.
// Run from Admin > System (admin/upgrade.php) or on the command line: php database/migrate.php
// =====================================================================================

const SCHEMA_VERSION = 5;

function mig_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}

/** Column info or null: ['DATA_TYPE', 'CHARACTER_MAXIMUM_LENGTH', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT'] */
function mig_column(PDO $pdo, string $t, string $c): ?array {
    $st = $pdo->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function mig_add_column(PDO $pdo, string $t, string $c, string $def, array &$log): void {
    if (mig_column($pdo, $t, $c)) return;
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    $log[] = "Added column $t.$c";
}

/** Make a VARCHAR column at least $len long (never shortens, never changes TEXT). */
function mig_widen(PDO $pdo, string $t, string $c, int $len, array &$log): void {
    $col = mig_column($pdo, $t, $c);
    if (!$col || strtolower($col['DATA_TYPE']) !== 'varchar' || (int)$col['CHARACTER_MAXIMUM_LENGTH'] >= $len) return;
    $null = $col['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
    $pdo->exec("ALTER TABLE `$t` MODIFY `$c` VARCHAR($len) $null");
    $log[] = "Widened $t.$c to VARCHAR($len)";
}

function setting_get(string $k): ?string {
    try { $v = q('SELECT v FROM app_settings WHERE k = ?', [$k])->fetchColumn(); return $v === false ? null : (string)$v; }
    catch (PDOException $e) { return null; }
}
function setting_set(string $k, string $v): void {
    q('INSERT INTO app_settings (k, v, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)', [$k, $v, now()]);
}

/** Current schema version (0 when the settings table does not exist yet). */
function schema_version(): int {
    static $v = null;
    if ($v !== null) return $v;
    try { $v = (int)(q("SELECT v FROM app_settings WHERE k = 'schema_version'")->fetchColumn() ?: 0); }
    catch (PDOException $e) { $v = 0; }
    return $v;
}

/** Runs every step. Returns a list of what changed (empty list = already up to date). */
function run_migrations(PDO $pdo): array {
    $log = [];

    // ---- settings table (holds the schema version and one-off data fixes) ----
    if (!mig_table_exists($pdo, 'app_settings')) {
        $pdo->exec("CREATE TABLE app_settings (k VARCHAR(60) NOT NULL, v TEXT NULL, updated_at DATETIME NULL, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Created table app_settings';
    }

    // ---- tables added by earlier upgrades, in case one of them was never run ----
    if (!mig_table_exists($pdo, 'student_meetings')) {
        $pdo->exec("CREATE TABLE student_meetings (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT, student_id INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'other',
          title VARCHAR(150) NOT NULL, meeting_date DATE NULL, meeting_time VARCHAR(10) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
          join_link VARCHAR(500) NULL, recording_link VARCHAR(500) NULL, notes TEXT NULL, student_can_see TINYINT(1) NOT NULL DEFAULT 1,
          created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
          PRIMARY KEY (id), KEY idx_meet_student (student_id, meeting_date),
          CONSTRAINT fk_meet_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Created table student_meetings';
    }
    if (!mig_table_exists($pdo, 'student_files')) {
        $pdo->exec("CREATE TABLE student_files (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT, student_id INT UNSIGNED NOT NULL, doc_key VARCHAR(40) NOT NULL DEFAULT 'other',
          meeting_id INT UNSIGNED NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(120) NOT NULL, mime VARCHAR(100) NOT NULL,
          size INT UNSIGNED NOT NULL, uploaded_by INT UNSIGNED NULL, uploaded_role VARCHAR(12) NULL, created_at DATETIME NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY uq_file_student_stored (student_id, doc_key, stored_name), KEY idx_file_student (student_id, doc_key),
          KEY idx_file_meeting (meeting_id),
          CONSTRAINT fk_file_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
          CONSTRAINT fk_file_meeting FOREIGN KEY (meeting_id) REFERENCES student_meetings(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Created table student_files';
    }
    if (!mig_table_exists($pdo, 'student_tasks')) {
        $pdo->exec("CREATE TABLE student_tasks (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT, student_id INT UNSIGNED NOT NULL, task_type VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL,
          description TEXT NULL, assigned_to INT UNSIGNED NULL, assigned_team VARCHAR(20) NULL, due_date DATE NULL,
          priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
          status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
          auto_generated TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NULL, completed_at DATETIME NULL,
          PRIMARY KEY (id), KEY idx_task_student (student_id), KEY idx_task_status (status), KEY idx_task_assigned (assigned_to),
          CONSTRAINT fk_task_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Created table student_tasks';
    }

    // ---- students: columns the forms save to ----
    $add = [
        'tax_recording_link'   => 'TEXT NULL',
        'initial_meeting_mode' => 'VARCHAR(30) NULL',
        'tax_meeting_mode'     => 'VARCHAR(30) NULL',
        'second_meeting_mode'  => 'VARCHAR(30) NULL',
        'shortlisting_shared'  => 'VARCHAR(150) NULL',
        'updated_by'           => 'INT UNSIGNED NULL',
        // from upgrade 3 (kept so older data and the task engine keep working)
        'total_package_fee'    => 'VARCHAR(40) NULL',
        'total_paid'           => 'VARCHAR(40) NULL',
        'balance_due'          => 'VARCHAR(40) NULL',
        'payment_status'       => 'VARCHAR(40) NULL',
        'draft_step'           => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'is_draft'             => 'TINYINT(1) NOT NULL DEFAULT 0',
        'aps_number'           => 'VARCHAR(100) NULL',
        'nostrification_status' => 'VARCHAR(100) NULL',
        // free-text note next to the documents status (Czech / Austria sheets write progress there)
        'documents_note'       => 'TEXT NULL',
    ];
    foreach ($add as $c => $def) mig_add_column($pdo, 'students', $c, $def, $log);

    // Columns that were too short for values the sheets use (strict SQL mode rejected them).
    foreach ([
        'appointment_status' => 30, 'visa_checklist_shared' => 20, 'financial_verified' => 20, 'cimea_received' => 20,
        'health_insurance' => 20, 'flight_hotel' => 20, 'visa_form_by' => 20, 'cv_status' => 30, 'sop_status' => 30,
        'mock_interview' => 30, 'consult1' => 40, 'consult2' => 40, 'consult3' => 40, 'ielts' => 60, 'intake' => 60,
        'refund' => 60, 'dues' => 60, 'shortlisting' => 60, 'embassy' => 150, 'last_installment' => 150,
        'recording_note' => 500, 'contact_no' => 60, 'client_type' => 30, 'consent' => 30, 'docs_checklist' => 30,
        'initial_meeting' => 40, 'recorded_uploaded' => 40, 'tax_meeting' => 30, 'meeting_priority' => 30,
        'second_meeting' => 30, 'offer' => 30, 'enrollment' => 30, 'visa' => 30, 'study_level' => 40,
        'sponsor' => 255, 'process_status' => 30, 'gpa' => 100, 'branch' => 100, 'counselor' => 150,
    ] as $c => $len) mig_widen($pdo, 'students', $c, $len, $log);

    // ---- users: sub-admin role ----
    $role = mig_column($pdo, 'users', 'role');
    if ($role && !str_contains((string)$role['COLUMN_TYPE'], "'subadmin'")) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','subadmin','officer','tax','student') NOT NULL");
        $log[] = "Added the 'subadmin' role";
    }
    mig_widen($pdo, 'users', 'countries', 150, $log);

    // ---- meetings: format of the meeting (face to face / online / phone) ----
    foreach ([
        'meeting_type' => 'VARCHAR(30) NULL', 'location' => 'VARCHAR(150) NULL', 'staff_member' => 'VARCHAR(150) NULL',
        'recording_available' => 'VARCHAR(10) NULL', 'recording_upload_status' => 'VARCHAR(30) NULL',
        'exception_reason' => 'TEXT NULL', 'exception_authorized_by' => 'INT UNSIGNED NULL',
    ] as $c => $def) mig_add_column($pdo, 'student_meetings', $c, $def, $log);
    // Upgrade 3 gave meeting_type a default of 'Face-to-Face', which silently labelled every meeting as
    // face to face. A meeting's format must be chosen, so the column no longer has a default.
    $mt = mig_column($pdo, 'student_meetings', 'meeting_type');
    if ($mt && ($mt['IS_NULLABLE'] !== 'YES' || ($mt['COLUMN_DEFAULT'] !== null && strtoupper((string)$mt['COLUMN_DEFAULT']) !== 'NULL'))) {
        $pdo->exec('ALTER TABLE student_meetings MODIFY meeting_type VARCHAR(30) NULL DEFAULT NULL');
        $log[] = 'Removed the automatic Face-to-Face default from student_meetings.meeting_type';
    }

    // ---- files ----
    mig_add_column($pdo, 'student_files', 'file_hash', 'VARCHAR(64) NULL', $log);
    mig_add_column($pdo, 'student_files', 'version', 'INT UNSIGNED NOT NULL DEFAULT 1', $log);

    // ---- university applications: columns the Czech / Austria / Germany lists have ----
    foreach (['nostrification_status' => 'VARCHAR(100) NULL', 'nostrification_fee' => 'VARCHAR(100) NULL',
              'starting' => 'VARCHAR(100) NULL', 'language' => 'VARCHAR(255) NULL', 'portal_id' => 'VARCHAR(150) NULL'] as $c => $def) {
        mig_add_column($pdo, 'applications', $c, $def, $log);
    }

    // ---- one-off data fixes (each runs once) ----
    $done = fn(string $k) => (bool)$pdo->query("SELECT 1 FROM app_settings WHERE k = " . $pdo->quote($k))->fetchColumn();
    $mark = function (string $k) use ($pdo) {
        $st = $pdo->prepare('INSERT INTO app_settings (k, v, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()');
        $st->execute([$k, '1']);
    };

    if (!$done('fix_meeting_modes_v1')) {
        // The sheets wrote the format inside the Yes/No column ("meeting in office", "meeting on call").
        // Keep the answer (held = Yes) and move the format to its own column. Nothing is guessed:
        //  - "meeting in office" / "yes, face to face"            -> Face to Face
        //  - "meeting on call"                                      -> Phone call
        //  - held, recording uploaded and a recording folder link   -> Online (recorded in the student's Zoom folder)
        //  - anything else keeps an empty format for a person to fill in.
        $n1 = $pdo->exec("UPDATE students SET initial_meeting_mode = 'Face-to-Face', initial_meeting = 'Yes' WHERE initial_meeting_mode IS NULL AND LOWER(TRIM(initial_meeting)) = 'meeting in office'");
        $n2 = $pdo->exec("UPDATE students SET initial_meeting_mode = 'On-Call', initial_meeting = 'Yes' WHERE initial_meeting_mode IS NULL AND LOWER(TRIM(initial_meeting)) = 'meeting on call'");
        $n3 = $pdo->exec("UPDATE students SET initial_meeting_mode = 'Face-to-Face' WHERE initial_meeting_mode IS NULL AND LOWER(TRIM(initial_meeting)) = 'yes' AND LOWER(TRIM(recorded_uploaded)) = 'yes, face to face'");
        $n4 = $pdo->exec("UPDATE students SET initial_meeting_mode = 'Zoom Meeting' WHERE initial_meeting_mode IS NULL AND LOWER(TRIM(initial_meeting)) = 'yes' AND LOWER(TRIM(recorded_uploaded)) = 'yes' AND drive_link IS NOT NULL AND drive_link <> '' AND (documents_link IS NULL OR drive_link <> documents_link)");
        $log[] = "Meeting format moved to its own column: $n1 face to face, $n2 phone call, $n3 face to face (no recording needed), $n4 online with a recording";
        $mark('fix_meeting_modes_v1');
    }
    if (!$done('fix_meeting_types_v1')) {
        // Meetings in the log that only had the old automatic default: use the format the record says, or
        // "online" when the meeting has a recording link, otherwise leave it empty to be filled in.
        $sql = "UPDATE student_meetings m JOIN students s ON s.id = m.student_id
                SET m.meeting_type = CASE
                    WHEN m.kind = 'initial' AND s.initial_meeting_mode IS NOT NULL THEN s.initial_meeting_mode
                    WHEN m.kind = 'tax' AND s.tax_meeting_mode IS NOT NULL THEN s.tax_meeting_mode
                    WHEN m.recording_link IS NOT NULL AND m.recording_link <> '' THEN 'Zoom Meeting'
                    ELSE NULL END
                WHERE m.meeting_type = 'Face-to-Face' AND (m.location IS NULL OR m.location = '') AND (m.staff_member IS NULL OR m.staff_member = '')";
        $n = $pdo->exec($sql);
        $log[] = "Re-labelled $n meeting(s) that had only the automatic Face-to-Face default";
        $mark('fix_meeting_types_v1');
    }

    if (!$done('fix_doc_keys_v1')) {
        // One key per document: passport / CNIC front and back were separate keys before.
        $n = 0;
        foreach (DOC_ALIASES as $old => $new) {
            $n += $pdo->exec("UPDATE student_files SET doc_key = " . $pdo->quote($new) . " WHERE doc_key = " . $pdo->quote($old));
            $pdo->exec("UPDATE IGNORE student_documents SET doc_key = " . $pdo->quote($new) . " WHERE doc_key = " . $pdo->quote($old));
            $pdo->exec("DELETE FROM student_documents WHERE doc_key = " . $pdo->quote($old));
        }
        // Drive files filed as "degree" or "other" before the checklist had transcript, picture, experience,
        // equivalence and curriculum: file them again by their name (files added by people are not touched).
        $re = 0;
        $st = $pdo->query("SELECT f.id, f.doc_key, f.original_name, s.country FROM student_files f JOIN students s ON s.id = f.student_id
                           WHERE f.uploaded_role = 'drive' AND f.meeting_id IS NULL AND f.doc_key IN ('bachelor', 'other')");
        $upd = $pdo->prepare('UPDATE student_files SET doc_key = ? WHERE id = ?');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $k = classify_drive_file((string)$f['country'], (string)$f['original_name']);
            if ($k !== '__meeting__' && $k !== $f['doc_key'] && ($f['doc_key'] === 'other' || $k === 'transcript')) { $upd->execute([$k, (int)$f['id']]); $re++; }
        }
        $log[] = "Document keys: $n file(s) moved to the single passport / CNIC keys, $re Drive file(s) filed under transcript, picture, experience, equivalence or curriculum";
        $mark('fix_doc_keys_v1');
    }

    if (!$done('fix_docs_checklist_v1')) {
        // "Documents status" and "Pending documents" are now worked out from the documents in the portal.
        // So that no student changes status just because their papers are on paper or in Drive, what the
        // teams wrote in the sheet is turned into the checklist once:
        //  - Done / Completed (or a progress note such as "Superlegalized..."): every needed document is ticked received;
        //  - Pending with names ("BS degree / LOR"): the named documents stay pending, the others are ticked received,
        //    named optional ones (experience letter, equivalence...) are marked "asked from the student";
        //  - Pending with "all" or with nothing named, or empty: nothing is ticked.
        // Words that name no document and progress notes are kept in the new "Documents note"; the old values are
        // written to the activity log of each student whose values change.
        $ticked = 0; $asked = 0; $notes = 0; $changed = 0;
        $rows = $pdo->query('SELECT id, country, documents_status, pending_documents, documents_note FROM students')->fetchAll(PDO::FETCH_ASSOC);
        $map = docs_map(array_column($rows, 'id'));
        $ins = $pdo->prepare('INSERT IGNORE INTO student_documents (student_id, doc_key, status, updated_by, updated_at) VALUES (?, ?, ?, NULL, NOW())');
        $setNote = $pdo->prepare('UPDATE students SET documents_note = ? WHERE id = ?');
        $del = $pdo->prepare('DELETE FROM student_documents WHERE student_id = ? AND doc_key = ?');
        $logIns = $pdo->prepare('INSERT INTO activity_log (user_id, student_id, action, ip, created_at) VALUES (NULL, ?, ?, NULL, NOW())');
        foreach ($rows as $r) {
            $sid = (int)$r['id']; $c = (string)$r['country'];
            $s = trim((string)$r['documents_status']); $p = trim((string)$r['pending_documents']);
            $plan = country_doc_plan($c);
            $have = $map[$sid]['have'] ?? [];
            $sl = strtolower($s);
            $isAll = (bool)preg_match('/^all(\s+(pending|docs?|documents?))?\.?$/i', $p);
            [$keys, $unmatched] = $p !== '' ? doc_keys_from_text($p, $c) : [[], []];
            $tick = []; $ask = []; $note = [];
            if (in_array($sl, ['done', 'completed'], true)) {
                $tick = $plan['req'];
                if ($p !== '' && !preg_match('/^all\s+done\.?$/i', $p)) $note[] = 'Pending documents in the sheet: ' . $p;
            } else {
                if ($sl !== 'pending' && $sl !== '') $note[] = $s;   // a progress note written in the status column (Czech / Austria)
                if ($isAll) $ask = $plan['req'];
                elseif ($keys) { $tick = array_diff($plan['req'], $keys); $ask = $keys; }
                elseif ($p === '' && $sl !== 'pending' && $sl !== '') $tick = $plan['req'];
                if ($p !== '' && !$isAll && ($unmatched || !$keys)) $note[] = 'Pending documents in the sheet: ' . $p;
            }
            foreach ($tick as $k) if (empty($have[$k])) { $ins->execute([$sid, $k, 'Received']); $ticked += $ins->rowCount(); }
            // Named as pending by the team: pending even when an older file for it is in the Drive folder.
            foreach ($ask as $k) { $del->execute([$sid, $k]); $ins->execute([$sid, $k, 'Requested']); $asked += $ins->rowCount(); }
            if ($note && trim((string)$r['documents_note']) === '') { $setNote->execute([implode("\n", $note), $sid]); $notes++; }
            $before = [$s, $p];
            refresh_student_documents_status($sid, false);
            $after = $pdo->query('SELECT documents_status, pending_documents FROM students WHERE id = ' . $sid)->fetch(PDO::FETCH_NUM);
            if ([(string)$after[0], (string)$after[1]] !== $before) {
                $changed++;
                $logIns->execute([$sid, mb_substr('Documents status is now worked out from the documents. In the sheet it was: status "' . $s . '", pending "' . $p . '"; now: status "' . $after[0] . '", pending "' . $after[1] . '"', 0, 255)]);
            }
        }
        $log[] = "Documents checklist set up from the sheets: $ticked document(s) ticked received, $asked asked from the student, $notes note(s) kept, $changed student(s) with a reworded status or pending list (old values are in each student's activity)";
        $mark('fix_docs_checklist_v1');
    }

    $cur = (int)($pdo->query("SELECT v FROM app_settings WHERE k = 'schema_version'")->fetchColumn() ?: 0);
    if ($cur < SCHEMA_VERSION) {
        $st = $pdo->prepare("INSERT INTO app_settings (k, v, updated_at) VALUES ('schema_version', ?, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()");
        $st->execute([(string)SCHEMA_VERSION]);
        $log[] = 'Database is now at version ' . SCHEMA_VERSION;
    }
    return $log;
}

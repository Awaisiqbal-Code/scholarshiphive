<?php
declare(strict_types=1);

// =====================================================================================
// ScholarshipHive Automated Tasks & Notification Engine
// Automatically generates, updates, and completes operational tasks across:
//  - Missing documents
//  - Pending consent forms
//  - Overdue meetings
//  - Missing Zoom recordings
//  - Admission follow-ups
//  - Installment / payment reminders
//  - Visa appointments
// =====================================================================================

/**
 * Evaluates a student's current status and automatically syncs all automated tasks.
 */
function evaluate_student_tasks(int $studentId): array {
    $s = q("SELECT * FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$s) return [];

    $country = (string)($s['country'] ?? 'italy');
    $today = date('Y-m-d');
    $frontOfficer = !empty($s['officer_id']) ? (int)$s['officer_id'] : null;
    $backOfficer = !empty($s['back_officer_id']) ? (int)$s['back_officer_id'] : null;

    $tasksCreated = 0;
    $tasksResolved = 0;

    // Helper to create or resolve a task of a given type
    $syncTask = function(string $type, bool $condition, string $title, string $desc, ?int $assignedTo, string $assignedTeam, ?string $dueDate, string $priority = 'medium') use ($studentId, &$tasksCreated, &$tasksResolved) {
        $existing = q("SELECT id, status FROM student_tasks WHERE student_id = ? AND task_type = ? AND auto_generated = 1 ORDER BY id DESC LIMIT 1", [$studentId, $type])->fetch();
        if ($condition) {
            // Task SHOULD exist and be pending
            if (!$existing) {
                q("INSERT INTO student_tasks (student_id, task_type, title, description, assigned_to, assigned_team, due_date, priority, status, auto_generated, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?)",
                  [$studentId, $type, $title, $desc, $assignedTo, $assignedTeam, $dueDate, $priority, now()]);
                $tasksCreated++;
            } elseif ($existing['status'] === 'completed' || $existing['status'] === 'cancelled') {
                // Re-open if condition resurfaced
                q("UPDATE student_tasks SET status = 'pending', title = ?, description = ?, due_date = ?, updated_at = ? WHERE id = ?",
                  [$title, $desc, $dueDate, now(), (int)$existing['id']]);
            } else {
                // Update details
                q("UPDATE student_tasks SET title = ?, description = ?, due_date = ?, assigned_to = COALESCE(?, assigned_to), updated_at = ? WHERE id = ?",
                  [$title, $desc, $dueDate, $assignedTo, now(), (int)$existing['id']]);
            }
        } else {
            // Condition met / resolved -> auto complete task
            if ($existing && $existing['status'] === 'pending') {
                q("UPDATE student_tasks SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), (int)$existing['id']]);
                $tasksResolved++;
            }
        }
    };

    // 1. Pending Consent Form
    $needsConsent = in_array($country, ['italy', 'france', 'germany'], true);   // the sheets that have a consent column
    $consentPending = $needsConsent && !is_yes($s['consent'] ?? '');
    $syncTask(
        'pending_consent',
        $consentPending,
        'Pending Signed Consent Form',
        'Signed client consent form is required to open and process student file.',
        $frontOfficer,
        'frontend',
        $s['received_date'] ? date('Y-m-d', strtotime($s['received_date'] . ' +3 days')) : $today,
        'high'
    );

    // 2. Missing Documents Task
    $docDet = student_doc_detect($s);
    $hasMissing = !empty($docDet['missing']) && $docDet['status'] !== 'complete';
    $missingLabels = array_map(fn($m) => $m['label'], array_filter($docDet['missing'] ?? [], fn($m) => empty($m['is_optional'])));
    $missingStr = implode(', ', array_slice($missingLabels, 0, 5)) . (count($missingLabels) > 5 ? ' (+' . (count($missingLabels) - 5) . ' more)' : '');
    $syncTask(
        'missing_docs',
        $hasMissing && !empty($missingLabels),
        'Missing Required Documents: ' . ($missingStr ?: 'Pending Upload'),
        'Student has outstanding mandatory documents required for university application.',
        $backOfficer,
        'backend',
        date('Y-m-d', strtotime('+5 days')),
        'high'
    );

    // 3. Zoom Recording Missing Task
    // Online meetings only: a face-to-face or phone meeting needs no recording.
    $missingZoom = q("SELECT m.id FROM student_meetings m WHERE m.student_id = ? AND m.meeting_type = 'Zoom Meeting' AND m.status = 'Done'
                      AND (m.recording_link IS NULL OR m.recording_link = '') AND COALESCE(m.recording_upload_status, '') <> 'Exception Authorized'
                      AND NOT EXISTS (SELECT 1 FROM student_files f WHERE f.meeting_id = m.id)", [$studentId])->fetchAll();
    // The sheet's own columns: initial meeting held online but the recording is not uploaded to the student folder.
    if (!$missingZoom && meeting_held($s['initial_meeting'] ?? '') && ($s['initial_meeting_mode'] ?? '') === 'Zoom Meeting' && strtolower(trim((string)($s['recorded_uploaded'] ?? ''))) === 'no') $missingZoom = [1];
    $syncTask(
        'missing_zoom_recording',
        !empty($missingZoom),
        'Missing online meeting recording',
        'An online (Zoom / Meet) meeting is marked done without its recording. Upload the recording or record an approved exception in the Meetings panel.',
        $frontOfficer,
        'frontend',
        $today,
        'high'
    );

    // 4. Overdue Meeting Task
    $overdueMeetings = q("SELECT id, title, meeting_date FROM student_meetings WHERE student_id = ? AND status = 'Scheduled' AND meeting_date < ?", [$studentId, $today])->fetchAll();
    $syncTask(
        'meeting_overdue',
        !empty($overdueMeetings),
        'Overdue Scheduled Meeting',
        'Student has a scheduled meeting whose date has passed without being updated as Done or rescheduled.',
        $frontOfficer,
        'frontend',
        $today,
        'medium'
    );

    // 5. Tax Consultant Meeting Pending / Overdue
    $taxMeetingPending = ($country === 'italy') && !is_yes($s['tax_meeting'] ?? '');
    $taxDueDate = $s['meeting_date'] ?: ($s['follow_up_3'] ?: date('Y-m-d', strtotime('+7 days')));
    $syncTask(
        'tax_meeting_pending',
        $taxMeetingPending,
        '2nd Meeting (Tax Consultant) Pending',
        '2nd Meeting (Tax Consultant) for ISEE Parificato / scholarship documentation is pending.',
        null,
        'tax',
        $taxDueDate,
        ($s['meeting_priority'] ?? '') === 'Yes' ? 'high' : 'medium'
    );

    // 6. Payment / Installment Overdue Task
    $totalPackage = (float)preg_replace('/[^\d\.]/', '', (string)($s['total_package_fee'] ?? ''));
    $totalPaid = (float)preg_replace('/[^\d\.]/', '', (string)($s['total_paid'] ?? ''));
    $hasDues = ($s['payment_status'] ?? '') === 'Payment Overdue' || ($totalPackage > 0 && ($totalPackage - $totalPaid) > 0 && !empty($s['dues']));
    $syncTask(
        'payment_overdue',
        $hasDues,
        'Outstanding Consultancy Installment Due',
        'Student has an outstanding consultancy payment balance (' . ($s['balance_due'] ?: $s['dues']) . '). Follow up for collection.',
        $frontOfficer,
        'frontend',
        date('Y-m-d', strtotime('+3 days')),
        'medium'
    );

    // 7. Visa Appointment Approaching Task
    $visaApptUpcoming = !empty($s['appointment_date']) && empty($s['visa_submitted_date']) && $s['appointment_date'] >= $today && $s['appointment_date'] <= date('Y-m-d', strtotime('+7 days'));
    $syncTask(
        'visa_appointment_approaching',
        $visaApptUpcoming,
        'Visa Appointment Approaching: ' . ($s['appointment_date'] ?? ''),
        'Embassy visa filing appointment is scheduled in less than 7 days. Ensure all visa files and passports are ready.',
        $backOfficer,
        'backend',
        $s['appointment_date'] ?? $today,
        'high'
    );

    return ['created' => $tasksCreated, 'resolved' => $tasksResolved];
}

/**
 * Fetch tasks for one student.
 */
function student_tasks_list(int $studentId, string $status = ''): array {
    $sql = "SELECT t.*, u.name AS assigned_to_name FROM student_tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.student_id = ?";
    $params = [$studentId];
    if ($status !== '') {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY CASE t.priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, t.status = 'pending' DESC, t.due_date ASC, t.id DESC";
    return q($sql, $params)->fetchAll();
}

/**
 * Count active pending tasks for a student or globally.
 */
function student_pending_tasks_count(?int $studentId = null): int {
    if ($studentId !== null) {
        return (int)q("SELECT COUNT(*) FROM student_tasks WHERE student_id = ? AND status = 'pending'", [$studentId])->fetchColumn();
    }
    return (int)q("SELECT COUNT(*) FROM student_tasks WHERE status = 'pending'")->fetchColumn();
}

<?php
declare(strict_types=1);

// =====================================================================================
// Countries, document checklists, meetings and the student journey stages.
// The fields of each country form come from app/sheets.php (built from the country sheets).
// =====================================================================================

const COUNTRIES = [
    'italy'   => ['name' => 'Italy',          'code' => 'IT', 'sheet' => 'Front End + Back End Processing Team sheets'],
    'germany' => ['name' => 'Germany',        'code' => 'DE', 'sheet' => 'Germany (Erasmus) sheet'],
    'france'  => ['name' => 'France',         'code' => 'FR', 'sheet' => 'France Centralized Processing sheet 2027-28'],
    'czech'   => ['name' => 'Czech Republic', 'code' => 'CZ', 'sheet' => 'Czech Republic and Austria sheet (Czech part)'],
    'austria' => ['name' => 'Austria',        'code' => 'AT', 'sheet' => 'Czech Republic and Austria sheet (Austria part)'],
];

function country_name(?string $c): string { return COUNTRIES[$c]['name'] ?? ($c ? ucfirst($c) : 'Unknown'); }
function valid_country(string $c): bool { return isset(COUNTRIES[$c]); }

const LEGALIZATION = ['', 'Superlegalization complete', 'MOFA attested / legalization pending', 'Attestation not complete', 'Stop'];

// Document checklists, received / pending detection and upload staging: app/documents.php

// ---------------- Meetings ----------------
const MEETING_KINDS = [
    'initial' => '1st meeting (initial recorded meeting)',
    'tax'     => 'Tax consultant meeting',
    'second'  => '2nd processing meeting with client',
    'mock'    => 'Mock interview',
    'visa'    => 'Visa appointment',
    'other'   => 'Other meeting',
];

/** Student columns that summarise each meeting kind in the sheet: [done column, mode column, date column]. */
const MEETING_SUMMARY = [
    'initial' => ['initial_meeting', 'initial_meeting_mode', null],
    'tax'     => ['tax_meeting', 'tax_meeting_mode', 'meeting_date'],
    'second'  => ['second_meeting', 'second_meeting_mode', null],
];

const MEETING_STATUS = ['Scheduled', 'Done', 'Missed', 'Cancelled'];

function meeting_type_label(?string $t): string { return MEETING_TYPES[(string)$t] ?? ''; }

function drive_id(?string $u): string {
    if (!$u) return '';
    if (preg_match('~/(?:folders|file/d)/([A-Za-z0-9_-]{15,})~', $u, $m)) return $m[1];
    if (preg_match('~[?&]id=([A-Za-z0-9_-]{15,})~', $u, $m)) return $m[1];
    return strtolower(trim((string)strtok($u, '?')));
}

/**
 * Meetings that exist only as sheet columns (no row in the meetings log yet), so every meeting the
 * team recorded in the sheet is shown. Each item: title, kind, date, status, mode, link, note.
 */
function sheet_meetings(array $r, array $portalMeetings = []): array {
    $haveKind = [];
    foreach ($portalMeetings as $pm) $haveKind[$pm['kind'] ?? ''] = true;
    $out = [];
    $f = country_fields($r['country'] ?? 'italy');
    $recLink = safe_link($r['drive_link'] ?? null);
    if ($recLink && drive_id($recLink) === drive_id($r['documents_link'] ?? null) && !starts($r['recorded_uploaded'] ?? '', 'yes')) {
        $recLink = null;
    }
    $status = fn(?string $v) => meeting_held($v) ? 'Done' : (strtolower(trim((string)$v)) === 'no' ? 'Not done' : 'Not set');
    if (empty($haveKind['initial']) && (($r['initial_meeting'] ?? '') !== '' || $recLink || ($r['recording_note'] ?? '') !== '' || !empty($r['initial_meeting_mode']))) {
        $out[] = ['title' => MEETING_KINDS['initial'], 'kind' => 'initial', 'date' => meeting_held($r['initial_meeting'] ?? '') ? null : ($r['follow_up_1'] ?? null),
            'status' => $status($r['initial_meeting'] ?? ''), 'mode' => $r['initial_meeting_mode'] ?? null, 'link' => $recLink,
            'note' => trim((($r['recording_note'] ?? '') ? $r['recording_note'] : '') . (($r['recorded_uploaded'] ?? '') ? ' · Recording uploaded: ' . $r['recorded_uploaded'] : ''), ' ·')];
    }
    if (empty($haveKind['tax']) && (($r['tax_meeting'] ?? '') !== '' || !empty($r['meeting_date']) || !empty($r['follow_up_3']) || !empty($r['tax_meeting_mode']))) {
        $out[] = ['title' => MEETING_KINDS['tax'], 'kind' => 'tax', 'date' => $r['meeting_date'] ?: ($r['follow_up_3'] ?? null),
            'status' => $status($r['tax_meeting'] ?? ''), 'mode' => $r['tax_meeting_mode'] ?? null, 'link' => safe_link($r['tax_recording_link'] ?? null),
            'note' => ($r['meeting_priority'] ?? '') === 'Yes' ? 'Priority' : ''];
    }
    if (empty($haveKind['second']) && (($r['second_meeting'] ?? '') !== '' || !empty($r['follow_up_2']) || !empty($r['second_meeting_mode']))) {
        $out[] = ['title' => MEETING_KINDS['second'], 'kind' => 'second', 'date' => meeting_held($r['second_meeting'] ?? '') ? null : ($r['follow_up_2'] ?? null),
            'status' => $status($r['second_meeting'] ?? ''), 'mode' => $r['second_meeting_mode'] ?? null, 'link' => null, 'note' => ''];
    }
    if (empty($haveKind['mock']) && isset($f['mock_interview']) && ($r['mock_interview'] ?? '') !== '') {
        $out[] = ['title' => MEETING_KINDS['mock'], 'kind' => 'mock', 'date' => null, 'status' => $r['mock_interview'], 'mode' => null, 'link' => null, 'note' => ''];
    }
    if (empty($haveKind['visa']) && isset($f['appointment_date']) && (!empty($r['appointment_date']) || ($r['appointment_status'] ?? '') !== '')) {
        $out[] = ['title' => MEETING_KINDS['visa'], 'kind' => 'visa', 'date' => $r['appointment_date'] ?? null, 'status' => $r['appointment_status'] ?: 'Not set',
            'mode' => null, 'link' => null, 'note' => (string)($r['embassy'] ?? '')];
    }
    return $out;
}

/** "Done · Face to Face (in office)" style summary of one meeting column pair. */
function meeting_summary_text(array $r, string $kind): string {
    [$doneCol, $modeCol] = MEETING_SUMMARY[$kind];
    $v = trim((string)($r[$doneCol] ?? ''));
    if ($v === '') return 'Not set';
    $txt = meeting_held($v) ? 'Done' : 'Not done';
    $mode = meeting_type_label($r[$modeCol] ?? null);
    return $mode !== '' ? $txt . ' · ' . $mode : $txt;
}

function link_kind(?string $u): string {
    $u = strtolower((string)$u);
    return match (true) {
        str_contains($u, 'zoom.us')           => 'Zoom',
        str_contains($u, 'meet.google')       => 'Google Meet',
        str_contains($u, 'teams.microsoft')   => 'Teams',
        str_contains($u, 'drive.google.com/drive/folders') => 'Drive folder',
        str_contains($u, 'drive.google')      => 'Drive file',
        str_contains($u, 'docs.google')       => 'Google Docs',
        default => 'Link',
    };
}

// ---------------- Student Journey Stages per Country ----------------
function is_yes($v): bool { return strtolower(trim((string)$v)) === 'yes'; }
function starts($v, string $p): bool { return str_starts_with(strtolower(trim((string)$v)), strtolower($p)); }
function apps_n(array $r, array $results): int { $n = 0; foreach ($results as $x) $n += (int)($r['apps'][$x] ?? 0); return $n; }

function stages(string $c): array {
    static $cache = [];
    if (isset($cache[$c])) return $cache[$c];
    $applied = fn($r) => apps_n($r, ['Applied', 'Accepted', 'Conditional offer', 'Rejected']) > 0;
    $offerApp = fn($r) => apps_n($r, ['Accepted', 'Conditional offer']) > 0;
    $offer = fn($r) => offer_received($r) === true || $offerApp($r);

    $s = match ($c) {
        'italy' => [
            ['Consent signed', 'Front end', 'You sign the consent form so we can open your file.', fn($r) => is_yes($r['consent'] ?? '')],
            ['Docs checklist', 'Front end', 'We share the list of documents you need to collect.', fn($r) => is_yes($r['docs_checklist'] ?? '')],
            ['Initial meeting', 'Front end', 'Meeting with your counselor and our front-end team.', fn($r) => meeting_held($r['initial_meeting'] ?? '')],
            ['Recording uploaded', 'Front end', 'Your meeting recording is saved in your student folder (not needed for a face-to-face meeting).', fn($r) => starts($r['recorded_uploaded'] ?? '', 'yes') || (meeting_held($r['initial_meeting'] ?? '') && ($r['initial_meeting_mode'] ?? '') === 'Face-to-Face')],
            ['Tax consultant meeting', 'Front end', 'Meeting with the tax consultant to review your financial and sponsorship documents.', fn($r) => is_yes($r['tax_meeting'] ?? ''), 'tax'],
            ['Documents complete', 'Admission', 'All your documents are collected and checked.', fn($r) => is_docs_step_complete($r), 'docs'],
            ['University shortlisting', 'Admission', 'We shortlist universities and courses for you.', fn($r) => in_array(strtolower((string)($r['shortlisting'] ?? '')), ['done', 'shortlisting done'], true)],
            ['Offer received', 'Admission', 'Your university admission or offer letter arrives.', $offer, 'offer'],
            ['Enrollment summary', 'Admission', 'Your enrollment summary is received.', fn($r) => enrollment_received($r) === true],
            ['Visa checklist shared', 'Visa filing', 'We send you the visa document checklist.', fn($r) => is_yes($r['visa_checklist_shared'] ?? '')],
            ['Financial documents verified', 'Visa filing', 'Your financial and sponsor documents are received and checked.', fn($r) => is_yes($r['financial_verified'] ?? '')],
            ['CIMEA / DoV received', 'Visa filing', 'Your degree equivalence (CIMEA or DoV) is received.', fn($r) => is_yes($r['cimea_received'] ?? '')],
            ['Visa appointment booked', 'Visa filing', 'Your embassy appointment is booked.', fn($r) => in_array($r['appointment_status'] ?? '', ['Booked', 'Attended'], true)],
            ['Visa submitted', 'Visa filing', 'Your visa application is submitted at the embassy.', fn($r) => !empty($r['visa_submitted_date'])],
            ['Visa granted', 'Visa filing', 'Your visa decision has arrived.', fn($r) => ($r['visa'] ?? '') === 'Granted', 'visa'],
        ],
        'germany' => [
            ['Consent signed', 'Onboarding', 'You sign the consent form so we can open your file.', fn($r) => is_yes($r['consent'] ?? '')],
            ['Docs checklist', 'Onboarding', 'We share the list of documents you need to collect.', fn($r) => is_yes($r['docs_checklist'] ?? '')],
            ['Documents complete', 'Documents', 'All your documents are collected and checked.', fn($r) => is_docs_step_complete($r), 'docs'],
            ['Applications submitted', 'Admission', 'Your university applications are submitted.', $applied],
            ['Offer received', 'Admission', 'A university admission or offer arrives.', $offer, 'offer'],
            ['Embassy appointment', 'Visa', 'Your embassy appointment is received.', fn($r) => starts($r['embassy_status'] ?? '', 'appointment received')],
            ['Visa granted', 'Visa', 'Your visa decision has arrived.', fn($r) => ($r['visa'] ?? '') === 'Granted', 'visa'],
        ],
        'france' => [
            ['Consent form received', 'Onboarding', 'You sign the consent form so we can open your file.', fn($r) => is_yes($r['consent'] ?? '')],
            ['CV & SOP ready', 'Documents', 'Your CV and SOP are prepared for French universities.', fn($r) => ($r['cv_status'] ?? '') === 'Done' && ($r['sop_status'] ?? '') === 'Done'],
            ['Documents complete', 'Documents', 'All your documents are collected and checked.', fn($r) => is_docs_step_complete($r), 'docs'],
            ['Campus France portal complete', 'Campus France', 'Your Campus France portal is complete.', fn($r) => (bool)preg_match('/^(done|yes)/i', trim((string)($r['campus_france'] ?? '')))],
            ['Mock interview', 'Campus France', 'Practice interview for Campus France.', fn($r) => ($r['mock_interview'] ?? '') === 'Done'],
            ['Offer received', 'Admission', 'A university offer arrives.', $offer, 'offer'],
        ],
        'czech', 'austria' => [
            ['Courses shortlisted', 'Admission', 'We shortlist universities and courses for you.', fn($r) => trim((string)($r['shortlisted_course'] ?? '')) !== ''],
            ['Applied to universities', 'Admission', 'Your university applications are submitted.', $applied],
            ['Offer or conditional offer', 'Admission', 'A university accepts you.', fn($r) => $offerApp($r) || starts($r['admission_university'] ?? '', 'accepted'), 'offer'],
            ['Documents complete', 'Documents', 'All your documents are collected and checked.', fn($r) => is_docs_step_complete($r), 'docs'],
        ],
        default => [],
    };
    return $cache[$c] = $s;
}

function row_stages(array $r): array { return stages($r['country'] ?? 'italy'); }
function stage_done(array $r, int $i): bool { $s = row_stages($r); return isset($s[$i]) && ($s[$i][3])($r); }
function stage_key_done(array $r, string $key): bool {
    foreach (row_stages($r) as $i => $s) if (($s[4] ?? '') === $key) return ($s[3])($r);
    return false;
}
function done_count(array $r): int { $n = 0; foreach (row_stages($r) as $i => $_) if (stage_done($r, $i)) $n++; return $n; }

function current_index(array $r): ?int {
    if (($r['visa'] ?? '') === 'Refused' || ($r['process_status'] ?? '') === 'Stopped') return null;
    $s = row_stages($r);
    $last = -1;
    foreach ($s as $i => $_) if (stage_done($r, $i)) $last = $i;
    for ($i = $last + 1; $i < count($s); $i++) if (!stage_done($r, $i)) return $i;
    return null;
}
function next_stage(array $r): ?string { $i = current_index($r); return $i === null ? null : row_stages($r)[$i][0]; }
function stage_phase(array $r): string {
    if (($r['process_status'] ?? '') === 'Stopped') return 'Stopped';
    $i = current_index($r); return $i === null ? 'Closed' : row_stages($r)[$i][1];
}

function attach_app_counts(array $rows): array {
    if (!$rows) return $rows;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $m = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (q("SELECT student_id, result, COUNT(*) AS n FROM applications WHERE student_id IN ($in) GROUP BY student_id, result", $chunk) as $a) {
            $m[(int)$a['student_id']][$a['result'] ?? ''] = (int)$a['n'];
        }
    }
    foreach ($rows as &$r) { $r['apps'] = $m[(int)$r['id']] ?? []; $r['apps_total'] = array_sum($r['apps']); }
    return $rows;
}

function due_list(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (($r['process_status'] ?? '') === 'Stopped') continue;
        if (!empty($r['follow_up_1']) && !meeting_held($r['initial_meeting'] ?? '')) $out[] = ['r' => $r, 'date' => $r['follow_up_1'], 'what' => MEETING_KINDS['initial']];
        if (!is_yes($r['tax_meeting'] ?? '')) {
            $d = $r['meeting_date'] ?: ($r['follow_up_3'] ?? null);
            if ($d) $out[] = ['r' => $r, 'date' => $d, 'what' => MEETING_KINDS['tax']];
        }
        if (!is_yes($r['second_meeting'] ?? '') && !empty($r['follow_up_2'])) $out[] = ['r' => $r, 'date' => $r['follow_up_2'], 'what' => MEETING_KINDS['second']];
        if (!empty($r['appointment_date']) && empty($r['visa_submitted_date'])) $out[] = ['r' => $r, 'date' => $r['appointment_date'], 'what' => 'Visa appointment'];
    }
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

// ---------------- Who may see / edit which part of a student record ----------------

/** Countries where a front-end and a back-end team share each student (separate sheets per team). */
const TWO_TEAM_COUNTRIES = ['italy'];


/**
 * May this person edit a field owned by $team on this student?
 * basic = every officer of the student; frontend / backend = that team (Italy); tax = the tax
 * consultant and the Italy front-end team. France, Czech Republic, Austria and Germany are run
 * end to end by one processor, who edits the whole record of their students.
 */
function can_edit_team(array $u, string $team, array $r = []): bool {
    if (is_admin($u)) return true;
    if ($u['role'] === 'tax') return $team === 'tax';
    if ($u['role'] !== 'officer') return false;
    $mine = $u['team'] ?? '';
    if (in_array($r['country'] ?? 'italy', TWO_TEAM_COUNTRIES, true)) {
        // Italy: front-end and back-end teams each keep their own columns of the sheet.
        return match ($team) { 'basic' => true, 'tax' => $mine === 'frontend', default => $mine === $team };
    }
    // Other countries: one processor runs the whole file, so the officer assigned to the student edits all of it.
    $me = (int)$u['id'];
    return in_array($me, [(int)($r['officer_id'] ?? 0), (int)($r['back_officer_id'] ?? 0)], true) || $team === 'basic';
}

/** May this person see a field owned by $team (and of $type) on this student? */
function can_see_field(array $u, string $team, string $type, array $r = []): bool {
    if (is_admin($u)) return true;
    if ($type === 'secret') return $u['role'] === 'officer' && can_edit_team($u, 'backend', $r);
    if ($u['role'] === 'tax') return in_array($team, ['basic', 'tax', 'frontend', 'backend'], true);
    return $u['role'] === 'officer';
}

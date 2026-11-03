<?php
declare(strict_types=1);

// Builds a country's workbook (same tabs, same columns, same order as the team's sheet) from the
// database. Used by the Excel / CSV export and by the on-screen sheet view.

/** Students of one country that this person may see, in sheet order. */
function export_students(array $u, string $country): array {
    [$w, $p] = scope_sql($u);
    return q("SELECT s.*, o.name AS officer_name, b.name AS back_officer_name FROM students s
              LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users b ON b.id = s.back_officer_id
              WHERE s.country = ? AND $w ORDER BY s.sr_no IS NULL, s.sr_no, s.id", array_merge([$country], $p))->fetchAll();
}

function export_tab_filter(?string $filter, array $r, ?array $app = null): bool {
    if (!$filter) return true;
    $list = strtolower((string)($app['list_name'] ?? ''));
    if ($app && (str_contains($list, 'winter') || str_contains($list, 'summer'))) return str_contains($list, $filter);
    return str_contains(strtolower((string)($r['intake'] ?? '')), $filter);
}

/** One cell. $ctx: ['row' => n, 'student_no' => n, 'app_no' => n, 'first' => bool, 'passwords' => bool, 'html' => bool] */
function export_cell(string $src, array $r, ?array $a, array $ctx) {
    if (str_starts_with($src, 'a:')) {
        $k = substr($src, 2);
        return $a === null ? '' : (string)($a[$k] ?? '');
    }
    if (str_starts_with($src, '@first:')) {
        if (empty($ctx['first'])) return '';
        return export_cell(substr($src, 7), $r, null, $ctx);
    }
    if ($src[0] === '@') {
        $k = substr($src, 1);
        $yn = fn(?bool $b) => $b === null ? '' : ($b ? 'Yes' : 'No');
        return match ($k) {
            'sr'          => $r['sr_no'] !== null && $r['sr_no'] !== '' ? (int)$r['sr_no'] : (int)$ctx['row'],
            'row'         => (int)$ctx['row'],
            'student_no'  => !empty($ctx['first']) ? (int)$ctx['student_no'] : '',
            'app_no'      => (int)$ctx['app_no'],
            'blank'       => '',
            'front_officer' => (string)($r['officer_name'] ?? ''),
            'back_officer'  => (string)($r['back_officer_name'] ?? ''),
            'country_name'  => country_name($r['country'] ?? ''),
            'initial_meeting_sheet' => (function () use ($r) {
                $v = trim((string)($r['initial_meeting'] ?? ''));
                if ($v === '' || !meeting_held($v)) return $v;
                return match ($r['initial_meeting_mode'] ?? '') { 'Face-to-Face' => 'meeting in office', 'On-Call' => 'meeting on call', default => 'Yes' };
            })(),
            'second_meeting_sheet' => (function () use ($r) {
                $v = trim((string)($r['second_meeting'] ?? ''));
                if (!is_yes($v)) return $v;
                return match ($r['second_meeting_mode'] ?? '') { 'Face-to-Face' => 'Yes (face to face)', 'On-Call' => 'Yes (phone call)', default => 'Yes' };
            })(),
            'offer_yn'      => $yn(offer_received($r)),
            'enrollment_yn' => $yn(enrollment_received($r)),
            'drive_link_or_note' => (string)(($r['drive_link'] ?? '') ?: ($r['recording_note'] ?? '')),
            'visa_granted_refused' => in_array($r['visa'] ?? '', ['Granted', 'Refused'], true) ? $r['visa'] : '',
            'visa_pending_received' => match ($r['visa'] ?? '') { 'Pending' => 'Pending', 'Granted', 'Refused' => 'Received', default => (string)($r['visa'] ?? '') },
            'shortlisting_italy' => match ($r['shortlisting'] ?? '') { 'Done' => 'Shortlisting Done', 'Pending' => 'Shortlisting Pending', default => (string)($r['shortlisting'] ?? '') },
            'application_status' => (string)($r['application_status'] ?? ''),
            'consult_germany' => in_array($r['consult1'] ?? '', ['1st', '2nd', '3rd'], true) ? $r['consult1'] . ' Installment Received' : (string)($r['consult1'] ?? ''),
            'offer_germany' => match (offer_received($r)) { true => 'Offer Received', false => 'Offer Not Received', default => '' },
            // Czech / Austria: the sheet's documents column holds a progress note when there is one, else Done / Pending
            'docs_status_or_note' => trim((string)($r['documents_note'] ?? '')) !== '' ? trim((string)$r['documents_note']) : (string)($r['documents_status'] ?? ''),
            'consent_tick' => is_yes($r['consent'] ?? '') ? '✓' : (string)($r['consent'] ?? ''),
            default => '',
        };
    }
    $def = FIELDS[$src] ?? ['', 'text'];
    $v = $r[$src] ?? null;
    if ($v === null || $v === '') return '';
    return match ($def[1]) {
        'date'   => !empty($ctx['html']) ? fmt_date((string)$v) : ['date' => (string)$v],
        'secret' => !empty($ctx['passwords']) ? dec((string)$v) : '',
        'money'  => ctype_digit((string)$v) ? (int)$v : (string)$v,
        default  => (string)$v,
    };
}

/**
 * Workbook for one country: [tab title => ['headers' => [...], 'rows' => [[...], ...], 'ids' => [student id per row]]].
 */
function export_workbook(array $u, string $country, bool $passwords = false, bool $html = false): array {
    $students = export_students($u, $country);
    $apps = [];
    if ($students) {
        $ids = array_map(fn($r) => (int)$r['id'], $students);
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (q('SELECT * FROM applications WHERE student_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id', $chunk) as $a) $apps[(int)$a['student_id']][] = $a;
        }
    }
    $book = [];
    foreach (country_sheet_tabs($country) as $title => $tab) {
        $headers = array_map(fn($c) => $c[0], $tab['cols']);
        $rows = []; $ids = []; $n = 0; $sn = 0;
        foreach ($students as $r) {
            if ($tab['rows'] === 'students') {
                if (!export_tab_filter($tab['filter'] ?? null, $r)) continue;
                $n++;
                $ctx = ['row' => $n, 'passwords' => $passwords, 'html' => $html];
                $rows[] = array_map(fn($c) => export_cell($c[1], $r, null, $ctx), $tab['cols']);
                $ids[] = (int)$r['id'];
            } else {
                $list = array_values(array_filter($apps[(int)$r['id']] ?? [], fn($a) => export_tab_filter($tab['filter'] ?? null, $r, $a)));
                if (!$list) continue;
                $sn++;
                if (isset($tab['group_col'])) {   // "1. Student name" on its own row, as in the sheet
                    $g = array_fill(0, count($tab['cols']), '');
                    $g[$tab['group_col']] = $sn . '. ' . $r['name'];
                    $rows[] = $g;
                    $ids[] = (int)$r['id'];
                }
                foreach ($list as $i => $a) {
                    $n++;
                    $ctx = ['row' => $n, 'student_no' => $sn, 'app_no' => $i + 1, 'first' => $i === 0, 'passwords' => $passwords, 'html' => $html];
                    $rows[] = array_map(fn($c) => export_cell($c[1], $r, $a, $ctx), $tab['cols']);
                    $ids[] = (int)$r['id'];
                }
            }
        }
        $book[$title] = ['headers' => $headers, 'rows' => $rows, 'ids' => $ids];
    }
    return $book;
}

/** Plain text of a cell (CSV / HTML), with spreadsheet formula injection neutralised for CSV. */
function export_text($cell, bool $csv = false): string {
    $s = is_array($cell) ? fmt_date((string)($cell['date'] ?? '')) : (string)$cell;
    if ($csv && preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
    return $s;
}

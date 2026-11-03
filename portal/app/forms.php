<?php
declare(strict_types=1);

// =====================================================================================
// Field validation and rendering for the country student forms (definitions in sheets.php).
// =====================================================================================

/** Maximum lengths of the VARCHAR columns of a table, read from the database itself. */
function column_limits(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $out = [];
    try {
        foreach (q('SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $c) {
            $len = (int)$c['CHARACTER_MAXIMUM_LENGTH'];
            if ($len > 0) $out[$c['COLUMN_NAME']] = min($len, strtolower($c['DATA_TYPE']) === 'text' ? 60000 : $len);
        }
    } catch (PDOException $e) { /* fall back to generic limits */ }
    return $cache[$table] = $out;
}

/** Normalise a GPA / marks entry, or return null when it does not look like one. */
function normalize_gpa(string $v): ?string {
    $v = trim(preg_replace('/\s+/', ' ', $v));
    if ($v === '') return '';
    if (preg_match('/^pending$/i', $v)) return 'Pending';
    // 3.45  |  3.45/4  |  3.45 / 4.00  |  3.45 out of 4  |  8.2/10
    if (preg_match('/^(\d(?:\.\d{1,3})?)(?:\s*(?:\/|out\s+of)\s*(4|5|10)(?:\.0{1,2})?)?$/i', $v, $m)) {
        $g = (float)$m[1]; $scale = isset($m[2]) ? (int)$m[2] : null;
        if ($g <= 0 || $g > ($scale ?? 5)) return null;
        return $scale ? $m[1] . ' / ' . number_format($scale, 2) : $m[1];
    }
    if (preg_match('/^(\d{1,2}(?:\.\d{1,2})?)\s*\/\s*(10)(?:\.0{1,2})?$/', $v, $m) && (float)$m[1] <= 10 && (float)$m[1] > 0) return $m[1] . ' / 10.00';
    // 76% | 76.5 %
    if (preg_match('/^(\d{1,3}(?:\.\d{1,2})?)\s*%$/', $v, $m) && (float)$m[1] > 0 && (float)$m[1] <= 100) return $m[1] . '%';
    // marks: 812/1100
    if (preg_match('/^(\d{2,4})\s*\/\s*(\d{3,4})$/', $v, $m) && (int)$m[1] > 0 && (int)$m[1] <= (int)$m[2]) return $m[1] . '/' . $m[2];
    return null;
}

/**
 * Validate one submitted value. Returns [value to store (null = empty), error message or null].
 * $cur is the value already stored, so older values outside a list are kept, never wiped.
 */
function validate_field(string $key, array $def, string $raw, ?string $cur, ?int $limit): array {
    $label = $def['label'];
    $type = $def['type'];
    $v = $type === 'textarea' ? trim(str_replace("\r\n", "\n", $raw)) : trim(preg_replace('/\s+/u', ' ', $raw));
    $cur = $cur === null ? '' : (string)$cur;
    if ($v === '') return [null, null];

    switch ($type) {
        case 'email':
            $v = strtolower($v);
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return [$v, "$label is not a valid email address."];
            break;
        case 'phone':
            $digits = preg_replace('/\D/', '', $v);
            if (!preg_match('/^\+?[\d\s\-()]+$/', $v) || strlen($digits) < 7 || strlen($digits) > 15) return [$v, "$label must be a phone number (7 to 15 digits, e.g. 0300 1234567 or +92 300 1234567)."];
            break;
        case 'url':
            if (!safe_link($v)) return [$v, "$label must be a full link starting with https://"];
            break;
        case 'date':
            if (!valid_date($v)) return [$v, "$label is not a valid date."];
            $y = (int)substr($v, 0, 4);
            if ($y < 1990 || $y > 2100) return [$v, "$label has an unlikely year ($y)."];
            break;
        case 'money':
            $n = preg_replace('/[\s,]|^rs\.?|pkr/i', '', $v);
            if (!preg_match('/^\d{1,12}$/', $n)) return [$v, "$label must be an amount in numbers (e.g. 100000)."];
            $v = $n;
            break;
        case 'gpa':
            $g = normalize_gpa($v);
            if ($g === null) return [$v, "$label must look like 3.45, 3.45/4, 76%, 812/1100 or Pending."];
            $v = $g;
            break;
        case 'yn':
            $m = ['yes' => 'Yes', 'y' => 'Yes', 'no' => 'No', 'n' => 'No'][strtolower($v)] ?? null;
            if ($m === null) { if ($v === $cur) return [$v, null]; return [$v, "Choose Yes or No for $label."]; }
            $v = $m;
            break;
        case 'select':
            $opts = $def['options'] ?: [];
            if (!in_array($v, $opts, true)) {
                if ($v === $cur) return [$v, null];   // an older value from the sheet stays as it is
                return [$v, "Choose a value from the list for $label."];
            }
            break;
        case 'meeting_mode':
            if (!isset(MEETING_TYPES[$v])) return [$v, "Choose how the meeting was held for $label."];
            break;
    }
    $max = $type === 'textarea' ? min($limit ?: 5000, 5000) : min($limit ?: 500, $type === 'url' ? 2000 : 500);
    if (mb_strlen($v) > $max) return [$v, "$label is too long (at most $max characters)."];
    return [$v, null];
}

/** Officers who may be assigned to a student of this country: [id => label]. */
function officer_choices(string $country, string $team, ?int $current = null): array {
    $out = [];
    foreach (q("SELECT id, name, team, countries, active FROM users WHERE role = 'officer' ORDER BY team = ? DESC, name", [$team]) as $o) {
        $cs = array_filter(explode(',', (string)$o['countries']));
        $fits = !$cs || in_array($country, $cs, true);
        if ((int)$o['id'] !== $current && (!(int)$o['active'] || !$fits)) continue;
        $out[(int)$o['id']] = $o['name'] . ($o['team'] !== $team ? ' (' . ($o['team'] === 'backend' ? 'back end' : 'front end') . ')' : '') . ((int)$o['active'] ? '' : ' (disabled)');
    }
    return $out;
}

/** Suggestions for combo fields: the list in the definition plus the values already used for this country. */
function combo_suggestions(string $key, array $def, string $country): array {
    static $cache = [];
    $ck = $key . '|' . $country;
    if (!isset($cache[$ck])) {
        $vals = array_filter($def['options'] ?: []);
        try {
            foreach (q("SELECT DISTINCT `$key` v FROM students WHERE country = ? AND `$key` IS NOT NULL AND `$key` <> '' ORDER BY `$key` LIMIT 60", [$country]) as $x) $vals[] = trim((string)$x['v']);
        } catch (PDOException $e) { /* column missing on an old database: no suggestions */ }
        $cache[$ck] = array_values(array_unique(array_filter($vals)));
    }
    return $cache[$ck];
}

/**
 * Render one field. $state: ['value' => current, 'error' => msg|null, 'edit' => bool, 'country' => c, 'officers' => [...]].
 */
function render_field(string $key, array $def, array $state): string {
    $type = $def['type'];
    $val = (string)($state['value'] ?? '');
    $edit = !empty($state['edit']);
    $err = $state['error'] ?? null;
    $id = 'f_' . $key;
    $wide = in_array($type, ['textarea', 'url', 'meeting_mode'], true) || in_array($key, ['name', 'last_degree', 'pending_documents', 'financial_missing', 'admission_university', 'shortlisted_course', 'refund_reason', 'application_status', 'documents_status'], true) && $type !== 'select';
    // The browser checks required fields before sending, except on older records that never had the value
    // ('strict' => false): those are saved with a reminder, as the server does.
    $strict = $def['req'] && ($state['strict'] ?? true);
    $attrs = ' id="' . $id . '" name="' . h($key) . '"' . ($edit ? '' : ' disabled') . ($strict ? ' data-req="1"' : '') . ' data-label="' . h($def['label']) . '"';
    $o = '<div class="f' . ($wide ? ' wide' : '') . ($err ? ' has-err' : '') . '" data-field="' . h($key) . '">';
    $o .= '<label for="' . $id . '"><span>' . h($def['label']) . ($def['req'] ? ' <b class="req" title="Required">*</b>' : '') . '</span></label>';

    switch ($type) {
        case 'textarea':
            $o .= '<textarea' . $attrs . ' rows="2">' . h($val) . '</textarea>';
            break;
        case 'yn':
        case 'select':
            $opts = $type === 'yn' ? YN : ($def['options'] ?: ['']);
            $o .= '<select' . $attrs . '>';
            foreach ($opts as $ov) $o .= '<option value="' . h($ov) . '"' . ($val === $ov ? ' selected' : '') . '>' . ($ov === '' ? 'Not set' : h($ov)) . '</option>';
            if ($val !== '' && !in_array($val, $opts, true)) $o .= '<option value="' . h($val) . '" selected>' . h($val) . ' (as written in the sheet)</option>';
            $o .= '</select>';
            break;
        case 'meeting_mode':
            $o .= '<div class="radios" role="radiogroup" aria-label="' . h($def['label']) . '">';
            foreach (['' => 'Not set'] + MEETING_TYPES as $mk => $ml) {
                $o .= '<label class="check"><input type="radio" name="' . h($key) . '" value="' . h($mk) . '"' . ($val === $mk ? ' checked' : '') . ($edit ? '' : ' disabled') . ' data-mode-for="' . h($key) . '"> ' . h($ml) . '</label>';
            }
            $o .= '</div>';
            break;
        case 'front_officer':
        case 'back_officer':
            $list = $state['officers'] ?? [];
            $o .= '<select' . $attrs . '><option value="">Not assigned</option>';
            foreach ($list as $oid => $on) $o .= '<option value="' . (int)$oid . '"' . ((string)$oid === $val ? ' selected' : '') . '>' . h($on) . '</option>';
            $o .= '</select>';
            break;
        case 'secret':
            $saved = $val !== '';
            $o .= '<input type="text"' . $attrs . ' value="" autocomplete="new-password" placeholder="' . ($saved ? 'Saved. Type a new one to change it' : 'Not saved') . '">';
            if ($saved && $edit) {
                $o .= '<details class="reveal"><summary>Show saved password</summary><code>' . h(dec($val)) . '</code></details>'
                    . '<label class="check small"><input type="checkbox" name="' . h($key) . '__clear" value="1"> Remove the saved password</label>';
            }
            break;
        case 'combo':
            $sugg = combo_suggestions($key, $def, (string)($state['country'] ?? ''));
            $o .= '<input type="text"' . $attrs . ' value="' . h($val) . '" list="dl_' . h($key) . '" autocomplete="off">';
            if ($sugg) {
                $o .= '<datalist id="dl_' . h($key) . '">';
                foreach ($sugg as $sv) $o .= '<option value="' . h($sv) . '">';
                $o .= '</datalist>';
            }
            break;
        default:
            $input = match ($type) { 'date' => 'date', 'email' => 'email', 'url' => 'url', 'phone' => 'tel', default => 'text' };
            $extra = match ($type) {
                'money' => ' inputmode="numeric" placeholder="e.g. 100000"',
                'gpa'   => ' placeholder="e.g. 3.45 or 3.45/4 or 76%"',
                'phone' => ' placeholder="e.g. 0300 1234567"',
                'url'   => ' placeholder="https://..."',
                default => '',
            };
            $o .= '<input type="' . $input . '"' . $attrs . ' value="' . h($val) . '"' . $extra . '>';
    }
    if (!empty($def['col'])) $o .= '<small class="col-hint">Sheet: ' . h($def['col']) . '</small>';
    if ($err) $o .= '<small class="ferr" role="alert">' . h($err) . '</small>';
    return $o . '</div>';
}

/** Value of a field for reading (record view, export, logs). */
function field_display(string $key, array $def, array $r): string {
    $v = $r[$key] ?? null;
    if ($v === null || $v === '') return '';
    return match ($def['type']) {
        'date'         => fmt_date((string)$v),
        'secret'       => 'Saved',
        'money'        => rs((string)$v),
        'meeting_mode' => meeting_type_label((string)$v),
        'front_officer' => (string)($r['officer_name'] ?? ''),
        'back_officer' => (string)($r['back_officer_name'] ?? ''),
        default        => (string)$v,
    };
}

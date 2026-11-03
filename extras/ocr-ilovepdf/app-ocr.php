<?php
declare(strict_types=1);

// =====================================================================================
// OCR with the iLovePDF API (https://www.iloveapi.com/docs/api-reference).
// Every call is made from this server; the keys are read from the environment
// (portal/app/.env, see .env.example) and never reach the browser.
//
// Flow for one document:
//   image (jpg/png)  -> tool "imagepdf" -> PDF
//   PDF              -> tool "extract"  -> text            (digital PDFs already contain text)
//   too little text  -> tool "pdfocr"   -> searchable PDF -> tool "extract" -> text
// Each tool is one task: start -> upload -> process -> download.
// Authentication: a JWT signed on this server with the secret key (HS256), as in the official SDK.
// =====================================================================================

final class OcrError extends RuntimeException {
    /** kind: config | upload | auth | limit | quality | password | network | api */
    public function __construct(public readonly string $kind, string $message) { parent::__construct($message); }
}

function ocr_public_key(): string { return env('ILOVEPDF_PUBLIC_KEY'); }
function ocr_secret_key(): string { return env('ILOVEPDF_SECRET_KEY'); }
function ocr_configured(): bool { return ocr_public_key() !== '' && ocr_secret_key() !== ''; }
function ocr_region(): string {
    $r = strtolower(env('ILOVEPDF_REGION', 'eu'));
    return in_array($r, ['eu', 'us', 'fr', 'de', 'pl', 'in', 'sg'], true) ? $r : 'eu';
}
function ocr_languages(): array {
    $l = array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', env('ILOVEPDF_OCR_LANGUAGES', 'eng'))), fn($x) => preg_match('/^[a-z_]{3,10}$/', $x)));
    return $l ?: ['eng'];
}

/** API base. ILOVEPDF_API_BASE exists only to point the client at a test double; leave it unset in production. */
function ocr_base(?string $server = null): string {
    $override = rtrim(env('ILOVEPDF_API_BASE'), '/');
    if ($override !== '') return $override;
    return $server ? 'https://' . preg_replace('/[^A-Za-z0-9.\-]/', '', $server) : 'https://api.ilovepdf.com';
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Self-signed token (claims as in the official iLovePDF PHP SDK; 90-minute clock-skew margin). */
function ilovepdf_token(): string {
    $t = time(); $skew = 5400;
    $head = b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $body = b64url(json_encode(['iss' => '', 'aud' => '', 'iat' => $t - $skew, 'nbf' => $t - $skew, 'exp' => $t + 3600 + $skew, 'jti' => ocr_public_key()]));
    return "$head.$body." . b64url(hash_hmac('sha256', "$head.$body", ocr_secret_key(), true));
}

/** One HTTP call. Returns [status, raw body]. Throws OcrError on network failure. */
function ilovepdf_http(string $method, string $url, array|string|null $body = null, int $timeout = 120): array {
    if (!function_exists('curl_init')) throw new OcrError('config', 'The PHP cURL extension is not enabled on this server. Enable it in cPanel > Select PHP Version > Extensions.');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . ilovepdf_token(), 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new OcrError('network', 'Could not reach iLovePDF (' . ($err ?: 'network error') . '). Check the server internet connection and try again.');
    return [$status, (string)$raw];
}

/** Error text from either of the two error shapes iLovePDF returns. */
function ilovepdf_error_text(string $raw): string {
    $j = json_decode($raw, true);
    if (!is_array($j)) return trim(mb_substr(strip_tags($raw), 0, 200));
    $e = $j['error'] ?? $j;
    $parts = array_filter([is_string($e['type'] ?? null) ? $e['type'] : null, is_string($e['message'] ?? null) ? $e['message'] : null,
                           is_string($e['name'] ?? null) ? $e['name'] : null]);
    if (!empty($e['param']) && is_array($e['param'])) $parts[] = json_encode($e['param']);
    return implode(': ', array_unique($parts));
}

/** Turn a non-200 answer into a clear message. */
function ilovepdf_fail(int $status, string $raw, string $step): never {
    $why = ilovepdf_error_text($raw);
    $low = strtolower($why);
    if ($status === 401) {
        if (str_contains($low, 'expire')) throw new OcrError('auth', 'iLovePDF says the signed token has expired. Check that the server clock is correct, then try again.');
        throw new OcrError('auth', 'iLovePDF rejected the API keys (401' . ($why ? ': ' . $why : '') . '). Check ILOVEPDF_PUBLIC_KEY and ILOVEPDF_SECRET_KEY in portal/app/.env.');
    }
    if ($status === 429) throw new OcrError('limit', 'iLovePDF limit reached (too many requests or no files left this month). Wait a minute and try again, or check the plan in the iLovePDF console.');
    if ($status === 402 || str_contains($low, 'credit') || str_contains($low, 'limit') || str_contains($low, 'quota')) {
        throw new OcrError('limit', 'iLovePDF has no processing credits left for this project (' . $why . '). Check the plan in the iLovePDF console.');
    }
    if (str_contains($low, 'password')) throw new OcrError('password', 'This PDF is password protected. Remove the password (or upload an unprotected copy) and scan again.');
    if ($status >= 500) throw new OcrError('api', 'iLovePDF had a problem on its side while ' . $step . ' (HTTP ' . $status . '). Try again in a few minutes.');
    if ($step === 'uploading') throw new OcrError('upload', 'iLovePDF did not accept the file (' . ($why ?: 'HTTP ' . $status) . '). Upload a PDF, JPG or PNG under the size limit.');
    throw new OcrError('api', 'iLovePDF could not finish ' . $step . ' (HTTP ' . $status . ($why ? ': ' . $why : '') . ').');
}

/** Runs one iLovePDF tool on one local file and returns the output file's bytes. */
function ilovepdf_run_tool(string $tool, string $filePath, string $fileName, array $params = []): string {
    // 1) start
    [$st, $raw] = ilovepdf_http('GET', ocr_base() . '/v1/start/' . $tool . '/' . ocr_region(), null, 40);
    if ($st !== 200) ilovepdf_fail($st, $raw, 'starting the task');
    $start = json_decode($raw, true);
    if (!is_array($start) || empty($start['server']) || empty($start['task'])) throw new OcrError('api', 'iLovePDF returned an unexpected answer when starting the task.');
    if (isset($start['remaining_credits']) && is_numeric($start['remaining_credits']) && (int)$start['remaining_credits'] <= 0) {
        throw new OcrError('limit', 'iLovePDF has no processing credits left for this project this month.');
    }
    $base = ocr_base((string)$start['server']);
    $task = (string)$start['task'];

    // 2) upload
    [$st, $raw] = ilovepdf_http('POST', $base . '/v1/upload', ['task' => $task, 'file' => new CURLFile($filePath, null, $fileName)], 180);
    if ($st !== 200) ilovepdf_fail($st, $raw, 'uploading');
    $up = json_decode($raw, true);
    if (!is_array($up) || empty($up['server_filename'])) throw new OcrError('upload', 'iLovePDF did not confirm the upload. Try again.');

    // 3) process (form fields, nested arrays as files[0][server_filename], ocr_languages[0] ...)
    $form = array_merge(['task' => $task, 'tool' => $tool,
        'files' => [['server_filename' => $up['server_filename'], 'filename' => $fileName]]], $params);
    [$st, $raw] = ilovepdf_http('POST', $base . '/v1/process', http_build_query($form), 240);
    if ($st !== 200) ilovepdf_fail($st, $raw, 'processing');

    // 4) download
    [$st, $raw] = ilovepdf_http('GET', $base . '/v1/download/' . rawurlencode($task), null, 180);
    if ($st !== 200) ilovepdf_fail($st, $raw, 'downloading the result');
    return $raw;
}

/** The text inside the "extract" tool's output (a .txt file, or a ZIP of them for several files). */
function ocr_text_from_output(string $bytes): string {
    if (str_starts_with($bytes, "PK\x03\x04") && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'ocrz');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $out = '';
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string)$zip->getNameIndex($i);
                if (preg_match('/\.(txt|csv)$/i', $n)) $out .= (string)$zip->getFromIndex($i) . "\n";
            }
            $zip->close();
        }
        @unlink($tmp);
        return $out;
    }
    return $bytes;
}

/**
 * Extract the text of an uploaded document. Returns ['text' => string, 'steps' => [tool names used]].
 * Throws OcrError with a message meant for staff.
 */
function ocr_extract_text(string $path, string $origName, string $mime): array {
    if (!ocr_configured()) {
        throw new OcrError('config', 'OCR is not set up yet: add ILOVEPDF_PUBLIC_KEY and ILOVEPDF_SECRET_KEY to portal/app/.env (copy portal/app/.env.example).');
    }
    $steps = [];
    $work = [];
    $cleanup = function () use (&$work) { foreach ($work as $f) @unlink($f); };
    try {
        $pdf = $path;
        $base = pathinfo($origName, PATHINFO_FILENAME) ?: 'document';
        if (str_starts_with($mime, 'image/')) {
            $bytes = ilovepdf_run_tool('imagepdf', $path, $origName, ['orientation' => 'portrait', 'margin' => 0, 'pagesize' => 'fit', 'merge_after' => 'true']);
            $steps[] = 'imagepdf';
            $pdf = $work[] = tempnam(sys_get_temp_dir(), 'ocrp');
            file_put_contents($pdf, $bytes);
        }
        $text = '';
        if (!str_starts_with($mime, 'image/')) {   // a digital PDF may already contain its text
            $text = ocr_text_from_output(ilovepdf_run_tool('extract', $pdf, $base . '.pdf'));
            $steps[] = 'extract';
        }
        if (mb_strlen(preg_replace('/\s+/u', '', $text)) < 40) {   // scanned: no text layer yet
            $ocrPdf = $work[] = tempnam(sys_get_temp_dir(), 'ocro');
            file_put_contents($ocrPdf, ilovepdf_run_tool('pdfocr', $pdf, $base . '.pdf', ['ocr_languages' => ocr_languages()]));
            $steps[] = 'pdfocr';
            $text = ocr_text_from_output(ilovepdf_run_tool('extract', $ocrPdf, $base . '.pdf'));
            $steps[] = 'extract';
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (mb_strlen(preg_replace('/\s+/u', '', $text)) < 20) {
            throw new OcrError('quality', 'No readable text was found. The scan is probably too blurry, dark or small: scan it again at 300 dpi, straight and well lit, and upload again.');
        }
        return ['text' => $text, 'steps' => $steps];
    } finally {
        $cleanup();
    }
}

// -------------------------------------------------------------------------------------
// Text -> form fields. Only values that are written in the document are returned, each with the
// line it came from. Nothing is guessed: a field that is not clearly present is left empty and
// listed for review.
// -------------------------------------------------------------------------------------

const OCR_DOC_TYPES = [
    'auto'       => 'Detect automatically',
    'passport'   => 'Passport',
    'cnic'       => 'CNIC / B-form',
    'transcript' => 'Degree / transcript / DMC',
    'english'    => 'IELTS / PTE / TOEFL / Duolingo result',
    'cv'         => 'CV / resume',
    'form'       => 'Registration / consent form',
];

/** Fields each kind of document normally contains (used to highlight what still needs checking). */
const OCR_EXPECTED = [
    'passport'   => ['name'],
    'cnic'       => ['name'],
    'transcript' => ['name', 'last_degree', 'gpa'],
    'english'    => ['name', 'ielts'],
    'cv'         => ['name', 'student_email', 'contact_no', 'last_degree'],
    'form'       => ['name', 'student_email', 'contact_no', 'last_degree', 'gpa', 'ielts'],
    'auto'       => ['name', 'student_email', 'contact_no', 'last_degree', 'gpa', 'ielts'],
];

/** Fields OCR is able to read. */
const OCR_FIELDS = ['name', 'student_email', 'contact_no', 'last_degree', 'gpa', 'ielts'];

function ocr_detect_type(string $t): string {
    return match (true) {
        (bool)preg_match('/^P[<A-Z0-9][A-Z<]{3}[A-Z<]{20,}/m', $t) || (bool)preg_match('/\bpassport\s*(no|number)\b/i', $t) => 'passport',
        (bool)preg_match('/\b(ielts|test report form|pearson|pte academic|toefl|duolingo)\b/i', $t) => 'english',
        (bool)preg_match('/\b(transcript|detailed marks|marks certificate|cgpa|grade point average|semester)\b/i', $t) => 'transcript',
        (bool)preg_match('/\b(national identity card|identity number|\d{5}-\d{7}-\d)\b/i', $t) => 'cnic',
        (bool)preg_match('/\b(curriculum vitae|resume|work experience|skills)\b/i', $t) => 'cv',
        default => 'form',
    };
}

function ocr_clean_name(string $n): ?string {
    $n = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z .\'-]/', ' ', $n)));
    $n = preg_replace('/^(mr|mrs|ms|miss)\.?\s+/i', '', $n);
    if (mb_strlen($n) < 3 || mb_strlen($n) > 60 || !preg_match('/[A-Za-z]{2,}/', $n)) return null;
    if (preg_match('/\b(father|husband|guardian|university|college|board|school|institute|date|birth|gender|address)\b/i', $n)) return null;
    return ucwords(strtolower($n));
}

/** Passport machine-readable zone: P<PAKSURNAME<<GIVEN<NAMES<<<<<< */
function ocr_mrz_name(string $t): ?array {
    foreach (preg_split('/\n/', $t) as $line) {
        $l = strtoupper(preg_replace('/\s+/', '', $line));
        if (preg_match('/^P[<A-Z]([A-Z]{3})([A-Z]+(?:<[A-Z]+)*)<<([A-Z]+(?:<[A-Z]+)*)/', $l, $m)) {
            $sur = str_replace('<', ' ', $m[2]);
            $given = str_replace('<', ' ', $m[3]);
            $name = ocr_clean_name($given . ' ' . $sur);
            if ($name) return [$name, trim($line)];
        }
    }
    return null;
}

function ocr_line_of(string $t, int $offset): string {
    $s = strrpos(substr($t, 0, $offset), "\n");
    $e = strpos($t, "\n", $offset);
    return trim(substr($t, $s === false ? 0 : $s + 1, ($e === false ? strlen($t) : $e) - ($s === false ? 0 : $s + 1)));
}

/**
 * Parse text into fields. Returns ['type' => detected type, 'fields' => [key => ['value', 'source']], 'missing' => [keys]]
 * restricted to $allowed (the selected country's form fields).
 */
function ocr_parse_fields(string $text, string $docType, array $allowed): array {
    $t = preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text));
    $type = $docType === 'auto' ? ocr_detect_type($t) : $docType;
    $f = [];
    $put = function (string $k, ?string $v, string $src) use (&$f) {
        $v = $v === null ? null : trim($v);
        if ($v !== null && $v !== '' && !isset($f[$k])) $f[$k] = ['value' => $v, 'source' => mb_substr(trim($src), 0, 160)];
    };

    // ---- name ----
    if ($mrz = ocr_mrz_name($t)) $put('name', $mrz[0], $mrz[1]);
    if (preg_match('/^\s*family\s*name\s*[:\-]?\s*([A-Za-z .\'-]{2,40})\s*$.*?^\s*first\s*name\(?s?\)?\s*[:\-]?\s*([A-Za-z .\'-]{2,40})\s*$/ims', $t, $m)) {
        $put('name', ocr_clean_name($m[2] . ' ' . $m[1]), $m[0]);
    }
    if (preg_match('/^\s*(?:student\'?s?\s*name|full\s*name|name\s*of\s*(?:the\s*)?(?:student|candidate|applicant)|candidate\'?s?\s*name|applicant\'?s?\s*name|test\s*taker(?:\s*name)?|name)\s*[:\-]\s*([A-Za-z .\'-]{3,60})$/im', $t, $m, PREG_OFFSET_CAPTURE)) {
        $put('name', ocr_clean_name($m[1][0]), ocr_line_of($t, $m[0][1]));
    }

    // ---- email ----
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $t, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as [$e, $off]) {
            if (preg_match('/scholarshiphive|ilovepdf|example\.com|ielts\.org|pearson|britishcouncil|idp\.com/i', $e)) continue;
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $put('student_email', strtolower($e), ocr_line_of($t, $off)); break; }
        }
    }

    // ---- phone (Pakistan mobile numbers, or a labelled number) ----
    if (preg_match('/(?<![\d])(\+?92[\s\-]?3\d{2}[\s\-]?\d{7}|0?3\d{2}[\s\-]?\d{7})(?![\d])/', $t, $m, PREG_OFFSET_CAPTURE)) {
        $put('contact_no', preg_replace('/\s+/', ' ', $m[1][0]), ocr_line_of($t, $m[0][1]));
    } elseif (preg_match('/\b(?:phone|mobile|cell|contact(?:\s*no\.?)?|whats\s*app)\s*[:\-]?\s*(\+?[\d][\d\s\-()]{8,18}\d)/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        $put('contact_no', trim($m[1][0]), ocr_line_of($t, $m[0][1]));
    }

    // ---- GPA / CGPA: the cumulative value wins over semester GPAs ----
    $gpaRe = '/\b(C\.?\s?G\.?\s?P\.?\s?A\.?|Cumulative\s+(?:Grade\s+Point\s+Average|GPA)|Grade\s+Point\s+Average|G\.?\s?P\.?\s?A\.?)\b[^\d\n]{0,25}(\d(?:\.\d{1,3})?)(?:\s*(?:\/|out\s*of)\s*(\d(?:\.\d{1,2})?))?/i';
    if (preg_match_all($gpaRe, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        $best = null;
        foreach ($mm as $m) {
            $label = strtolower(preg_replace('/[\s.]/', '', $m[1][0]));
            $val = (float)$m[2][0];
            $scale = isset($m[3]) && $m[3][0] !== '' ? (float)$m[3][0] : null;
            if ($val <= 0 || $val > ($scale ?: 5.0) || ($scale && !in_array($scale, [4.0, 5.0, 10.0], true))) continue;
            $cum = str_starts_with($label, 'cgpa') || str_starts_with($label, 'cumulative');
            if (!$best || $cum || !$best['cum']) $best = ['v' => $m[2][0] . ($scale ? ' / ' . number_format($scale, 2) : ''), 'cum' => $cum, 'src' => ocr_line_of($t, $m[0][1])];
            if ($cum) break;
        }
        if ($best) $put('gpa', $best['v'], $best['src']);
    }
    if (!isset($f['gpa']) && preg_match('/\b(?:marks\s*obtained|obtained\s*marks|total\s*marks\s*obtained|grand\s*total)\b\D{0,20}(\d{2,4})\s*(?:\/|out\s*of)\s*(\d{3,4})/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((int)$m[1][0] <= (int)$m[2][0]) $put('gpa', $m[1][0] . '/' . $m[2][0], ocr_line_of($t, $m[0][1]));
    }
    if (!isset($f['gpa']) && preg_match('/\bpercentage\b\D{0,15}(\d{1,3}(?:\.\d{1,2})?)\s*%/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((float)$m[1][0] <= 100) $put('gpa', $m[1][0] . '%', ocr_line_of($t, $m[0][1]));
    }

    // ---- English test ----
    if (preg_match('/\bIELTS\b|Test\s+Report\s+Form/i', $t) && preg_match('/Overall\s*Band\s*Score\D{0,15}(\d(?:\.[05])?)\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((float)$m[1][0] <= 9) $put('ielts', $m[1][0], ocr_line_of($t, $m[0][1]));
    } elseif (preg_match('/\bPTE\b|Pearson/i', $t) && preg_match('/Overall\s*(?:Score)?\D{0,12}(\d{2})\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((int)$m[1][0] >= 10 && (int)$m[1][0] <= 90) $put('ielts', 'PTE ' . $m[1][0], ocr_line_of($t, $m[0][1]));
    } elseif (preg_match('/\bTOEFL\b/i', $t) && preg_match('/Total\s*Score\D{0,12}(\d{2,3})\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((int)$m[1][0] <= 120) $put('ielts', 'TOEFL ' . $m[1][0], ocr_line_of($t, $m[0][1]));
    } elseif (preg_match('/\bDuolingo\b/i', $t) && preg_match('/\bOverall\b\D{0,12}(\d{2,3})\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
        if ((int)$m[1][0] >= 10 && (int)$m[1][0] <= 160) $put('ielts', 'Duolingo ' . $m[1][0], ocr_line_of($t, $m[0][1]));
    }

    // ---- last degree (degree documents, CVs and forms only) ----
    if (in_array($type, ['transcript', 'cv', 'form'], true)) {
        if (preg_match('/\b((?:Bachelor|Master|Doctor)\s+of\s+[A-Z][A-Za-z&,()\s]{2,70}?)(?=\s*(?:\n|\(|,|\.|\d|session|program|with|$))/', $t, $m, PREG_OFFSET_CAPTURE)) {
            $put('last_degree', preg_replace('/\s+/', ' ', trim($m[1][0], " ,")), ocr_line_of($t, $m[0][1]));
        } elseif (preg_match('/\b(B\.?\s?S\.?\s?c?|BBA|B\.?\s?Com|B\.?\s?E|M\.?\s?S\.?\s?c?|MBA|M\.?\s?Phil|BS\s*\(Hons\.?\))\s*(?:\(\s*|in\s+)([A-Z][A-Za-z &]{2,50})\)?/', $t, $m, PREG_OFFSET_CAPTURE)) {
            $put('last_degree', trim($m[1][0]) . ' (' . trim($m[2][0]) . ')', ocr_line_of($t, $m[0][1]));
        }
    }

    $fields = array_intersect_key($f, array_flip($allowed));
    $expected = array_values(array_intersect(OCR_EXPECTED[$type] ?? OCR_EXPECTED['auto'], $allowed));
    $missing = array_values(array_diff($expected, array_keys($fields)));
    return ['type' => $type, 'fields' => $fields, 'missing' => $missing];
}

// -------------------------------------------------------------------------------------
// Scanned files kept briefly so they can be attached to the student when the form is saved.
// -------------------------------------------------------------------------------------

function ocr_tmp_dir(): string {
    $d = storage_dir() . '/ocr_tmp';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function ocr_tmp_cleanup(): void {
    foreach (glob(ocr_tmp_dir() . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
}

/** Stored scan for this user, or null. */
function ocr_tmp_get(string $token, array $u): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $meta = ocr_tmp_dir() . '/' . $token . '.json';
    if (!is_file($meta)) return null;
    $m = json_decode((string)file_get_contents($meta), true);
    if (!is_array($m) || (int)($m['user'] ?? 0) !== (int)$u['id'] || !is_file(ocr_tmp_dir() . '/' . $token)) return null;
    $m['path'] = ocr_tmp_dir() . '/' . $token;
    return $m;
}

function ocr_tmp_delete(string $token): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
    @unlink(ocr_tmp_dir() . '/' . $token);
    @unlink(ocr_tmp_dir() . '/' . $token . '.json');
}

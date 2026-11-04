<?php
declare(strict_types=1);

// =====================================================================================
// Downloads with the real files (never links):
//  - Google Drive files are copied into the portal (app/storage/files/gdrive_cache) the first time they are
//    needed, so a download contains the document itself. A file that is not shared publicly in Drive cannot be
//    copied; it is named in "Files not downloaded.txt" so the team knows which ones to upload by hand.
//  - The ZIP is written straight to the browser (no temporary copy on the server), files stored as they are.
//  - "Student details.pdf" holds the student's record as on the sheet (passwords are never included).
// =====================================================================================

const GDRIVE_FAIL_RETRY = 6 * 3600;   // after a failed copy, try that Drive file again after 6 hours

function gdrive_fail_path(array $f): string { return file_path($f) . '.fail'; }

/** Is a copy of this Drive file in the portal? */
function gdrive_cached(array $f): bool {
    $p = file_path($f);
    return is_file($p) && filesize($p) > 0;
}

/** Reason a Drive file could not be copied lately, or null. */
function gdrive_recent_failure(array $f): ?string {
    $p = gdrive_fail_path($f);
    if (!is_file($p) || filemtime($p) < time() - GDRIVE_FAIL_RETRY) return null;
    return trim((string)file_get_contents($p)) ?: 'could not be copied';
}

/**
 * Copy Drive files into the portal, several at a time, until $deadline (unix time).
 * Returns [file id => true | reason string]. Files already copied count as true.
 */
function gdrive_fetch_many(array $rows, float $deadline, int $parallel = 8): array {
    $res = []; $queue = [];
    foreach ($rows as $f) {
        if (!is_gdrive_file($f)) continue;
        if (gdrive_cached($f)) { $res[(int)$f['id']] = true; continue; }
        if (($why = gdrive_recent_failure($f)) !== null) { $res[(int)$f['id']] = $why; continue; }
        $queue[] = $f;
    }
    if (!$queue) return $res;
    if (!function_exists('curl_multi_init')) {
        foreach ($queue as $f) $res[(int)$f['id']] = 'the PHP curl extension is not enabled on the server';
        return $res;
    }
    $mh = curl_multi_init();
    $active = [];   // (int)handle => [file row, tmp path, fh]
    $start = function (array $f) use ($mh, &$active): void {
        $tmp = file_path($f) . '.part';
        $fh = fopen($tmp, 'wb');
        $ch = curl_init('https://drive.usercontent.google.com/download?id=' . rawurlencode(gdrive_file_id($f)) . '&export=download&confirm=t');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300, CURLOPT_FAILONERROR => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (ScholarshipHive portal document copy)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = [$f, $tmp, $fh, $ch];
    };
    $finish = function ($ch) use ($mh, &$active, &$res): void {
        [$f, $tmp, $fh] = $active[(int)$ch];
        fclose($fh);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $err = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        unset($active[(int)$ch]);
        $size = is_file($tmp) ? (int)filesize($tmp) : 0;
        $head = $size ? (string)file_get_contents($tmp, false, null, 0, 512) : '';
        $isHtml = str_contains($type, 'text/html') || preg_match('/^\s*(<!doctype html|<html)/i', $head);
        if ($err === '' && $code === 200 && $size > 0 && !$isHtml) {
            rename($tmp, file_path($f));
            @unlink(gdrive_fail_path($f));
            if ((int)$f['size'] !== $size) q('UPDATE student_files SET size = ? WHERE id = ?', [$size, (int)$f['id']]);
            $res[(int)$f['id']] = true;
            return;
        }
        @unlink($tmp);
        $why = $err !== '' ? 'Google Drive could not be reached (' . $err . ')'
            : ($code === 404 ? 'the file is no longer in Google Drive'
            : ($isHtml || in_array($code, [401, 403], true) ? 'the file is not shared publicly in Google Drive (sign-in needed)' : 'Google Drive answered with error ' . $code));
        @file_put_contents(gdrive_fail_path($f), $why);
        $res[(int)$f['id']] = $why;
    };
    $i = 0;
    while ($i < count($queue) || $active) {
        while (count($active) < $parallel && $i < count($queue) && microtime(true) < $deadline) $start($queue[$i++]);
        if (!$active) break;
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) $finish($info['handle']);
        if ($active) curl_multi_select($mh, 0.5);
        if (microtime(true) > $deadline + 240) break;   // a very slow transfer: stop waiting for it
    }
    foreach ($active as [$f, $tmp, $fh, $ch]) { curl_multi_remove_handle($mh, $ch); fclose($fh); @unlink($tmp); }
    curl_multi_close($mh);
    return $res;
}

// -------------------------------------------------------------------------------------
// ZIP written straight to the browser. Files are stored as they are (PDFs and photos do not shrink
// further), so nothing has to be held in memory or on disk. ZIP64 is used when the archive passes 4 GB.
// -------------------------------------------------------------------------------------
final class ZipOut {
    private int $offset = 0;
    private array $central = [];
    private array $names = [];

    /** Unique, safe path inside the archive. */
    public function name(string $path): string {
        $parts = array_map(fn($p) => zip_clean_name($p), explode('/', $path));
        $path = implode('/', array_filter($parts, fn($p) => $p !== ''));
        $base = $path; $n = 2;
        while (isset($this->names[mb_strtolower($path)])) {
            $dot = strrpos($base, '.');
            $slash = strrpos($base, '/');
            $path = ($dot !== false && ($slash === false || $dot > $slash)) ? substr($base, 0, $dot) . " ($n)" . substr($base, $dot) : "$base ($n)";
            $n++;
        }
        $this->names[mb_strtolower($path)] = true;
        return $path;
    }

    /** When set (a file handle), the archive is written there instead of to the browser (used by the backup worker). */
    public $sink = null;

    /** An (empty) folder entry, so empty categories still show up in the archive. */
    public function addDir(string $path): void {
        $parts = array_filter(array_map('zip_clean_name', explode('/', $path)), fn($p) => $p !== '');
        $name = implode('/', $parts) . '/';
        if (isset($this->names[mb_strtolower($name)])) return;
        $this->names[mb_strtolower($name)] = true;
        $this->header($name, 0, 0, time());
    }

    /** Number of entries written so far. */
    public function entries(): int { return count($this->central); }

    private function out(string $bytes): void {
        if ($this->sink !== null) {
            if (fwrite($this->sink, $bytes) !== strlen($bytes)) throw new RuntimeException('Could not write the backup file (disk full?)');
        } else {
            echo $bytes;
        }
        $this->offset += strlen($bytes);
    }

    private static function dos(int $t): array {
        $d = getdate($t);
        if ($d['year'] < 1980) $d = getdate(mktime(0, 0, 0, 1, 1, 1980));
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }

    private function header(string $name, int $crc, int $size, int $mtime): void {
        [$tm, $dt] = self::dos($mtime);
        $this->central[] = [$name, $crc, $size, $this->offset, $tm, $dt];
        $this->out(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $tm, $dt, $crc, $size, $size, strlen($name), 0) . $name);
    }

    public function addString(string $name, string $data, ?int $mtime = null): void {
        $this->header($this->name($name), (int)hexdec(hash('crc32b', $data)), strlen($data), $mtime ?? time());
        $this->out($data);
        @flush();
    }

    public function addFile(string $name, string $path, ?int $mtime = null): bool {
        $size = @filesize($path);
        if ($size === false || $size >= 0xFFFFFFFF) return false;
        $crc = (int)hexdec((string)hash_file('crc32b', $path));
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $this->header($this->name($name), $crc, $size, $mtime ?? (int)filemtime($path));
        while (!feof($fh)) {
            $chunk = fread($fh, 1 << 18);
            if ($chunk === false || $chunk === '') break;
            $this->out($chunk);
            @flush();
        }
        fclose($fh);
        return true;
    }

    public function finish(): void {
        $cdStart = $this->offset;
        foreach ($this->central as [$name, $crc, $size, $off, $tm, $dt]) {
            $z64 = $off >= 0xFFFFFFFF;
            $extra = $z64 ? pack('vvP', 0x0001, 8, $off) : '';
            $this->out(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | ($z64 ? 45 : 20), $z64 ? 45 : 20, 0x0800, 0, $tm, $dt, $crc, $size, $size,
                strlen($name), strlen($extra), 0, 0, 0, 0100644 << 16, $z64 ? 0xFFFFFFFF : $off) . $name . $extra);
        }
        $cdSize = $this->offset - $cdStart;
        $n = count($this->central);
        if ($cdStart >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF || $n >= 0xFFFF) {
            $z64eocd = $this->offset;
            $this->out(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, $cdSize, $cdStart));
            $this->out(pack('VVPV', 0x07064b50, 0, $z64eocd, 1));
            $this->out(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
        } else {
            $this->out(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $cdSize, $cdStart, 0));
        }
        @flush();
    }
}

function zip_clean_name(string $s): string {
    $s = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '', ' .');
    return mb_substr($s, 0, 120);
}

/** Send the headers of a ZIP download and switch off everything that would buffer it. */
function zip_begin(string $filename): ZipOut {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', 'Off');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Security-Policy');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, no-store');
    header('X-Accel-Buffering: no');
    return new ZipOut();
}

// -------------------------------------------------------------------------------------
// Small PDF writer (text only, A4, Helvetica). Enough for a clear, printable record of a student.
// -------------------------------------------------------------------------------------
const PDF_HELV_W = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
    278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944,
    667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556,
    500, 722, 500, 500, 500, 334, 260, 334, 584];

function pdf_enc(string $s): string {
    $s = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}", "\u{2192}", "\u{2713}", "\u{00A0}"], ["'", "'", '"', '"', '-', '-', '...', '->', 'v', ' '], $s);
    $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
}
function pdf_width(string $enc, float $size, bool $bold = false): float {
    $w = 0;
    for ($i = 0, $n = strlen($enc); $i < $n; $i++) $w += PDF_HELV_W[ord($enc[$i])] ?? 556;
    return $w * $size / 1000 * ($bold ? 1.07 : 1.0);
}
function pdf_wrap(string $text, float $width, float $size, bool $bold = false): array {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', pdf_enc($text)) as $para) {
        $line = '';
        foreach (preg_split('/ +/', $para) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if (pdf_width($try, $size, $bold) <= $width) { $line = $try; continue; }
            if ($line !== '') $lines[] = $line;
            while (pdf_width($word, $size, $bold) > $width && strlen($word) > 1) {   // a very long word (a link): cut it
                $cut = strlen($word);
                while ($cut > 1 && pdf_width(substr($word, 0, $cut), $size, $bold) > $width) $cut--;
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        $lines[] = $line;
    }
    return $lines;
}
function pdf_str(string $enc): string { return '(' . strtr($enc, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')'; }

/**
 * $doc: ['title' => ..., 'subtitle' => ..., 'sections' => [['heading' => ..., 'rows' => [[label, value], ...]] | ['heading' => ..., 'table' => [headers, rows]]]]
 */
function pdf_document(array $doc): string {
    $W = 595.28; $H = 841.89; $M = 46; $pages = []; $ops = ''; $y = 0;
    $footer = pdf_enc('ScholarshipHive Processing Portal · ' . date('j M Y, H:i'));
    $newPage = function () use (&$pages, &$ops, &$y, $H, $M) { if ($ops !== '') $pages[] = $ops; $ops = ''; $y = $H - $M; };
    $text = function (float $x, float $yy, string $enc, float $size, bool $bold = false, string $color = '0.13 0.15 0.2') use (&$ops) {
        $ops .= sprintf("BT %s rg /%s %.1F Tf %.2F %.2F Td %s Tj ET\n", $color, $bold ? 'F2' : 'F1', $size, $x, $yy, pdf_str($enc));
    };
    $need = function (float $h) use (&$y, $M, $newPage) { if ($y - $h < $M + 20) $newPage(); };
    $newPage();
    foreach (pdf_wrap((string)$doc['title'], $W - 2 * $M, 17, true) as $l) { $text($M, $y - 17, $l, 17, true, '0.03 0.27 0.42'); $y -= 22; }
    if (!empty($doc['subtitle'])) foreach (pdf_wrap((string)$doc['subtitle'], $W - 2 * $M, 9.5) as $l) { $text($M, $y - 10, $l, 9.5, false, '0.4 0.43 0.48'); $y -= 13; }
    $y -= 8;
    foreach ($doc['sections'] as $sec) {
        $need(40);
        $y -= 10;
        $text($M, $y - 11, pdf_enc((string)$sec['heading']), 11.5, true, '0.03 0.27 0.42');
        $y -= 16;
        $ops .= sprintf("0.03 0.53 0.8 RG 0.8 w %.2F %.2F m %.2F %.2F l S\n", $M, $y, $W - $M, $y);
        $y -= 6;
        if (isset($sec['rows'])) {
            $lw = 175; $vw = $W - 2 * $M - $lw - 10;
            foreach ($sec['rows'] as [$label, $value]) {
                $ll = pdf_wrap((string)$label, $lw, 8.8, true);
                $vl = pdf_wrap((string)$value === '' ? '-' : (string)$value, $vw, 9.3);
                $h = max(count($ll), count($vl)) * 11.5 + 4;
                $need($h);
                foreach ($ll as $i => $l) $text($M, $y - 10 - $i * 11.5, $l, 8.8, true, '0.35 0.38 0.43');
                foreach ($vl as $i => $l) $text($M + $lw + 10, $y - 10 - $i * 11.5, $l, 9.3);
                $y -= $h;
                $ops .= sprintf("0.88 0.9 0.92 RG 0.4 w %.2F %.2F m %.2F %.2F l S\n", $M, $y + 1, $W - $M, $y + 1);
            }
        } elseif (isset($sec['table'])) {
            [$heads, $rows, $widths] = $sec['table'] + [2 => null];
            $tw = $W - 2 * $M;
            $widths = $widths ?: array_fill(0, count($heads), $tw / count($heads));
            $drawRow = function (array $cells, bool $bold) use (&$y, &$ops, $widths, $M, $text, $need) {
                $wrapped = []; $max = 1;
                foreach ($cells as $i => $c) { $wrapped[$i] = pdf_wrap((string)$c, $widths[$i] - 6, 8.6, $bold); $max = max($max, count($wrapped[$i])); }
                $h = $max * 11 + 5;
                $need($h);
                $x = $M;
                foreach ($wrapped as $i => $ls) { foreach ($ls as $j => $l) $text($x, $y - 10 - $j * 11, $l, 8.6, $bold, $bold ? '0.35 0.38 0.43' : '0.13 0.15 0.2'); $x += $widths[$i]; }
                $y -= $h;
                $ops .= sprintf("0.88 0.9 0.92 RG 0.4 w %.2F %.2F m %.2F %.2F l S\n", $M, $y + 1, $M + array_sum($widths), $y + 1);
            };
            $drawRow($heads, true);
            if (!$rows) $drawRow(array_merge(['None'], array_fill(0, count($heads) - 1, '')), false);
            foreach ($rows as $r) $drawRow($r, false);
        }
        $y -= 4;
    }
    $pages[] = $ops;

    // Assemble: 1 catalog, 2 pages, 3 font, 4 bold font, then page + content pairs.
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = []; $n = 5;
    $total = count($pages);
    foreach ($pages as $i => $content) {
        $content .= sprintf("BT 0.5 0.53 0.58 rg /F1 7.5 Tf %.2F %.2F Td %s Tj ET\n", $M, 26, pdf_str($footer));
        $pg = pdf_enc('Page ' . ($i + 1) . ' of ' . $total);
        $content .= sprintf("BT 0.5 0.53 0.58 rg /F1 7.5 Tf %.2F %.2F Td %s Tj ET\n", $W - $M - pdf_width($pg, 7.5), 26, pdf_str($pg));
        $stream = function_exists('gzcompress') ? gzcompress($content, 6) : $content;
        $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
        $objs[$n] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $W, $H, $n + 1);
        $objs[$n + 1] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
        $kids[] = "$n 0 R";
        $n += 2;
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objs);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

// -------------------------------------------------------------------------------------
// One student's folder: details PDF + every document file (+ meeting recordings when asked for)
// -------------------------------------------------------------------------------------

/** The student's record as a PDF: the country form's sections (what this person may see; no passwords), documents, universities, meetings. */
function student_details_pdf(array $u, array $r): string {
    $c = (string)$r['country'];
    $sections = [];
    $isStudent = $u['role'] === 'student';
    foreach (country_groups($c) as $title => $g) {
        $rows = [];
        foreach ($g['fields'] as $k => $def) {
            if ($def['type'] === 'secret' || ($isStudent ? !in_array($title, ['Student profile'], true) : !can_see_field($u, $def['team'], $def['type'], $r))) continue;
            if ($u['role'] === 'tax' && !in_array($title, ['Student profile', 'Meetings', 'Documents', 'Visa filing', 'Admission & visa'], true)) continue;
            $v = $def['type'] === 'front_officer' ? (string)($r['officer_name'] ?? '') : ($def['type'] === 'back_officer' ? (string)($r['back_officer_name'] ?? '') : (string)($r[$k] ?? ''));
            if ($def['type'] === 'date' && $v !== '') $v = fmt_date($v);
            if ($def['type'] === 'meeting_mode' && $v !== '') $v = meeting_type_label($v);
            $rows[] = [$def['label'], $v];
        }
        if ($rows) $sections[] = ['heading' => $title, 'rows' => $rows];
    }
    // Documents checklist
    $st = docs_state($r);
    $files = student_files((int)$r['id']);
    $docRows = [];
    foreach (country_docs($c) + ['other' => ['Other supporting documents', '']] as $k => [$label]) {
        $fl = $files['docs'][$k] ?? [];
        $state = !empty($st['have'][$k]) ? ($fl ? 'Received' : 'Received (outside the portal)') : (in_array($k, $st['pending'], true) ? 'Pending' : ($fl ? 'Received' : '-'));
        if ($k === 'other' && !$fl) continue;
        $docRows[] = [$label, $state, $fl ? implode(', ', array_map(fn($f) => $f['original_name'], $fl)) : ''];
    }
    $sections[] = ['heading' => 'Documents  ·  status: ' . $st['status'] . ($st['pending_text'] ? '  ·  pending: ' . $st['pending_text'] : ''),
                   'table' => [['Document', 'Status', 'Files'], $docRows, [170, 95, 238]]];
    if (!$isStudent) {
        $apps = q('SELECT * FROM applications WHERE student_id = ? ORDER BY list_name, id', [(int)$r['id']])->fetchAll();
        if ($apps) $sections[] = ['heading' => 'Universities and programmes', 'table' => [['University', 'Programme', 'Deadline', 'Status', 'Result'],
            array_map(fn($a) => [(string)$a['university'], (string)$a['program'], (string)$a['deadline'], (string)$a['status'], (string)$a['result']], $apps), [140, 140, 70, 80, 73]]];
    }
    $meets = student_meetings((int)$r['id'], $isStudent);
    if ($meets) $sections[] = ['heading' => 'Meetings', 'table' => [['Meeting', 'Date', 'How', 'Status', 'Notes'],
        array_map(fn($m) => [(string)$m['title'], fmt_date($m['meeting_date']), meeting_type_label($m['meeting_type']), (string)$m['status'], mb_strimwidth((string)$m['notes'], 0, 300, '…')], $meets), [120, 65, 85, 60, 173]]];
    return pdf_document([
        'title' => (string)$r['name'],
        'subtitle' => 'Study in ' . country_name($c) . (($r['intake'] ?? '') !== '' ? ' · Intake ' . $r['intake'] : '') . ' · Portal record #' . (int)$r['id'] . ' · Documents status: ' . $st['status'],
        'sections' => $sections,
    ]);
}

/** Folder of each document file inside a student's folder. */
function doc_zip_folder(string $country, string $key): string {
    $key = doc_key_norm($key);
    $plan = country_doc_plan($country);
    $order = array_merge($plan['req'], $plan['opt'], $plan['later']);
    $i = array_search($key, $order, true);
    if ($i === false) return 'Documents/Other';
    $label = str_replace('/', ' or ', DOC_ITEMS[$key][1] ?? DOC_LATER[$key][0]);
    return 'Documents/' . sprintf('%02d', $i + 1) . ' ' . $label;
}

/** Files of a student to put in the ZIP: [[path inside the student folder, file row], ...] */
function student_zip_files(int $sid, string $country, bool $recordings): array {
    $out = [];
    foreach (q('SELECT * FROM student_files WHERE student_id = ? ORDER BY doc_key, created_at, id', [$sid]) as $f) {
        $isRec = !empty($f['meeting_id']) || str_starts_with((string)$f['doc_key'], 'meeting') || is_media_file($f);
        if ($isRec && !$recordings) continue;
        $out[] = [($isRec ? 'Meeting recordings' : doc_zip_folder($country, (string)$f['doc_key'])) . '/' . $f['original_name'], $f];
    }
    return $out;
}

/**
 * Write one student's folder into the ZIP. $failed collects [student, file, reason] for files that could not be added.
 * Drive files must have been copied before (gdrive_fetch_many); a file without a copy is listed in $failed.
 */
function zip_student(ZipOut $zip, array $u, array $r, string $folder, bool $recordings, array $fetchResult, array &$failed): void {
    $zip->addString($folder . '/Student details.pdf', student_details_pdf($u, $r));
    foreach (student_zip_files((int)$r['id'], (string)$r['country'], $recordings) as [$path, $f]) {
        if (is_gdrive_file($f)) {
            if (!gdrive_cached($f)) { $failed[] = [$r['name'], $path, is_string($fetchResult[(int)$f['id']] ?? null) ? $fetchResult[(int)$f['id']] : 'not copied from Google Drive yet']; continue; }
        } elseif (!is_file(file_path($f))) {
            $failed[] = [$r['name'], $path, 'the file is missing from the portal storage']; continue;
        }
        if (!$zip->addFile($folder . '/' . $path, file_path($f), strtotime((string)$f['created_at']) ?: null)) $failed[] = [$r['name'], $path, 'could not be read'];
    }
}

function failed_list_text(array $failed): string {
    $t = "These files are listed in the portal but could not be put in this download.\r\n"
       . "Files in Google Drive must be shared as \"Anyone with the link\" (or uploaded in the portal) to be copied.\r\n\r\n";
    $last = null;
    foreach ($failed as [$student, $path, $why]) {
        if ($student !== $last) { $t .= "\r\n" . $student . "\r\n"; $last = $student; }
        $t .= '  - ' . $path . ' — ' . $why . "\r\n";
    }
    return $t;
}

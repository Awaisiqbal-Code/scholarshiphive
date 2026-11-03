<?php
declare(strict_types=1);

// Minimal .xlsx writer (Office Open XML) using PHP's ZipArchive: several tabs, a bold header row,
// real date cells and numbers. No external library is needed on the hosting account.

function xlsx_col(int $i): string {   // 0 -> A, 26 -> AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xlsx_esc(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_sheet_name(string $n, array $used): string {
    $n = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $n)) ?: 'Sheet';
    $n = mb_substr($n, 0, 31);
    $base = $n; $i = 2;
    while (in_array(mb_strtolower($n), $used, true)) { $n = mb_substr($base, 0, 28) . ' ' . $i++; }
    return $n;
}

/** Excel serial number for a Y-m-d date. */
function xlsx_date_serial(string $ymd): ?float {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return (float)((new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable("$m[1]-$m[2]-$m[3]"))->days);
}

/**
 * $tabs: [title => ['headers' => [..], 'rows' => [[cell, ...], ...]]]
 * A cell is a string, an int/float, or ['date' => 'Y-m-d'].
 * Writes the workbook to $path. Throws RuntimeException when ZipArchive is missing.
 */
function xlsx_write(array $tabs, string $path): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is not enabled.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create the Excel file.');

    $sheetsXml = ''; $relsXml = ''; $ctXml = ''; $used = []; $n = 0;
    foreach ($tabs as $title => $tab) {
        $n++;
        $name = xlsx_sheet_name((string)$title, $used);
        $used[] = mb_strtolower($name);
        $headers = $tab['headers'];
        $rows = $tab['rows'];

        // column widths from the content (capped)
        $w = array_map(fn($h) => min(45, max(8, mb_strlen((string)$h) + 2)), $headers);
        foreach (array_slice($rows, 0, 400) as $row) foreach ($row as $ci => $cell) {
            $len = is_array($cell) ? 11 : mb_strlen((string)$cell);
            if (isset($w[$ci])) $w[$ci] = min(45, max($w[$ci], $len + 2));
        }
        $cols = '';
        foreach ($w as $ci => $cw) $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $cw . '" customWidth="1"/>';

        $data = '<row r="1">';
        foreach ($headers as $ci => $h) $data .= '<c r="' . xlsx_col($ci) . '1" t="inlineStr" s="1"><is><t xml:space="preserve">' . xlsx_esc((string)$h) . '</t></is></c>';
        $data .= '</row>';
        foreach ($rows as $ri => $row) {
            $r = $ri + 2;
            $data .= '<row r="' . $r . '">';
            foreach ($row as $ci => $cell) {
                $ref = xlsx_col($ci) . $r;
                if (is_array($cell) && isset($cell['date'])) {
                    $serial = xlsx_date_serial((string)$cell['date']);
                    $data .= $serial !== null ? '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>'
                                              : '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlsx_esc((string)$cell['date']) . '</t></is></c>';
                } elseif (is_int($cell) || is_float($cell)) {
                    $data .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                } elseif ((string)$cell !== '') {
                    $data .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string)$cell) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $last = xlsx_col(max(0, count($headers) - 1)) . (count($rows) + 1);
        $zip->addFromString("xl/worksheets/sheet$n.xml",
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$data</sheetData>"
            . ($rows ? '<autoFilter ref="A1:' . $last . '"/>' : '') . '</worksheet>');
        $sheetsXml .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $relsXml .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $ctXml .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $relsXml .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ctXml . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetsXml . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsXml . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd-mmm-yyyy"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE9F5"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->close();
}

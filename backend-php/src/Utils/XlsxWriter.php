<?php
declare(strict_types=1);

namespace App\Utils;

use ZipArchive;

/**
 * XlsxWriter
 * Minimal, dependency-free .xlsx generator. An XLSX file is just a ZIP of XML
 * parts, so we assemble the required parts by hand using PHP's built-in
 * ZipArchive (bundled with XAMPP — no Composer package required).
 *
 * Produces a genuine spreadsheet Excel opens natively (no "format mismatch"
 * prompt, unlike a renamed CSV). Values are written as inline strings/numbers.
 *
 * Usage:
 *   XlsxWriter::stream('registrations.xlsx',
 *       ['ID','Name','Email'],
 *       [[1,'Jane','jane@x.com'], [2,'John','john@x.com']]);
 */
final class XlsxWriter
{
    /** Build the .xlsx binary in-memory and return it as a string. */
    public static function build(array $headers, array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>'
        );

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Registrations" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>'
        );

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>'
        );

        // Build sheet rows.
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $allRows = !empty($headers) ? array_merge([$headers], $rows) : $rows;
        foreach ($allRows as $rIndex => $row) {
            $rowNum = $rIndex + 1;
            $sheet .= '<row r="' . $rowNum . '">';
            $col = 0;
            foreach ($row as $value) {
                $ref = self::colLetter($col) . $rowNum;
                if (is_numeric($value) && $value !== '' && !preg_match('/^0\d/', (string) $value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::esc((string) $value) . '</t></is></c>';
                }
                $col++;
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bin = file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    /** Build and stream the file to the browser as a download, then exit. */
    public static function stream(string $filename, array $headers, array $rows): never
    {
        $bin = self::build($headers, $rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }

    private static function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

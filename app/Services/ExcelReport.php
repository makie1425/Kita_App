<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class ExcelReport
{
    public static function create(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kita-report-');
        if ($path === false) {
            throw new RuntimeException('Could not create the Excel report.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            unlink($path);
            throw new RuntimeException('Could not open the Excel report.');
        }

        $open = true;
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/><sheet name="Summary" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');

            $columns = $data['columns'];
            $report = [array_keys($columns)];
            foreach ($data['rows'] as $row) {
                $cells = [];
                foreach ($columns as $key) {
                    $value = $row->$key ?? null;
                    $cells[] = in_array($key, $data['numericKeys'], true) && is_numeric($value)
                        ? (float) $value : (string) ($value ?? '');
                }
                $report[] = $cells;
            }
            $summary = [['KITA Report', $data['title']], ['Generated', $data['generated']], ['Prepared by', $data['preparedBy']], ['Records', count($data['rows'])], ['Notes', $data['note']]];
            foreach ($data['labels'] as $label => $value) {
                $summary[] = [(string) $label, (string) $value];
            }
            foreach ($data['summary'] as $label => $value) {
                $summary[] = [(string) $label, is_numeric($value) ? (float) $value : (string) $value];
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($report));
            $zip->addFromString('xl/worksheets/sheet2.xml', self::sheet($summary));
            $closed = $zip->close();
            $open = false;
            if (! $closed) {
                throw new RuntimeException('Could not finish the Excel report.');
            }
        } catch (\Throwable $error) {
            if ($open) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }

        return $path;
    }

    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $cells) {
            $number = $index + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($cells as $offset => $value) {
                $column = self::column($offset + 1);
                $reference = $column.$number;
                if (is_float($value) || is_int($value)) {
                    $xml .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } else {
                    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
                    $xml .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function column(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}

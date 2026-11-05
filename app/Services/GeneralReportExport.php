<?php
namespace App\Services;

use ZipArchive;
use RuntimeException;

class GeneralReportExport
{
    public static function csv(array $data): string
    {
        $path=tempnam(sys_get_temp_dir(), 'kita-general-');
        $zip=new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create report archive.');
        try {
            foreach ($data['sections'] as $section) {
                $out=fopen('php://temp','w+');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out,array_keys($section['columns']));
                foreach ($section['rows'] as $row) fputcsv($out,array_map(function ($key) use ($row) {
                    $value=(string)($row->$key ?? '');
                    return preg_match('/^[\s]*[=+@-]/u',$value) ? "'".$value : $value;
                },array_values($section['columns'])));
                rewind($out);$zip->addFromString($section['type'].'.csv',stream_get_contents($out));fclose($out);
            }
            $zip->close();return $path;
        } catch (\Throwable $e) { $zip->close();unlink($path);throw $e; }
    }

    public static function excel(array $data): string
    {
        $path=tempnam(sys_get_temp_dir(), 'kita-general-');$zip=new ZipArchive;
        if ($zip->open($path,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create workbook.');
        $sheets='';$rels='';$types='';
        try {
            foreach ($data['sections'] as $index=>$section) {
                $n=$index+1;
                $part=ExcelReport::create($section);
                $source=new ZipArchive;$source->open($part);
                $xml=$source->getFromName('xl/worksheets/sheet1.xml');$source->close();unlink($part);
                $zip->addFromString("xl/worksheets/sheet$n.xml",$xml);
                $name=htmlspecialchars(substr($section['title'],0,31),ENT_XML1|ENT_QUOTES,'UTF-8');
                $sheets.="<sheet name=\"$name\" sheetId=\"$n\" r:id=\"rId$n\"/>";
                $rels.="<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
                $types.="<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            }
            $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
            $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
            $zip->close();return $path;
        } catch (\Throwable $e) {$zip->close();unlink($path);throw $e;}
    }
}

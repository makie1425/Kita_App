<?php
namespace App\Services;

use ZipArchive;
use RuntimeException;

class GeneralReportExport
{
    public static function csv(array $data): string
    {
        $path=tempnam(sys_get_temp_dir(), 'kita-general-');
        $out=fopen($path,'w');
        if (!$out) throw new RuntimeException('Could not create CSV report.');
        $safe=fn ($value)=>preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',(string)$value) ? "'".$value : (string)$value;
        try {
            fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,[$data['title']]);
            fputcsv($out,['Generated',$data['generated'],'Prepared by',$safe($data['preparedBy'])]);
            foreach ($data['labels'] as $label=>$value) fputcsv($out,[$label,$safe($value)]);
            foreach ($data['sections'] as $section) {
                fputcsv($out,[]);fputcsv($out,[$section['title']]);
                fputcsv($out,array_keys($section['columns']));
                foreach ($section['rows'] as $row) fputcsv($out,array_map(fn ($key)=>$safe($row->$key ?? ''),array_values($section['columns'])));
                if (!count($section['rows'])) fputcsv($out,['No records for the selected filters.']);
                foreach ($section['summary'] as $label=>$value) fputcsv($out,[$label,$safe($value)]);
                fputcsv($out,['Report basis',$safe($section['note'])]);
            }
            fclose($out);return $path;
        } catch (\Throwable $e) { fclose($out);unlink($path);throw $e; }
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
                $xml=$source->getFromName('xl/worksheets/sheet1.xml');
                $summaryXml=$source->getFromName('xl/worksheets/sheet2.xml');
                $source->close();unlink($part);
                $doc=new \DOMDocument;$doc->loadXML($xml);
                $meta=new \DOMDocument;$meta->loadXML($summaryXml);
                $sheetData=$doc->getElementsByTagName('sheetData')->item(0);
                $offset=count($section['rows'])+3;
                foreach ($meta->getElementsByTagName('row') as $row) {
                    $copy=$doc->importNode($row,true);$number=(int)$copy->getAttribute('r')+$offset;
                    $copy->setAttribute('r',(string)$number);
                    foreach ($copy->getElementsByTagName('c') as $cell) $cell->setAttribute('r',preg_replace('/[0-9]+$/',(string)$number,$cell->getAttribute('r')));
                    $sheetData->appendChild($copy);
                }
                $xml=$doc->saveXML();
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

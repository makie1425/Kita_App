<!doctype html><html lang="en"><head><meta charset="utf-8"><title>General Report</title>
<style>
@page { size:A4 landscape; margin:12mm; }
body{font:10px DejaVu Sans,Arial,sans-serif;color:#17233b}h1{color:#143c60}h2{font-size:15px;page-break-after:avoid}table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7px;margin:12px 0 20px}th,td{padding:5px;border-bottom:1px solid #dce4eb;text-align:left;word-wrap:break-word}th{background:#edf1f7}thead{display:table-header-group}tr{page-break-inside:avoid}.numeric{text-align:right}.section{page-break-before:always}.section.first{page-break-before:auto}.notes{color:#526579;line-height:1.5}.actions{margin:16px 0}@media print{.actions{display:none}}
</style>@if(request('format') !== 'pdf') @include('reports.print-style') @endif
</head><body>
@if(request('format') !== 'pdf')<div class="actions"><button onclick="window.print()">Print</button></div>@endif
<h1>KITA / General Report</h1><p>Generated: {{ $generated }} | Prepared by: {{ $preparedBy }}</p>
<p>@foreach($labels as $label=>$value)<strong>{{ $label }}:</strong> {{ $value }} &nbsp; @endforeach</p><p>{{ $note }}</p>
@foreach($sections as $section)
<section class="section {{ $loop->first ? 'first' : '' }}">
<h2>{{ $section['title'] }}</h2>
@if($section['type']==='inventory')<p>Current stock as of {{ $generated }}</p>@endif
<p>@foreach($section['summary'] as $label=>$value)<strong>{{ $label }}:</strong> {{ $value }} &nbsp; @endforeach</p>
<table><thead><tr>@foreach($section['columns'] as $label=>$key)<th class="{{ in_array($key,$section['numericKeys'])?'numeric':'' }}">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($section['rows'] as $row)<tr>@foreach($section['columns'] as $key)<td class="{{ in_array($key,$section['numericKeys'])?'numeric':'' }}">{{ ($row->$key ?? null)===null ? '-' : (in_array($key,$section['moneyKeys'])?number_format((float)$row->$key,2):$row->$key) }}</td>@endforeach</tr>
@empty<tr><td colspan="{{ count($section['columns']) }}">No records for the selected filters.</td></tr>@endforelse
</tbody></table><p class="notes">{{ $section['note'] }}</p></section>
@endforeach</body></html>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KITA — {{ $title }}</title>
    <style>
        body{font:13px Arial,sans-serif;color:#17233b;margin:24px}h1{font-size:22px}p{line-height:1.5}table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #bbc4d0;padding:7px;text-align:left;overflow-wrap:anywhere}th{background:#edf1f7}thead{display:table-header-group}tr{break-inside:avoid}button{padding:12px;cursor:pointer}.table-wrap{overflow-x:auto}
        @page{size:A4 landscape;margin:12mm}@media print{.actions{display:none}body{margin:0}.table-wrap{overflow:visible}th{background:#eee}}
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Print / Save PDF</button><p>Choose “Save as PDF” in the print destination to download a PDF.</p></div>
    <h1>KITA — {{ $title }}</h1>
    <p>Generated: {{ $generated }} · {{ count($rows) }} records</p>
    <p>@foreach($labels as $label => $value)<strong>{{ $label }}:</strong> {{ $value }} &nbsp; @endforeach</p>
    <p>{{ $note }}</p>
    <div class="table-wrap"><table>
        <thead><tr>@foreach($columns as $label => $key)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@forelse($rows as $row)<tr>@foreach($columns as $key)<td>{{ $row->$key ?? '—' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}">No records match these filters.</td></tr>@endforelse</tbody>
    </table></div>
</body>
</html>

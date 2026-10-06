<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KITA — {{ $title }}</title>
    <style>
        body{font:13px Arial,sans-serif;color:#17233b;margin:24px}h1{font-size:22px}p{line-height:1.5}table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #bbc4d0;padding:7px;text-align:left;overflow-wrap:anywhere}th{background:#edf1f7}thead{display:table-header-group}tr{break-inside:avoid}button{padding:12px;cursor:pointer}.table-wrap{overflow-x:auto}
        *{box-sizing:border-box}body{background:#eef2f6;padding:28px;margin:0;color:#182c43}.sheet{max-width:1500px;margin:auto;background:white;padding:32px;box-shadow:0 3px 20px #182c4310}.actions{max-width:1500px;margin:0 auto 18px}button{background:#143c60;color:white;border:0;border-radius:5px;font-weight:bold}.heading{display:flex;justify-content:space-between;gap:25px;border-bottom:3px solid #143c60;padding-bottom:20px}.wordmark{font-size:28px;font-weight:800;letter-spacing:5px;color:#143c60}.eyebrow{text-transform:uppercase;letter-spacing:1.5px;font-size:10px;color:#65788b;margin-top:6px}h1{font-size:24px;margin:16px 0 0}.metadata{text-align:right;font-size:11px;line-height:1.8;color:#526579}.filters{padding:14px 0;border-bottom:1px solid #dce4eb;line-height:1.8}.summary{display:flex;gap:14px;margin:22px 0}.metric{flex:1;padding:15px;background:#f3f6f9;border:1px solid #dce4eb;border-radius:5px}.metric span{font-size:10px;text-transform:uppercase;color:#526579;display:block}.metric strong{display:block;font-size:21px;margin-top:8px;font-variant-numeric:tabular-nums}h2{font-size:11px;text-transform:uppercase;letter-spacing:1px;margin:24px 0 10px}table{font-size:10px}th{background:#143c60;color:white;font-size:9px;text-transform:uppercase}th,td{border:0;border-bottom:1px solid #dce4eb;padding:8px 6px;vertical-align:top}tbody tr:nth-child(even){background:#f6f8fa}.numeric{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.reference{font-family:Consolas,monospace;font-size:9px;min-width:125px}.notes{padding:12px 15px;border-left:3px solid #9aabbc;background:#f6f8fa;font-size:10px;color:#53677d;line-height:1.7;margin-top:22px}.footer{display:flex;justify-content:space-between;border-top:1px solid #dce4eb;margin-top:24px;padding-top:12px;font-size:10px;color:#66788a}.heading,.summary,.notes{break-inside:avoid}
        @page{size:A4 landscape;margin:12mm}@media print{.actions{display:none}body{margin:0;padding:0;background:white}.sheet{box-shadow:none;padding:0}.table-wrap{overflow:visible}th{background:#e8eef4;color:#182c43}.metric{background:white}}@media(max-width:700px){body{padding:10px}.sheet{padding:16px}.heading{display:block}.metadata{text-align:left;margin-top:14px}.summary{flex-wrap:wrap}.metric{min-width:130px}}
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Print / Save PDF</button><p>Choose “Save as PDF” in the print destination to download a PDF.</p></div>
    <main class="sheet">
    <header class="heading"><div><div class="wordmark">KITA</div><div class="eyebrow">Retail Management · Management Reports</div><h1>{{ $title }}</h1></div><div class="metadata"><strong>GENERATED</strong><br>{{ $generated }}<br><strong>PREPARED BY</strong><br>{{ $preparedBy }}<br>{{ count($rows) }} records</div></header>
    <div class="filters">@foreach($labels as $label => $value)<strong>{{ $label }}:</strong> {{ $value }} &nbsp; @endforeach</div>
    <section class="summary" aria-label="Report summary">@foreach($summary as $label => $value)<div class="metric"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>@endforeach</section>
    <h2>Detailed records</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th>@foreach($columns as $label => $key)<th class="{{ in_array($key, $numericKeys) ? 'numeric' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td>@foreach($columns as $key)<td class="{{ in_array($key, $numericKeys) ? 'numeric' : ($key === 'reference' ? 'reference' : '') }}">{{ $row->$key === null ? '—' : (in_array($key, $moneyKeys) ? number_format((float) $row->$key, 2) : (in_array($key, $numericKeys) ? number_format((float) $row->$key) : $row->$key)) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) + 1 }}">No records match these filters.</td></tr>@endforelse</tbody>
    </table></div>
    <aside class="notes"><strong>Report basis</strong><br>{{ $note }}<br>Amounts are in Philippine pesos (PHP). This management report is not a tax invoice or official receipt.</aside>
    <footer class="footer"><span>KITA · Internal management use</span><span>End of report · {{ count($rows) }} detail rows</span></footer>
    </main>
</body>
</html>

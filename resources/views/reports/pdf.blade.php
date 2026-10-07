<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
@page { margin: 30px 28px 42px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #21344a; }
.header { width:100%; border-bottom:3px solid #16456b; margin-bottom:14px; }
.header td { border:0; padding:0 0 14px; vertical-align:top; }
.brand { font-size:24px; font-weight:bold; color:#16456b; letter-spacing:4px; }
.subtitle { font-size:8px; color:#62758a; margin-top:4px; }
h1 { font-size:19px; margin:12px 0 0; }
.meta { text-align:right; font-size:8px; line-height:1.7; }
.filters { font-size:8px; line-height:1.7; margin-bottom:14px; }
.summary { width:100%; margin-bottom:18px; border-collapse:collapse; }
.summary td { background:#f0f4f8; border:1px solid #dbe3eb; padding:10px; vertical-align:top; }
.metric-label { font-size:8px; color:#566b80; }
.metric-value { font-size:16px; font-weight:bold; margin-top:6px; }
h2 { font-size:9px; letter-spacing:1px; margin:0 0 8px; text-transform:uppercase; }
.records { border-collapse:collapse; width:100%; table-layout:fixed; font-size:7px; }
.records th { background:#16456b; color:#fff; text-align:left; font-size:7px; padding:7px 5px; }
.records td { padding:7px 5px; border-bottom:1px solid #dbe3eb; vertical-align:top; overflow-wrap:break-word; word-wrap:break-word; }
.records tr:nth-child(even) { background:#f5f7fa; }
.records .numeric { text-align:right; }
.row-number { width:3%; }
thead { display:table-header-group; }
tr { page-break-inside:avoid; }
.notes { font-size:8px; line-height:1.6; margin-top:16px; padding:10px; background:#f0f4f8; border-left:3px solid #91a6ba; }
.footer { position:fixed; bottom:-24px; font-size:8px; color:#65788a; }
</style></head><body>
<div class="footer">KITA | Internal management report | PHP</div>
<table class="header"><tr><td><div class="brand">KITA</div><div class="subtitle">RETAIL MANAGEMENT / REPORTS</div><h1>{{ $title }}</h1></td><td class="meta"><strong>GENERATED</strong><br>{{ $generated }}<br><strong>PREPARED BY</strong><br>{{ $preparedBy }}<br>{{ count($rows) }} records</td></tr></table>
<div class="filters">@foreach($labels as $label => $value)<strong>{{ $label }}:</strong> {{ $value }} &nbsp;&nbsp; @endforeach</div>
<table class="summary"><tr>@foreach($summary as $label => $value)<td><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div></td>@endforeach</tr></table>
<h2>Detailed records</h2>
<table class="records"><thead><tr><th class="row-number">#</th>@foreach($columns as $label => $key)<th class="{{ in_array($key, $numericKeys) ? 'numeric' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
<tbody>@forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td>@foreach($columns as $key)<td class="{{ in_array($key, $numericKeys) ? 'numeric' : '' }}">{{ $row->$key === null ? '-' : (in_array($key, $moneyKeys) ? number_format((float) $row->$key, 2) : (in_array($key, $numericKeys) ? number_format((float) $row->$key) : $row->$key)) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns)+1 }}">No records match these filters.</td></tr>@endforelse</tbody></table>
<div class="notes"><strong>Report basis</strong><br>{{ $note }}<br>Amounts are in Philippine pesos (PHP). This management report is not a tax invoice or official receipt.</div>
</body></html>

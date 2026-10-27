<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Batch {{ $number }}</title>
<style>
body{font:13px Arial,sans-serif;color:#172033;margin:24px}h1{font-size:22px}h2{font-size:16px;margin-top:26px;break-after:avoid}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;overflow-wrap:anywhere}th,tfoot{background:#edf3ff}button{padding:10px 18px;cursor:pointer}.scroll{overflow:auto}.num{text-align:right}.totals{margin:24px 0 0 auto;width:340px;max-width:100%;break-inside:avoid}.total{font-weight:bold;background:#edf3ff}@media print{button{display:none}.scroll{overflow:visible}table{table-layout:fixed;font-size:11px}thead{display:table-header-group}tfoot{display:table-row-group}tr{break-inside:avoid}@page{size:A4 landscape;margin:12mm}}
</style></head>
<body><button type="button" onclick="window.print()">Print / Save PDF</button><h1>Batch {{ $number }}</h1>
<p>{{ $rows->pluck('productId')->unique()->count() }} products &middot; {{ $rows->count() }} stock entries<br>Generated: {{ $generatedAt }} ({{ config('app.timezone') }})</p>
<p>All entries in this batch are included, regardless of screen filters or pagination. Quantities use stock units. Order qty is the quantity due for this delivery; received qty is accepted stock in this batch. Deductions cover outstanding missing or damaged units. Amounts are in PHP.</p>
@foreach($tables as $title => $items)
<h2>{{ $title }}</h2>
<div class="scroll"><table><thead><tr><th>Item</th><th>Category</th><th>Order qty</th><th>Rcv qty</th><th>Discrepancies</th><th>Unit</th><th>Unit cost</th><th>Subtotal</th></tr></thead><tbody>
@foreach($items as $item)
<tr><td>{{ $item->name }}</td><td>{{ $item->category ?? 'Not recorded' }}</td><td class="num">{{ $item->ordered }}</td><td class="num">{{ $item->received }}</td><td>{{ $item->discrepancies }}</td><td>{{ $item->unit }}</td><td class="num">{{ number_format($item->unitCost, 2) }}</td><td class="num">{{ number_format($item->subtotalCents / 100, 2) }}</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="7">Table subtotal</th><th class="num">{{ number_format($items->sum('subtotalCents') / 100, 2) }}</th></tr></tfoot></table></div>
@endforeach
<table class="totals"><tbody><tr><th>Subtotal of all tables</th><td class="num">{{ number_format($subtotal, 2) }}</td></tr><tr><th>Deductions</th><td class="num">{{ number_format($deductions, 2) }}</td></tr><tr class="total"><th>Total cost</th><td class="num">{{ number_format($totalCost, 2) }}</td></tr></tbody></table>
</body></html>

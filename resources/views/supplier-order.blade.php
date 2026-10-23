<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Purchase Order {{ $order->id }}</title><style>body{font:14px Arial;color:#172033;max-width:1000px;margin:30px auto;padding:20px}h1{color:#1747e8}table{border-collapse:collapse;width:100%;margin:24px 0}th,td{padding:10px;border:1px solid #ccd4df;text-align:left}th{background:#eef3fa}.scroll{overflow:auto}button{padding:10px}@media print{button{display:none}.scroll{overflow:visible}thead{display:table-header-group}}</style></head><body>
<button onclick="window.print()">Print / Save PDF</button><h1>KITA ? Purchase Order</h1><h2>{{ $order->id }}</h2>
<p>Order date: {{ $order->created }}<br>Expected delivery: {{ ($order->expectedDeliveryDate ?? null) ?: 'To be agreed' }}</p>
<p><strong>Supplier:</strong> {{ $order->supplierName ?? $supplier?->name }}<br>{{ $supplier?->address }}<br>{{ $supplier?->contact }} {{ $supplier?->phone }}</p>
<p>Please supply the following quantities in the stock units shown. This purchase order does not confirm receipt or payment.</p>
<div class="scroll"><table><thead><tr><th>Item Code</th><th>Item</th><th>Stock Unit</th><th>Ordered Qty</th><th>Unit Cost (PHP)</th><th>Order Amount (PHP)</th></tr></thead><tbody>
@foreach($lines as $line)<tr><td>{{ $line->productId }}</td><td>{{ $line->name }}</td><td>{{ $line->unit }}</td><td>{{ $line->orderedQty }}</td><td>{{ number_format($line->unitCost,2) }}</td><td>{{ number_format($line->lineTotal,2) }}</td></tr>@endforeach
</tbody></table></div><p><strong>Total order amount: PHP {{ number_format($order->orderedValue,2) }}</strong></p>
@if($lines->contains(fn ($l)=>$l->carryoverQty > 0))<p>Includes previously approved outstanding quantities carried from legacy orders. These carryover quantities do not add a new order charge.</p>@endif
<p>Authorized signature: __________________________</p><p>Generated {{ now()->format('Y-m-d H:i:s T') }}</p></body></html>

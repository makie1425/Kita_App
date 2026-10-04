<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->id }} — KITA Purchase / Receiving Report</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f7f9fc;color:#172033;font:14px Arial,sans-serif;line-height:1.5}
        .toolbar{max-width:1000px;margin:20px auto;display:flex;gap:12px}.toolbar button,.toolbar a{padding:10px 18px;border:1px solid #1747e8;border-radius:6px;background:#1747e8;color:#fff;text-decoration:none;cursor:pointer}
        .paper{max-width:1000px;margin:20px auto;padding:40px;background:#fff;border:1px solid #e4e7ec}header{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #1747e8;padding-bottom:20px;gap:20px}header img{width:110px;height:auto}h1{font-size:20px;margin:0;color:#0f1f4a}h2{font-size:16px;margin-top:25px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px 25px;margin:25px 0}.meta strong{display:block;color:#667085;font-size:11px;text-transform:uppercase}.mono{font-family:monospace;overflow-wrap:anywhere}table{border-collapse:collapse;width:100%;margin:15px 0;font-size:12px}th,td{padding:9px;border:1px solid #d0d5dd;text-align:left;overflow-wrap:anywhere}th{background:#eaf0ff}td.num{text-align:right}.notice{border-left:4px solid #f59e0b;padding:10px;background:#fffbeb}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:50px;margin-top:60px;break-inside:avoid}.signature{border-top:1px solid #172033;padding-top:8px}.muted{color:#667085;font-size:12px}.notes{white-space:pre-wrap}.receipt{break-inside:avoid}footer{margin-top:30px;border-top:1px solid #e4e7ec;padding-top:10px;font-size:11px;color:#667085}
        @page{size:A4;margin:14mm}@media print{body{background:#fff;font-size:11px}.toolbar{display:none!important}.paper{margin:0;padding:0;max-width:none;border:0}header img{width:85px}h1{font-size:17px}table{font-size:10px}thead{display:table-header-group}tr{break-inside:avoid}th,td{padding:6px}.meta{gap:8px 20px}.signatures{margin-top:40px}a{color:inherit;text-decoration:none}}
        .paper{overflow-wrap:anywhere}.meta>*{min-width:0}.toolbar{flex-wrap:wrap}.toolbar>*{min-height:44px;text-align:center;font:inherit}.table-wrap{max-width:100%;overflow-x:auto}.table-wrap:focus-visible{outline:2px solid #1747e8;outline-offset:2px}
        @media screen{.toolbar,.paper{width:calc(100% - 32px)}.table-wrap table{min-width:680px}.table-wrap th,.table-wrap td{min-width:80px}header>div{min-width:0}}
        @media screen and (max-width:767px){.paper{padding:18px;margin:12px auto}.meta{grid-template-columns:minmax(0,1fr)}.toolbar{margin:12px auto}.toolbar>*{flex:1 1 120px}header{flex-wrap:wrap;gap:12px}header img{width:90px}h1{font-size:18px}.signatures{grid-template-columns:minmax(0,1fr);gap:40px}}
        @media print{.table-wrap{overflow:visible}.table-wrap table{min-width:0;table-layout:fixed}.table-wrap th,.table-wrap td{min-width:0}.receipt{break-inside:auto}.notes{overflow-wrap:anywhere}}
    </style>
</head>
<body>
<nav class="toolbar" aria-label="Report actions"><button type="button" onclick="window.print()">Print Report</button><a href="/">Return to KITA</a></nav>
<main class="paper">
    <header><img src="{{ asset('images/kita-logo.jpg') }}" alt="KITA"><div><h1>PURCHASE ORDER / RECEIVING REPORT</h1><div>KITA — Retail Management</div><strong class="mono">PO ID: {{ $order->id }}</strong></div></header>
    <section class="meta">
        <div><strong>Supplier</strong>{{ $order->supplierName ?? $supplier?->name ?? 'Not recorded' }}</div>
        <div><strong>Status</strong>{{ $order->status }}</div>
        <div><strong>Request date</strong>{{ $purchase?->requested_at ?? $purchase?->dateRequested ?? 'Not recorded' }}</div>
        <div><strong>Approval date</strong>{{ $purchase?->approved_at ?? 'Not recorded' }}</div>
        <div><strong>Requested by</strong>{{ $purchase?->requestedBy ?? 'Not recorded' }}</div>
        <div><strong>Approved by</strong>{{ $purchase?->approvedBy ?? 'Not recorded' }}</div>
        <div><strong>Receiving dates</strong>{{ $receipts->pluck('date')->filter()->unique()->implode(', ') ?: 'Not received' }}</div>
        <div><strong>Received by</strong>{{ $receipts->pluck('receivedBy')->filter()->unique()->implode(', ') ?: 'Not recorded' }}</div>
    </section>
    @if($order->itemRequestId && $order->itemRequestId !== $order->id)<p class="muted">Legacy request reference: {{ $order->itemRequestId }}. Original imported identifiers are retained.</p>@endif
    <h2>Purchase items — cumulative receiving</h2>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Purchase items"><table><thead><tr><th>Item</th><th>Category</th><th>Ordered Qty</th><th>Received Qty</th><th>Difference*</th><th>Unit</th><th>Unit Cost</th><th>Ordered Value</th></tr></thead><tbody>
    @foreach($lines as $line)
        <tr><td>{{ $line->name }}</td><td>{{ $line->category ?? 'Not recorded' }}</td><td class="num">{{ $line->orderedQty }}</td><td class="num">{{ $line->deliveredQty ?? 0 }}</td><td class="num">{{ ($line->deliveredQty ?? 0) - $line->orderedQty }}</td><td>{{ $line->unit ?? 'Not recorded' }}</td><td class="num">{{ number_format($line->unitCost, 2) }}</td><td class="num">{{ number_format($line->lineTotal, 2) }}</td></tr>
    @endforeach
    </tbody><tfoot><tr><th colspan="7">Total ordered value (₱)</th><td class="num">{{ number_format($order->orderedValue, 2) }}</td></tr></tfoot></table></div>
    <p class="muted">* Difference = received − ordered. Negative quantities are outstanding; positive quantities are excess. Quantities use the inventory unit shown for each item.</p>
    @if($lines->contains(fn ($line) => (int) $line->deliveredQty !== (int) $line->orderedQty))<p class="notice">Quantity discrepancies remain. This order is not marked fully received.</p>@endif
    <h2>Delivery transactions</h2>
    @forelse($receipts as $receipt)
        <section class="receipt"><strong class="mono">{{ $receipt->id }}</strong> · Supplier reference: {{ $receipt->deliveryReference ?? 'Not recorded' }}
        <p>Date received: {{ $receipt->date }} · Recorded at: {{ $receipt->received_at ?? 'Not recorded' }}<br>Received by: {{ $receipt->receivedBy ?? 'Not recorded' }} · Status after delivery: {{ $receipt->deliveryStatus }}</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Delivery items"><table><thead><tr><th>Item</th><th>Ordered Qty</th><th>Received Order</th><th>Unit</th><th>Actual unit cost</th><th>Batch</th><th>Expiry</th></tr></thead><tbody>
        @foreach($receiptLines->get($receipt->id, collect()) as $line)<tr><td>{{ $line->name ?? 'Item #'.$line->productId }}</td><td>{{ $line->poQty }}</td><td>{{ $line->deliveredQty }}</td><td>{{ $line->unit ?? 'Not recorded' }}</td><td>{{ $line->unitCost === null ? 'Not recorded' : number_format($line->unitCost, 2) }}</td><td>{{ $line->batchNumber ?? '-' }}</td><td>{{ $line->expiryDate ?? '-' }}</td></tr>@endforeach
        </tbody></table></div>
        @php($inspection = json_decode($receipt->inspection ?? 'null', true))
        @if(is_array($inspection))
            <h2>{{ collect($inspection)->contains(fn ($item) => $item['missing'] > 0) ? 'MISSING ITEM / SHORTAGE REPORT' : 'RECEIVING INSPECTION REPORT' }}</h2>
            <p>Inspected by {{ $receipt->receivedBy ?? 'Not recorded' }} at {{ $receipt->received_at }}. PO {{ $order->id }}.</p>
            <p class="muted">Snapshot after this delivery: received quantities include earlier deliveries. Actual items received were added to inventory; missing items were not added. Later deliveries do not alter this report.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Inspection items"><table><thead><tr><th>Item</th><th>Ordered</th><th>Total Received at Inspection</th><th>Missing</th><th>Excess</th><th>Unit</th></tr></thead><tbody>
            @foreach($inspection as $item)
                <tr><td>{{ $item['name'] }}</td><td>{{ $item['ordered'] }}</td><td>{{ $item['received'] }}</td><td>{{ $item['missing'] }}</td><td>{{ $item['excess'] }}</td><td>{{ $item['unit'] ?? 'Not recorded' }}</td></tr>
            @endforeach
            </tbody></table></div>
        @else
            <p class="muted">No inspection snapshot was recorded for this legacy delivery.</p>
        @endif
        <p class="notes">{{ $receipt->outcome ?: 'No receiving remarks.' }}</p></section>
    @empty<p>No deliveries recorded.</p>@endforelse
    <h2>Notes / remarks</h2><p class="notes">{{ $purchase?->notes ?: 'No request notes.' }}</p><p class="notes">Admin: {{ $purchase?->adminNote ?: 'No review notes.' }}</p>
    <section class="signatures"><div class="signature">Prepared / Received By<br>Signature: _____________________<br>Date: _________________________</div><div class="signature">Approved / Verified By<br>{{ $purchase?->approvedBy }}<br>Signature: _____________________<br>Date: _________________________</div></section>
    <footer>Generated by: {{ auth()->user()?->name ?? 'Not recorded' }}<br>Generated {{ now()->format('Y-m-d H:i:s T') }} · KITA business records · {{ $order->id }}</footer>
</main>
</body>
</html>

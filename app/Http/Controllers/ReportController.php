<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\ReceivingRemarks;
use App\Services\TransactionNumber;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __invoke(Request $request)
    {
        InventoryRules::authorize($request, ['manager', 'admin']);
        $filters = $request->validate([
            'type' => ['required', Rule::in(['inventory', 'sales', 'receipts', 'batches', 'movements', 'expiry', 'receiving', ...\App\Services\PurchasingReports::TYPES])],
            'format' => ['nullable', Rule::in(['json', 'print', 'csv', 'pdf'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'category' => ['nullable', 'string', 'max:100'],
            'brandId' => ['nullable', 'integer', 'exists:brands,id'],
            'subcategoryId' => ['nullable', 'integer', 'exists:subcategories,id'],
            'productId' => ['nullable', 'integer', 'exists:products,id'],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'lowStock' => ['nullable', 'boolean'],
            'batch' => ['nullable', 'string', 'max:100'],
            'manager' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:60'],
            'audit' => ['nullable', Rule::in(['missing', 'damaged', 'flagged', 'none'])],
            'expiryStatus' => ['nullable', Rule::in(['Nearly expired', 'Expired', 'Critical', 'Warning', 'Good', 'Unknown'])],
        ]);
        $type = $filters['type'];
        if (in_array($type, \App\Services\PurchasingReports::TYPES)) return $this->respond($request, $type, \App\Services\PurchasingReports::data($request));
        $query = DB::table('products as p')->leftJoin('brands as brand', 'brand.id', '=', 'p.brandId')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategoryId');
        $columns = ['Product' => 'product', 'Category' => 'category', 'Brand' => 'brand', 'Subcategory' => 'subcategory'];
        $select = ['p.name as product', 'p.category', 'brand.name as brand', 'sub.name as subcategory'];
        $dateColumn = null;
        $note = 'Category, brand and supplier filters use current product records.';
        if ($type === 'inventory') {
            $title = 'Current Inventory';
            $note .= ' This is current stock, not a historical balance. Date filters do not apply.';
            $columns += ['Item Code' => 'id', 'Barcode' => 'barcode', 'Stock Unit' => 'unit', 'Qty on Hand' => 'stockOnHand', 'Available Qty' => 'sellableStock', 'Expired Qty' => 'expiredStock', 'Recalled Qty' => 'recalledStock', 'Reserved Qty' => 'reservedStock', 'Availability Remarks' => 'exclusionReason', 'Reorder Level' => 'minimum', 'Stock Condition'=>'stockCondition', 'Inventory Value (PHP)'=>'inventoryValue', 'Valuation Basis'=>'valuationBasis', 'Selling Price (PHP)' => 'price', 'Item Status' => 'status'];
            $select = array_merge($select, ['p.id', 'p.barcode', 'p.stockUnit as unit', 'p.stock', 'p.expiry', 'p.minStock', 'p.minStock as minimum', 'p.unitPrice', 'p.price', 'p.status', 'p.archivedAt']);
            $note .= ' On-hand stock includes expired units awaiting disposal; sellable quantities exclude expired and recalled batches.';
        } elseif ($type === 'sales') {
            $title = 'Sales Transaction Detail';
            $query->join('transaction_lines as line', 'line.productId', '=', 'p.id')->join('transactions as t', 't.uuid', '=', 'line.transaction_uuid');
            $query->leftJoin('transaction_numbers as tn', 'tn.transaction_uuid', '=', 't.uuid');
            $dateColumn = 't.date';
            $note .= ' Amounts are recorded line totals; refunds shown are cumulative, including refunds after the sale date. All recorded transaction statuses are listed.';
            $columns = ['Transaction No.' => 'reference', 'Sale date' => 'date'] + $columns + ['Status' => 'status', 'Quantity' => 'quantity', 'Line total (PHP)' => 'amount', 'Refunded (PHP)' => 'refunded'];
            $select[0] = 'line.name as product';
            $select = array_merge($select, ['t.date', DB::raw('(SELECT MIN(clock_line.created_at) FROM transaction_lines AS clock_line WHERE clock_line.transaction_uuid = t.uuid) as recordedAt'), 't.uuid as reference', 'tn.id as numberId', 'tn.issued_date as numberDate', 't.status', 'line.qty as quantity', 'line.lineTotal as amount', 'line.refundedAmount as refunded']);
        } elseif ($type === 'receiving') {
            $title = 'Stock Receiving Audit';
            $query->join('receiving_record_lines as rl', 'rl.productId', '=', 'p.id')->join('receiving_records as rr', 'rr.id', '=', 'rl.receivingId')->join('purchase_orders as po', 'po.id', '=', 'rr.poId')->leftJoin('suppliers as supplier', 'supplier.id', '=', 'po.supplierId');
            $dateColumn = 'rr.date';
            $columns = ['PO Number' => 'reportPo', 'Receipt ID' => 'reference', 'Supplier' => 'supplier', 'Product ID'=>'productId','Category'=>'category','Batch Number'=>'batch','Expiration Date'=>'expiryDate','Product' => 'product', 'Remarks' => 'remarks', 'Discrepancy' => 'discrepancyType', 'Condition' => 'condition', 'Received' => 'quantity'];
            $select = ['p.id as productId','p.category','rl.batchNumber as batch','rl.expiryDate','p.name as product', 'rr.poId as reportPo', 'rr.id as reference', 'supplier.name as supplier', 'rr.inspection', 'rr.outcome', 'rr.discrepancyType', 'rl.conditionText as condition', 'rl.deliveredQty as quantity', 'rr.discrepancy'];
            $note = 'Recorded receipt lines and inspection findings. Open a PO number to view or print the complete receiving report.';
            if ($request->filled('supplierId')) $query->where('po.supplierId', $filters['supplierId']);
            if ($request->filled('audit')) {
                $audit = $filters['audit'];
                if ($audit === 'missing') $query->where('rr.discrepancyType', 'like', '%Missing%');
                elseif ($audit === 'damaged') $query->where(fn ($q) => $q->where('rr.discrepancyType', 'like', '%Damag%')->orWhere('rl.conditionText', 'like', '%Damag%'));
                elseif ($audit === 'none') $query->where('rr.discrepancy', false)->where(fn ($q) => $q->whereNull('rl.conditionText')->orWhere('rl.conditionText', 'not like', '%Damag%'));
                else $query->where('rr.discrepancy', true)->where(fn ($q) => $q->whereNull('rr.discrepancyType')->orWhere('rr.discrepancyType', 'not like', '%Missing%'))->where(fn ($q) => $q->whereNull('rl.conditionText')->orWhere('rl.conditionText', 'not like', '%Damag%'))->where(fn ($q) => $q->whereNull('rr.discrepancyType')->orWhere('rr.discrepancyType', 'not like', '%Damag%'));
            }
        } elseif ($type === 'expiry') {
            $title = 'Printable Expiration & Shelf-Life Audit';
            $query->join('inventory_batches as b', 'b.productId', '=', 'p.id')->where('b.quantityRemaining', '>', 0);
            $dateColumn = 'b.expiryDate';
            $columns = ['Product ID' => 'code', 'Batch' => 'batch', 'Product name' => 'product', 'Category' => 'category', 'Manufacturer'=>'manufacturer', 'Expiration date' => 'expiryDate', 'Stock remaining' => 'remaining', 'Unit' => 'unit', 'Expiration status' => 'status'];
            $select = array_merge($select, ['p.id as code', 'b.id', 'b.manufacturer', 'b.batchNumber as batch', 'b.lotNumber as lot', 'b.expiryDate', 'b.quantityRemaining as remaining', 'p.stockUnit as unit']);
            $note = 'Current remaining batches, including recalled stock. Dates filter expiration dates. Expired: today or earlier; critical: within 7 days; warning: within 30 days. Shelf locations are not recorded in this system.';
        } elseif (in_array($type, ['receipts', 'batches'])) {
            $title = $type === 'batches' ? 'Inventory Batches' : 'Received Stock';
            $query->join('inventory_batches as b', 'b.productId', '=', 'p.id');
            if ($type === 'receipts') $query->where('b.source', '<>', 'legacy_opening');
            if ($request->filled('batch')) $query->where('b.batchNumber', $filters['batch']);
            $dateColumn = 'b.receivedDate';
            $note = $type === 'batches' ? 'Recorded inventory batches, including opening balances.' : 'Actual stock batches received; legacy opening balances are excluded.';
            $columns += ['Product ID'=>'productId','Manufacturer'=>'manufacturer','PO Number'=>'poId','Received date' => 'date', 'Receipt' => 'reference', 'Supplier' => 'supplier', 'Quantity received' => 'quantity', 'Remaining now' => 'remaining', 'Unit cost (PHP)' => 'cost', 'Batch' => 'batch'];
            $query->leftJoin('suppliers as s', 's.id', '=', 'b.supplierId')->leftJoin('receiving_records as receipt','receipt.id','=','b.receiptId');
            $select = array_merge($select, ['p.id as productId','b.manufacturer','receipt.poId','b.receivedDate as date', 'b.receiptId as reference', 's.name as supplier', 'b.quantityReceived as quantity', 'b.quantityRemaining as remaining', 'b.unitCost as cost', 'b.batchNumber as batch']);
            $columns += ['Manufactured' => 'manufacturedDate', 'Expiry' => 'expiryDate'];
            $select = array_merge($select, ['b.manufacturedDate', 'b.expiryDate']);
        } else {
            $title = 'Stock Movements';
            $query->join('stock_movements as m', 'm.productId', '=', 'p.id');
            $query->leftJoin('transaction_numbers as tn', function ($join) {
                $join->on('tn.transaction_uuid', '=', 'm.referenceId')
                    ->whereIn('m.referenceType', ['checkout', 'payment_reservation', 'payment_release', 'refund', 'void', 'exchange', 'exchange_replacement']);
            });
            $dateColumn = 'm.created_at';
            $columns += ['Product ID'=>'productId','Batch references'=>'batchNumbers','Recorded at' => 'date', 'Type' => 'movement', 'Reference' => 'reference', 'Change' => 'change', 'Before' => 'before', 'After' => 'after'];
            $select = array_merge($select, ['m.productId','m.referenceType','m.referenceId','m.created_at as date', 'm.referenceType as movement', 'm.referenceId as reference', 'tn.id as numberId', 'tn.issued_date as numberDate', 'm.quantityChange as change', 'm.quantityBefore as before', 'm.quantityAfter as after']);
        }
        foreach (['category' => 'p.category', 'brandId' => 'p.brandId', 'subcategoryId' => 'p.subcategoryId', 'productId' => 'p.id', 'supplierId' => in_array($type, ['receipts', 'batches', 'expiry']) ? 'b.supplierId' : 'p.supplierId'] as $key => $column) {
            if ($request->filled($key) && !($type === 'receiving' && $key === 'supplierId')) {
                $query->where($column, $filters[$key]);
            }
        }
        if ($request->filled('status') && in_array($type, ['sales', 'inventory'])) {
            $column = match ($type) { 'sales' => 't.status', 'receiving' => 'rr.deliveryStatus', default => 'p.status' };
            if ($type === 'sales' && $filters['status'] === 'Completed') $query->whereIn($column, ['Unused', 'Completed']);
            else $query->where($column, $filters['status']);
        }
        if ($dateColumn) {
            if ($request->filled('from')) {
                $query->where($dateColumn, '>=', $filters['from']);
            }
            if ($request->filled('to')) {
                $query->where($dateColumn, '<', \Carbon\CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
            }
            if ($type === 'expiry') { $query->orderBy($dateColumn); } else { $query->orderByDesc($dateColumn); }
            $query->orderByDesc(match ($type) {
                'sales' => 'line.id', 'receiving' => 'rl.id', 'receipts', 'batches', 'expiry' => 'b.id', default => 'm.id',
            });
        }
        if ($request->boolean('lowStock') && $type !== 'inventory') {
            $query->whereColumn('p.stock', '<=', 'p.minStock');
            $note .= ' Restricted to products currently at or below their reorder level.';
        }
        if ($type === 'inventory') {
            $query->orderByDesc('p.created_at');
        }
        $rows = $query->orderByDesc('p.id')->get($select);
        if ($type === 'receiving') {
            foreach ($rows as $row) {
                $row->remarks = ReceivingRemarks::forReceipt($row);
                unset($row->inspection, $row->outcome);
            }
        }
        if ($type === 'movements') {
            $rows=\App\Services\BatchTrace::movements($rows);
            $note.=' Batch references identify batches linked to this transaction, not per-movement quantity allocations. Unrecorded historical links are not inferred.';
        }
        if ($type === 'expiry') {
            $today = now()->timezone('Asia/Manila')->startOfDay();
            foreach ($rows as $row) {
                $days = $row->expiryDate ? (int) $today->diffInDays(\Carbon\CarbonImmutable::parse($row->expiryDate, 'Asia/Manila')->startOfDay(), false) : null;
                $row->status = $days === null ? 'Unknown' : ($days <= 0 ? 'Expired' : ($days <= 7 ? 'Critical' : ($days <= 30 ? 'Warning' : 'Good')));
                $row->batch = ($row->batch ?: 'Batch #'.$row->id).($row->lot && $row->lot!==$row->batch ? ' / '.$row->lot : '');
            }
            $rows = $rows->sortBy(fn ($row) => [in_array($row->status, ['Critical', 'Warning']) ? 0 : ($row->status === 'Expired' ? 1 : 2), $row->expiryDate ?? '9999-12-31', $row->id])->values();
            if ($request->filled('expiryStatus')) {
                $rows = ($filters['expiryStatus'] === 'Nearly expired'
                    ? $rows->whereIn('status', ['Critical', 'Warning'])
                    : $rows->where('status', $filters['expiryStatus']))->values();
            }
        }
        if ($type === 'inventory') {
            $rows = collect(\App\Services\InventoryAvailability::products($rows->map(fn ($row) => (array) $row)->all()))->map(fn ($row) => (object) $row);
            if ($request->boolean('lowStock')) {
                $rows = $rows->where('needsReorder', true)->values();
                $note .= ' Restricted to active items whose available quantity is at or below the reorder level.';
            }
            foreach ($rows as $row) $row->exclusionReason = implode('; ', $row->exclusionReasons) ?: 'Eligible';
        }
        foreach ($rows as $row) {
            if (in_array($type,['expiry','receipts','batches']) && !filled($row->manufacturer ?? null)) $row->manufacturer='Not recorded';
            if (in_array($type, ['sales', 'movements'])) {
                $row->transactionId = $row->reference;
                if ($type === 'sales') {
                    $row->time = $row->recordedAt ? \Carbon\CarbonImmutable::parse($row->recordedAt, config('app.timezone'))->setTimezone('Asia/Manila')->format('H:i') : null;
                    unset($row->recordedAt);
                }
                if ($row->numberId) {
                    $row->reference = TransactionNumber::format($row->numberId, $row->numberDate);
                }
                if ($type === 'sales' && $row->status === 'Unused') {
                    $row->status = 'Completed';
                }
                unset($row->numberId, $row->numberDate);
            }
        }
        $moneyKeys = ['price', 'amount', 'refunded', 'cost', 'inventoryValue'];
        $numericKeys = array_merge($moneyKeys, ['stock', 'stockOnHand', 'sellableStock', 'expiredStock', 'recalledStock', 'reservedStock', 'minimum', 'quantity', 'remaining', 'change', 'before', 'after']);
        $summary = $type === 'expiry' ? [] : ['Records' => number_format($rows->count())];
        if ($type === 'sales') {
            $summary += ['Transactions' => number_format($rows->pluck('reference')->unique()->count()), 'Recorded line totals (PHP)' => number_format($rows->sum('amount'), 2), 'Cumulative refunds (PHP)' => number_format($rows->sum('refunded'), 2)];
            $summary['Recorded less refunds (PHP)'] = number_format($rows->sum('amount') - $rows->sum('refunded'), 2);
        } elseif ($type === 'inventory') {
            $summary += ['Items at reorder level' => number_format($rows->where('needsReorder', true)->count()), 'Inventory value (PHP)'=>number_format($rows->sum('inventoryValue'), 2)];
        } elseif (in_array($type, ['receipts', 'batches'])) {
            $summary += ['Received value (PHP)' => number_format($rows->sum(fn ($row) => round($row->quantity * $row->cost, 2)), 2)];
        }
        $preparedBy = $request->user()->name;
        $generated = now()->timezone('Asia/Manila')->format('Y-m-d H:i:s').' Asia/Manila';
        $labels = ['Type' => $title];
        foreach (['status' => 'Status', 'audit' => 'Discrepancy audit'] as $key => $label) if ($request->filled($key) && !($type === 'receiving' && $key === 'status')) $labels[$label] = $filters[$key];
        if ($type === 'expiry' && $request->filled('expiryStatus')) $labels['Expiration status'] = $filters['expiryStatus'];
        foreach (['from' => 'From', 'to' => 'To', 'category' => 'Category', 'batch'=>'Batch'] as $key => $label) {
            if ($request->filled($key) && ! ($type === 'inventory' && in_array($key, ['from', 'to']))) {
                $labels[$label] = $filters[$key];
            }
        }
        foreach (['brandId' => ['brands', 'Brand'], 'subcategoryId' => ['subcategories', 'Subcategory'], 'productId' => ['products', 'Product'], 'supplierId' => ['suppliers', 'Supplier']] as $key => [$table, $label]) {
            if ($request->filled($key)) {
                $labels[$label] = DB::table($table)->where('id', $filters[$key])->value('name');
            }
        }
        return $this->respond($request, $type, compact('title', 'columns', 'rows', 'note', 'generated', 'labels', 'summary', 'moneyKeys', 'numericKeys', 'preparedBy'));
    }

    public function respond(Request $request, string $type, array $data)
    {
        extract($data);
        if ($request->input('format') === 'csv') {
            return response()->streamDownload(function () use ($rows, $columns) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_keys($columns));
                foreach ($rows as $row) {
                    fputcsv($out, array_map(function ($key) use ($row) {
                        $value = (string) ($row->$key ?? '');

                        return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
                    }, array_values($columns)));
                }
                fclose($out);
            }, 'kita-'.$type.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $data = compact('title', 'columns', 'rows', 'note', 'generated', 'labels', 'summary', 'moneyKeys', 'numericKeys', 'preparedBy');
        if ($request->input('format') === 'pdf') {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('fontCache', storage_path('framework/cache'));
            $options->set('tempDir', storage_path('framework/cache'));
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('reports.pdf', $data)->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            $pdf->getCanvas()->page_text(715, 565, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.35, 0.4, 0.45]);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="kita-'.$type.'-'.now()->timezone('Asia/Manila')->format('Y-m-d').'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        if ($request->input('format') === 'print') {
            return response()->view('reports.print', $data)->header('Cache-Control', 'no-store');
        }

        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}

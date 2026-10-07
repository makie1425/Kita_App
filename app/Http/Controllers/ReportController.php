<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
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
            'type' => ['required', Rule::in(['inventory', 'sales', 'receipts', 'movements'])],
            'format' => ['nullable', Rule::in(['json', 'print', 'csv', 'pdf'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'category' => ['nullable', 'string', 'max:100'],
            'brandId' => ['nullable', 'integer', 'exists:brands,id'],
            'subcategoryId' => ['nullable', 'integer', 'exists:subcategories,id'],
            'productId' => ['nullable', 'integer', 'exists:products,id'],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'lowStock' => ['nullable', 'boolean'],
        ]);
        $type = $filters['type'];
        $query = DB::table('products as p')->leftJoin('brands as brand', 'brand.id', '=', 'p.brandId')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategoryId');
        $columns = ['Product' => 'product', 'Category' => 'category', 'Brand' => 'brand', 'Subcategory' => 'subcategory'];
        $select = ['p.name as product', 'p.category', 'brand.name as brand', 'sub.name as subcategory'];
        $dateColumn = null;
        $note = 'Category, brand and supplier filters use current product records.';
        if ($type === 'inventory') {
            $title = 'Current Inventory';
            $note .= ' This is current stock, not a historical balance. Date filters do not apply.';
            $columns += ['Stock ID' => 'id', 'Barcode' => 'barcode', 'Unit' => 'unit', 'Stock' => 'stock', 'Reorder level' => 'minimum', 'Retail price (PHP)' => 'price', 'Status' => 'status'];
            $select = array_merge($select, ['p.id', 'p.barcode', 'p.stockUnit as unit', 'p.stock', 'p.minStock as minimum', 'p.price', 'p.status']);
        } elseif ($type === 'sales') {
            $title = 'Sales Transaction Detail';
            $query->join('transaction_lines as line', 'line.productId', '=', 'p.id')->join('transactions as t', 't.uuid', '=', 'line.transaction_uuid');
            $query->leftJoin('transaction_numbers as tn', 'tn.transaction_uuid', '=', 't.uuid');
            $dateColumn = 't.date';
            $note .= ' Amounts are recorded line totals; refunds shown are cumulative, including refunds after the sale date. All recorded transaction statuses are listed.';
            $columns = ['Transaction No.' => 'reference', 'Sale date' => 'date'] + $columns + ['Status' => 'status', 'Quantity' => 'quantity', 'Line total (PHP)' => 'amount', 'Refunded (PHP)' => 'refunded'];
            $select[0] = 'line.name as product';
            $select = array_merge($select, ['t.date', 't.uuid as reference', 'tn.id as numberId', 'tn.issued_date as numberDate', 't.status', 'line.qty as quantity', 'line.lineTotal as amount', 'line.refundedAmount as refunded']);
        } elseif ($type === 'receipts') {
            $title = 'Received Stock';
            $query->join('inventory_batches as b', 'b.productId', '=', 'p.id')->where('b.source', '<>', 'legacy_opening');
            $dateColumn = 'b.receivedDate';
            $note = 'Actual stock batches received; legacy opening balances are excluded. Supplier filter uses the receipt supplier.';
            $columns += ['Received date' => 'date', 'Receipt' => 'reference', 'Supplier' => 'supplier', 'Quantity received' => 'quantity', 'Remaining now' => 'remaining', 'Unit cost (PHP)' => 'cost', 'Batch' => 'batch'];
            $query->leftJoin('suppliers as s', 's.id', '=', 'b.supplierId');
            $select = array_merge($select, ['b.receivedDate as date', 'b.receiptId as reference', 's.name as supplier', 'b.quantityReceived as quantity', 'b.quantityRemaining as remaining', 'b.unitCost as cost', 'b.batchNumber as batch']);
        } else {
            $title = 'Stock Movements';
            $query->join('stock_movements as m', 'm.productId', '=', 'p.id');
            $query->leftJoin('transaction_numbers as tn', function ($join) {
                $join->on('tn.transaction_uuid', '=', 'm.referenceId')
                    ->whereIn('m.referenceType', ['checkout', 'payment_reservation', 'payment_release', 'refund', 'void', 'exchange', 'exchange_replacement']);
            });
            $dateColumn = 'm.created_at';
            $columns += ['Recorded at' => 'date', 'Type' => 'movement', 'Reference' => 'reference', 'Change' => 'change', 'Before' => 'before', 'After' => 'after'];
            $select = array_merge($select, ['m.created_at as date', 'm.referenceType as movement', 'm.referenceId as reference', 'tn.id as numberId', 'tn.issued_date as numberDate', 'm.quantityChange as change', 'm.quantityBefore as before', 'm.quantityAfter as after']);
        }
        foreach (['category' => 'p.category', 'brandId' => 'p.brandId', 'subcategoryId' => 'p.subcategoryId', 'productId' => 'p.id', 'supplierId' => $type === 'receipts' ? 'b.supplierId' : 'p.supplierId'] as $key => $column) {
            if ($request->filled($key)) {
                $query->where($column, $filters[$key]);
            }
        }
        if ($dateColumn) {
            if ($request->filled('from')) {
                $query->whereDate($dateColumn, '>=', $filters['from']);
            }
            if ($request->filled('to')) {
                $query->whereDate($dateColumn, '<=', $filters['to']);
            }
            $query->orderByDesc($dateColumn);
            $query->orderByDesc(match ($type) {
                'sales' => 'line.id', 'receipts' => 'b.id', default => 'm.id',
            });
        }
        if ($request->boolean('lowStock')) {
            $query->whereColumn('p.stock', '<=', 'p.minStock');
            $note .= ' Restricted to products currently at or below their reorder level.';
        }
        if ($type === 'inventory') {
            $query->orderByDesc('p.created_at');
        }
        $rows = $query->orderByDesc('p.id')->get($select);
        foreach ($rows as $row) {
            if (in_array($type, ['sales', 'movements'])) {
                $row->transactionId = $row->reference;
                if ($row->numberId) {
                    $row->reference = TransactionNumber::format($row->numberId, $row->numberDate);
                }
                if ($type === 'sales' && $row->status === 'Unused') {
                    $row->status = 'Completed';
                }
                unset($row->numberId, $row->numberDate);
            }
        }
        $moneyKeys = ['price', 'amount', 'refunded', 'cost'];
        $numericKeys = array_merge($moneyKeys, ['stock', 'minimum', 'quantity', 'remaining', 'change', 'before', 'after']);
        $summary = ['Detail rows' => number_format($rows->count())];
        if ($type === 'sales') {
            $summary += ['Transactions' => number_format($rows->pluck('reference')->unique()->count()), 'Recorded line totals (PHP)' => number_format($rows->sum('amount'), 2), 'Cumulative refunds (PHP)' => number_format($rows->sum('refunded'), 2)];
        } elseif ($type === 'inventory') {
            $summary += ['Products at reorder level' => number_format($rows->filter(fn ($row) => $row->stock <= $row->minimum)->count())];
        } elseif ($type === 'receipts') {
            $summary += ['Received value (PHP)' => number_format($rows->sum(fn ($row) => round($row->quantity * $row->cost, 2)), 2)];
        }
        $preparedBy = $request->user()->name;
        $generated = now()->timezone('Asia/Manila')->format('Y-m-d H:i:s').' Asia/Manila';
        $labels = ['Type' => $title];
        foreach (['from' => 'From', 'to' => 'To', 'category' => 'Category'] as $key => $label) {
            if ($request->filled($key) && ! ($type === 'inventory' && in_array($key, ['from', 'to']))) {
                $labels[$label] = $filters[$key];
            }
        }
        foreach (['brandId' => ['brands', 'Brand'], 'subcategoryId' => ['subcategories', 'Subcategory'], 'productId' => ['products', 'Product'], 'supplierId' => ['suppliers', 'Supplier']] as $key => [$table, $label]) {
            if ($request->filled($key)) {
                $labels[$label] = DB::table($table)->where('id', $filters[$key])->value('name');
            }
        }
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

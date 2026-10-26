<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchReportController extends Controller
{
    public function __invoke(Request $request, int $id)
    {
        InventoryRules::authorize($request);
        $entry = DB::table('inventory_batches')->where('id', $id)->first();
        abort_unless($entry, 404);
        $query = DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
            ->leftJoin('receiving_records as r', 'r.id', '=', 'b.receiptId')
            ->leftJoin('suppliers as s', 's.id', '=', 'b.supplierId');
        if ($entry->batchNumber) $query->where('b.batchNumber', $entry->batchNumber);
        else $query->where('b.id', $entry->id);
        $rows = $query->orderBy('p.name')->orderBy('b.id')->get([
            'b.*', 'p.name', 'p.stockUnit', 's.name as supplier', 'r.poId', 'r.received_at', 'r.receivedBy',
        ]);
        return response()->view('reports.batch', ['number' => $entry->batchNumber ?: 'Batch #'.$id,
            'rows' => $rows, 'generatedAt' => now()])->header('Cache-Control', 'private, no-store');
    }
}

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
        $entry = \App\Services\WorkspaceRead::table('inventory_batches')->where('id', $id)->first();
        abort_unless($entry, 404);
        $query = \App\Services\WorkspaceRead::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
            ->leftJoin('receiving_records as r', 'r.id', '=', 'b.receiptId')
            ->leftJoin('suppliers as s', 's.id', '=', 'b.supplierId');
        if (config('workspaces.enabled')) {
            $workspaceId = \App\Services\WorkspaceWrite::requireId();
            $query->where('p.workspace_id', $workspaceId)
                ->where(fn ($joined) => $joined->whereNull('r.id')->orWhere('r.workspace_id', $workspaceId))
                ->where(fn ($joined) => $joined->whereNull('s.id')->orWhere('s.workspace_id', $workspaceId));
        }
        if ($entry->batchNumber) $query->where('b.batchNumber', $entry->batchNumber);
        else $query->where('b.id', $entry->id);
        $rows = $query->orderBy('p.name')->orderBy('b.id')->get([
            'b.*', 'p.name', 'p.category', 'p.stockUnit', 's.name as supplier', 'r.poId', 'r.received_at', 'r.receivedBy',
        ]);
        return response()->view('reports.batch', ['number' => $entry->batchNumber ?: 'Batch #'.$id,
            'rows' => $rows, ...\App\Services\BatchPrintReport::build($rows),
            'generatedAt' => now()])->header('Cache-Control', 'private, no-store');
    }
}

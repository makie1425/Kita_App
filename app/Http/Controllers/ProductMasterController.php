<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\WorkspaceRead;
use App\Services\WorkspaceWrite;
use Database\Seeders\RetailBrandSeeder;
use Database\Seeders\RetailSubcategorySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductMasterController extends Controller
{
    public function populateBrands(Request $request)
    {
        InventoryRules::authorize($request);
        WorkspaceWrite::requireId();

        return DB::transaction(function () use ($request) {
            InventoryRules::lockActor($request);
            WorkspaceRead::table('categories')->orderBy('name')->lockForUpdate()->get();
            $before = WorkspaceRead::table('brands')->count();
            (new RetailBrandSeeder)->run();
            $added = WorkspaceRead::table('brands')->count() - $before;
            InventoryRules::audit($request->user()->name, 'Added standard brands', 'Catalog', null, (string) $added);

            return response()->json(['added' => $added, 'message' => $added.' standard brands added.']);
        });
    }

    public function populateSubcategories(Request $request)
    {
        InventoryRules::authorize($request);
        WorkspaceWrite::requireId();

        return DB::transaction(function () use ($request) {
            InventoryRules::lockActor($request);
            // Serialize catalog imports, including requests from different managers.
            WorkspaceRead::table('categories')->orderBy('name')->lockForUpdate()->get();
            $before = WorkspaceRead::table('subcategories')->count();
            (new RetailSubcategorySeeder)->run();
            $added = WorkspaceRead::table('subcategories')->count() - $before;
            InventoryRules::audit($request->user()->name, 'Added standard subcategories', 'Catalog', null, (string) $added);

            return response()->json(['added' => $added, 'message' => $added.' standard subcategories added.']);
        });
    }

    public function save(Request $request, string $kind, ?int $id = null)
    {
        InventoryRules::authorize($request);
        WorkspaceWrite::requireId();
        abort_unless(in_array($kind, ['brands', 'subcategories']), 404);
        if ($id) abort_unless(WorkspaceRead::table($kind)->where('id', $id)->exists(), 404);
        $rules = ['name' => ['required', 'string', 'max:100'], 'status' => ['required', Rule::in(['Active', 'Inactive'])]];
        if ($kind === 'subcategories') {
            $rules['category'] = ['required', Rule::exists('categories', 'name')->where('status', 'Active')->where(fn ($q) => WorkspaceWrite::scopeValidation($q))];
        }
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate($rules);
        $unique = Rule::unique($kind, 'name')->ignore($id)->where(fn ($q) => WorkspaceWrite::scopeValidation($q));
        if ($kind === 'subcategories') {
            $unique->where('category', $data['category']);
        }
        $request->validate(['name' => [$unique]]);

        return DB::transaction(function () use ($request, $kind, $id, $data) {
            InventoryRules::lockActor($request);
            if ($id) {
                $existing = WorkspaceRead::table($kind)->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404);
                if ($kind === 'subcategories' && $data['category'] !== $existing->category) {
                    abort_if(WorkspaceRead::table('products')->where('subcategoryId', $id)->exists(), 422, 'A used subcategory cannot move to another category.');
                }
                WorkspaceRead::table($kind)->where('id', $id)->update($data);
            } else {
                $id = WorkspaceRead::table($kind)->insertGetId(WorkspaceWrite::owned($data));
            }
            InventoryRules::audit($request->user()->name, 'Saved '.$kind, (string) $id, null, json_encode($data));

            return response()->json(['record' => WorkspaceRead::table($kind)->where('id', $id)->first()]);
        });
    }

    public function inventory(Request $request)
    {
        InventoryRules::authorize($request);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'movementType'=>['nullable', Rule::in(array_keys(\App\Services\InventoryLedger::TYPES))], 'stockCondition'=>['nullable',Rule::in(['reorder','out','unavailable'])], 'activeOnly'=>['nullable','boolean']]);
        $products = \App\Services\WorkspaceRead::table('products as p');
        $movements = \App\Services\WorkspaceRead::table('stock_movements as m')->joinSub(\App\Services\WorkspaceRead::table('products'), 'p', 'p.id', '=', 'm.productId');
        foreach (['category' => 'p.category', 'brandId' => 'p.brandId', 'subcategoryId' => 'p.subcategoryId', 'productId' => 'p.id'] as $key => $column) {
            if ($request->filled($key)) {
                $products->where($column, $request->input($key));
                $movements->where($column, $request->input($key));
            }
        }
        if ($request->filled('supplierId')) {
            $products->where(fn ($q)=>$q->where('p.supplierId',$request->input('supplierId'))->orWhereIn('p.id', WorkspaceRead::table('supplier_products')->where('supplierId',$request->input('supplierId'))->select('productId')));
            $movements->where('p.supplierId', $request->input('supplierId'));
        }
        if ($request->filled('from')) {
            $movements->whereDate('m.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $movements->whereDate('m.created_at', '<=', $request->input('to'));
        }
        if ($request->filled('movementType')) $movements->where('m.referenceType', $request->input('movementType'));
        $query = \App\Services\WorkspaceRead::table('inventory_batches as b')->joinSub(\App\Services\WorkspaceRead::table('products'), 'p', 'p.id', '=', 'b.productId')
            ->leftJoinSub(\App\Services\WorkspaceRead::table('brands'), 'brand', 'brand.id', '=', 'p.brandId')->leftJoinSub(\App\Services\WorkspaceRead::table('subcategories'), 'sub', 'sub.id', '=', 'p.subcategoryId')
            ->leftJoinSub(\App\Services\WorkspaceRead::table('suppliers'), 'supplier', 'supplier.id', '=', 'b.supplierId')
            ->leftJoinSub(\App\Services\WorkspaceRead::table('receiving_records'), 'receipt', 'receipt.id', '=', 'b.receiptId')
            ->leftJoinSub(\App\Services\WorkspaceRead::table('purchase_orders'), 'batch_po', 'batch_po.id', '=', 'receipt.poId')
            ->leftJoinSub(\App\Services\WorkspaceRead::table('item_requests'), 'batch_request', 'batch_request.id', '=', 'batch_po.itemRequestId');
        foreach (['category' => 'p.category', 'brandId' => 'p.brandId', 'subcategoryId' => 'p.subcategoryId', 'productId' => 'p.id', 'supplierId' => 'b.supplierId'] as $key => $column) {
            if ($request->filled($key)) {
                $query->where($column, $request->input($key));
            }
        }
        if ($request->filled('from')) {
            $query->where('b.receivedDate', '>=', $request->date('from')->format('Y-m-d'));
        }
        if ($request->filled('to')) {
            $query->where('b.receivedDate', '<=', $request->date('to')->format('Y-m-d'));
        }

        if ($request->boolean('activeOnly')) $products->where('p.status','Active')->whereNull('p.archivedAt');
        $current = collect(\App\Services\InventoryAvailability::products($products->orderByDesc('p.created_at')->orderByDesc('p.id')->get(['p.*'])->map(fn ($p) => (array) $p)->all()));
        if ($request->filled('stockCondition')) $current=$current->filter(fn ($p)=>match ($request->input('stockCondition')) {
            'reorder'=>(bool)$p['needsReorder'], 'out'=>$p['stockCondition']==='Out of stock', 'unavailable'=>(int)$p['unavailableStock']>0, default=>true
        })->values();
        if ($request->boolean('lowStock')) $current = $current->where('needsReorder', true)->values();
        $movementCount = (clone $movements)->count();
        $movements->leftJoinSub(\App\Services\WorkspaceRead::table('users'), 'actor', fn ($join) => $join->on('actor.id', '=', 'm.userId')->on('actor.role', '=', 'm.userRole'))
            ->leftJoinSub(\App\Services\WorkspaceRead::table('receiving_records'), 'receipt', fn ($join) => $join->on('receipt.id', '=', 'm.referenceId')->where('m.referenceType', 'purchase_receiving'));
        return response()->json([
            'batches' => \App\Services\InventoryAvailability::batches($query->orderByDesc('b.receivedDate')->orderByDesc('b.id')->get(['b.*', 'p.name', 'p.category', 'p.size', 'p.sizeUnit', 'p.stockUnit', 'p.status as productStatus', 'p.archivedAt as productArchivedAt', 'brand.name as brand', 'sub.name as subcategory', 'supplier.name as supplier', 'receipt.poId', 'receipt.deliveryReference', 'receipt.receivedBy', 'batch_request.requested_at as requestedAt', 'receipt.received_at as receivedAt'])),
            'products' => $current,
            'movements' => \App\Services\InventoryLedger::describe(\App\Services\BatchTrace::movements($movements->orderByDesc('m.id')->limit(500)->get(['m.*', 'p.name', 'p.stockUnit', 'p.barcode', 'p.category', 'actor.name as recordedBy', 'receipt.poId']))),
            'movementCount'=>$movementCount, 'movementLimit'=>500, 'movementTypes'=>\App\Services\InventoryLedger::TYPES,
            'checkedAt'=>now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}

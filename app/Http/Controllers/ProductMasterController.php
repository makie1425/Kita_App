<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use Database\Seeders\RetailSubcategorySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductMasterController extends Controller
{
    public function populateSubcategories(Request $request)
    {
        InventoryRules::authorize($request);

        return DB::transaction(function () use ($request) {
            InventoryRules::lockActor($request);
            // Serialize catalog imports, including requests from different managers.
            DB::table('categories')->orderBy('name')->lockForUpdate()->get();
            $before = DB::table('subcategories')->count();
            (new RetailSubcategorySeeder)->run();
            $added = DB::table('subcategories')->count() - $before;
            InventoryRules::audit($request->user()->name, 'Added standard subcategories', 'Catalog', null, (string) $added);

            return response()->json(['added' => $added, 'message' => $added.' standard subcategories added.']);
        });
    }

    public function save(Request $request, string $kind, ?int $id = null)
    {
        InventoryRules::authorize($request);
        abort_unless(in_array($kind, ['brands', 'subcategories']), 404);
        $rules = ['name' => ['required', 'string', 'max:100'], 'status' => ['required', Rule::in(['Active', 'Inactive'])]];
        if ($kind === 'subcategories') {
            $rules['category'] = ['required', Rule::exists('categories', 'name')->where('status', 'Active')];
        }
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate($rules);
        $unique = Rule::unique($kind, 'name')->ignore($id);
        if ($kind === 'subcategories') {
            $unique->where('category', $data['category']);
        }
        $request->validate(['name' => [$unique]]);

        return DB::transaction(function () use ($request, $kind, $id, $data) {
            InventoryRules::lockActor($request);
            if ($id) {
                $existing = DB::table($kind)->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404);
                if ($kind === 'subcategories' && $data['category'] !== $existing->category) {
                    abort_if(DB::table('products')->where('subcategoryId', $id)->exists(), 422, 'A used subcategory cannot move to another category.');
                }
                DB::table($kind)->where('id', $id)->update($data);
            } else {
                $id = DB::table($kind)->insertGetId($data);
            }
            InventoryRules::audit($request->user()->name, 'Saved '.$kind, (string) $id, null, json_encode($data));

            return response()->json(['record' => DB::table($kind)->where('id', $id)->first()]);
        });
    }

    public function inventory(Request $request)
    {
        InventoryRules::authorize($request);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $products = DB::table('products as p');
        $movements = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.productId');
        foreach (['category' => 'p.category', 'brandId' => 'p.brandId', 'subcategoryId' => 'p.subcategoryId', 'productId' => 'p.id'] as $key => $column) {
            if ($request->filled($key)) {
                $products->where($column, $request->input($key));
                $movements->where($column, $request->input($key));
            }
        }
        if ($request->filled('supplierId')) {
            $products->where('p.supplierId', $request->input('supplierId'));
            $movements->where('p.supplierId', $request->input('supplierId'));
        }
        if ($request->filled('from')) {
            $movements->whereDate('m.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $movements->whereDate('m.created_at', '<=', $request->input('to'));
        }
        if ($request->boolean('lowStock')) {
            $products->whereColumn('p.stock', '<=', 'p.minStock');
        }
        $query = DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
            ->leftJoin('brands as brand', 'brand.id', '=', 'p.brandId')->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategoryId')
            ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'b.supplierId');
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

        return response()->json([
            'batches' => $query->orderByDesc('b.receivedDate')->orderByDesc('b.id')->get(['b.*', 'p.name', 'p.category', 'p.size', 'p.sizeUnit', 'p.stockUnit', 'brand.name as brand', 'sub.name as subcategory', 'supplier.name as supplier']),
            'products' => $products->orderBy('p.name')->get(['p.*']),
            'movements' => $movements->orderByDesc('m.id')->limit(500)->get(['m.*', 'p.name']),
        ])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\InventoryAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAvailabilityController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'productId' => ['nullable', 'integer', 'min:1', 'prohibits:productIds'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_with:productId'],
            'productIds' => ['sometimes', 'array', 'min:1', 'max:500'],
            'productIds.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        // A read-only preflight must not wait for a product write lock.
        // Checkout separately locks and revalidates stock before committing a sale.
        $request->attributes->set('workspace_scope_snapshot',true);
        try {
        return DB::transaction(function () use ($data) {
            $query = \App\Services\WorkspaceRead::table('products')->orderBy('id');
            if (isset($data['productId'])) {
                $product = $query->where('id', $data['productId'])->first();
                abort_unless($product, 404, 'Product not found.');
                $products = [InventoryAvailability::assertSellable($product, $data['quantity'])];
            } else {
                if (isset($data['productIds'])) $query->whereIn('id', $data['productIds']);
                $rows = $query->get();
                abort_if(isset($data['productIds']) && $rows->count() !== count($data['productIds']), 404, 'A requested product is unavailable to this account.');
                $products = InventoryAvailability::products($rows->map(fn ($p) => (array) $p)->all());
            }

            $promotions = \App\Services\WorkspaceRead::table('promotions')->where('startDate', '<=', now()->toDateString())
                ->where('endDate', '>=', now()->toDateString())->orderBy('id')->get();
            $links = \App\Services\WorkspaceRead::table('promotion_products')->whereIn('promotionId', $promotions->pluck('id'))->get()->groupBy('promotionId');
            foreach ($promotions as $promotion) {
                $promotion->productIds = ($links->get($promotion->id) ?? collect())->pluck('productId')->all();
            }
            return response()->json(['products' => $products, 'activePromotions' => $promotions, 'businessDate' => now()->toDateString(), 'checkedAt' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
        });
        } finally {
            $request->attributes->remove('workspace_scope_snapshot');
            $request->attributes->remove('workspace_verified_scopes');
        }
    }
}

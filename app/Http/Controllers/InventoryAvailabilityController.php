<?php

namespace App\Http\Controllers;

use App\Services\InventoryAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAvailabilityController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['productId' => ['nullable', 'integer', 'min:1'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_with:productId']]);

        return DB::transaction(function () use ($data) {
            $query = DB::table('products')->orderBy('id');
            if (isset($data['productId'])) {
                $product = $query->where('id', $data['productId'])->lockForUpdate()->first();
                abort_unless($product, 404, 'Product not found.');
                $products = [InventoryAvailability::assertSellable($product, $data['quantity'])];
            } else {
                $products = InventoryAvailability::products($query->get()->map(fn ($p) => (array) $p)->all());
            }

            $promotions = DB::table('promotions')->where('startDate', '<=', now()->toDateString())
                ->where('endDate', '>=', now()->toDateString())->orderBy('id')->get();
            $links = DB::table('promotion_products')->whereIn('promotionId', $promotions->pluck('id'))->get()->groupBy('promotionId');
            foreach ($promotions as $promotion) {
                $promotion->productIds = ($links->get($promotion->id) ?? collect())->pluck('productId')->all();
            }
            return response()->json(['products' => $products, 'activePromotions' => $promotions, 'businessDate' => now()->toDateString(), 'checkedAt' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
        });
    }
}

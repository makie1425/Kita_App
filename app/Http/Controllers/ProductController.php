<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        InventoryRules::authorize($request);
        $validated = $this->validateProduct($request);
        try {
            $product = DB::transaction(function () use ($validated, $request): array {
                InventoryRules::lockActor($request);
                // Both automatic and manual registrations serialize on this existing row.
                $sequence = DB::table('inventory_sequences')->where('name', 'products')->lockForUpdate()->first();
                $latest = max((int) $sequence->lastValue, (int) DB::table('products')->max('id'));
                $id = isset($validated['id']) ? (int) $validated['id'] : $latest + 1;
                if ($id > 2147483647) {
                    throw ValidationException::withMessages(['id' => 'No more Stock IDs are available.']);
                }
                if (DB::table('products')->where('id', $id)->exists()) {
                    throw ValidationException::withMessages(['id' => 'Stock ID already exists.']);
                }
                $product = $this->productValues($validated) + [
                    'id' => $id, 'parentId' => null, 'variantLabel' => '',
                    'barcodeStatus' => 'Scanned', 'archivedAt' => null, 'archivedBy' => null,
                ];
                $product['registrationQuantity'] = 0;
                DB::table('products')->insert($product);
                InventoryRules::audit($request->user()->name, 'Registered item', (string) $id, null, json_encode($product));
                DB::table('inventory_sequences')->where('name', 'products')->update(['lastValue' => max($latest, $id)]);

                return $product;
            }, 3);
        } catch (QueryException $exception) {
            $this->handleDuplicate($exception);
        }

        return response()->json(['message' => 'Product registered.', 'product' => $product], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        InventoryRules::authorize($request);
        abort_unless(ctype_digit($id), 404, 'Product not found.');
        $validated = $this->validateProduct($request, (int) $id);
        try {
            $product = DB::transaction(function () use ($validated, $id, $request): object {
                $existing = DB::table('products')->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404, 'Product not found.');
                if (isset($validated['id']) && (int) $validated['id'] !== (int) $id) {
                    throw ValidationException::withMessages(['id' => 'Stock ID cannot be changed.']);
                }
                if (isset($validated['stock']) && $validated['stock'] !== (int) $existing->stock) {
                    throw ValidationException::withMessages(['stock' => 'Stock is changed only by receiving or outgoing transactions.']);
                }
                $validated['stock'] = (int) $existing->stock;
                $values = $this->productValues($validated);
                foreach (['brandId', 'subcategoryId', 'size', 'sizeUnit', 'minStock'] as $field) {
                    if (! array_key_exists($field, $validated)) {
                        $values[$field] = $existing->{$field};
                    }
                }
                if ($values['subcategoryId'] && ! DB::table('subcategories')->where('id', $values['subcategoryId'])->where('category', $values['category'])->exists()) {
                    throw ValidationException::withMessages(['subcategoryId' => 'Choose a subcategory belonging to the selected category.']);
                }
                if (($existing->stock > 0 || DB::table('inventory_batches')->where('productId', $id)->exists()) && $values['stockUnit'] !== $existing->stockUnit) {
                    throw ValidationException::withMessages(['stockUnit' => 'Cannot change the inventory unit after inventory history exists.']);
                }
                $priceChanged = (float) $existing->price !== (float) $values['price'] || (float) $existing->unitPrice !== (float) $values['unitPrice'];
                if ($priceChanged && ! trim((string) $request->input('reason'))) {
                    throw ValidationException::withMessages(['reason' => 'A reason is required for price changes.']);
                }
                $values['cost'] = $existing->cost;
                DB::table('products')->where('id', $id)->update($values);
                foreach (['price', 'unitPrice'] as $field) {
                    if ((float) $existing->{$field} !== (float) $values[$field]) {
                        DB::table('field_version_history')->insert([
                            'field' => $field, 'record' => $existing->name, 'oldValue' => $existing->{$field}, 'newValue' => $values[$field],
                            'user' => $request->user()->name, 'ts' => now(), 'reason' => $request->input('reason'),
                        ]);
                    }
                }
                InventoryRules::audit($request->user()->name, 'Updated item', (string) $id, json_encode($existing), json_encode($values));

                return DB::table('products')->where('id', $id)->first();
            }, 3);
        } catch (QueryException $exception) {
            $this->handleDuplicate($exception);
        }

        return response()->json(['message' => 'Product updated.', 'product' => $product]);
    }

    private function validateProduct(Request $request, ?int $id = null): array
    {
        foreach (['name', 'barcode', 'stockUnit', 'purchaseUnit'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1', 'max:2147483647', Rule::unique('products', 'id')->ignore($id)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', Rule::exists('categories', 'name')->where('status', 'Active')->whereNull('archivedAt')],
            'vatClass' => ['nullable', Rule::in(['VATable', 'VAT-Exempt', 'Zero-Rated'])],
            'price' => InventoryRules::moneyRules(),
            'unitPrice' => InventoryRules::moneyRules(),
            'stock' => ['sometimes', 'integer', 'min:0', $id === null ? 'max:0' : 'max:2147483647'],
            'brandId' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('status', 'Active')],
            'subcategoryId' => [Rule::requiredIf($id === null && DB::table('subcategories')->where('category', $request->input('category'))->where('status', 'Active')->exists()), 'nullable', 'integer', Rule::exists('subcategories', 'id')->where('category', $request->input('category'))->where('status', 'Active')],
            'size' => ['nullable', 'required_with:sizeUnit', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'sizeUnit' => ['nullable', 'required_with:size', Rule::in(['mL', 'Liter', 'g', 'kg', 'cm', 'm', 'Piece'])],
            'minStock' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'barcode' => ['required', 'string', 'max:80', Rule::unique('products', 'barcode')->ignore($id)],
            'supplierId' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('status', 'Active')->whereNull('archivedAt')],
            'batch' => ['nullable', 'string', 'max:40'],
            'lot' => ['nullable', 'string', 'max:40'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
            'purchaseUnit' => ['required', 'string', 'max:40'],
            'stockUnit' => ['required', 'string', 'max:40'],
            'conversionFactor' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'id.unique' => 'Stock ID already exists.', 'barcode.unique' => 'Barcode already exists.',
            'category.exists' => 'Select an active category.', 'supplierId.exists' => 'Select an active supplier.',
            'stock.integer' => 'Quantity must be a whole number.', 'stock.min' => 'Quantity must not be negative.',
        ]);
        $validated['stock'] = isset($validated['stock']) ? (int) $validated['stock'] : null;
        $validated['vatClass'] = $validated['vatClass'] ?? 'VAT-Exempt';
        // Current stock may differ from the initial registration quantity after movements.
        // Update computes cost from the locked original record instead.
        $validated['cost'] = 0;

        return $validated;
    }

    private function productValues(array $validated): array
    {
        return [
            'name' => $validated['name'], 'category' => $validated['category'], 'vatClass' => $validated['vatClass'],
            'price' => $validated['price'], 'unitPrice' => $validated['unitPrice'], 'cost' => $validated['cost'],
            'stock' => $validated['stock'] ?? 0,
            'brandId' => $validated['brandId'] ?? null, 'subcategoryId' => $validated['subcategoryId'] ?? null,
            'size' => $validated['size'] ?? null, 'sizeUnit' => $validated['sizeUnit'] ?? null, 'minStock' => $validated['minStock'] ?? 0, 'unit' => trim($validated['stockUnit']), 'status' => $validated['status'] ?? 'Active',
            'batch' => $validated['batch'] ?? '', 'lot' => $validated['lot'] ?? '', 'expiry' => $validated['expiry'] ?? null,
            'barcode' => $validated['barcode'], 'supplierId' => $validated['supplierId'] ?? null,
            'purchaseUnit' => trim($validated['purchaseUnit']), 'stockUnit' => trim($validated['stockUnit']),
            'conversionFactor' => $validated['conversionFactor'],
        ];
    }

    private function handleDuplicate(QueryException $exception): never
    {
        if (in_array($exception->getCode(), ['23000', '23505'], true)) {
            $barcode = str_contains(strtolower($exception->getMessage()), 'barcode');
            throw ValidationException::withMessages([$barcode ? 'barcode' : 'id' => $barcode ? 'Barcode already exists.' : 'Stock ID already exists.']);
        }
        throw $exception;
    }
}

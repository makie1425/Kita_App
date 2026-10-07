<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        InventoryRules::authorize($request);
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'classification' => ['required', Rule::in(['Perishable', 'Non-Perishable'])],
            'status' => ['sometimes', Rule::in(['Active', 'Inactive'])],
        ]);
        if (DB::table('categories')->whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])->exists()) {
            throw ValidationException::withMessages(['name' => 'This category already exists.']);
        }
        $category = $validated + ['created_at' => now()->format('Y-m-d H:i:s.u'), 'status' => 'Active', 'archivedAt' => null, 'archivedBy' => null];
        try {
            DB::transaction(function () use ($category, $request): void {
                DB::table('categories')->insert($category);
                InventoryRules::audit($request->user()->name, 'Created category', $category['name'], null, $category['classification']);
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => 'This category already exists.']);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Category created.', 'category' => $category], 201);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        InventoryRules::authorize($request);
        $request->merge(['name' => trim((string) $request->input('name', $name))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($name, 'name')],
            'classification' => ['sometimes', 'required', Rule::in(['Perishable', 'Non-Perishable'])],
            'status' => ['sometimes', 'required', Rule::in(['Active', 'Inactive'])],
        ]);
        $category = DB::transaction(function () use ($validated, $name, $request): object {
            $category = DB::table('categories')->where('name', $name)->lockForUpdate()->first();
            abort_unless($category, 404, 'Category not found.');
            if ($validated) {
                DB::table('categories')->where('name', $name)->update($validated);
                if ($validated['name'] !== $name) {
                    DB::table('products')->where('category', $name)->update(['category' => $validated['name']]);
                    DB::table('subcategories')->where('category', $name)->update(['category' => $validated['name']]);
                    DB::table('promotions')->where('category', $name)->update(['category' => $validated['name']]);
                }
                InventoryRules::audit($request->user()->name, 'Updated category', $name, json_encode($category), json_encode($validated));
            }

            return DB::table('categories')->where('name', $validated['name'])->first();
        });

        return response()->json(['message' => 'Category updated.', 'category' => $category]);
    }
}

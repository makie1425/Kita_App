<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\WorkspaceRead;
use App\Services\WorkspaceWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductUnitController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        InventoryRules::authorize($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['stock', 'size'])],
            'name' => ['required', 'string', 'max:40', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
        ]);
        if ($data['kind'] === 'size' && mb_strlen($data['name']) > 20) {
            throw ValidationException::withMessages(['name' => 'Size units must be at most 20 characters.']);
        }
        if (WorkspaceRead::table('product_units')->where('kind', $data['kind'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists()) {
            throw ValidationException::withMessages(['name' => 'This unit already exists.']);
        }
        DB::table('product_units')->insert(WorkspaceWrite::owned($data + [
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return response()->json(['message' => 'Unit added.'], 201);
    }
}

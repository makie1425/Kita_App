<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchiveReferences
{
    public static function ensureUnused(string $kind, $records): void
    {
        if ($kind === 'products') abort_if($records->contains(fn ($r) => (int) $r->stock !== 0), 422, 'Products with stock must remain archived.');
        $ids = $records->pluck($kind === 'categories' ? 'name' : 'id')->all();
        // Legacy tables do not all have foreign keys. Preserve every historical link.
        $columns = match ($kind) {
            'products' => ['productId', 'originalProductId', 'substituteProductId', 'replacementProductId'],
            'suppliers' => ['supplierId'],
            default => ['category'],
        };
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === $kind || $name === 'migrations') continue;
            $available = Schema::getColumnListing($name);
            foreach (array_intersect($columns, $available) as $column) {
                abort_if(DB::table($name)->whereIn($column, $ids)->exists(), 422, 'Selected records are linked to inventory or transaction history. Keep them archived or restore them.');
            }
        }
        if ($kind === 'products') abort_if(DB::table('products')->whereIn('parentId', $ids)->exists(), 422, 'Products with linked variants must remain archived.');
    }
}

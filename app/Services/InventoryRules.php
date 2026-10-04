<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryRules
{
    public static function lockActor(Request $request): void
    {
        $user = DB::table('users')->where('id', $request->user()->id)->where('role', $request->user()->role)->lockForUpdate()->first();
        abort_unless($user && strtolower($user->status) === 'active', 403, 'This account is inactive.');
    }

    public static function authorize(Request $request, array $roles = ['manager', 'admin', 'superadmin', 'super_admin']): void
    {
        $user = $request->user();
        abort_unless($user, 401, 'Please log in.');
        abort_unless(($user->status ?? 'Active') === 'Active' && in_array(strtolower(str_replace(' ', '_', $user->role)), $roles, true), 403, 'You are not authorized to perform this action.');
    }

    public static function moneyRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'];
    }

    public static function lineTotal(int $qty, mixed $unitCost, string $field = 'lines', float $maximum = 9999999999.99): float
    {
        // Calculate currency in cents to avoid accumulating binary-float rounding.
        $cents = (int) round((float) $unitCost * 100);
        if ($qty < 0 || $cents < 0 || $qty * $cents > $maximum * 100) {
            throw ValidationException::withMessages([$field => 'The calculated amount is outside the allowed range.']);
        }

        return round($qty * $cents / 100, 2);
    }

    public static function product(int $id, string $field = 'productId'): object
    {
        $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
        if (! $product || $product->status !== 'Active' || $product->archivedAt !== null || $product->stock === null || $product->stock < 0) {
            throw ValidationException::withMessages([$field => 'Select an active inventory item.']);
        }
        if (! DB::table('categories')->where('name', $product->category)->where('status', 'Active')->whereNull('archivedAt')->exists()) {
            throw ValidationException::withMessages([$field => 'The item category is inactive or unavailable.']);
        }

        return $product;
    }

    public static function productLabel(object $product): string
    {
        $brand = empty($product->brandId) ? null : DB::table('brands')->where('id', $product->brandId)->value('name');
        $size = empty($product->size) ? null : (string) (float) $product->size;

        return implode(' ', array_filter([$product->name, $brand, $size, $product->sizeUnit ?? null], fn ($v) => $v !== null && $v !== ''));
    }

    public static function unitCost(object $product, string $field = 'lines'): float
    {
        if (! isset($product->unitPrice) || ! is_numeric($product->unitPrice) || (float) $product->unitPrice < 0) {
            throw ValidationException::withMessages([$field => 'Set a valid unit price for this item before purchasing.']);
        }

        return round((float) $product->unitPrice, 2);
    }

    public static function audit(string $user, string $action, string $record, ?string $before, string $after): void
    {
        DB::table('audit_logs')->insert(['ts' => now(), 'user' => $user, 'action' => $action, 'record' => $record, 'beforeValue' => $before, 'afterValue' => $after]);
        // Workflow decisions send their own recipient-specific notifications.
        if (in_array($action, ['Created supplier', 'Created category', 'Updated category', 'Registered item', 'Updated item', 'Added PO items'], true)
            && ($actor = auth()->user())) {
            WorkflowNotifications::send([$actor], $actor, $action, $action.': '.$record.'.', 'activity', $record);
        }
    }
}

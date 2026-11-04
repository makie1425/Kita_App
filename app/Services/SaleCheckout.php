<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleCheckout
{
    public static function rules(string $itemsKey = 'items'): array
    {
        return [
            'uuid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            $itemsKey => ['required', 'array', 'min:1', 'max:500'],
            $itemsKey.'.*.productId' => ['required', 'integer', 'min:1', 'distinct'],
            $itemsKey.'.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            $itemsKey.'.*.manualLookupQty' => ['sometimes', 'required', 'integer', 'min:0'],
            $itemsKey.'.*.overridden' => ['sometimes', 'boolean'],
            'discountType' => ['sometimes', 'in:none,employee,senior'],
            // Display totals are optional; reject malformed values but never trust them.
            'total' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    public static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** Must run inside the caller's database transaction. */
    public static function prepare(Request $request, array $data, string $itemsKey = 'items'): array
    {
        abort_unless($request->user(), 401);
        // Serialize checkout against account deactivation as well as other checkouts.
        WorkspaceWrite::requireId();
        $account = WorkspaceRead::table('users')->where('id', $request->user()->id)->where('role', $request->user()->role)->lockForUpdate()->first();
        abort_unless($account && strtolower($account->status ?? 'Active') === 'active', 403, 'This account is inactive.');
        abort_if(DB::table('transactions')->where('uuid', $data['uuid'])->exists(), 409, 'This transaction has already been saved.');

        $discountType = $data['discountType'] ?? 'none';
        $promotions = WorkspaceRead::table('promotions')->where('startDate', '<=', now()->toDateString())
            ->where('endDate', '>=', now()->toDateString())->orderBy('id')->get();
        $promotionProducts = WorkspaceRead::table('promotion_products')->whereIn('promotionId', $promotions->pluck('id'))->get()->groupBy('promotionId');
        $lines = [];
        $subtotal = $total = 0;
        foreach (collect($data[$itemsKey])->sortBy('productId') as $item) {
            $manualLookupQty = array_key_exists('manualLookupQty', $item) ? (int) $item['manualLookupQty'] : null;
            if ($manualLookupQty !== null && $manualLookupQty > $item['qty']) {
                throw ValidationException::withMessages([$itemsKey => 'Manual lookup quantity cannot exceed the sold quantity.']);
            }
            $product = WorkspaceRead::table('products')->where('id', $item['productId'])->lockForUpdate()->first();
            if (! $product || $product->status !== 'Active' || $product->archivedAt !== null
                || ! WorkspaceRead::table('categories')->where('name', $product->category)->where('status', 'Active')->whereNull('archivedAt')->exists()) {
                throw ValidationException::withMessages([$itemsKey => 'A cart product is unavailable for sale.']);
            }
            InventoryAvailability::assertSellable($product, (int) $item['qty'], $itemsKey);
            if ($product->price === null || ! is_numeric($product->price)) {
                throw ValidationException::withMessages([$itemsKey => 'A cart product has an invalid selling price.']);
            }
            $unitPrice = self::cents($product->price);
            if ($unitPrice < 0 || $unitPrice > 9999999999) {
                throw ValidationException::withMessages([$itemsKey => 'A cart product has an invalid selling price.']);
            }
            $base = $unitPrice * $item['qty'];
            // Preserve the existing 15% override, followed by the selected discount priority.
            $effectiveUnit = ($item['overridden'] ?? false) ? (int) round($unitPrice * 85 / 100) : $unitPrice;
            $rate = match ($discountType) {
                'employee' => 10, 'senior' => 20, default => 0
            };
            if ($discountType === 'none') {
                $promotion = $promotions->first(fn ($promo) => ($promo->type === 'Category-Wide' && $promo->category === $product->category)
                    || ($promotionProducts->get($promo->id)?->contains('productId', $product->id) ?? false));
                $rate = $promotion ? (float) $promotion->discountPct : 0;
            }
            if ($rate < 0 || $rate > 100) {
                throw ValidationException::withMessages([$itemsKey => 'A product promotion has an invalid discount.']);
            }
            $net = (int) round($effectiveUnit * $item['qty'] * (100 - $rate) / 100);
            $subtotal += $base;
            $total += $net;
            if ($subtotal > 9999999999 || $total > 9999999999) {
                throw ValidationException::withMessages([$itemsKey => 'The order amount is too large.']);
            }
            $lines[] = ['productId' => $product->id, 'name' => InventoryRules::productLabel($product),
                'stockId' => (string) $product->id, 'unit' => $product->stockUnit ?? $product->unit,
                'qty' => (int) $item['qty'], 'unitPrice' => $unitPrice / 100,
                'manualLookupQty' => $manualLookupQty,
                'entryMethod' => $manualLookupQty === null ? null : ($manualLookupQty === 0 ? 'Barcode' : ($manualLookupQty === (int) $item['qty'] ? 'Manual lookup' : 'Mixed')),
                'discountAmount' => ($base - $net) / 100, 'lineTotal' => $net / 100, 'stockBefore' => (int) $product->stock];
        }
        $approval = DiscountApproval::verify($request, $subtotal - $total);

        return ['lines' => $lines, 'subtotal' => $subtotal, 'total' => $total,
            'discount' => $subtotal - $total, 'discountType' => $discountType, 'approval' => $approval];
    }

    public static function save(Request $request, array $data, array $sale, string $status, string $paymentMode, int $tendered = 0, ?string $exchangeOriginal = null, int $exchangeCredit = 0, bool $notify = true): array
    {
        $paid = $status === 'Unused' ? $sale['total'] : 0;
        WorkspaceWrite::requireId();
        $record = WorkspaceWrite::owned([
            'uuid' => $data['uuid'], 'status' => $status, 'cashier' => $request->user()->name,
            'cashierId' => $request->user()->id, 'cashierRole' => $request->user()->role, 'cashierEmail' => $request->user()->email,
            'date' => now()->toDateString(), 'refundDate' => null,
            'subtotal' => $sale['subtotal'] / 100, 'discountAmount' => $sale['discount'] / 100,
            'discountType' => $sale['discountType'], 'total' => $sale['total'] / 100,
            'paymentMode' => $paymentMode, 'tendered' => $tendered / 100, 'paid' => $paid / 100,
            'changeAmount' => ($status === 'Unused' ? $tendered - $paid : 0) / 100, 'referenceNo' => null,
            'exchangeOriginalUuid' => $exchangeOriginal, 'exchangeCredit' => $exchangeCredit / 100,
        ]);
        DB::table('transactions')->insert($record);
        $record['transactionNumber'] = TransactionNumber::assign($data['uuid'], $record['date']);
        foreach ($sale['lines'] as $line) {
            $before = $line['stockBefore'];
            unset($line['stockBefore']);
            $changed = WorkspaceRead::table('products')->where('id', $line['productId'])->where('stock', '>=', $line['qty'])->decrement('stock', $line['qty']);
            if ($changed !== 1) {
                throw ValidationException::withMessages(['items' => 'Stock changed. Please refresh the cart and try again.']);
            }
            DB::table('transaction_lines')->insert(WorkspaceWrite::owned($line + ['transaction_uuid' => $data['uuid'], 'created_at' => now(), 'updated_at' => now()]));
            StockMovement::record($line['productId'], $before, $before - $line['qty'], $exchangeOriginal ? 'exchange_replacement' : ($status === 'Unused' ? 'checkout' : 'payment_reservation'), $data['uuid'], $request->user());
        }
        DiscountApproval::record($data['uuid'], $sale['approval']);
        if ($status === 'Unused') {
            self::recordSales($data['uuid']);
        }
        self::audit($request->user()->name, $status === 'Unused' ? 'Checkout' : 'Payment reserved', $data['uuid'], null, $status, $notify);

        return $record + ['change' => $record['changeAmount'], 'lines' => $sale['lines']];
    }

    public static function recordSales(string $uuid, ?int $workspaceId = null): void
    {
        $transaction = DB::table('transactions')->where('uuid', $uuid)->first();
        abort_unless($transaction && (! config('workspaces.enabled') || $transaction->workspace_id), 403);
        if (config('workspaces.enabled')) {
            abort_unless($workspaceId === null || $workspaceId === (int) $transaction->workspace_id, 403);
            $workspaceId = (int) $transaction->workspace_id;
        }
        $lines = DB::table('transaction_lines')->where('transaction_uuid', $uuid);
        if (config('workspaces.enabled')) $lines->where('workspace_id', $workspaceId);
        foreach ($lines->get() as $line) {
            $values = ['date' => now()->toDateString(), 'hour' => (int) now()->format('G'),
                'productId' => $line->productId, 'qty' => $line->qty, 'amount' => $line->lineTotal];
            if (config('workspaces.enabled')) $values['workspace_id'] = $workspaceId;
            DB::table('sales_log')->insert($values);
        }
    }

    public static function audit(string $user, string $action, string $record, ?string $before, string $after, bool $notify = true): void
    {
        $transaction = DB::table('transactions')->where('uuid', $record)->first();
        $values = ['ts' => now(), 'user' => $user, 'action' => $action, 'record' => $record,
            'beforeValue' => $before, 'afterValue' => $after];
        if (config('workspaces.enabled')) {
            abort_unless($transaction && $transaction->workspace_id, 403);
            $values['workspace_id'] = $transaction->workspace_id;
        }
        DB::table('audit_logs')->insert($values);
        if ($notify && in_array($action, ['Checkout', 'Payment reserved', 'Payment confirmed', 'Payment reservation released'], true)) {
            $owner = $transaction ? WorkflowNotifications::owner($transaction->cashierId, $transaction->cashierRole) : null;
            if ($owner) {
                WorkflowNotifications::send([$owner], auth()->user() ?? $owner, $action, 'Transaction '.$record.': '.$after.'.', 'activity', $record);
            }
        }
    }
}

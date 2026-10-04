<?php

namespace App\Http\Controllers;

use App\Services\FifoInventory;
use App\Services\ManagerApproval;
use App\Services\SaleCheckout;
use App\Services\StockMovement;
use App\Services\WorkflowNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $transaction = DB::table('transactions')->where('uuid', $uuid)->first();
        abort_unless($transaction, 404, 'Transaction not found.');
        abort_unless($request->user()->normalizedRole() === 'super_admin'
            || ((int) $transaction->cashierId === (int) $request->user()->id && $transaction->cashierRole === $request->user()->role), 403);

        return response()->json(['uuid' => $transaction->uuid, 'status' => $transaction->status, 'total' => $transaction->total, 'paid' => $transaction->paid,
            'checkoutUrl' => $transaction->status === 'Pending Payment' ? $transaction->checkoutUrl : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(SaleCheckout::rules() + [
            'paymentMode' => ['required', 'in:Cash'],
            'tendered' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);
        try {
            return DB::transaction(function () use ($data, $request) {
                $sale = SaleCheckout::prepare($request, $data);
                $tendered = SaleCheckout::cents($data['tendered']);
                if ($tendered < $sale['total']) {
                    throw ValidationException::withMessages(['tendered' => 'Tendered amount is less than the total due.']);
                }
                $transaction = SaleCheckout::save($request, $data, $sale, 'Unused', 'Cash', $tendered);

                return response()->json(['message' => 'Transaction saved.', 'uuid' => $data['uuid'], 'transaction' => $transaction], 201);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (DB::table('transactions')->where('uuid', $data['uuid'])->exists()) {
                return response()->json(['message' => 'This transaction has already been saved.'], 409);
            }
            throw $exception;
        }
    }

    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:refund,void,exchange'],
            'manager_id' => ['required', 'integer', 'min:1'],
            'manager_pin' => ['required', 'digits:4'],
            'reason' => ['required', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.productId' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'restock' => ['sometimes', 'boolean'],
            'request_key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'provider_refund_id' => ['nullable', 'string', 'max:80', 'regex:/^ref_[A-Za-z0-9]+$/'],
            'replacement_product_id' => ['required_if:action,exchange', 'nullable', 'integer', 'min:1'],
        ]);
        $manager = ManagerApproval::verify($request, $data['manager_id'], $data['manager_pin']);

        return DB::transaction(function () use ($data, $uuid, $manager, $request) {
            $transaction = DB::table('transactions')->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($transaction, 404, 'Transaction was not found.');
            $action = $data['action'];
            if ($transaction->status === 'Closed' && $action === 'void'
                && DB::table('transaction_approvals')->where('transaction_uuid', $uuid)->where('action', 'void')->exists()) {
                abort(409, 'This transaction has already been voided.');
            }
            if (! in_array($transaction->status, ['Unused', 'Paid', 'Partially Refunded'], true)) {
                abort(409, 'This transaction is not eligible for '.$action.'.');
            }
            if (! empty($data['request_key']) && DB::table('transaction_approvals')->where('request_key', $data['request_key'])->exists()) {
                abort(409, 'This return request has already been processed.');
            }
            if ($action !== 'refund' && ((float) $transaction->refundedAmount > 0 || $transaction->status === 'Partially Refunded')) {
                throw ValidationException::withMessages(['action' => 'A partially refunded transaction cannot be voided or exchanged.']);
            }
            if ($action === 'void') {
                if ($transaction->date !== now()->toDateString()) {
                    throw ValidationException::withMessages(['action' => 'Only same-day transactions can be voided.']);
                }
                if ($transaction->paymentMode !== 'Cash') {
                    throw ValidationException::withMessages(['action' => 'A paid wallet transaction must use the refund workflow.']);
                }
            }
            $lines = DB::table('transaction_lines')->where('transaction_uuid', $uuid)->orderBy('productId')->lockForUpdate()->get()->keyBy('productId');
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['action' => 'This legacy transaction has no saved item details. Reconcile it manually before returning stock or money.']);
            }
            $remaining = $lines->filter(fn ($line) => $line->qty > $line->refundedQty);
            $items = $data['items'] ?? $remaining->map(fn ($line) => ['productId' => $line->productId, 'qty' => $line->qty - $line->refundedQty])->values()->all();
            $returns = [];
            $refundCents = 0;
            foreach ($items as $item) {
                $line = $lines->get($item['productId']);
                if (! $line || $item['qty'] > $line->qty - $line->refundedQty) {
                    throw ValidationException::withMessages(['items' => 'Refund quantity exceeds the remaining purchased quantity.']);
                }
                $newQty = $line->refundedQty + $item['qty'];
                // Allocate the rounding remainder to the last returned unit.
                $cumulative = (int) round(SaleCheckout::cents($line->lineTotal) * $newQty / $line->qty);
                $amount = $cumulative - SaleCheckout::cents($line->refundedAmount);
                $refundCents += $amount;
                $returns[] = ['line' => $line, 'qty' => (int) $item['qty'], 'amount' => $amount, 'newQty' => $newQty, 'cumulative' => $cumulative];
            }
            if (! $returns || $refundCents > SaleCheckout::cents($transaction->total) - SaleCheckout::cents($transaction->refundedAmount)) {
                throw ValidationException::withMessages(['amount' => 'Refund amount exceeds the remaining transaction amount.']);
            }
            if (isset($data['amount']) && SaleCheckout::cents($data['amount']) !== $refundCents) {
                throw ValidationException::withMessages(['amount' => 'Refund amount must match the refundable value of the selected items.']);
            }
            if ($action !== 'refund' && array_sum(array_column($returns, 'qty')) !== (int) $lines->sum('qty')) {
                throw ValidationException::withMessages(['items' => 'Void and exchange apply to the entire original transaction.']);
            }
            if ($action === 'refund' && $transaction->paymentMode !== 'Cash') {
                $this->verifyWalletRefund($transaction, $data, $refundCents);
            }
            // Keep a single lock order across sales, returns, and replacements.
            $productIds = $lines->keys()->all();
            if ($action === 'exchange') {
                $productIds[] = $data['replacement_product_id'];
            }
            $products = DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $replacement = null;
            if ($action === 'exchange') {
                $replacement = $products->get($data['replacement_product_id']);
                if (! $replacement || $replacement->status !== 'Active' || $replacement->archivedAt !== null || $replacement->stock < 1
                    || ! DB::table('categories')->where('name', $replacement->category)->where('status', 'Active')->whereNull('archivedAt')->exists()) {
                    throw ValidationException::withMessages(['replacement_product_id' => 'The replacement is unavailable.']);
                }
                if ($replacement->price === null || ! is_numeric($replacement->price) || (float) $replacement->price < 0
                    || SaleCheckout::cents($replacement->price) !== $refundCents) {
                    throw ValidationException::withMessages(['replacement_product_id' => 'The replacement price must equal the original total. Use a refund and a new sale for a different amount.']);
                }
            }
            $restock = $action === 'void' || ($data['restock'] ?? true);
            $approvalId = DB::table('transaction_approvals')->insertGetId([
                'transaction_uuid' => $uuid, 'manager_id' => $manager->id, 'requested_by' => $request->user()->id,
                'requested_by_role' => $request->user()->role,
                'action' => $action, 'reason' => $data['reason'], 'replacement_product_id' => $replacement?->id,
                'amount' => $action === 'exchange' ? 0 : $refundCents / 100, 'restock' => $restock,
                'provider_refund_id' => $action === 'refund' && $transaction->paymentMode !== 'Cash' ? $data['provider_refund_id'] : null,
                'request_key' => $data['request_key'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($returns as $return) {
                $line = $return['line'];
                if ($restock) {
                    $product = $products->get($line->productId);
                    if (! $product) {
                        throw ValidationException::withMessages(['items' => 'An original product no longer exists. Stock cannot be restored automatically.']);
                    }
                    $before = (int) $product->stock;
                    $after = $before + $return['qty'];
                    if ($after > 2147483647) {
                        throw ValidationException::withMessages(['items' => 'Restoring these items would exceed the stock limit.']);
                    }
                    DB::table('products')->where('id', $line->productId)->update(['stock' => $after]);
                    StockMovement::record($line->productId, $before, $after, $action, $uuid, $request->user());
                    $product->stock = $after;
                }
                if (! $restock) {
                    FifoInventory::returnStock($line->productId, $return['qty'], $uuid, false);
                }
                DB::table('transaction_lines')->where('id', $line->id)->update([
                    'refundedQty' => $return['newQty'], 'refundedAmount' => $return['cumulative'] / 100, 'updated_at' => now(),
                ]);
                DB::table('transaction_return_lines')->insert([
                    'approval_id' => $approvalId, 'transaction_line_id' => $line->id,
                    'qty' => $return['qty'], 'amount' => $return['amount'] / 100, 'restocked' => $restock,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('sales_log')->insert(['date' => now()->toDateString(), 'hour' => (int) now()->format('G'),
                    'productId' => $line->productId, 'qty' => -$return['qty'], 'amount' => -$return['amount'] / 100]);
            }
            if ($replacement) {
                $before = (int) $replacement->stock;
                DB::table('products')->where('id', $replacement->id)->decrement('stock');
                StockMovement::record($replacement->id, $before, $before - 1, 'exchange_replacement', $uuid, $request->user());
                DB::table('sales_log')->insert(['date' => now()->toDateString(), 'hour' => (int) now()->format('G'),
                    'productId' => $replacement->id, 'qty' => 1, 'amount' => $refundCents / 100]);
            }
            $fullyReturned = ! DB::table('transaction_lines')->where('transaction_uuid', $uuid)->whereColumn('refundedQty', '<', 'qty')->exists();
            $status = $action === 'refund' ? ($fullyReturned ? 'Refunded' : 'Partially Refunded') : 'Closed';
            $refunded = $action === 'exchange' ? 0 : SaleCheckout::cents($transaction->refundedAmount) + $refundCents;
            DB::table('transactions')->where('uuid', $uuid)->update(['status' => $status, 'refundedAmount' => $refunded / 100, 'refundDate' => now()->toDateString()]);
            SaleCheckout::audit($request->user()->name, ucfirst($action).' approved by '.$manager->name, $uuid, $transaction->status, $status.'; '.$data['reason']);
            WorkflowNotifications::send([$request->user(), $manager], $request->user(), ucfirst($action).' approved',
                'Transaction '.$uuid.': '.$action.' approved by '.$manager->name.'.', 'activity', $uuid);

            return response()->json(['message' => ucfirst($action).' approved by '.$manager->name.'.', 'status' => $status,
                'refundDate' => now()->toDateString(), 'approved_by' => $manager->name, 'amount' => $refundCents / 100,
                'refundedAmount' => $refunded / 100, 'lines' => DB::table('transaction_lines')->where('transaction_uuid', $uuid)->get()]);
        });
    }

    private function verifyWalletRefund(object $transaction, array $data, int $amount): void
    {
        if (empty($data['provider_refund_id']) || ! $transaction->paymentReference) {
            throw ValidationException::withMessages(['provider_refund_id' => 'Refund the wallet payment in PayMongo, then enter its successful refund reference for verification.']);
        }
        if (DB::table('transaction_approvals')->where('provider_refund_id', $data['provider_refund_id'])->exists()) {
            throw ValidationException::withMessages(['provider_refund_id' => 'This PayMongo refund has already been recorded.']);
        }
        abort_unless(config('services.paymongo.secret'), 503, 'PayMongo is not configured.');
        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret'), '')->acceptJson()->timeout(15)
                ->get('https://api.paymongo.com/refunds/'.$data['provider_refund_id']);
        } catch (ConnectionException $exception) {
            abort(502, 'PayMongo could not be reached. The refund has not been recorded.');
        }
        $refund = $response->json('data.attributes', []);
        if ($response->failed() || $response->json('data.id') !== $data['provider_refund_id']
            || ($refund['status'] ?? null) !== 'succeeded' || ($refund['currency'] ?? null) !== 'PHP'
            || ($refund['payment_id'] ?? null) !== $transaction->paymentReference || ($refund['amount'] ?? null) !== $amount
            || ($refund['livemode'] ?? null) !== str_starts_with(config('services.paymongo.secret'), 'sk_live_')) {
            throw ValidationException::withMessages(['provider_refund_id' => 'The successful PayMongo refund must match this payment and the selected refund amount.']);
        }
    }
}

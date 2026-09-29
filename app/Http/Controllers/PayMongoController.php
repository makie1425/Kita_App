<?php

namespace App\Http\Controllers;

use App\Services\SaleCheckout;
use App\Services\StockMovement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PayMongoController extends Controller
{
    public function createCheckout(Request $request): JsonResponse
    {
        $data = $request->validate(SaleCheckout::rules('stockItems') + ['provider' => ['required', 'in:GCash,Maya,GrabPay']]);
        $transaction = DB::transaction(function () use ($request, $data) {
            $sale = SaleCheckout::prepare($request, $data, 'stockItems');
            if ($sale['total'] <= 0) {
                throw ValidationException::withMessages(['total' => 'Wallet payment amount must be greater than zero.']);
            }
            abort_unless(config('services.paymongo.secret'), 503, 'PayMongo is not configured.');

            return SaleCheckout::save($request, $data, $sale, 'Pending Payment', $data['provider']);
        }, 3);
        try {
            $response = $this->client()->post('https://api.paymongo.com/v1/checkout_sessions', ['data' => ['attributes' => [
                'reference_number' => $data['uuid'],
                'line_items' => [['currency' => 'PHP', 'amount' => SaleCheckout::cents($transaction['total']), 'name' => 'KITA purchase', 'quantity' => 1]],
                'payment_method_types' => [match ($data['provider']) {
                    'GCash' => 'gcash', 'Maya' => 'paymaya', default => 'grab_pay'
                }],
                'description' => 'KITA checkout '.$data['uuid'], 'send_email_receipt' => false, 'show_description' => true, 'show_line_items' => true,
                'success_url' => url('/payment/success?uuid='.urlencode($data['uuid'])),
                'cancel_url' => url('/payment/cancelled?uuid='.urlencode($data['uuid'])), 'metadata' => ['transaction_uuid' => $data['uuid']],
            ]]]);
        } catch (ConnectionException $exception) {
            // An ambiguous timeout can still create a remote session; keep the reservation for reconciliation.
            abort(502, 'Payment session could not be confirmed. Keep transaction '.$data['uuid'].' for reconciliation before trying again.');
        }
        if ($response->clientError()) {
            $this->release($data['uuid'], $request, 'Payment Failed');

            return response()->json(['message' => 'PayMongo rejected the checkout request. Stock has been released.', 'retryable' => true], 502);
        }
        $session = $response->json('data');
        if ($response->failed() || ! is_array($session) || ! preg_match('/^cs_[A-Za-z0-9]+$/', $session['id'] ?? '') || empty($session['attributes']['checkout_url'])) {
            abort(502, 'Payment session is awaiting reconciliation. Keep transaction '.$data['uuid'].' before trying again.');
        }
        DB::transaction(function () use ($data, $session) {
            $record = DB::table('transactions')->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($record->referenceNo && $record->referenceNo !== $session['id']) {
                abort(409, 'Payment session does not match.');
            }
            DB::table('transactions')->where('uuid', $data['uuid'])->update(['referenceNo' => $session['id'], 'checkoutUrl' => $session['attributes']['checkout_url']]);
        });

        return response()->json(['checkoutUrl' => $session['attributes']['checkout_url'], 'sessionId' => $session['id'], 'uuid' => $data['uuid']]);
    }

    public function cancelCheckout(Request $request, string $uuid): JsonResponse
    {
        $transaction = DB::table('transactions')->where('uuid', $uuid)->first();
        abort_unless($transaction, 404, 'Transaction not found.');
        abort_unless($request->user() && ($request->user()->normalizedRole() === 'super_admin'
            || ((int) $transaction->cashierId === (int) $request->user()->id && $transaction->cashierRole === $request->user()->role)), 403);
        if ($transaction->status === 'Cancelled') {
            return response()->json(['message' => 'Payment cancelled. Stock has been released.']);
        }
        abort_unless($transaction->status === 'Pending Payment', 409, 'This transaction is no longer pending.');
        abort_unless($transaction->referenceNo && config('services.paymongo.secret'), 409, 'The payment session must be reconciled before stock can be released.');
        try {
            $response = $this->client()->post('https://api.paymongo.com/v1/checkout_sessions/'.$transaction->referenceNo.'/expire');
            $verified = $this->client()->get('https://api.paymongo.com/v1/checkout_sessions/'.$transaction->referenceNo);
        } catch (ConnectionException $exception) {
            abort(502, 'Payment cancellation could not be confirmed. Stock remains reserved.');
        }
        $attributes = $verified->json('data.attributes', []);
        abort_unless($verified->successful() && $verified->json('data.id') === $transaction->referenceNo
            && ($attributes['status'] ?? '') === 'expired' && empty($attributes['payments'])
            && ! in_array(data_get($attributes, 'payment_intent.attributes.status'), ['succeeded', 'processing']), 409,
            'The payment session is not confirmed expired and unpaid. Stock remains reserved.');
        $this->release($uuid, $request, 'Cancelled');

        return response()->json(['message' => 'Payment cancelled. Stock has been released.']);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.paymongo.webhook_secret');
        abort_unless($secret && config('services.paymongo.secret'), 503, 'Payment verification is not configured.');
        $parts = [];
        foreach (explode(',', $request->header('Paymongo-Signature', '')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $live = str_starts_with(config('services.paymongo.secret'), 'sk_live_');
        $signature = $parts[$live ? 'li' : 'te'] ?? '';
        $timestamp = $parts['t'] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature), 401, 'Invalid payment signature.');
        if ($request->input('data.attributes.type', $request->input('data.type')) !== 'checkout_session.payment.paid') {
            return response()->json(['received' => true]);
        }
        $session = $request->input('data.attributes.data', $request->input('data.data', []));
        $uuid = data_get($session, 'attributes.metadata.transaction_uuid');
        abort_unless(is_string($uuid) && preg_match('/^cs_[A-Za-z0-9]+$/', $session['id'] ?? ''), 422, 'Payment event is incomplete.');
        // Retrieve the provider's canonical session; never accept client redirects as proof of payment.
        try {
            $response = $this->client()->get('https://api.paymongo.com/v1/checkout_sessions/'.$session['id']);
        } catch (ConnectionException $exception) {
            abort(502, 'Payment verification is temporarily unavailable.');
        }
        abort_unless($response->successful() && $response->json('data.id') === $session['id'], 502, 'Payment verification failed.');
        $attributes = $response->json('data.attributes', []);
        abort_unless(data_get($attributes, 'metadata.transaction_uuid') === $uuid && ($attributes['livemode'] ?? null) === $live, 422, 'Payment identity does not match.');
        $payment = collect($attributes['payments'] ?? [])->first(fn ($payment) => data_get($payment, 'attributes.status') === 'paid');
        abort_unless($payment && is_string($payment['id'] ?? null) && preg_match('/^pay_[A-Za-z0-9]+$/', $payment['id'])
            && data_get($payment, 'attributes.currency') === 'PHP', 422, 'Payment has not been confirmed.');

        return DB::transaction(function () use ($uuid, $session, $payment) {
            $transaction = DB::table('transactions')->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($transaction, 404, 'Transaction not found.');
            abort_unless($transaction->paymentMode !== 'Cash' && (! $transaction->referenceNo || $transaction->referenceNo === $session['id'])
                && SaleCheckout::cents($transaction->total) === data_get($payment, 'attributes.amount'), 422, 'Payment amount or session does not match.');
            if ($transaction->paymentReference === $payment['id']) {
                return response()->json(['received' => true]);
            }
            abort_unless($transaction->status === 'Pending Payment', 409, 'This payment requires reconciliation.');
            DB::table('transactions')->where('uuid', $uuid)->update(['status' => 'Paid', 'paid' => $transaction->total,
                'referenceNo' => $session['id'], 'paymentReference' => $payment['id']]);
            SaleCheckout::recordSales($uuid);
            SaleCheckout::audit('PayMongo', 'Payment confirmed', $uuid, 'Pending Payment', 'Paid');

            return response()->json(['received' => true]);
        }, 3);
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(config('services.paymongo.secret'), '')->acceptJson()->timeout(20);
    }

    private function release(string $uuid, Request $request, string $status): void
    {
        DB::transaction(function () use ($uuid, $request, $status) {
            $transaction = DB::table('transactions')->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($transaction && $transaction->status === 'Pending Payment', 409, 'Payment is no longer pending.');
            foreach (DB::table('transaction_lines')->where('transaction_uuid', $uuid)->orderBy('productId')->get() as $line) {
                $product = DB::table('products')->where('id', $line->productId)->lockForUpdate()->first();
                abort_unless($product && $product->stock + $line->qty <= 2147483647, 409, 'Stock release requires reconciliation.');
                DB::table('products')->where('id', $line->productId)->increment('stock', $line->qty);
                StockMovement::record($line->productId, $product->stock, $product->stock + $line->qty, 'payment_release', $uuid, $request->user());
            }
            DB::table('transactions')->where('uuid', $uuid)->update(['status' => $status]);
            SaleCheckout::audit($request->user()->name, 'Payment reservation released', $uuid, 'Pending Payment', $status);
        }, 3);
    }
}

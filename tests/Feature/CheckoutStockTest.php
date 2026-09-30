<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutStockTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->manager = User::factory()->create(['role' => 'manager', 'approval_pin' => Hash::make('1234')]);
        DB::table('categories')->insert(['name' => 'Medical', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Mask', 'stock' => 5, 'status' => 'Active', 'price' => 10, 'unitPrice' => 5, 'cost' => 25, 'category' => 'Medical', 'vatClass' => 'VATable']);
        $this->actingAs($this->cashier);
        Http::preventStrayRequests();
    }

    private function sale(int $qty = 1, string $uuid = 'TXN-test'): array
    {
        return ['uuid' => $uuid, 'items' => [['productId' => 1, 'qty' => $qty]], 'paymentMode' => 'Cash', 'tendered' => 100];
    }

    private function approval(string $action = 'refund'): array
    {
        return ['action' => $action, 'reason' => 'Returnable', 'manager_id' => $this->manager->id, 'manager_pin' => '1234'];
    }

    public function test_checkout_computes_totals_and_deducts_stock_once(): void
    {
        $this->postJson('/api/transactions', $this->sale(5) + ['total' => 1, 'paid' => 1, 'change' => 99, 'cashier' => 'Spoof', 'status' => 'Closed'])
            ->assertCreated()->assertJsonPath('transaction.total', 50)->assertJsonPath('transaction.change', 50)->assertJsonPath('transaction.cashier', $this->cashier->name);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 0]);
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-test', 'status' => 'Unused', 'total' => 50]);
        $this->assertDatabaseCount('transaction_lines', 1);
        $this->assertDatabaseHas('transaction_lines', ['transaction_uuid' => 'TXN-test', 'stockId' => '1']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson('/api/transactions', $this->sale(5))->assertConflict();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_invalid_quantities_unavailable_products_and_insufficient_stock_fail(): void
    {
        foreach ([0, -1, 1.5, 'bad', 6] as $qty) {
            $body = $this->sale();
            $body['items'][0]['qty'] = $qty;
            $this->postJson('/api/transactions', $body)->assertUnprocessable();
        }
        $body = $this->sale();
        $body['items'][0]['productId'] = 999;
        $this->postJson('/api/transactions', $body)->assertUnprocessable();
        DB::table('products')->where('id', 1)->update(['status' => 'Inactive']);
        $this->postJson('/api/transactions', $this->sale())->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }

    public function test_cart_failure_rolls_back_all_items_and_insufficient_tender_fails(): void
    {
        DB::table('products')->insert(['id' => 2, 'name' => 'Gloves', 'stock' => 1, 'status' => 'Active', 'price' => 20, 'category' => 'Medical']);
        $body = $this->sale(2);
        $body['items'][] = ['productId' => 2, 'qty' => 2];
        $this->postJson('/api/transactions', $body)->assertUnprocessable();
        $body = $this->sale();
        $body['tendered'] = 1;
        $this->postJson('/api/transactions', $body)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_discounts_require_a_real_active_manager_and_are_audited(): void
    {
        $body = $this->sale() + ['discountType' => 'employee'];
        $this->postJson('/api/transactions', $body)->assertUnprocessable();
        $body += ['discount_manager_id' => $this->manager->id, 'discount_manager_pin' => '9999', 'discount_reason' => 'PWD'];
        $this->postJson('/api/transactions', $body)->assertUnprocessable();
        $body['discount_manager_pin'] = '1234';
        $this->postJson('/api/transactions', $body)->assertCreated()->assertJsonPath('transaction.total', 9);
        $this->assertDatabaseHas('discount_approvals', ['transaction_uuid' => 'TXN-test', 'amount' => 1, 'manager_id' => $this->manager->id]);
        $this->patchJson('/api/transactions/TXN-test', $this->approval())->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }

    public function test_invalid_cash_tender_never_creates_a_sale(): void
    {
        foreach (['', 'bad', '10invalid', -1, '10.001', 0, 9] as $tendered) {
            $this->postJson('/api/transactions', array_replace($this->sale(), ['tendered' => $tendered]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }

    public function test_discount_reason_is_derived_without_manual_input(): void
    {
        foreach (['senior' => 'Senior/PWD discount', 'employee' => 'Employee discount'] as $type => $reason) {
            $uuid = 'TXN-auto-'.$type;
            $this->postJson('/api/transactions', array_replace($this->sale(), [
                'uuid' => $uuid, 'discountType' => $type,
                'discount_manager_id' => $this->manager->id, 'discount_manager_pin' => '1234',
            ]))->assertCreated();
            $this->assertDatabaseHas('discount_approvals', ['transaction_uuid' => $uuid, 'reason' => $reason]);
        }
    }
    public function test_exchange_rejects_unavailable_replacement_and_preserves_original_sale(): void
    {
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        DB::table('categories')->insert(['name' => 'Replacement category', 'status' => 'Inactive']);
        DB::table('products')->insert(['id' => 2, 'name' => 'Replacement', 'stock' => 3, 'status' => 'Active', 'price' => 10, 'category' => 'Replacement category']);
        $body = $this->approval('exchange') + ['replacement_product_id' => 2];
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable()->assertJsonValidationErrors('replacement_product_id');
        DB::table('categories')->where('name', 'Replacement category')->update(['status' => 'Active', 'archivedAt' => now()->toDateString()]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        DB::table('categories')->where('name', 'Replacement category')->update(['archivedAt' => null]);
        DB::table('products')->where('id', 2)->update(['archivedAt' => now()->toDateString()]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        DB::table('products')->where('id', 2)->update(['archivedAt' => null, 'price' => null]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-test', 'status' => 'Unused', 'total' => 10]);
        $this->assertDatabaseCount('transaction_approvals', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 4]);
        $this->assertDatabaseHas('products', ['id' => 2, 'stock' => 3]);

        DB::table('products')->where('id', 2)->update(['price' => 10]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertOk()->assertJsonPath('status', 'Closed');
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        $this->assertDatabaseHas('products', ['id' => 2, 'stock' => 2]);
        $this->assertDatabaseHas('stock_movements', ['productId' => 2, 'referenceType' => 'exchange_replacement', 'quantityChange' => -1]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertConflict();
    }

    public function test_pending_payment_link_is_only_available_to_the_owner_and_only_while_pending(): void
    {
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        DB::table('transactions')->where('uuid', 'TXN-test')->update(['status' => 'Pending Payment', 'paymentMode' => 'GCash', 'checkoutUrl' => 'https://checkout.paymongo.com/session']);
        $this->getJson('/api/transactions/TXN-test')->assertOk()->assertJsonPath('checkoutUrl', 'https://checkout.paymongo.com/session');
        $other = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($other)->getJson('/api/transactions/TXN-test')->assertForbidden();
        $this->actingAs($other)->postJson('/api/payments/paymongo/TXN-test/cancel')->assertForbidden();
        DB::table('transactions')->where('uuid', 'TXN-test')->update(['status' => 'Paid']);
        $this->actingAs($this->cashier)->getJson('/api/transactions/TXN-test')->assertOk()->assertJsonPath('checkoutUrl', null);
    }

    public function test_refund_quantities_amounts_and_duplicate_requests_are_checked(): void
    {
        $this->postJson('/api/transactions', $this->sale(3))->assertCreated();
        $body = $this->approval() + ['items' => [['productId' => 1, 'qty' => 4]]];
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        $body['items'][0]['qty'] = 1;
        $body['amount'] = 99;
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        $body['amount'] = 10;
        $body['request_key'] = 'return-one';
        $this->patchJson('/api/transactions/TXN-test', $body)->assertOk()->assertJsonPath('status', 'Partially Refunded');
        $this->patchJson('/api/transactions/TXN-test', $body)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 3]);
        $this->patchJson('/api/transactions/TXN-test', $this->approval())->assertOk()->assertJsonPath('status', 'Refunded');
        $this->patchJson('/api/transactions/TXN-test', $this->approval())->assertConflict();
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-test', 'total' => 30, 'refundedAmount' => 30]);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        $this->assertDatabaseCount('transaction_return_lines', 2);
    }

    public function test_nonreturnable_refund_does_not_restore_inventory(): void
    {
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        $this->patchJson('/api/transactions/TXN-test', $this->approval() + ['restock' => false])->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 4]);
        $this->assertDatabaseHas('transaction_return_lines', ['restocked' => false]);
    }

    public function test_void_restores_stock_and_cannot_repeat(): void
    {
        $this->postJson('/api/transactions', $this->sale(2))->assertCreated();
        $this->patchJson('/api/transactions/TXN-test', $this->approval('void'))->assertOk()->assertJsonPath('status', 'Closed');
        $this->patchJson('/api/transactions/TXN-test', $this->approval('void'))->assertConflict();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        $this->assertDatabaseCount('transaction_approvals', 1);
    }

    public function test_old_and_partially_refunded_sales_cannot_be_voided(): void
    {
        $this->postJson('/api/transactions', $this->sale(2))->assertCreated();
        DB::table('transactions')->update(['date' => now()->subDay()->toDateString()]);
        $this->patchJson('/api/transactions/TXN-test', $this->approval('void'))->assertUnprocessable();
        DB::table('transactions')->update(['date' => now()->toDateString()]);
        $this->patchJson('/api/transactions/TXN-test', $this->approval() + ['items' => [['productId' => 1, 'qty' => 1]]])->assertOk();
        $this->patchJson('/api/transactions/TXN-test', $this->approval('void'))->assertUnprocessable();
    }

    public function test_pin_is_rate_limited_and_cashier_cannot_approve(): void
    {
        $body = $this->approval();
        $body['manager_id'] = $this->cashier->id;
        for ($i = 0; $i < 5; $i++) {
            $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        }
        $this->patchJson('/api/transactions/TXN-test', $body)->assertStatus(429);
    }

    public function test_manager_pin_is_hashed_and_protected(): void
    {
        $this->postJson('/manager/approval-pin', ['pin' => '4321', 'pin_confirmation' => '4321'])->assertForbidden();
        $this->actingAs($this->manager)->postJson('/manager/approval-pin', ['pin' => '4321', 'pin_confirmation' => '4321', 'current_pin' => '1234'])->assertOk();
        $this->assertTrue(Hash::check('4321', $this->manager->fresh()->approval_pin));
    }

    public function test_imported_plain_pin_is_repaired_and_allows_pwd_checkout(): void
    {
        DB::table('users')->where('email', $this->manager->email)->update(['approval_pin' => '1234']);
        $migration = require database_path('migrations/2026_09_29_000001_repair_plain_manager_pins.php');
        $migration->up();
        $hash = $this->manager->fresh()->approval_pin;
        $this->assertTrue(Hash::check('1234', $hash));
        $migration->up();
        $this->assertSame($hash, $this->manager->fresh()->approval_pin);
        $this->postJson('/api/transactions', $this->sale() + [
            'discountType' => 'senior', 'discount_manager_id' => $this->manager->id,
            'discount_manager_pin' => '1234', 'discount_reason' => 'PWD',
        ])->assertCreated();
        $this->assertDatabaseHas('discount_approvals', ['transaction_uuid' => 'TXN-test', 'manager_id' => $this->manager->id]);
    }

    public function test_malformed_pin_is_rejected_without_server_error(): void
    {
        DB::table('users')->where('email', $this->manager->email)->update(['approval_pin' => 'invalid']);
        $this->postJson('/api/transactions', $this->sale() + [
            'discountType' => 'employee', 'discount_manager_id' => $this->manager->id,
            'discount_manager_pin' => '1234', 'discount_reason' => 'PWD',
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_manager_pin');
        $this->actingAs($this->manager->fresh())->postJson('/manager/approval-pin', [
            'current_pin' => '1234', 'pin' => '4321', 'pin_confirmation' => '4321',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_pin');
    }

    public function test_model_hashes_new_approval_pins(): void
    {
        $this->manager->forceFill(['approval_pin' => '4321'])->save();
        $this->assertTrue(Hash::check('4321', $this->manager->fresh()->approval_pin));
    }

    public function test_wallet_reserves_stock_and_signed_confirmation_is_idempotent(): void
    {
        config(['services.paymongo.secret' => 'sk_test_test', 'services.paymongo.webhook_secret' => 'secret']);
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_test', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/test']]])]);
        $body = ['uuid' => 'TXN-wallet', 'provider' => 'GCash', 'stockItems' => [['productId' => 1, 'qty' => 2]], 'total' => 1];
        $this->postJson('/api/payments/paymongo/checkout', $body)->assertOk();
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-wallet', 'total' => 20, 'status' => 'Pending Payment']);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 3]);
        Http::assertSent(fn ($r) => $r['data']['attributes']['line_items'][0]['amount'] === 2000);
        $this->postJson('/api/payments/paymongo/checkout', $body)->assertConflict();
        $session = ['id' => 'cs_test', 'attributes' => ['metadata' => ['transaction_uuid' => 'TXN-wallet'], 'livemode' => false, 'payments' => [['id' => 'pay_test', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => 2000]]]]];
        Http::fake(['*/checkout_sessions/cs_test' => Http::response(['data' => $session])]);
        $event = ['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => $session]]];
        $this->postJson('/api/payments/paymongo/webhook', $event)->assertUnauthorized();
        $raw = json_encode($event);
        $ts = time();
        $signature = 't='.$ts.',te='.hash_hmac('sha256', $ts.'.'.$raw, 'secret');
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/payments/paymongo/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature], $raw)->assertOk();
        }
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-wallet', 'status' => 'Paid', 'paid' => 20]);
        $this->assertDatabaseCount('sales_log', 1);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 3]);
    }

    public function test_wallet_refund_must_match_provider_payment_amount_and_status(): void
    {
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        DB::table('transactions')->where('uuid', 'TXN-test')->update(['status' => 'Paid', 'paymentMode' => 'GCash', 'paymentReference' => 'pay_original']);
        config(['services.paymongo.secret' => 'sk_test_test']);
        $body = $this->approval() + ['provider_refund_id' => 'ref_test'];
        $attributes = ['status' => 'succeeded', 'currency' => 'PHP', 'payment_id' => 'pay_wrong', 'amount' => 1000, 'livemode' => false];
        Http::fake(['*/refunds/ref_test' => Http::sequence()->push(['data' => ['id' => 'ref_test', 'attributes' => $attributes]])->push(['data' => ['id' => 'ref_test', 'attributes' => array_replace($attributes, ['payment_id' => 'pay_original'])]])]);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertUnprocessable();
        $this->assertDatabaseCount('transaction_approvals', 0);
        $this->patchJson('/api/transactions/TXN-test', $body)->assertOk()->assertJsonPath('status', 'Refunded');
        $this->assertDatabaseHas('transaction_approvals', ['provider_refund_id' => 'ref_test', 'amount' => 10]);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }

    public function test_legacy_transaction_without_lines_cannot_invent_a_refund(): void
    {
        DB::table('transactions')->insert(['uuid' => 'TXN-legacy', 'status' => 'Unused', 'paymentMode' => 'Cash', 'total' => 100, 'paid' => 100]);
        $this->patchJson('/api/transactions/TXN-legacy', $this->approval())->assertUnprocessable();
        $this->assertDatabaseCount('transaction_approvals', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }

    public function test_wallet_cancellation_releases_stock_only_after_remote_confirmation(): void
    {
        config(['services.paymongo.secret' => 'sk_test_test', 'services.paymongo.webhook_secret' => 'test-signing-secret']);
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_cancel', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cancel']]])]);
        $this->postJson('/api/payments/paymongo/checkout', ['uuid' => 'TXN-cancel', 'provider' => 'GCash', 'stockItems' => [['productId' => 1, 'qty' => 1]]])->assertOk();
        Http::fake(['*/expire' => Http::response([]), '*/checkout_sessions/cs_cancel' => Http::response(['data' => ['id' => 'cs_cancel', 'attributes' => ['status' => 'expired', 'payments' => []]]])]);
        $this->postJson('/api/payments/paymongo/TXN-cancel/cancel')->assertOk();
        $this->postJson('/api/payments/paymongo/TXN-cancel/cancel')->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
    }
}

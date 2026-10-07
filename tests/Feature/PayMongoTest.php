<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PayMongoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret' => 'sk_test_example', 'services.paymongo.webhook_secret' => 'signing-secret']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        DB::table('categories')->insert(['name' => 'Medical', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Mask', 'stock' => 5, 'status' => 'Active', 'price' => 100, 'category' => 'Medical']);
    }

    public function test_removed_wallets_are_rejected_without_reserving_stock(): void
    {
        foreach (['Maya', 'GrabPay'] as $provider) {
            $this->postJson('/api/payments/paymongo/checkout', [
                'uuid' => 'TXN-removed', 'provider' => $provider,
                'stockItems' => [['productId' => 1, 'qty' => 1]],
            ])->assertUnprocessable()->assertJsonValidationErrors('provider');
        }
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        Http::assertNothingSent();
    }

    private function checkout(): TestResponse
    {
        return $this->postJson('/api/payments/paymongo/checkout', [
            'uuid' => 'TXN-paymongo', 'provider' => 'GCash', 'stockItems' => [['productId' => 1, 'qty' => 1]],
        ]);
    }

    public function test_missing_configuration_does_not_reserve_stock(): void
    {
        config(['services.paymongo.secret' => null]);
        $this->checkout()->assertStatus(503);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        Http::assertNothingSent();
    }

    public function test_rejected_checkout_releases_stock_but_uncertain_response_keeps_it_reserved(): void
    {
        Http::fake(['*/checkout_sessions' => Http::sequence()->push([], 422)->push([], 500)]);
        $this->checkout()->assertStatus(502)->assertJsonPath('retryable', true);
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-paymongo', 'status' => 'Payment Failed']);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_method_types'] === ['gcash']
            && $r['data']['attributes']['reference_number'] === 'TXN-paymongo'
            && $r['data']['attributes']['line_items'][0]['amount'] === 10000);
        $this->postJson('/api/payments/paymongo/checkout', [
            'uuid' => 'TXN-uncertain', 'provider' => 'GCash', 'stockItems' => [['productId' => 1, 'qty' => 1]],
        ])->assertStatus(502);
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-uncertain', 'status' => 'Pending Payment']);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 4]);
    }

    private function deliver(array $session, int $timestamp, string $mode = 'te'): TestResponse
    {
        $raw = json_encode(['data' => ['type' => 'checkout_session.payment.paid', 'data' => $session]]);
        $signature = 't='.$timestamp.','.$mode.'='.hash_hmac('sha256', $timestamp.'.'.$raw, 'signing-secret');

        return $this->call('POST', '/api/payments/paymongo/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $raw);
    }

    public function test_qr_checkout_uses_exact_server_total_and_confirms_only_once(): void
    {
        DB::table('products')->where('id', 1)->update(['price' => 123.45]);
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_qr', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/qr']]])]);
        $this->postJson('/api/payments/paymongo/checkout', [
            'uuid' => 'TXN-qr', 'provider' => 'QR Ph', 'total' => 1,
            'stockItems' => [['productId' => 1, 'qty' => 2]],
        ])->assertOk()->assertJsonPath('checkoutUrl', 'https://checkout.paymongo.com/qr');
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_method_types'] === ['qrph']
            && $r['data']['attributes']['line_items'][0]['amount'] === 24690
            && $r['data']['attributes']['line_items'][0]['quantity'] === 1);
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-qr', 'paymentMode' => 'QR Ph', 'total' => 246.90, 'paid' => 0, 'status' => 'Pending Payment']);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 3]);
        $this->assertDatabaseCount('sales_log', 0);
        $session = ['id' => 'cs_qr', 'attributes' => ['metadata' => ['transaction_uuid' => 'TXN-qr'], 'livemode' => false,
            'payments' => [['id' => 'pay_qr', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => 24690]]]]];
        Http::fake(['*/checkout_sessions/cs_qr' => Http::response(['data' => $session])]);
        $this->deliver($session, time())->assertOk();
        $this->deliver($session, time())->assertOk();
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-qr', 'status' => 'Paid', 'paid' => 246.90]);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 3]);
        $this->assertDatabaseCount('sales_log', 1);
    }

    public function test_missing_webhook_secret_does_not_reserve_stock(): void
    {
        config(['services.paymongo.webhook_secret' => null]);
        $this->checkout()->assertStatus(503);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_replay_wrong_mode_wrong_amount_and_missing_payment_id(): void
    {
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_example', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/example']]])]);
        $this->checkout()->assertOk();
        $session = ['id' => 'cs_example', 'attributes' => ['metadata' => ['transaction_uuid' => 'TXN-paymongo'], 'livemode' => false,
            'payments' => [['id' => 'pay_example', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => 1]]]]];
        $this->deliver($session, time() - 600)->assertUnauthorized();
        $this->deliver($session, time(), 'li')->assertUnauthorized();
        $wrongAmount = $session;
        $session['attributes']['payments'][0]['attributes']['amount'] = 10000;
        unset($session['attributes']['payments'][0]['id']);
        Http::fake(['*/checkout_sessions/cs_example' => Http::sequence()->push(['data' => $wrongAmount])->push(['data' => $session])]);
        $this->deliver($wrongAmount, time())->assertUnprocessable();
        $this->deliver($session, time())->assertUnprocessable();
        $this->assertDatabaseHas('transactions', ['uuid' => 'TXN-paymongo', 'status' => 'Pending Payment', 'paid' => 0]);
        $this->assertDatabaseCount('sales_log', 0);
    }

    public function test_configuration_check_reports_missing_keys_and_accepts_https_test_setup(): void
    {
        config(['services.paymongo.secret' => null, 'services.paymongo.webhook_secret' => null, 'app.url' => 'http://localhost']);
        $this->artisan('paymongo:check')->assertFailed();
        config(['services.paymongo.secret' => 'sk_test_example', 'services.paymongo.webhook_secret' => 'signing-secret', 'app.url' => 'https://kita.example.com']);
        $this->artisan('paymongo:check')->assertSuccessful();
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionHistoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_newest_checkout_is_first_even_when_uuid_sorts_after_older_sales(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        foreach (['TXN-yesterday', 'TXN-A-older', 'TXN-Z-newest'] as $index => $uuid) {
            DB::table('transactions')->insert(['uuid' => $uuid, 'date' => $index === 0 ? now()->subDay()->toDateString() : now()->toDateString(), 'status' => 'Unused']);
            DB::table('transaction_lines')->insert(['transaction_uuid' => $uuid, 'productId' => 1,
                'name' => 'Test product', 'qty' => 1, 'unitPrice' => 10, 'lineTotal' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('transactions')->insert(['uuid' => 'TXN-legacy', 'date' => now()->subDays(2)->toDateString()]);
        DB::table('transaction_lines')->where('transaction_uuid', 'TXN-A-older')->update(['updated_at' => now()->addHour(), 'refundedQty' => 1]);
        $this->getJson('/api/kita-data')->assertOk()
            ->assertJsonPath('TRANSACTIONS.0.uuid', 'TXN-Z-newest')
            ->assertJsonPath('TRANSACTIONS.1.uuid', 'TXN-A-older')
            ->assertJsonPath('TRANSACTIONS.2.uuid', 'TXN-yesterday')
            ->assertJsonPath('TRANSACTIONS.3.uuid', 'TXN-legacy');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionHistoryOrderTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesWorkspaceFixtures;

    public function test_newest_checkout_is_first_even_when_uuid_sorts_after_older_sales(): void
    {
        $cashier=User::factory()->create(['role'=>'cashier']);$this->assignWorkspace(User::factory()->create(['role'=>'manager']),$cashier);$this->actingAs($cashier);
        foreach (['TXN-yesterday', 'TXN-A-older', 'TXN-Z-newest'] as $index => $uuid) {
            $this->insertFixture('transactions', ['uuid' => $uuid, 'date' => $index === 0 ? now()->subDay()->toDateString() : now()->toDateString(), 'status' => 'Unused', 'cashierId'=>$cashier->id,'cashierRole'=>'cashier']);
            $this->insertFixture('transaction_lines', ['transaction_uuid' => $uuid, 'productId' => 1,
                'name' => 'Test product', 'qty' => 1, 'unitPrice' => 10, 'lineTotal' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->insertFixture('transactions', ['uuid' => 'TXN-legacy', 'date' => now()->subDays(2)->toDateString(), 'cashierId'=>$cashier->id,'cashierRole'=>'cashier']);
        DB::table('transaction_lines')->where('transaction_uuid', 'TXN-A-older')->update(['updated_at' => now()->addHour(), 'refundedQty' => 1]);
        $this->getJson('/api/kita-data')->assertOk()
            ->assertJsonPath('TRANSACTIONS.0.uuid', 'TXN-Z-newest')
            ->assertJsonPath('TRANSACTIONS.1.uuid', 'TXN-A-older')
            ->assertJsonPath('TRANSACTIONS.2.uuid', 'TXN-yesterday')
            ->assertJsonPath('TRANSACTIONS.3.uuid', 'TXN-legacy');
    }
}

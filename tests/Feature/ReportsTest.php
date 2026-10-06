<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'manager', 'status' => 'Active']));
        DB::table('brands')->insert(['id' => 1, 'name' => 'Example']);
        DB::table('subcategories')->insert(['id' => 1, 'name' => 'Juice', 'category' => 'Beverages']);
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Supplier']);
        DB::table('products')->insert([
            ['id' => 1, 'name' => '=Danger,<script>', 'category' => 'Beverages', 'brandId' => 1, 'subcategoryId' => 1, 'supplierId' => 1, 'stock' => 2, 'minStock' => 5],
            ['id' => 2, 'name' => 'Other', 'category' => 'Snacks', 'brandId' => null, 'subcategoryId' => null, 'supplierId' => null, 'stock' => 20, 'minStock' => 5],
        ]);
        foreach (['2026-10-01', '2026-10-06', '2026-10-07'] as $index => $date) {
            DB::table('stock_movements')->insert(['productId' => 1, 'quantityChange' => 1, 'quantityBefore' => 1, 'quantityAfter' => 2, 'referenceType' => 'receiving', 'referenceId' => (string) $index, 'created_at' => $date.' 12:00:00']);
            DB::table('transactions')->insert(['uuid' => 'sale-'.$index, 'date' => $date, 'status' => 'Completed']);
            DB::table('transaction_lines')->insert(['transaction_uuid' => 'sale-'.$index, 'productId' => 1, 'name' => '=Danger,<script>', 'qty' => 1, 'unitPrice' => 10, 'lineTotal' => 10]);
            DB::table('inventory_batches')->insert(['productId' => 1, 'supplierId' => 1, 'quantityReceived' => 2, 'quantityRemaining' => 2, 'unitCost' => 10, 'receivedDate' => $date, 'source' => 'receiving', 'created_at' => $date]);
        }
    }

    public function test_date_and_product_filters_apply_to_all_dated_reports(): void
    {
        foreach (['sales', 'receipts', 'movements'] as $type) {
            $query = http_build_query(['type' => $type, 'from' => '2026-10-06', 'to' => '2026-10-06', 'category' => 'Beverages', 'brandId' => 1, 'subcategoryId' => 1, 'supplierId' => 1, 'productId' => 1]);
            $this->getJson('/reports/data?'.$query)->assertOk()->assertJsonCount(1, 'rows');
            $this->get('/reports/data?'.$query.'&format=print')->assertOk()->assertSee('1 records')->assertSee('&lt;script&gt;', false);
            $csv = $this->get('/reports/data?'.$query.'&format=csv')->assertOk()->streamedContent();
            $this->assertStringContainsString("'=Danger", $csv);
            $this->assertStringNotContainsString('2026-10-07', $csv);
        }
    }

    public function test_current_inventory_filter_and_admin_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'Active']));
        $this->getJson('/reports/data?type=inventory&lowStock=1&brandId=1')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', 1);
        $this->getJson('/reports/data?type=inventory&category=Missing')->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_validation_and_access_control(): void
    {
        $this->getJson('/reports/data?type=sales&from=2026-10-07&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/reports/data?type=unknown')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'status' => 'Active']));
        foreach (['json', 'csv', 'print'] as $format) {
            $this->getJson('/reports/data?type=inventory&format='.$format)->assertForbidden();
        }
    }
}

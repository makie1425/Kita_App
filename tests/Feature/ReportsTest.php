<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionNumber;
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
            TransactionNumber::assign('sale-'.$index, $date);
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

    public function test_receiving_audit_filters_and_exports(): void
    {
        DB::table('purchase_orders')->insert(['id' => 'PO-AUDIT', 'supplierId' => 1]);
        foreach (['Missing items', 'Damaged stock', null] as $i => $type) {
            DB::table('receiving_records')->insert(['id' => 'R-'.$i, 'poId' => 'PO-AUDIT', 'date' => '2026-10-22', 'deliveryStatus' => $type ? 'Partially Received' : 'Fully Received', 'discrepancy' => (bool) $type, 'discrepancyType' => $type]);
            DB::table('receiving_record_lines')->insert(['receivingId' => 'R-'.$i, 'productId' => 1, 'deliveredQty' => 2, 'conditionText' => $i === 1 ? 'Damaged' : 'Accepted']);
        }
        foreach (['missing', 'damaged', 'none'] as $audit) {
            $this->getJson('/reports/data?type=receiving&audit='.$audit)->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.reportPo', 'PO-AUDIT');
        }
        $this->getJson('/reports/data?type=receiving&status=Fully%20Received')->assertOk()->assertJsonCount(1, 'rows');
        $this->get('/reports/data?type=receiving&format=print')->assertOk();
        $this->get('/reports/data?type=receiving&format=pdf')->assertOk();
        $this->assertStringContainsString('PO-AUDIT', $this->get('/reports/data?type=receiving&format=csv')->assertOk()->streamedContent());
    }

    public function test_expiration_audit_statuses_filters_and_exports(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-22 12:00:00', 'Asia/Manila'));
        DB::table('inventory_batches')->delete();
        foreach ([0, 7, 30, 31] as $days) {
            DB::table('inventory_batches')->insert(['productId' => 1, 'supplierId' => 1, 'quantityReceived' => 2, 'quantityRemaining' => 2, 'unitCost' => 10, 'receivedDate' => '2026-10-01', 'source' => 'receiving', 'created_at' => now(), 'expiryDate' => now()->addDays($days)->toDateString()]);
        }
        $this->getJson('/reports/data?type=expiry')->assertOk()->assertJsonCount(4, 'rows')
            ->assertJsonPath('rows.0.status', 'Critical')->assertJsonPath('rows.1.status', 'Warning')
            ->assertJsonPath('rows.2.status', 'Expired')->assertJsonPath('rows.3.status', 'Good');
        $this->getJson('/reports/data?type=expiry&expiryStatus=Nearly%20expired')->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.status', 'Critical');
        $this->getJson('/reports/data?type=expiry&expiryStatus=Expired')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.status', 'Expired');
        $this->getJson('/reports/data?type=expiry&expiryStatus=Critical&category=Beverages')->assertOk()->assertJsonCount(1, 'rows');
        $this->getJson('/reports/data?type=expiry&from=2026-10-29&to=2026-10-29')->assertOk()->assertJsonCount(1, 'rows');
        $this->get('/reports/data?type=expiry&format=print')->assertOk()->assertSee('Printable Expiration');
        $csv = $this->get('/reports/data?type=expiry&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Critical', $csv);
        $this->get('/reports/data?type=expiry&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_sales_chart_time_and_refund_summary_use_recorded_lines(): void
    {
        DB::table('transaction_lines')->where('transaction_uuid', 'sale-1')->update(['created_at' => '2026-10-06 14:30:00', 'refundedAmount' => 3]);
        $this->getJson('/reports/data?type=sales&from=2026-10-06&to=2026-10-06')
            ->assertOk()->assertJsonPath('rows.0.time', '14:30')
            ->assertJsonPath('summary.Recorded less refunds (PHP)', '7.00');
    }

    public function test_chart_time_converts_storage_timezone_and_uses_one_time_per_transaction(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::table('transaction_lines')->where('transaction_uuid', 'sale-1')->update(['created_at' => '2026-10-06 04:59:00']);
        DB::table('transaction_lines')->insert(['transaction_uuid' => 'sale-1', 'productId' => 2, 'name' => 'Other', 'qty' => 1, 'unitPrice' => 10, 'lineTotal' => 10, 'created_at' => '2026-10-06 05:00:00']);
        $this->getJson('/reports/data?type=sales&from=2026-10-06&to=2026-10-06')
            ->assertOk()->assertJsonPath('rows.0.time', '12:59')->assertJsonPath('rows.1.time', '12:59');
    }

    public function test_transaction_numbers_remain_stable_across_filters_and_exports(): void
    {
        $number = TransactionNumber::assign('sale-1', '2026-10-06');
        $this->assertMatchesRegularExpression('/^TXN-20261006-\d{6}$/', $number);
        $this->assertSame($number, TransactionNumber::assign('sale-1', '2026-10-07'));
        $this->assertDatabaseCount('transaction_numbers', 3);
        $query = '/reports/data?type=sales&from=2026-10-06&to=2026-10-06';
        $this->getJson($query)->assertJsonPath('rows.0.reference', $number)->assertJsonPath('summary.Transactions', '1')->assertJsonPath('summary.Recorded line totals (PHP)', '10.00');
        $this->get($query.'&format=print')->assertOk()->assertSee($number)->assertSee('PREPARED BY')->assertSee('10.00');
        $this->assertStringContainsString($number, $this->get($query.'&format=csv')->streamedContent());
        DB::table('stock_movements')->insert(['productId' => 1, 'quantityChange' => -1, 'quantityBefore' => 2, 'quantityAfter' => 1, 'referenceType' => 'checkout', 'referenceId' => 'sale-1', 'created_at' => '2026-10-08 12:00:00']);
        $this->getJson('/reports/data?type=movements&from=2026-10-08')->assertOk()->assertJsonPath('rows.0.reference', $number);
    }

    public function test_migration_numbers_older_transactions_without_changing_their_ids(): void
    {
        $migration = require database_path('migrations/2026_10_06_000004_add_transaction_numbers.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('transaction_numbers', 3);
        $this->assertDatabaseHas('transactions', ['uuid' => 'sale-1']);
        $this->assertDatabaseHas('transaction_lines', ['transaction_uuid' => 'sale-1']);
        $this->getJson('/reports/data?type=sales&from=2026-10-06&to=2026-10-06')->assertJsonPath('rows.0.reference', 'TXN-20261006-000002');
    }

    public function test_validation_and_access_control(): void
    {
        $this->getJson('/reports/data?type=sales&from=2026-10-07&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/reports/data?type=unknown')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'status' => 'Active']));
        foreach (['json', 'csv', 'print', 'pdf'] as $format) {
            $this->getJson('/reports/data?type=inventory&format='.$format)->assertForbidden();
        }
    }

    public function test_pdf_download_is_a_real_attachment_for_every_report_type(): void
    {
        foreach (['sales', 'inventory', 'receipts', 'movements'] as $type) {
            $response = $this->get('/reports/data?type='.$type.'&format=pdf&from=2026-10-06&to=2026-10-06');
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('%%EOF', $response->getContent());
        }
    }
}

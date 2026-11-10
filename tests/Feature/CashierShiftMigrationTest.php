<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashierShiftMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_index_names_fit_the_production_limit(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new MySqlConnection(fn () => throw new \RuntimeException('SQL compilation must not connect.'), 'testing', '', ['version' => '8.0.36']);
        try {
            Schema::swap($connection->getSchemaBuilder());
            $queries = $connection->pretend(function () {
                $migration = require database_path('migrations/2026_11_10_000002_create_cashier_shifts.php');
                $migration->up();
            });
        } finally {
            Schema::swap($original);
        }
        $names = [];
        foreach ($queries as $query) {
            preg_match_all('/add (?:unique |index )`([^`]+)`/i', $query['query'], $matches);
            array_push($names, ...$matches[1]);
        }
        $this->assertCount(5, $names);
        foreach ($names as $name) $this->assertLessThanOrEqual(64, strlen($name), $name);
    }

    public function test_retry_repairs_missing_indexes_and_tables_without_losing_shifts(): void
    {
        $id = DB::table('cashier_shifts')->insertGetId([
            'cashier_id' => 1, 'cashier_role' => 'cashier', 'cashier_name' => 'Existing cashier',
            'opening_cash' => 125, 'opened_at' => now(),
        ]);
        Schema::table('cashier_shifts', fn (Blueprint $table) => $table->dropIndex('cashier_shifts_actor_open_idx'));
        Schema::drop('cashier_shift_entries');
        $migration = require database_path('migrations/2026_11_10_000002_create_cashier_shifts.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('cashier_shifts', ['id' => $id, 'opening_cash' => 125]);
        $this->assertTrue(Schema::hasIndex('cashier_shifts', 'cashier_shifts_actor_open_idx'));
        $this->assertTrue(Schema::hasTable('cashier_shift_entries'));
        $this->assertTrue(Schema::hasIndex('cashier_shift_entries', 'cashier_shift_entries_shift_id_type_reference_unique'));
    }
}

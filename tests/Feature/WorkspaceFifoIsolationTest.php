<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FifoInventory;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceFifoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $name): array
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => $name]);
        $workspaceId = DB::table('workspaces')->insertGetId(['organization_id' => $organizationId, 'name' => $name]);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $workspaceId]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $workspaceId, 'user_id' => $manager->id, 'user_role' => 'manager', 'active' => true]);
        $user = User::factory()->create(['role' => 'cashier', 'assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager']);
        $user->forceFill(['workspace_id' => $workspaceId])->save();
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $user->id, 'user_role' => 'cashier',
            'active' => true, 'can_manage_accounts' => false,
        ]);
        DB::table('products')->insert([
            'id' => $workspaceId, 'workspace_id' => $workspaceId, 'name' => $name,
            'category' => $name, 'status' => 'Active', 'stock' => 3, 'price' => 10,
        ]);

        return [$user, $workspaceId];
    }

    public function test_fifo_consumes_only_owned_batches_and_stamps_allocations_and_movements(): void
    {
        config(['workspaces.enabled' => true]);
        [$firstUser, $firstId] = $this->workspace('First');
        [, $secondId] = $this->workspace('Second');
        $this->actingAs($firstUser);

        DB::transaction(function () use ($firstId, $secondId, $firstUser) {
            FifoInventory::receive($firstId, 3, 5, now()->toDateString());
            $foreignBatch = DB::table('inventory_batches')->insertGetId([
                'workspace_id' => $secondId, 'productId' => $firstId, 'quantityReceived' => 5,
                'quantityRemaining' => 5, 'unitCost' => 5, 'receivedDate' => now()->toDateString(),
                'source' => 'legacy_opening', 'created_at' => now(),
            ]);
            DB::table('products')->where('id', $firstId)->update(['stock' => 2]);
            StockMovement::record($firstId, 3, 2, 'checkout', 'OWNED-SALE', $firstUser);
            $this->assertDatabaseHas('inventory_batches', ['id' => $foreignBatch, 'quantityRemaining' => 5]);
            $this->assertDatabaseHas('inventory_allocations', ['referenceId' => 'OWNED-SALE', 'workspace_id' => $firstId, 'quantity' => 1]);
            $this->assertDatabaseHas('stock_movements', ['referenceId' => 'OWNED-SALE', 'workspace_id' => $firstId]);
        });
    }

    public function test_foreign_product_and_allocation_cannot_be_changed(): void
    {
        config(['workspaces.enabled' => true]);
        [$firstUser, $firstId] = $this->workspace('First');
        [, $secondId] = $this->workspace('Second');
        $this->actingAs($firstUser);

        try {
            DB::transaction(fn () => StockMovement::record($secondId, 3, 2, 'checkout', 'FOREIGN', $firstUser));
            $this->fail('Foreign product was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        DB::table('transactions')->insert([
            'uuid' => 'FOREIGN-SALE', 'workspace_id' => $secondId, 'status' => 'Unused',
            'date' => now()->toDateString(), 'total' => 10, 'cashierId' => $firstUser->id,
        ]);
        try {
            DB::transaction(fn () => FifoInventory::returnStock($firstId, 1, 'FOREIGN-SALE', reservationRelease: true));
            $this->fail('Foreign transaction was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_allocations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unassigned_legacy_batch_is_not_silently_recreated(): void
    {
        config(['workspaces.enabled' => true]);
        [$user, $workspaceId] = $this->workspace('First');
        $this->actingAs($user);
        DB::table('inventory_batches')->insert([
            'productId' => $workspaceId, 'quantityReceived' => 3, 'quantityRemaining' => 3,
            'unitCost' => 5, 'receivedDate' => now()->toDateString(), 'source' => 'legacy_opening',
            'created_at' => now(),
        ]);

        try {
            DB::transaction(fn () => FifoInventory::opening($workspaceId, 3));
            $this->fail('Unassigned stock was recreated.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_batches', 1);
    }
}

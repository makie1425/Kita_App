<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkflowNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesWorkspaceFixtures;

    public function test_notifications_and_read_state_are_private_and_persistent(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->assignWorkspace($manager, $other);
        WorkflowNotifications::send([$manager, $manager], $other, 'Approved', 'PO 1 approved.', 'purchase', '1');
        $this->assertDatabaseCount('notifications', 1);
        $id = DB::table('notifications')->value('id');
        $this->actingAs($other)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'notifications');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertNotFound();
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($manager)->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.0.screen', 'mgrRequestView');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->actingAs($other)->getJson('/api/kita-data')->assertOk()->assertJsonCount(0, 'NOTIFICATIONS');
    }

    public function test_failed_transaction_does_not_send_notifications_and_unassigned_legacy_rows_are_private(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $this->assignWorkspace($user);
        try {
            DB::transaction(function () use ($user) {
                WorkflowNotifications::send([$user], $user, 'Saved', 'Should roll back', 'activity');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('notifications', 0);
        DB::table('notifications')->insert(['id' => 99, 'message' => 'Legacy unassigned']);
        $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'notifications');
    }

    public function test_pagination_and_mark_all_preserve_other_users_read_state(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'manager']);
        $this->assignWorkspace($other, $user);
        for ($i = 0; $i < 52; $i++) {
            WorkflowNotifications::send([$user], $other, 'Action', 'Saved '.$i, 'activity');
        }
        WorkflowNotifications::send([$other], $user, 'Action', 'Private', 'activity');
        $first = $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonCount(50, 'notifications')->assertJsonPath('unread_count', 52);
        $this->getJson('/api/notifications?before='.$first->json('next_before'))->assertOk()->assertJsonCount(2, 'notifications');
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($other)->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PresentationAccountCheckTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesWorkspaceFixtures;

    public function test_checks_roles_and_membership_without_changing_records(): void
    {
        $manager = User::factory()->create(['role'=>'manager']);
        $admin = User::factory()->create(['role'=>'admin']);
        $this->assignWorkspace($manager, $admin);
        $before = DB::table('users')->get()->toJson();
        $this->artisan('presentation:check-accounts', ['admin'=>$admin->email,'manager'=>$manager->email])->assertSuccessful();
        $this->assertSame($before, DB::table('users')->get()->toJson());
        $this->artisan('presentation:check-accounts', ['admin'=>$manager->email,'manager'=>$admin->email])->assertFailed();
        DB::table('workspace_memberships')->where('user_role','admin')->update(['active'=>false]);
        $this->artisan('presentation:check-accounts', ['admin'=>$admin->email,'manager'=>$manager->email])->assertFailed();
    }

    public function test_missing_accounts_fail_without_creating_them(): void
    {
        $this->artisan('presentation:check-accounts', ['admin'=>'absent@example.com','manager'=>'missing@example.com'])->assertFailed();
        $this->assertDatabaseCount('users',0);
    }

    public function test_grocery_loader_is_scoped_balanced_and_repeat_safe(): void
    {
        $manager=User::factory()->create(['role'=>'manager']);
        $admin=User::factory()->create(['role'=>'admin']);
        $this->assignWorkspace($manager,$admin);
        $args=['admin'=>$admin->email,'manager'=>$manager->email];
        $this->artisan('presentation:load-grocery',$args)->assertSuccessful();
        $this->assertDatabaseCount('products',0);
        $this->artisan('presentation:load-grocery',$args+['--apply'=>true])->assertSuccessful();
        $this->assertDatabaseCount('products',50);
        $this->assertDatabaseCount('transactions',150);
        $this->assertDatabaseCount('inventory_batches',150);
        $this->assertDatabaseCount('notifications',0);
        foreach(DB::table('products')->get() as $product){
            $this->assertEquals($this->fixtureWorkspace,$product->workspace_id);
            $this->assertEquals($product->stock,DB::table('inventory_batches')->where('productId',$product->id)->sum('quantityRemaining'));
        }
        $this->artisan('presentation:load-grocery',$args+['--apply'=>true])->assertSuccessful();
        $this->assertDatabaseCount('transactions',150);
    }

    public function test_grocery_loader_preserves_existing_catalog(): void
    {
        $manager=User::factory()->create(['role'=>'manager']);
        $admin=User::factory()->create(['role'=>'admin']);
        $this->assignWorkspace($manager,$admin);
        DB::table('categories')->insert(['name'=>'Existing category','workspace_id'=>$this->fixtureWorkspace,'status'=>'Active']);
        $this->artisan('presentation:load-grocery',['admin'=>$admin->email,'manager'=>$manager->email,'--apply'=>true])->assertFailed();
        $this->assertDatabaseCount('categories',1);
        $this->assertDatabaseCount('products',0);
        $this->assertDatabaseCount('transactions',0);
    }

    public function test_interrupted_import_keeps_complete_checkpoints_and_resumes_without_duplicates(): void
    {
        $manager=User::factory()->create(['role'=>'manager']);
        $admin=User::factory()->create(['role'=>'admin']);
        $this->assignWorkspace($manager,$admin);
        $workspace=$this->fixtureWorkspace;
        $args=['admin'=>$admin->email,'manager'=>$manager->email,'--apply'=>true];
        $interrupt=true;
        DB::listen(function ($query) use (&$interrupt, $workspace) {
            if ($interrupt && str_starts_with(strtolower($query->sql), 'insert into "transactions"') && in_array('GR'.$workspace.'-2024-1',$query->bindings,true)) {
                $interrupt=false;
                throw new \RuntimeException('Simulated interrupted second checkpoint');
            }
        });
        $this->artisan('presentation:load-grocery',$args)->assertFailed();
        $this->assertFalse($interrupt, 'The test must interrupt a real history checkpoint.');
        $this->assertDatabaseCount('products',50);
        $this->assertDatabaseCount('transactions',1);
        $this->assertDatabaseCount('inventory_batches',1);
        $this->assertDatabaseCount('purchase_orders',1);
        $this->assertDatabaseHas('grocery_imports',['workspace_id'=>$workspace,'completed_steps'=>1,'completed'=>false]);
        $this->actingAs($manager)->getJson('/api/kita-data')->assertOk()->assertJsonCount(50,'PRODUCTS')->assertJsonCount(1,'TRANSACTIONS');
        $this->artisan('presentation:load-grocery',$args)->assertSuccessful();
        $this->assertDatabaseCount('products',50);
        $this->assertDatabaseCount('transactions',150);
        $this->assertDatabaseCount('inventory_batches',150);
        $this->assertDatabaseCount('purchase_orders',151);
        $this->assertDatabaseHas('grocery_imports',['workspace_id'=>$workspace,'completed_steps'=>150,'completed'=>true]);
        $this->actingAs($manager)->getJson('/api/kita-data')->assertOk()->assertJsonCount(50,'PRODUCTS')->assertJsonCount(150,'TRANSACTIONS');
        $this->actingAs(User::factory()->create(['role'=>'manager']))->getJson('/api/kita-data')->assertOk()->assertJsonCount(0,'PRODUCTS')->assertJsonCount(0,'TRANSACTIONS');
    }

    public function test_unassigned_accounts_receive_a_new_isolated_workspace(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $manager=User::factory()->create(['role'=>'manager']);
        $unrelated=User::factory()->create(['role'=>'manager']);
        $args=['admin'=>$admin->email,'manager'=>$manager->email,'--apply'=>true];
        $this->artisan('presentation:load-grocery',$args)->assertSuccessful();
        $this->assertNotNull($manager->fresh()->workspace_id);
        $this->assertEquals($admin->fresh()->workspace_id,$manager->fresh()->workspace_id);
        $this->assertNull($unrelated->fresh()->workspace_id);
        $this->assertDatabaseCount('workspace_memberships',2);
        $this->assertDatabaseCount('products',50);
        $this->artisan('presentation:load-grocery',$args)->assertSuccessful();
        $this->assertDatabaseCount('workspaces',1);
    }

    public function test_conflicting_creator_is_not_reassigned(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $other=User::factory()->create(['role'=>'admin']);
        $manager=User::factory()->create(['role'=>'manager']);
        $manager->forceFill(['created_by_user_id'=>$other->id,'created_by_user_role'=>'admin'])->save();
        $this->artisan('presentation:load-grocery',['admin'=>$admin->email,'manager'=>$manager->email,'--apply'=>true])->assertFailed();
        $this->assertDatabaseCount('workspaces',0);
        $this->assertDatabaseCount('products',0);
    }
}

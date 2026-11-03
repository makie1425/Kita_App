<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class AdminProvisioningTest extends TestCase
{
 use RefreshDatabase;
 public function test_legacy_admin_is_visible_without_granting_business_workspace_access(): void
 {
  $super=User::factory()->create(['role'=>'superadmin','workspace_id'=>null]);
  $org=DB::table('organizations')->insertGetId(['name'=>'Existing organization']);
  $workspace=DB::table('workspaces')->insertGetId(['organization_id'=>$org,'name'=>'Existing workspace']);
  $admin=User::factory()->create(['role'=>'admin','workspace_id'=>$workspace,'email'=>'markkiel97@gmail.com']);
  User::factory()->create(['role'=>'manager','workspace_id'=>$workspace]);
  User::factory()->create(['role'=>'cashier','workspace_id'=>$workspace]);
  DB::table('categories')->insert(['name'=>'Private category','status'=>'Active','workspace_id'=>$workspace]);
  $this->actingAs($super)->getJson('/api/accounts')->assertOk()->assertJsonCount(1,'accounts')->assertJsonPath('accounts.0.email',$admin->email);
  $this->getJson('/api/kita-data')->assertOk()->assertJsonCount(0,'CATEGORIES')->assertJsonCount(0,'PRODUCTS');
  $this->patchJson('/api/accounts/admin/'.$admin->id,['first_name'=>'Mark','last_name'=>'Marzo'])->assertOk();
  $this->assertEquals($workspace,$admin->fresh()->workspace_id);
  $this->assertDatabaseCount('workspace_memberships',0);
  $this->actingAs($admin)->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
 }
 public function test_super_admin_without_workspace_creates_isolated_admins_and_can_manage_them(): void
 {
  Mail::fake();
  $super=User::factory()->create(['role'=>'superadmin','workspace_id'=>null]);
  $this->actingAs($super);
  foreach (['alpha','beta'] as $name) {
   $this->postJson('/api/accounts',['first_name'=>$name,'last_name'=>'Owner','email'=>$name.'@example.com','role'=>'admin','status'=>'Active','workspace_id'=>999])->assertCreated();
  }
  $a=User::where('email','alpha@example.com')->firstOrFail();
  $b=User::where('email','beta@example.com')->firstOrFail();
  $this->assertNotEquals($a->workspace_id,$b->workspace_id);
  $this->assertNotEquals(DB::table('workspaces')->where('id',$a->workspace_id)->value('organization_id'),DB::table('workspaces')->where('id',$b->workspace_id)->value('organization_id'));
  $this->assertNull($super->fresh()->workspace_id);
  $this->getJson('/api/accounts')->assertOk()->assertJsonCount(2,'accounts');
  $this->patchJson('/api/accounts/admin/'.$b->id,['status'=>'Inactive'])->assertOk();
  $this->patchJson('/api/accounts/admin/'.$b->id,['status'=>'Active'])->assertOk();
  DB::table('categories')->insert(['name'=>'Private category','status'=>'Active','workspace_id'=>$a->workspace_id]);
  $a->forceFill(['must_change_password'=>false,'credential_version'=>0])->save();
  $b->forceFill(['must_change_password'=>false,'credential_version'=>0])->save();
  $this->actingAs($a)->getJson('/api/kita-data')->assertOk()->assertJsonCount(1,'CATEGORIES');
  $this->actingAs($b)->getJson('/api/kita-data')->assertOk()->assertJsonCount(0,'CATEGORIES')->assertJsonCount(0,'PRODUCTS')->assertJsonCount(0,'TRANSACTIONS');
  $this->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
  $this->patchJson('/api/accounts/admin/'.$a->id,['status'=>'Inactive'])->assertForbidden();
 }
}

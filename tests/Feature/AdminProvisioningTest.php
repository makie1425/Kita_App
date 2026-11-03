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

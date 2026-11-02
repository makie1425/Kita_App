<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceAccountIsolationTest extends TestCase
{
 use RefreshDatabase;
 public function test_super_admin_account_actions_cannot_reach_other_roles(): void {
  config(['workspaces.enabled'=>true]);
  $super=$this->manager('Super');$super->forceFill(['role'=>'superadmin'])->save();
  DB::table('workspace_memberships')->where('user_id',$super->id)->update(['user_role'=>'super_admin']);
  $admin=User::factory()->create(['role'=>'admin','workspace_id'=>$super->workspace_id]);
  $cashier=User::factory()->create(['role'=>'cashier','workspace_id'=>$super->workspace_id]);
  $manager=User::factory()->create(['role'=>'manager','workspace_id'=>$super->workspace_id]);
  $this->assertSame([$admin->id],\App\Services\WorkspaceAccess::accounts($super)->pluck('id')->all());
  $this->actingAs($super)->getJson('/api/accounts')->assertOk()->assertJsonCount(1,'accounts')->assertJsonPath('accounts.0.role','admin');
  $this->postJson('/api/accounts',['first_name'=>'New','last_name'=>'Manager','email'=>'blocked@example.com','role'=>'manager'])->assertForbidden();
  $this->assertDatabaseMissing('users',['email'=>'blocked@example.com']);
  $this->actingAs($super)->postJson('/api/accounts/cashier/'.$cashier->id.'/resend-setup')->assertNotFound();
  $this->postJson('/api/accounts/manager/'.$manager->id.'/send-reset')->assertNotFound();
  $this->patchJson('/api/accounts/cashier/'.$cashier->id,['status'=>'Inactive'])->assertForbidden();
  $this->assertSame('Active',$cashier->fresh()->status);
  $this->assertSame('Active',$admin->fresh()->status);
 }
 public function test_manager_created_cashier_has_explicit_assignment_and_other_manager_cannot_access_it(): void {
  config(['workspaces.enabled'=>true]);\Illuminate\Support\Facades\Mail::fake();
  $owner=$this->manager('Owner');$other=$this->manager('Other');
  $this->actingAs($owner)->postJson('/api/accounts',['first_name'=>'Owned','last_name'=>'Cashier','email'=>'owned@example.com','role'=>'cashier'])->assertCreated();
  $cashier=User::where('email','owned@example.com')->firstOrFail();
  $this->assertSame($owner->id,(int)$cashier->assigned_manager_id);
  $this->assertSame('manager',$cashier->assigned_manager_role);
  $this->actingAs($other)->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
  $this->postJson('/api/accounts/cashier/'.$cashier->id.'/send-reset')->assertNotFound();
 }
 private function manager(string $name): User {
  $org=DB::table('organizations')->insertGetId(['name'=>$name]);
  $scope=DB::table('workspaces')->insertGetId(['organization_id'=>$org,'name'=>$name]);
  $actor=User::factory()->create(['role'=>'manager']);$actor->forceFill(['workspace_id'=>$scope])->save();
  DB::table('workspace_memberships')->insert(['workspace_id'=>$scope,'user_id'=>$actor->id,'user_role'=>'manager','can_manage_accounts'=>true,'active'=>true]);
  return $actor;
 }
 public function test_managers_cannot_list_or_update_each_others_cashiers(): void {
  config(['workspaces.enabled'=>true]);$a=$this->manager('A');$b=$this->manager('B');
  $body=['first_name'=>'Cashier','last_name'=>'A','email'=>'a@example.com','role'=>'cashier','workspace_id'=>$b->workspace_id,'created_by_user_id'=>$b->id];
  $this->actingAs($a)->postJson('/api/accounts',$body)->assertCreated();
  $cashier=User::where('email','a@example.com')->firstOrFail();
  $this->assertEquals($a->workspace_id,$cashier->workspace_id);$this->assertEquals($a->id,$cashier->created_by_user_id);
  $this->actingAs($b)->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
  $this->patchJson('/api/accounts/cashier/'.$cashier->id,['name'=>'Changed'])->assertNotFound();
  $this->postJson('/api/accounts/cashier/'.$cashier->id.'/resend-setup')->assertNotFound();
  $this->postJson('/api/accounts/cashier/'.$cashier->id.'/send-reset')->assertNotFound();
  $this->actingAs($a)->getJson('/api/accounts')->assertOk()->assertJsonCount(1,'accounts');
  $this->patchJson('/api/accounts/cashier/'.$cashier->id,['first_name'=>'Own','last_name'=>'cashier'])->assertOk();
 }
 public function test_unassigned_admin_does_not_get_organization_access_by_role(): void {
  config(['workspaces.enabled'=>true]);$a=$this->manager('A');$admin=User::factory()->create(['role'=>'admin']);
  $this->actingAs($admin)->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
  $this->patchJson('/api/accounts/manager/'.$a->id,['name'=>'Other manager'])->assertNotFound();
  $this->postJson('/api/accounts',['name'=>'New','email'=>'new@example.com','role'=>'cashier'])->assertForbidden();
 }
 public function test_revoked_membership_and_unassigned_legacy_records_are_hidden(): void {
  config(['workspaces.enabled'=>true]);$a=$this->manager('A');
  User::factory()->create(['role'=>'cashier','createdBy'=>$a->name]);
  $this->actingAs($a)->getJson('/api/accounts')->assertOk()->assertJsonCount(0,'accounts');
  DB::table('workspace_memberships')->where('user_id',$a->id)->update(['active'=>false]);
  $this->postJson('/api/accounts',['name'=>'New','email'=>'new@example.com','role'=>'cashier'])->assertForbidden();
 }
 public function test_new_manager_has_empty_workspace_and_cashier_inherits_membership():void {
  config(['workspaces.enabled'=>true]);\Illuminate\Support\Facades\Mail::fake();
  $admin=$this->manager('Organization');$admin->forceFill(['role'=>'admin'])->save();
  DB::table('workspace_memberships')->where('user_id',$admin->id)->update(['user_role'=>'admin']);
  $this->actingAs($admin)->postJson('/api/accounts',['first_name'=>'New','last_name'=>'Manager','email'=>'newmanager@example.com','role'=>'manager'])->assertCreated();
  $manager=User::where('email','newmanager@example.com')->firstOrFail();
  $this->assertNotEquals($admin->workspace_id,$manager->workspace_id);
  $this->assertDatabaseHas('workspace_memberships',['user_id'=>$manager->id,'user_role'=>'manager','workspace_id'=>$manager->workspace_id,'can_manage_accounts'=>true]);
  $this->assertDatabaseHas('workspace_memberships',['user_id'=>$admin->id,'user_role'=>'admin','workspace_id'=>$manager->workspace_id,'can_manage_accounts'=>true]);
  $this->assertSame(0,DB::table('products')->where('workspace_id',$manager->workspace_id)->count());
  $manager->forceFill(['must_change_password'=>false])->save();
  $this->actingAs($manager)->withSession(['credential_version'=>$manager->credential_version])->postJson('/api/accounts',['first_name'=>'New','last_name'=>'Cashier','email'=>'newcashier@example.com','role'=>'cashier'])->assertCreated();
  $cashier=User::where('email','newcashier@example.com')->firstOrFail();
  $this->assertSame($manager->workspace_id,$cashier->workspace_id);
  $this->assertDatabaseHas('workspace_memberships',['user_id'=>$cashier->id,'user_role'=>'cashier','workspace_id'=>$manager->workspace_id,'can_manage_accounts'=>false]);
 }
 public function test_workspace_selection_rejects_unassigned_admin_and_manager_switching():void {
  config(['workspaces.enabled'=>true]);$a=$this->manager('A');$b=$this->manager('B');
  $this->actingAs($a)->postJson('/api/workspaces/select',['workspace_id'=>$b->workspace_id])->assertForbidden();
  $admin=User::factory()->create(['role'=>'admin']);
  $this->actingAs($admin)->getJson('/api/workspaces')->assertOk()->assertJsonCount(0,'workspaces');
  $this->postJson('/api/workspaces/select',['workspace_id'=>$a->workspace_id])->assertNotFound();
  DB::table('workspace_memberships')->insert(['workspace_id'=>$a->workspace_id,'user_id'=>$admin->id,'user_role'=>'admin','active'=>true]);
  $this->postJson('/api/workspaces/select',['workspace_id'=>$a->workspace_id])->assertOk();
  $this->assertSame($a->workspace_id,$admin->fresh()->workspace_id);
 }
 public function test_legacy_assignment_command_previews_and_then_assigns_only_named_accounts():void {
  $this->artisan('migrate')->assertExitCode(0);
  $owner=User::factory()->create(['role'=>'manager','email'=>'owner@example.com']);
  $admin=User::factory()->create(['role'=>'admin','email'=>'admin@example.com']);
  $super=User::factory()->create(['role'=>'superadmin','email'=>'super@example.com']);
  $cashier=User::factory()->create(['role'=>'cashier','email'=>'cashier@example.com','createdBy'=>'Historic Creator']);
  DB::table('products')->insert(['id'=>345,'name'=>'Legacy','category'=>'Food','status'=>'Active','stock'=>5,'price'=>10]);
  $args=['owner'=>'owner@example.com','--admins'=>'admin@example.com','--superadmins'=>'super@example.com','--cashiers'=>'cashier@example.com'];
  $this->artisan('workspaces:assign-legacy',$args)->assertExitCode(0);
  $this->assertNull($owner->fresh()->workspace_id);$this->assertNull(DB::table('products')->where('id',345)->value('workspace_id'));
  $args['--apply']=true;$this->artisan('workspaces:assign-legacy',$args)->assertExitCode(1);
  foreach([$owner,$admin,$super,$cashier] as $account)$this->assertNull($account->fresh()->workspace_id);
  $this->assertSame('Historic Creator',$cashier->fresh()->createdBy);
  $this->assertNull(DB::table('products')->where('id',345)->value('workspace_id'));
 }
}

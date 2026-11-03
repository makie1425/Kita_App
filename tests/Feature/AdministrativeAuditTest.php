<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class AdministrativeAuditTest extends TestCase {
 use RefreshDatabase;
 public function test_audit_is_privileged_scoped_and_records_error_status_changes(): void {
  $this->getJson('/api/super-admin/audit')->assertUnauthorized();
  $this->actingAs(User::factory()->create(['role'=>'admin']))->getJson('/api/super-admin/audit')->assertForbidden();
  $super=User::factory()->create(['role'=>'superadmin']);
  $id=DB::table('monitoring_errors')->insertGetId(['occurred_at'=>now(),'severity'=>'Error','component'=>'Application','message'=>'Operation failed','status'=>'New']);
  $this->actingAs($super)->patchJson('/api/super-admin/errors/'.$id,['status'=>'Resolved'])->assertOk();
  $this->getJson('/api/super-admin/audit')->assertOk()->assertJsonCount(1,'events')->assertJsonPath('events.0.status',200);
  $this->getJson('/api/super-admin/audit?result=failure')->assertOk()->assertJsonCount(0,'events');
  $this->patchJson('/api/super-admin/errors/'.$id,['status'=>'invalid'])->assertUnprocessable();
  $this->getJson('/api/super-admin/audit?result=failure')->assertOk()->assertJsonCount(1,'events')->assertJsonPath('events.0.status',422);
  $this->getJson('/api/super-admin/audit?to=2099-01-01')->assertOk()->assertJsonCount(2,'events');
  $this->actingAs(User::factory()->create(['role'=>'superadmin']))->getJson('/api/super-admin/audit')->assertOk()->assertJsonCount(0,'events');
 }
}

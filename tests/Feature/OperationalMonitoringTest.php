<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\OperationalMonitoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class OperationalMonitoringTest extends TestCase {
 use RefreshDatabase;
 public function test_only_super_admin_can_monitor_and_change_error_status(): void {
  Log::error('secret=password customer@example.com', ['exception'=>new \RuntimeException('private password')]);
  $id=DB::table('monitoring_errors')->value('id');
  $this->getJson('/api/super-admin/dashboard')->assertUnauthorized();
  foreach(['admin','manager','cashier'] as $role){
   $this->actingAs(User::factory()->create(['role'=>$role]))->getJson('/api/super-admin/dashboard')->assertForbidden();
   $this->getJson('/api/super-admin/errors/'.$id)->assertForbidden();
   $this->patchJson('/api/super-admin/errors/'.$id,['status'=>'Resolved'])->assertForbidden();
  }
  $super=User::factory()->create(['role'=>'superadmin']);
  $this->actingAs($super)->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonCount(1,'errors')->assertDontSee('customer@example.com')->assertDontSee('password');
  $this->getJson('/api/super-admin/errors/'.$id)->assertOk()->assertJsonPath('error.severity','Error');
  $this->patchJson('/api/super-admin/errors/'.$id,['status'=>'Resolved'])->assertOk();
  $this->assertDatabaseHas('monitoring_errors',['id'=>$id,'status'=>'Resolved']);
  $this->patchJson('/api/super-admin/errors/'.$id,['status'=>'Invalid'])->assertUnprocessable();
  $this->getJson('/api/super-admin/errors/999999')->assertNotFound();
  $this->assertSame('Active',$super->fresh()->status);
 }
 public function test_rpm_counts_requests_and_preserves_missing_samples(): void {
  $this->travelTo(\Carbon\Carbon::parse('2026-11-03 12:00:20','UTC'));
  $this->getJson('/api/super-admin/dashboard')->assertUnauthorized();
  $this->get('/up')->assertOk();
  $this->travel(1)->minutes();
  $snapshot=app(OperationalMonitoring::class)->snapshot();
  $this->assertSame(2,$snapshot['rpm'][29]['requests']);
  $this->assertNull($snapshot['rpm'][28]['requests']);
  $this->assertArrayNotHasKey('total_users',$snapshot);
  $this->assertArrayNotHasKey('database_response_ms',$snapshot);
  $this->assertNull($snapshot['availability']);
 }
 public function test_external_probe_tracks_failures_recovery_and_unknown_gaps(): void {
  config(['monitoring.probe_url'=>'https://monitor-test.example/up']);
  $this->travelTo(\Carbon\Carbon::parse('2026-11-03 12:00:00','UTC'));
  Http::fakeSequence()->push('',200)->push('',503)->push('',200)->push('',200);
  $this->artisan('monitoring:probe')->assertSuccessful();
  $this->travel(1)->minutes();$this->artisan('monitoring:probe')->assertSuccessful();
  $this->travel(1)->minutes();$this->artisan('monitoring:probe')->assertSuccessful();
  $this->travel(10)->minutes();$this->artisan('monitoring:probe')->assertSuccessful();
  $availability=app(OperationalMonitoring::class)->snapshot()['availability'];
  $this->assertSame(60,$availability['uptime_seconds']);$this->assertSame(60,$availability['downtime_seconds']);
  $this->assertSame(86280,$availability['unknown_seconds']);$this->assertSame('Operational',$availability['status']);
  $this->travel(2)->minutes();$this->assertSame('Unavailable',app(OperationalMonitoring::class)->snapshot()['availability']['status']);
 }
 public function test_missing_monitoring_tables_do_not_break_business_or_invent_measurements(): void {
  Schema::drop('monitoring_requests');Schema::drop('monitoring_checks');Schema::drop('monitoring_errors');
  $this->get('/up')->assertOk();
  $this->actingAs(User::factory()->create(['role'=>'superadmin']))->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('rpm',null)->assertJsonPath('availability',null)->assertJsonPath('errors',null);
 }
 public function test_real_resources_are_measured_or_explicitly_unavailable(): void {
  $service=app(OperationalMonitoring::class);$storage=$service->storage();
  if($storage!==null){$this->assertGreaterThan(0,$storage['limit_bytes']);$this->assertGreaterThanOrEqual(0,$storage['used_bytes']);}
  $memory=$service->memory();$this->assertTrue($memory===null||($memory['used_bytes']>=0&&$memory['scope']==='Application container'));
 }
}

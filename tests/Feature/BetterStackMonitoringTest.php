<?php
namespace Tests\Feature;

use App\Services\BetterStackMonitoring;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BetterStackMonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['monitoring.better_stack_token'=>'test-token', 'monitoring.better_stack_monitor_id'=>'5173244', 'cache.stores.file'=>['driver'=>'array']]);
        Http::preventStrayRequests();
    }

    private function fake(string $status = 'up', int $age = 10, int $slaStatus = 200): void
    {
        Http::fake([
            '*/5173244'=>Http::response(['data'=>['id'=>'5173244','attributes'=>['status'=>$status,'last_checked_at'=>now()->subSeconds($age)->toIso8601String(),'check_frequency'=>180,'auth_password'=>'must-not-leak']]]),
            '*/5173244/sla'=>Http::response(['data'=>['id'=>'5173244','attributes'=>['availability'=>99.5,'total_downtime'=>120]]], $slaStatus),
        ]);
    }

    public function test_reads_provider_data_caches_and_does_not_expose_credentials(): void
    {
        $this->fake();
        $service = app(BetterStackMonitoring::class);
        $data = $service->snapshot();
        $this->assertSame('Operational', $data['status']);
        $this->assertSame(99.5, $data['uptime_percentage']);
        $this->assertSame(120, $data['downtime_seconds']);
        $this->assertStringNotContainsString('must-not-leak', json_encode($data));
        $this->assertStringNotContainsString('test-token', json_encode($data));
        $this->assertSame($data, $service->snapshot());
        Http::assertSentCount(2);
        Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer test-token'));
    }

    public function test_stale_checks_are_not_reported_as_up(): void
    {
        $this->fake('up', 1000);
        $this->assertSame('Unavailable', app(BetterStackMonitoring::class)->snapshot()['status']);
    }

    public function test_sla_failure_preserves_current_down_status_without_inventing_totals(): void
    {
        $this->fake('down', 10, 403);
        $data = app(BetterStackMonitoring::class)->snapshot();
        $this->assertSame('Down', $data['status']);
        $this->assertNull($data['uptime_percentage']);
        $this->assertNull($data['downtime_seconds']);
    }

    public function test_authentication_failure_is_unavailable_and_cached(): void
    {
        Http::fake(['*'=>Http::response([],401)]);
        $this->assertNull(app(BetterStackMonitoring::class)->snapshot());
        $this->assertNull(app(BetterStackMonitoring::class)->snapshot());
        Http::assertSentCount(1);
    }

    public function test_incomplete_configuration_does_not_send_credentials(): void
    {
        config(['monitoring.better_stack_monitor_id'=>'']);
        $this->assertNull(app(BetterStackMonitoring::class)->snapshot());
        Http::assertNothingSent();
    }
}

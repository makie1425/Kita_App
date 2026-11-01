<?php

namespace Tests\Feature;

use App\Services\ReceivingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_increment_independently_and_restart_on_the_next_issue_date(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        foreach (['BATCH', 'SR', 'RCV'] as $kind) {
            $this->assertSame($kind.'-10-26-26-00001', ReceivingNumber::next($kind));
            $this->assertSame($kind.'-10-26-26-00002', ReceivingNumber::next($kind));
        }
        $this->travel(1)->days();
        $this->assertSame('BATCH-10-27-26-00001', ReceivingNumber::next('BATCH'));
        $this->travelBack();
    }

    public function test_allocation_rolls_back_with_a_failed_receiving_transaction(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        DB::beginTransaction();
        $first = ReceivingNumber::next('RCV');
        DB::rollBack();
        $this->assertSame($first, ReceivingNumber::next('RCV'));
        $this->travelBack();
    }
}

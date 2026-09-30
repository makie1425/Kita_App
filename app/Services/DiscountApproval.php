<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscountApproval
{
    public static function verify(Request $request, int $discountCents): ?array
    {
        if ($discountCents <= 0) {
            return null;
        }
        abort_unless($request->user(), 401, 'Sign in to request discount approval.');
        $data = $request->validate([
            'discount_manager_id' => ['required', 'integer'],
            'discount_manager_pin' => ['required', 'digits:4'],
        ]);
        $manager = ManagerApproval::verify($request, $data['discount_manager_id'], $data['discount_manager_pin'], 'discount_manager_pin');

        return ['manager_id' => $manager->id, 'requested_by' => $request->user()->id,
            'requested_by_role' => $request->user()->role, 'amount' => $discountCents / 100, 'reason' => match ($request->input('discountType', 'none')) {
                'employee' => 'Employee discount',
                'senior' => 'Senior/PWD discount',
                default => 'Promotion / price override',
            }];
    }

    public static function record(string $uuid, ?array $approval): void
    {
        if ($approval) {
            DB::table('discount_approvals')->insert($approval + ['transaction_uuid' => $uuid, 'created_at' => now(), 'updated_at' => now()]);
            $actor = WorkflowNotifications::owner($approval['requested_by'], $approval['requested_by_role']);
            if ($actor) {
                WorkflowNotifications::send([$actor, WorkflowNotifications::owner($approval['manager_id'], 'manager')], $actor,
                    'Discount approved', 'Manager-approved discount recorded for transaction '.$uuid.'.', 'activity', $uuid);
            }
        }
    }
}

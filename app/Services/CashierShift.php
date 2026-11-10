<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShift
{
    public static function active(Request $request, bool $lock = false): ?object
    {
        $query = WorkspaceRead::table('cashier_shifts')->where('cashier_id', $request->user()->id)
            ->where('cashier_role', $request->user()->role)->whereNull('closed_at')->orderByDesc('id');
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public static function requireActive(Request $request): object
    {
        $shift = self::active($request, true);
        if (! $shift) throw ValidationException::withMessages(['shift' => 'Open your cash drawer shift before checkout.']);
        return $shift;
    }

    public static function record(object $shift, string $type, int $cents, string $reference, ?string $reason = null, ?int $approvedBy = null): void
    {
        DB::table('cashier_shift_entries')->insert(WorkspaceWrite::owned([
            'shift_id' => $shift->id, 'type' => $type, 'amount' => $cents / 100,
            'reference' => $reference, 'reason' => $reason, 'approved_by' => $approvedBy, 'created_at' => now(),
        ]));
    }

    public static function balance(object $shift): int
    {
        $entries = WorkspaceRead::table('cashier_shift_entries')->where('shift_id', $shift->id)->get();
        return SaleCheckout::cents($shift->opening_cash) + $entries->sum(fn ($entry) =>
            in_array($entry->type, ['sale', 'added', 'exchange_difference'], true)
                ? SaleCheckout::cents($entry->amount) : -SaleCheckout::cents($entry->amount));
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SupplierAccounts
{
    public static function ready(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('supplier_settlements');
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Purchasing database update is pending. Ask the administrator to apply the deployment migrations.');
    }

    public static function receipts()
    {
        self::requireReady();
        $values = DB::table('receiving_record_lines')->groupBy('receivingId')
            ->select('receivingId')->selectRaw('SUM(ROUND(deliveredQty * unitCost, 2)) as receivedValue, SUM(CASE WHEN unitCost IS NULL AND deliveredQty > 0 THEN 1 ELSE 0 END) as unknownCosts');
        $settled = DB::table('supplier_settlements')->whereNull('voidedAt')->groupBy('receiptId')->select('receiptId')
            ->selectRaw("SUM(CASE WHEN kind = 'Payment' THEN amount ELSE 0 END) as payments, SUM(CASE WHEN kind = 'Credit note' THEN amount ELSE 0 END) as credits");
        return DB::table('receiving_records as r')->join('purchase_orders as po', 'po.id', '=', 'r.poId')
            ->leftJoinSub($values, 'v', 'v.receivingId', '=', 'r.id')->leftJoinSub($settled, 's', 's.receiptId', '=', 'r.id')
            ->select('r.*', 'po.supplierId', 'po.paidValue as legacyPaidValue')
            ->selectRaw('COALESCE(v.receivedValue, 0) as receivedValue, COALESCE(v.unknownCosts, 0) as unknownCosts, COALESCE(s.payments, 0) as payments, COALESCE(s.credits, 0) as credits');
    }

    public static function describe($row)
    {
        $row->balance = round(max(0, (float) $row->receivedValue - (float) $row->payments - (float) $row->credits), 2);
        $row->accountRemark = $row->unknownCosts || $row->legacyPaidValue > 0 ? 'Review legacy costs / payments' : ($row->balance <= 0 ? 'Settled' : ($row->paymentDueDate && $row->paymentDueDate < now()->toDateString() ? 'Overdue' : 'Unpaid'));
        $row->remarks = ReceivingRemarks::forReceipt($row);
        if ($row->accountRemark === 'Review legacy costs / payments') $row->balance = null;
        if ($row->unknownCosts) $row->receivedValue = null;
        unset($row->payloadHash, $row->idempotencyKey);
        return $row;
    }
}

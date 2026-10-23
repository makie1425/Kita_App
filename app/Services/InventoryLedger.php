<?php

namespace App\Services;

class InventoryLedger
{
    public const TYPES = [
        'purchase_receiving'=>'Purchase receipt', 'checkout'=>'Sale',
        'payment_reservation'=>'Payment reservation', 'payment_release'=>'Reservation release',
        'refund'=>'Customer return', 'void'=>'Sale void / stock restored',
        'exchange'=>'Exchange return', 'exchange_replacement'=>'Exchange issue',
        'adjustment'=>'Stock adjustment', 'reconciliation'=>'Stock count adjustment',
        'write-off'=>'Write-off', 'disposal'=>'Disposal', 'transfer'=>'Transfer out', 'supplier return'=>'Return to supplier',
        'recall'=>'Batch recall', 'recall_release'=>'Recall released',
    ];

    public static function describe($rows)
    {
        foreach ($rows as $row) {
            $row->transactionType = self::TYPES[$row->referenceType] ?? ucwords(str_replace('_', ' ', $row->referenceType));
            $row->quantityIn = max(0, (int) $row->quantityChange);
            $row->quantityOut = max(0, -(int) $row->quantityChange);
            $row->balanceBasis = 'Unreserved book quantity';
            $row->remarks = match ($row->referenceType) {
                'payment_reservation'=>'Reserved for pending payment; physical stock has not left.',
                'payment_release'=>'Reservation cancelled or expired; quantity is available again.',
                'recall', 'recall_release'=>'Availability changed; physical quantity is unchanged.',
                default => '',
            };
        }
        return $rows;
    }
}

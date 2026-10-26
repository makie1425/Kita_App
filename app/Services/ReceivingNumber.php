<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReceivingNumber
{
    /** Allocate on the server's issue date, independently of a backdated delivery. */
    public static function next(string $kind): string
    {
        $targets = match ($kind) {
            'BATCH' => [['inventory_batches', 'batchNumber'], ['physical_inventory_lots', 'id'], ['receiving_deliveries', 'batchNumber']],
            'SR' => [['stock_receipts', 'id']],
            'RCV' => [['receiving_records', 'id']],
            default => throw new \InvalidArgumentException('Unknown receiving number type.'),
        };
        $prefix = $kind.'-'.now()->format('m-d-y').'-';
        return DB::transaction(function () use ($prefix, $targets) {
            $name = 'receiving:'.$prefix;
            DB::table('inventory_sequences')->insertOrIgnore(['name' => $name, 'lastValue' => 0]);
            $sequence = DB::table('inventory_sequences')->where('name', $name)->lockForUpdate()->first();
            $number = (int) $sequence->lastValue;
            do {
                abort_if($number >= PHP_INT_MAX - 1, 409, 'Receiving number limit reached.');
                $id = $prefix.str_pad((string) ++$number, 5, '0', STR_PAD_LEFT);
                $exists = false;
                foreach ($targets as [$table, $column]) {
                    if (DB::table($table)->where($column, $id)->exists()) {
                        $exists = true;
                        break;
                    }
                }
            } while ($exists);
            DB::table('inventory_sequences')->where('name', $name)->update(['lastValue' => $number]);
            return $id;
        }, 3);
    }
}

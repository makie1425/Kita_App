<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TransactionNumber
{
    public static function assign(string $uuid, ?string $date): string
    {
        DB::table('transaction_numbers')->insertOrIgnore(['transaction_uuid' => $uuid, 'issued_date' => $date]);
        $record = DB::table('transaction_numbers')->where('transaction_uuid', $uuid)->first();

        return self::format($record->id, $record->issued_date);
    }

    public static function format(int $id, ?string $date): string
    {
        return 'TXN-'.($date ? str_replace('-', '', substr($date, 0, 10)) : 'UNDATED').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}

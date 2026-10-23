<?php

namespace App\Services;

class ReceivingRemarks
{
    public static function forReceipt(object $receipt): string
    {
        $inspection = $receipt->inspection ?? null;
        $items = is_string($inspection) ? json_decode($inspection, true) : $inspection;
        $remarks = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $details = [];
            if (($item['missing'] ?? 0) > 0) $details[] = $item['missing'].' missing';
            if (($item['damaged'] ?? 0) > 0) $details[] = $item['damaged'].' damaged — returned, not received';
            if (($item['excess'] ?? 0) > 0) $details[] = $item['excess'].' extra accepted';
            if ($details) $remarks[] = ($item['name'] ?? 'Item').': '.implode(', ', $details);
        }
        if (! $items && ! empty($receipt->discrepancyType)) $remarks[] = $receipt->discrepancyType;
        if (! empty($receipt->outcome)) $remarks[] = $receipt->outcome;

        return implode('; ', $remarks) ?: 'Received as ordered';
    }
}

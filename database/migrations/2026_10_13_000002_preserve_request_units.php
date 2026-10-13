<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_request_lines', function (Blueprint $table) {
            $table->string('orderUnit', 40)->nullable();
            $table->unsignedInteger('piecesPerBox')->nullable();
        });
        // Earlier box requests retained the entered packaging in their audit record.
        DB::table('audit_logs')->where('action', 'Submitted purchase request')->orderBy('id')->chunkById(100, function ($logs) {
            foreach ($logs as $log) {
                $data = json_decode($log->afterValue ?? '', true);
                $groups = collect($data['requestedLines'] ?? [])->groupBy('productId');
                foreach ($groups as $productId => $lines) {
                    $first = $lines->first();
                    $unit = $first['orderUnit'] ?? null;
                    $factor = $unit === 'Box' ? (int) ($first['piecesPerBox'] ?? 1) : 1;
                    if (!$unit || $factor < 1 || $lines->contains(fn ($line) => ($line['orderUnit'] ?? null) !== $unit || ($unit === 'Box' && (int) ($line['piecesPerBox'] ?? 1) !== $factor))) {
                        continue;
                    }
                    DB::table('item_request_lines')->where('requestId', $log->record)->where('productId', $productId)
                        ->where('qty', $lines->sum('qty') * $factor)->whereNull('orderUnit')
                        ->update(['orderUnit' => $unit, 'piecesPerBox' => $unit === 'Box' && isset($first['piecesPerBox']) ? $factor : null]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_request_lines', fn (Blueprint $table) => $table->dropColumn(['orderUnit', 'piecesPerBox']));
    }
};

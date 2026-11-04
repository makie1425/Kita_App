<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->unsignedInteger('manualLookupQty')->nullable();
            $table->string('entryMethod', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', fn (Blueprint $table) => $table->dropColumn(['manualLookupQty', 'entryMethod']));
    }
};

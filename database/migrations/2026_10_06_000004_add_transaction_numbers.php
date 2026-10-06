<?php

use App\Services\TransactionNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_uuid', 30)->unique();
            $table->date('issued_date')->nullable();
        });
        DB::table('transactions')->orderBy('date')->orderBy('uuid')->chunk(200, function ($transactions) {
            foreach ($transactions as $transaction) {
                TransactionNumber::assign($transaction->uuid, $transaction->date);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_numbers');
    }
};

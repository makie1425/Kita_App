<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->unsignedBigInteger('cashier_id'); $t->string('cashier_role',20);
            $t->string('cashier_name'); $t->decimal('opening_cash',12,2);
            $t->timestamp('opened_at'); $t->timestamp('closed_at')->nullable();
            $t->decimal('expected_cash',12,2)->nullable(); $t->decimal('counted_cash',12,2)->nullable();
            $t->string('difference_reason',255)->nullable();
            $t->index(['workspace_id','cashier_id','cashier_role','closed_at']);
        });
        Schema::create('cashier_shift_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->unsignedBigInteger('shift_id'); $t->string('type',30);
            $t->decimal('amount',12,2); $t->string('reference',100)->nullable();
            $t->string('reason',255)->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('created_at');
            $t->index(['shift_id','created_at']); $t->unique(['shift_id','type','reference']);
        });
    }
    public function down(): void { Schema::dropIfExists('cashier_shift_entries'); Schema::dropIfExists('cashier_shifts'); }
};

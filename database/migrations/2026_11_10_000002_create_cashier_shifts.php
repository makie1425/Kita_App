<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // MySQL commits table creation before later index statements. A failed
        // index must be recoverable without dropping an existing cash ledger.
        if (! Schema::hasTable('cashier_shifts')) Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('cashier_id'); $t->string('cashier_role',20);
            $t->string('cashier_name'); $t->decimal('opening_cash',12,2);
            $t->timestamp('opened_at'); $t->timestamp('closed_at')->nullable();
            $t->decimal('expected_cash',12,2)->nullable(); $t->decimal('counted_cash',12,2)->nullable();
            $t->string('difference_reason',255)->nullable();
        });
        if (! Schema::hasTable('cashier_shift_entries')) Schema::create('cashier_shift_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('shift_id'); $t->string('type',30);
            $t->decimal('amount',12,2); $t->string('reference',100)->nullable();
            $t->string('reason',255)->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('created_at');
        });
        foreach ([
            ['cashier_shifts', 'cashier_shifts_workspace_id_index', ['workspace_id'], false],
            ['cashier_shifts', 'cashier_shifts_actor_open_idx', ['workspace_id', 'cashier_id', 'cashier_role', 'closed_at'], false],
            ['cashier_shift_entries', 'cashier_shift_entries_workspace_id_index', ['workspace_id'], false],
            ['cashier_shift_entries', 'cashier_shift_entries_shift_id_created_at_index', ['shift_id', 'created_at'], false],
            ['cashier_shift_entries', 'cashier_shift_entries_shift_id_type_reference_unique', ['shift_id', 'type', 'reference'], true],
        ] as [$table, $name, $columns, $unique]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $t) use ($name, $columns, $unique) {
                    $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
                });
            }
        }
    }
    public function down(): void { Schema::dropIfExists('cashier_shift_entries'); Schema::dropIfExists('cashier_shifts'); }
};

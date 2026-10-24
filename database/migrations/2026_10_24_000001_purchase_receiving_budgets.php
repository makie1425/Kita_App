<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_budget_requests', function (Blueprint $t) {
            $t->id();
            $t->string('poId')->index();
            $t->uuid('requestKey')->unique();
            $t->unsignedInteger('version');
            $t->decimal('amount', 12, 2);
            $t->text('reason');
            $t->string('status')->default('Pending');
            $t->unsignedBigInteger('requestedBy');
            $t->unsignedBigInteger('reviewedBy')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('purchase_budget_requests'); }
};

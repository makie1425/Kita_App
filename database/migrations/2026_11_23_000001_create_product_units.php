<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('name', 40);
            $table->timestamps();
            $table->index(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};

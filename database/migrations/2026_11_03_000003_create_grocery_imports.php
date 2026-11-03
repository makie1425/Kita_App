<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('grocery_imports', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->primary();
            $table->unsignedBigInteger('manager_id');
            $table->text('products');
            $table->unsignedInteger('completed_steps')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grocery_imports');
    }
};

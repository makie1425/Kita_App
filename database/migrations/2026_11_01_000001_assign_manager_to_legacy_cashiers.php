<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_manager_id')->nullable()->index();
            $table->string('assigned_manager_role', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['assigned_manager_id']);
            $table->dropColumn(['assigned_manager_id', 'assigned_manager_role']);
        });
    }
};

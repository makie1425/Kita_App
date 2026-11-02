<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_expiry_settings', function (Blueprint $table) {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->restrictOnDelete();
            $table->unsignedSmallInteger('days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_expiry_settings');
    }
};

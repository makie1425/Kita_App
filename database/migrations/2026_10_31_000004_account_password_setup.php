<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('first_name', 60)->nullable();
            $t->string('last_name', 60)->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('temporary_password_expires_at')->nullable();
            $t->unsignedInteger('credential_version')->default(0);
        });
        Schema::create('password_reset_challenges', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('code_hash');
            $t->timestamp('expires_at');
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('credential_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_challenges');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['first_name', 'last_name', 'must_change_password', 'temporary_password_expires_at', 'credential_version']));
    }
};

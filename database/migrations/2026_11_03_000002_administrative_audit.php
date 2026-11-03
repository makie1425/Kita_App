<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('administrative_audit',function(Blueprint $t){$t->id();$t->unsignedBigInteger('actor_id')->index();$t->string('actor_role',30);$t->string('action',180);$t->unsignedSmallInteger('status');$t->timestamp('occurred_at')->index();}); }
 public function down(): void { Schema::dropIfExists('administrative_audit'); }
};

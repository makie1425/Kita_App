<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('monitoring_requests', function(Blueprint $t){$t->timestamp('minute')->primary();$t->unsignedBigInteger('requests')->default(0);});
  Schema::create('monitoring_checks', function(Blueprint $t){$t->id();$t->timestamp('observed_at')->index();$t->boolean('available');});
  Schema::create('monitoring_errors', function(Blueprint $t){$t->id();$t->timestamp('occurred_at')->index();$t->string('severity',20);$t->string('component',180);$t->text('message');$t->string('status',20)->default('New');$t->timestamp('updated_at')->nullable();});
 }
 public function down(): void {Schema::dropIfExists('monitoring_errors');Schema::dropIfExists('monitoring_checks');Schema::dropIfExists('monitoring_requests');}
};

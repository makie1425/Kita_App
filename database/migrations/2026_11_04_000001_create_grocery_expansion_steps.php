<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('grocery_expansion_steps',function(Blueprint $t){$t->unsignedBigInteger('workspace_id');$t->string('step',80);$t->text('result')->nullable();$t->timestamp('created_at');$t->primary(['workspace_id','step']);$t->foreign('workspace_id')->references('id')->on('workspaces');}); }
 public function down(): void { Schema::dropIfExists('grocery_expansion_steps'); }
};

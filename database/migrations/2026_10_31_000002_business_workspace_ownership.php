<?php
use App\Services\WorkspaceTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  foreach(WorkspaceTables::BUSINESS as $name)Schema::table($name,function(Blueprint $table){
   $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
  });
 }
 public function down(): void {
  foreach(array_reverse(WorkspaceTables::BUSINESS) as $name)Schema::table($name,fn(Blueprint $table)=>$table->dropConstrainedForeignId('workspace_id'));
 }
};

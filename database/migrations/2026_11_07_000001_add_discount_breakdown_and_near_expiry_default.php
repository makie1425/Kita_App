<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  foreach (['transactions','transaction_lines'] as $name) Schema::table($name,function(Blueprint $table){
   foreach(['promotionDiscount','customerDiscount','overrideDiscount'] as $column) $table->decimal($column,12,2)->nullable();
  });
  DB::table('inventory_settings')->where('name','expiry_days')->where('value','7')->update(['value'=>'30']);
  DB::table('workspace_expiry_settings')->where('days',7)->update(['days'=>30]);
 }
 public function down(): void {
  foreach (['transactions','transaction_lines'] as $name) Schema::table($name,fn(Blueprint $table)=>$table->dropColumn(['promotionDiscount','customerDiscount','overrideDiscount']));
 }
};

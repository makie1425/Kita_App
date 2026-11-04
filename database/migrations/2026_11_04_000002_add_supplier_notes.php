<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('suppliers',fn(Blueprint $t)=>$t->text('notes')->nullable());}
 public function down():void {Schema::table('suppliers',fn(Blueprint $t)=>$t->dropColumn('notes'));}
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('organizations',function(Blueprint $t){$t->id();$t->string('name');$t->timestamps();});
  Schema::create('workspaces',function(Blueprint $t){$t->id();$t->foreignId('organization_id')->constrained()->restrictOnDelete();$t->string('name');$t->timestamps();});
  Schema::create('workspace_memberships',function(Blueprint $t){
   $t->id();$t->foreignId('workspace_id')->constrained()->restrictOnDelete();
   $t->unsignedBigInteger('user_id');$t->string('user_role',30);$t->boolean('can_manage_accounts')->default(false);$t->boolean('active')->default(true);$t->timestamps();
   $t->unique(['workspace_id','user_id','user_role'],'workspace_member_identity');
  });
  Schema::table('users',function(Blueprint $t){
   $t->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
   $t->unsignedBigInteger('created_by_user_id')->nullable()->index();$t->string('created_by_user_role',30)->nullable();
  });
 }
 public function down(): void {
  Schema::table('users',function(Blueprint $t){$t->dropConstrainedForeignId('workspace_id');$t->dropIndex(['created_by_user_id']);$t->dropColumn(['created_by_user_id','created_by_user_role']);});
  Schema::dropIfExists('workspace_memberships');Schema::dropIfExists('workspaces');Schema::dropIfExists('organizations');
 }
};

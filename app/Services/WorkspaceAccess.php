<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Explicit assignments only. An administrator role is not an ownership grant. */
class WorkspaceAccess
{
 public static function accountWorkspaceIds(User $actor): array
 {
  if(!$actor->isActive())return [];
  if($actor->normalizedRole()==='manager' && !WorkspaceRead::forUser($actor))return [];
  return DB::table('workspace_memberships')->where('user_id',$actor->id)->where('user_role',$actor->normalizedRole())
   ->where('active',true)->where('can_manage_accounts',true)->pluck('workspace_id')->map(fn($id)=>(int)$id)->all();
 }
 public static function accounts(User $actor): Builder
 {
  $role=$actor->normalizedRole();
  $query=User::query();
  if(config('workspaces.enabled'))$query->whereIn('workspace_id',self::accountWorkspaceIds($actor));
  else if($role!=='manager')$query->where('created_by_user_id',$actor->id)->where('created_by_user_role',$role);
  if($role==='super_admin')$query->whereRaw('LOWER(role) = ?', ['admin']);
  if($role==='admin')$query->whereIn('role',['manager','Manager','cashier','Cashier']);
  if($role==='manager')$query->where('role','cashier')->where(fn($q)=>$q->where(fn($creator)=>$creator->where('created_by_user_id',$actor->id)->where('created_by_user_role','manager'))->orWhere(fn($assigned)=>$assigned->where('assigned_manager_id',$actor->id)->where('assigned_manager_role','manager')));
  return $query;
 }
 public static function requireAccountWorkspace(User $actor): int
 {
  $id=(int)$actor->workspace_id;
  abort_unless($id && in_array($id,self::accountWorkspaceIds($actor),true),403,'No account-management workspace is assigned to your account.');
  return $id;
 }
}

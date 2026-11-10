<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read boundaries for the explicitly migrated endpoints. Never accepts a client scope ID. */
class WorkspaceRead
{
 public static function id(): ?int
 {
  $request=request();
  if (!$request->attributes->get('workspace_scope_snapshot', false)) return self::forUser(auth()->user());
  $actor=auth()->user();
  $key=implode(':',[$actor?->id,$actor?->role,$actor?->workspace_id,$actor?->status]);
  $scopes=$request->attributes->get('workspace_verified_scopes',[]);
  if (!array_key_exists($key,$scopes)) {
   $scopes[$key]=self::forUser($actor);
   $request->attributes->set('workspace_verified_scopes',$scopes);
  }
  return $scopes[$key];
 }
 public static function forUser(?User $actor): ?int
 {
  if(!$actor || !$actor->isActive() || !$actor->workspace_id)return null;
  $workspaceId=(int)$actor->workspace_id;
  if(!DB::table('workspace_memberships')->where('workspace_id',$workspaceId)
   ->where('user_id',$actor->id)->where('user_role',$actor->normalizedRole())->where('active',true)->exists())return null;
  if(in_array($actor->normalizedRole(),['manager','cashier'],true)){
   $managerId=self::managerId($workspaceId);
   if(!$managerId)return null;
   if($actor->normalizedRole()==='manager' && $managerId!==(int)$actor->id)return null;
   if($actor->normalizedRole()==='cashier'){
    $assigned=$actor->assigned_manager_role==='manager' ? $actor->assigned_manager_id : null;
    if(!$assigned && $actor->created_by_user_role==='manager')$assigned=$actor->created_by_user_id;
    if((int)$assigned!==$managerId)return null;
   }
  }
  return $workspaceId;
 }
 public static function managerId(int $workspaceId): ?int
 {
  $ids=DB::table('users as owner')->join('workspace_memberships as membership',function($join){
   $join->on('membership.user_id','=','owner.id')->where('membership.user_role','manager');
  })->where('owner.workspace_id',$workspaceId)->where('membership.workspace_id',$workspaceId)
   ->whereRaw('LOWER(owner.role) = ?', ['manager'])->whereRaw("LOWER(COALESCE(owner.status, 'Active')) = 'active'")
   ->where('membership.active',true)->distinct()->pluck('owner.id');
  return $ids->count()===1?(int)$ids->first():null;
 }
 public static function table(string $table): Builder
 {
  $query=DB::table($table);
  if(!config('workspaces.enabled'))return $query;
  $parts=preg_split('/\s+as\s+/i',$table);$base=$parts[0];$alias=$parts[1]??$base;
  if(!in_array($base,WorkspaceTables::BUSINESS,true)&&!in_array($base,['users','cashier_shifts','cashier_shift_entries'],true))throw new \LogicException('Unclassified workspace table: '.$base);
  if($base!=='users' && auth()->user()?->normalizedRole()==='super_admin')return $query->whereRaw('1 = 0');
  $id=self::id();
  if(!$id)return $query->whereRaw('1 = 0');
  $query->where($alias.'.workspace_id',$id);
  if($base==='users'){
   $actor=auth()->user();
   if($actor->normalizedRole()==='cashier')$query->where($alias.'.id',$actor->id)->where($alias.'.role',$actor->role);
   elseif($actor->normalizedRole()==='manager')$query->where(fn($q)=>$q->where(fn($own)=>$own->where($alias.'.id',$actor->id)->where($alias.'.role',$actor->role))
    ->orWhere(fn($cashier)=>$cashier->where($alias.'.role','cashier')->where(fn($owner)=>$owner
     ->where(fn($creator)=>$creator->where($alias.'.created_by_user_id',$actor->id)->where($alias.'.created_by_user_role','manager'))
     ->orWhere(fn($assigned)=>$assigned->where($alias.'.assigned_manager_id',$actor->id)->where($alias.'.assigned_manager_role','manager')))));
   elseif($actor->normalizedRole()==='super_admin')$query->where($alias.'.role','admin');
  }
  if($base==='transactions'&&auth()->user()->normalizedRole()==='cashier')$query->where($alias.'.cashierId',auth()->user()->id)->where($alias.'.cashierRole',auth()->user()->role);
  return $query;
 }
}

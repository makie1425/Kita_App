<?php
namespace App\Services;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read boundaries for the explicitly migrated endpoints. Never accepts a client scope ID. */
class WorkspaceRead
{
 public static function id(): ?int
 {
  $actor=auth()->user();
  if(!$actor || !$actor->isActive() || !$actor->workspace_id)return null;
  return DB::table('workspace_memberships')->where('workspace_id',$actor->workspace_id)
   ->where('user_id',$actor->id)->where('user_role',$actor->normalizedRole())->where('active',true)->exists()?(int)$actor->workspace_id:null;
 }
 public static function table(string $table): Builder
 {
  $query=DB::table($table);
  if(!config('workspaces.enabled'))return $query;
  $parts=preg_split('/\s+as\s+/i',$table);$base=$parts[0];$alias=$parts[1]??$base;
  if(!in_array($base,WorkspaceTables::BUSINESS,true)&&$base!=='users')throw new \LogicException('Unclassified workspace table: '.$base);
  $id=self::id();
  if(!$id)return $query->whereRaw('1 = 0');
  $query->where($alias.'.workspace_id',$id);
  if($base==='users'){
   $actor=auth()->user();
   if($actor->normalizedRole()==='cashier')$query->where($alias.'.id',$actor->id)->where($alias.'.role',$actor->role);
   elseif($actor->normalizedRole()==='manager')$query->where(fn($q)=>$q->where(fn($own)=>$own->where($alias.'.id',$actor->id)->where($alias.'.role',$actor->role))
    ->orWhere(fn($cashier)=>$cashier->where($alias.'.created_by_user_id',$actor->id)->where($alias.'.created_by_user_role','manager')->where($alias.'.role','cashier')));
  }
  if($base==='transactions'&&auth()->user()->normalizedRole()==='cashier')$query->where($alias.'.cashierId',auth()->user()->id)->where($alias.'.cashierRole',auth()->user()->role);
  return $query;
 }
}

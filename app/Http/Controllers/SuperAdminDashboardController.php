<?php
namespace App\Http\Controllers;
use App\Services\OperationalMonitoring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SuperAdminDashboardController extends Controller {
 public function audit(Request $request) {
  abort_unless($request->user()?->normalizedRole()==='super_admin',403);
  $data=$request->validate(['from'=>'nullable|date','to'=>'nullable|date'.($request->filled('from')?'|after_or_equal:from':''),'action'=>'nullable|string|max:100','result'=>'nullable|in:success,failure']);
  $query=DB::table('administrative_audit')->where('actor_id',$request->user()->id)->where('actor_role','super_admin');
  if(!empty($data['from']))$query->where('occurred_at','>=',\Carbon\Carbon::parse($data['from'],'Asia/Manila')->startOfDay()->utc());
  if(!empty($data['to']))$query->where('occurred_at','<',\Carbon\Carbon::parse($data['to'],'Asia/Manila')->startOfDay()->addDay()->utc());
  if(!empty($data['action']))$query->where('action','like','%'.$data['action'].'%');
  if(!empty($data['result']))$query->where('status',$data['result']==='success'?'<':'>=',400);
  return response()->json(['events'=>$query->orderByDesc('id')->limit(100)->get(),'actor'=>$request->user()->name])->header('Cache-Control','no-store');
 }
 public function __invoke(Request $request, OperationalMonitoring $monitoring) {
  abort_unless($request->user()?->normalizedRole()==='super_admin',403);
  return response()->json($monitoring->snapshot())->header('Cache-Control','no-store');
 }
 public function detail(Request $request, int $id) {
  abort_unless($request->user()?->normalizedRole()==='super_admin',403);
  $error=DB::table('monitoring_errors')->where('id',$id)->first();
  abort_unless($error,404);
  return response()->json(['error'=>$error])->header('Cache-Control','no-store');
 }
 public function update(Request $request, int $id) {
  abort_unless($request->user()?->normalizedRole()==='super_admin',403);
  $data=$request->validate(['status'=>'required|in:New,Acknowledged,Resolved']);
  abort_unless(DB::table('monitoring_errors')->where('id',$id)->exists(),404);
  DB::table('monitoring_errors')->where('id',$id)->update($data+['updated_at'=>now()->utc()]);
  return response()->json(['message'=>'Error status updated.']);
 }
}

<?php
namespace App\Http\Controllers;
use App\Services\OperationalMonitoring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SuperAdminDashboardController extends Controller {
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

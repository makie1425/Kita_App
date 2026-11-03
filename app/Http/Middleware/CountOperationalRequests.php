<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
class CountOperationalRequests {
 public function handle(Request $request, Closure $next){
  try{
   $minute=now()->utc()->startOfMinute()->format('Y-m-d H:i:s');
   DB::table('monitoring_requests')->insertOrIgnore(['minute'=>$minute,'requests'=>0]);
   DB::table('monitoring_requests')->where('minute',$minute)->increment('requests');
  }catch(Throwable){}
  return $next($request);
 }
}

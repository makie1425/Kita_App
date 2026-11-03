<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\DB;
class AdministrativeAudit {
 public function handle($request, Closure $next) {
  $response=$next($request);
  $user=$request->user();
  if($user?->normalizedRole()==='super_admin' && (!$request->isMethod('GET') || $request->is('api/backups/*/download'))) {
   // Route templates omit emails, identifiers, passwords and request payloads.
   DB::table('administrative_audit')->insert(['actor_id'=>$user->id,'actor_role'=>'super_admin','action'=>$request->method().' '.($request->route()?->uri()??'unknown'),'status'=>$response->getStatusCode(),'occurred_at'=>now()->utc()]);
  }
  return $response;
 }
}

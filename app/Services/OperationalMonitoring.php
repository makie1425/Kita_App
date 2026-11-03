<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class OperationalMonitoring
{
 public function memory(): ?array {
  foreach ([['/sys/fs/cgroup/memory.current','/sys/fs/cgroup/memory.max'],['/sys/fs/cgroup/memory/memory.usage_in_bytes','/sys/fs/cgroup/memory/memory.limit_in_bytes']] as [$used,$limit]) {
   if(is_readable($used)&&is_readable($limit)){
    $u=trim(file_get_contents($used));$l=trim(file_get_contents($limit));
    if(ctype_digit($u))return ['used_bytes'=>(int)$u,'limit_bytes'=>ctype_digit($l)&&(float)$l<PHP_INT_MAX?(int)$l:null,'scope'=>'Application container'];
   }
  }
  return null;
 }
 public function storage(): ?array {
  $total=@disk_total_space(storage_path());$free=@disk_free_space(storage_path());
  return $total===false||$free===false?null:['used_bytes'=>$total-$free,'limit_bytes'=>$total,'scope'=>'Filesystem containing application storage; excludes cloud database'];
 }
 public function snapshot(): array {
  $now=now()->utc();$rpm=null;$availability=null;$errors=null;
  try {
   $buckets=DB::table('monitoring_requests')->where('minute','>=',$now->copy()->subMinutes(30)->startOfMinute())->pluck('requests','minute');
   $rpm=[];
   for($i=30;$i>=1;$i--){$minute=$now->copy()->subMinutes($i)->startOfMinute();$key=$minute->format('Y-m-d H:i:s');$rpm[]=['time'=>$minute->toIso8601String(),'requests'=>isset($buckets[$key])?(int)$buckets[$key]:null];}
  }catch(Throwable){}
  try {
   $checks=DB::table('monitoring_checks')->where('observed_at','>=',$now->copy()->subDay())->orderBy('observed_at')->get();
   $up=0;$down=0;
   for($i=0;$i<$checks->count()-1;$i++){
    $seconds=strtotime($checks[$i+1]->observed_at.' UTC')-strtotime($checks[$i]->observed_at.' UTC');
    if($seconds>0&&$seconds<=90){if($checks[$i]->available)$up+=$seconds;else $down+=$seconds;}
   }
   $last=$checks->last();
   if($last)$availability=['uptime_seconds'=>$up,'downtime_seconds'=>$down,'unknown_seconds'=>max(0,86400-$up-$down),'status'=>($now->timestamp-strtotime($last->observed_at.' UTC')>90)?'Unavailable':($last->available?'Operational':'Down'),'observed_at'=>$last->observed_at.'Z','window'=>'Last 24 hours; sampled intervals, gaps excluded'];
  }catch(Throwable){}
  $external=app(BetterStackMonitoring::class);
  if($external->configured())$availability=$external->snapshot();
  try{$errors=DB::table('monitoring_errors')->orderByDesc('id')->limit(25)->get();}catch(Throwable){}
  return ['storage'=>$this->storage(),'memory'=>$this->memory(),'rpm'=>$rpm,'availability'=>$availability,'errors'=>$errors,'measured_at'=>$now->toIso8601String()];
 }
 public static function capture(Throwable $error, string $severity = 'Error'): void {
  // Do not persist exception messages, SQL, request input or stack arguments: they can contain credentials and business data.
  $message=match(true){$error instanceof \Illuminate\Database\QueryException=>'Database operation failed.',$error instanceof \Illuminate\Http\Client\ConnectionException=>'External service connection failed.',default=>'Application operation failed.'};
  self::writeError($severity, substr(get_class($error),0,180), $message);
 }
 public static function writeError(string $severity, string $component, string $message): void {
  try{DB::table('monitoring_errors')->insert(['occurred_at'=>now()->utc(),'severity'=>$severity,'component'=>$component,'message'=>$message,'status'=>'New']);}catch(Throwable){/* Original logger remains available. */}
 }
}

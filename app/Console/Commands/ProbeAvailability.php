<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;
class ProbeAvailability extends Command {
 protected $signature='monitoring:probe';
 protected $description='Run from an external scheduler every minute to record application availability';
 public function handle(): int {
  $url=config('monitoring.probe_url');
  if(!$url||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)){$this->error('Configure MONITORING_PROBE_URL on the external monitor host.');return 1;}
  try{$ok=Http::timeout(10)->withOptions(['allow_redirects'=>false])->get($url)->successful();}catch(Throwable){$ok=false;}
  DB::table('monitoring_checks')->insert(['observed_at'=>now()->utc(),'available'=>$ok]);
  DB::table('monitoring_checks')->where('observed_at','<',now()->utc()->subDays(7))->delete();
  DB::table('monitoring_requests')->where('minute','<',now()->utc()->subDays(2))->delete();
  DB::table('monitoring_errors')->where('occurred_at','<',now()->utc()->subDays(30))->delete();
  $this->info($ok?'Operational':'Down');return 0;
 }
}

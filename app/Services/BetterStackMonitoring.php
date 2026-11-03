<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BetterStackMonitoring
{
    public function configured(): bool
    {
        return filled(config('monitoring.better_stack_monitor_id')) || filled(config('monitoring.better_stack_token'));
    }

    public function snapshot(): ?array
    {
        $id = (string) config('monitoring.better_stack_monitor_id');
        $token = (string) config('monitoring.better_stack_token');
        if (!ctype_digit($id) || $token === '') return null;

        try {
            // Cache only sanitized output, including failures; never cache provider credentials.
            $result = Cache::store('file')->remember('better-stack:'.hash('sha256', $id.$token), 60, function () use ($id, $token) {
                try {
                    $url = 'https://incidents.betterstack.com/api/v2/monitors/'.$id;
                    $client = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting();
                    $response = $client->get($url);
                    if (!$response->successful() || (string) $response->json('data.id') !== $id) return ['value'=>null];
                    $monitor = $response->json('data.attributes');
                    if (!is_array($monitor)) return ['value'=>null];
                    $checked = !empty($monitor['last_checked_at']) ? CarbonImmutable::parse($monitor['last_checked_at']) : null;
                    $frequency = max(30, (int) ($monitor['effective_check_frequency'] ?? $monitor['check_frequency'] ?? 180));
                    $age = $checked ? now()->timestamp - $checked->timestamp : null;
                    $fresh = $age !== null && $age >= 0 && $age <= $frequency * 2 + 60;
                    $status = $fresh ? match ($monitor['status'] ?? '') {
                        'up'=>'Operational', 'down'=>'Down', 'validating'=>'Recovering', default=>'Unavailable',
                    } : 'Unavailable';
                    $value = ['status'=>$status, 'observed_at'=>$checked?->toIso8601String(),
                        'uptime_percentage'=>null, 'downtime_seconds'=>null,
                        'window'=>'Better Stack: since monitor creation', 'source'=>'Better Stack'];
                    $sla = $client->get($url.'/sla');
                    if ($sla->successful() && (string) $sla->json('data.id') === $id) {
                        $percentage = $sla->json('data.attributes.availability');
                        $down = $sla->json('data.attributes.total_downtime');
                        if (is_numeric($percentage) && $percentage >= 0 && $percentage <= 100) $value['uptime_percentage'] = (float) $percentage;
                        if (is_numeric($down) && $down >= 0) $value['downtime_seconds'] = (int) $down;
                    }
                    return ['value'=>$value];
                } catch (Throwable) {
                    return ['value'=>null];
                }
            });
            return $result['value'];
        } catch (Throwable) {
            return null;
        }
    }
}

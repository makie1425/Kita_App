<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) {
            if (!in_array($event->level, ['error','critical','alert','emergency'], true)) return;
            $exception=$event->context['exception']??null;
            if ($exception instanceof \Throwable) {
                \App\Services\OperationalMonitoring::capture($exception, ucfirst($event->level));
            } else {
                \App\Services\OperationalMonitoring::writeError(ucfirst($event->level), 'Application logger', 'Application logged an error. See protected server logs for diagnostic details.');
            }
        });
    }
}

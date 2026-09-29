<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckPayMongo extends Command
{
    protected $signature = 'paymongo:check';

    protected $description = 'Check PayMongo configuration without exposing secrets or making payments';

    public function handle(): int
    {
        $key = (string) config('services.paymongo.secret');
        $url = (string) config('app.url');
        $valid = true;
        if (! preg_match('/^sk_(test|live)_\S+$/', $key, $matches)) {
            $this->error('Set PAYMONGO_SECRET_KEY to your test or live secret API key.');
            $valid = false;
        } else {
            $this->info('PayMongo mode: '.$matches[1]);
        }
        if (! filled(config('services.paymongo.webhook_secret'))) {
            $this->error('Set PAYMONGO_WEBHOOK_SECRET to the signing secret for your webhook endpoint.');
            $valid = false;
        } else {
            $this->info('Webhook signing secret is configured.');
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
            $this->error('Set APP_URL to a public HTTPS deployment or tunnel URL reachable by PayMongo.');
            $valid = false;
        }
        $this->line('Webhook URL: '.rtrim($url, '/').'/api/payments/paymongo/webhook');
        $this->line('Subscribe to checkout_session.payment.paid in the matching PayMongo mode.');
        $this->line('This checks local configuration only; it does not verify credentials or webhook delivery.');

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}

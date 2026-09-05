<?php
declare(strict_types=1);
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class HealthCheck extends Command
{
    protected $signature = 'chargeguard:health-check';
    protected $description = 'Check configuration without exposing secrets';
    public function handle(): int
    {
        $checks = [
            'PHP 8.2+'=>PHP_VERSION_ID >= 80200,'Database queue'=>config('queue.default') === 'database',
            'Shopify credentials'=>(bool) (config('shopify.api_key') && config('shopify.api_secret')),
            'Mail sender'=>\App\Services\Email\Recipient::valid(config('mail.from.address')),
            'App key'=>(bool) config('app.key'),
        ];
        try { DB::select('SELECT 1'); $checks['Database'] = true; } catch (\Throwable) { $checks['Database'] = false; }
        if (app()->environment('production')) {
            $checks['HTTPS'] = str_starts_with(config('app.url'),'https://');
            $checks['Debug disabled'] = !config('app.debug');
            $checks['Billing enforced'] = (bool) config('chargeguard.billing_enforced');
            $checks['Partner credentials'] = (bool) (config('shopify.partner_token') && config('shopify.partner_id') && config('shopify.app_id'));
            $checks['Production mailer'] = config('mail.default') === 'smtp';
            $checks['Secure cookies'] = (bool) config('session.secure');
        }
        foreach ($checks as $label=>$passed) { $this->line(($passed ? 'PASS ' : 'FAIL ').$label); }
        return in_array(false,$checks,true) ? self::FAILURE : self::SUCCESS;
    }
}

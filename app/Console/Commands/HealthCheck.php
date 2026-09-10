<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DeploymentMode;
use App\Services\Email\MerchantSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthCheck extends Command
{
    protected $signature = 'chargeguard:health-check';

    protected $description = 'Check configuration without exposing secrets';

    public function handle(): int
    {
        $checks = [
            'PHP 8.2+' => PHP_VERSION_ID >= 80200, 'Database queue' => config('queue.default') === 'database',
            'Queue shares application database' => ! config('queue.connections.database.connection') || config('queue.connections.database.connection') === config('database.default'),
            'Shopify credentials' => (bool) (config('shopify.api_key') && config('shopify.api_secret')),
            'System verification sender configuration' => app(MerchantSenderService::class)->managedConfigured(),
            'App key' => (bool) config('app.key'),
            'Storage writable' => is_writable(storage_path()),
            'Cache directory writable' => is_writable(base_path('bootstrap/cache')),
            'Required scopes' => ! array_diff(['read_customers', 'read_orders', 'read_shopify_payments_disputes'], config('shopify.scopes', [])),
        ];
        try {
            DB::select('SELECT 1');
            $checks['Database'] = true;
            if (DB::connection()->getDriverName() === 'mysql') {
                $tables = DB::select("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
                $checks['Transactional InnoDB tables'] = collect($tables)->every(fn ($table) => strtoupper($table->ENGINE) === 'INNODB');
            }
            $checks['Required tables'] = collect(['migrations', 'shops', 'shop_settings', 'disputes', 'email_templates', 'email_logs', 'automation_deliveries', 'webhook_events', 'privacy_requests', 'jobs', 'failed_jobs', 'email_sending_domains', 'merchant_email_senders', 'subscription_usage_periods'])->every(fn ($table) => Schema::hasTable($table));
            $migrations = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')));
            $checks['Migrations applied'] = Schema::hasTable('migrations') && ! array_diff($migrations, DB::table('migrations')->pluck('migration')->all());
        } catch (\Throwable) {
            $checks['Database'] = false;
        }
        if (app()->environment('production')) {
            $host = parse_url(config('app.url'), PHP_URL_HOST) ?: '';
            $checks['Permanent HTTPS URL'] = str_starts_with(config('app.url'), 'https://') && $host && ! preg_match('/(localhost|example\.com|\.invalid|\.trycloudflare\.com)$/i', $host) && ! filter_var($host, FILTER_VALIDATE_IP);
            $checks['Debug disabled'] = ! config('app.debug');
            $checks['Demo disabled'] = ! config('chargeguard.demo_mode');
            $checks['Test tools disabled'] = ! DeploymentMode::testTools();
            if (config('chargeguard.billing_enabled')) {
                $checks['Billing enforced'] = (bool) config('chargeguard.billing_enforced');
                $checks['Partner credentials'] = (bool) (config('shopify.partner_token') && config('shopify.partner_id') && config('shopify.app_id') && config('shopify.app_handle'));
                $this->line('PASS Billing enabled; subscriptions must be verified');
            } else {
                $checks['Private prelaunch allowlist'] = (bool) config('chargeguard.prelaunch') && count(config('chargeguard.prelaunch_shops', [])) > 0
                    && collect(config('chargeguard.prelaunch_shops'))->every(fn ($shop) => preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com\z/D', $shop));
                $this->line('WARN Billing disabled for prelaunch; public launch is not permitted');
            }
            $checks['Production Postmark mailer'] = config('mail.default') === 'postmark';
            $checks['Postmark send token configured'] = (bool) config('services.postmark.token') && config('services.postmark.token') !== 'POSTMARK_API_TEST';
            $checks['Postmark account token configured'] = (bool) config('services.postmark.account_token');
            $checks['Secure cookies'] = (bool) config('session.secure');
            $checks['Embedded cookies'] = config('session.same_site') === 'none';
        } else {
            $this->line('WARN APP_ENV is not production');
        }
        if (config('chargeguard.test_mode')) {
            $this->line('WARN Global customer email safety mode blocks live automation');
        }
        foreach ($checks as $label => $passed) {
            $this->line(($passed ? 'PASS ' : 'FAIL ').$label);
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}

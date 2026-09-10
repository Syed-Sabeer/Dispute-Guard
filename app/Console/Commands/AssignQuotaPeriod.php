<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\DeploymentMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignQuotaPeriod extends Command
{
    protected $signature = 'chargeguard:quota-period {shop} {plan} {start : ISO-8601 timestamp} {end : ISO-8601 timestamp}';

    protected $description = 'Assign an explicit private-prelaunch quota period without enabling billing or sending';

    public function handle(): int
    {
        $shop = Shop::where('shop_domain', $this->argument('shop'))->first();
        if (config('chargeguard.billing_enabled') || ! $shop?->active() || ! DeploymentMode::privateShop($shop->shop_domain)
            || ! array_key_exists($this->argument('plan'), config('quotas.plans'))) {
            $this->error('FAIL Only an active allowlisted private-prelaunch shop and a supported plan are accepted.');

            return self::FAILURE;
        }
        try {
            foreach (['start', 'end'] as $field) {
                if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})\z/', $this->argument($field))) {
                    throw new \RuntimeException;
                }
            }
            $start = CarbonImmutable::parse($this->argument('start'))->utc();
            $end = CarbonImmutable::parse($this->argument('end'))->utc();
            if ($start->gt(now()) || $end->lte(now()) || $end->lte($start)) {
                throw new \RuntimeException;
            }
            DB::transaction(function () use ($shop, $start, $end) {
                $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
                // Changing plans cannot manufacture a reset in an ongoing assigned period.
                if ($shop->billing_period_end?->gt(now()) && $shop->billing_period_start && ! $shop->billing_period_start->equalTo($start)) {
                    throw new \RuntimeException;
                }
                $shop->update(['quota_plan' => $this->argument('plan'), 'billing_period_start' => $start, 'billing_period_end' => $end]);
            });
        } catch (\Throwable) {
            $this->error('FAIL Provide a current valid period; an ongoing period cannot be reset.');

            return self::FAILURE;
        }
        $this->info('PASS Private validation quota period assigned. Sending and billing settings are unchanged.');

        return self::SUCCESS;
    }
}

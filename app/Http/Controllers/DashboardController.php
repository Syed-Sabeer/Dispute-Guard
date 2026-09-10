<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DisputeStatus;
use App\Services\Billing\BillingServiceInterface;
use App\Services\Billing\UsageQuota;
use App\Services\Email\MerchantSenderService;

class DashboardController extends MerchantController
{
    public function __invoke()
    {
        $shop = $this->shop();
        // Verify before resolving usage: verification may refresh the subscription period.
        $entitled = app(BillingServiceInterface::class)->entitled($shop);
        $usage = app(UsageQuota::class)->summary($shop);
        $settings = $shop->settings;
        $pauseReason = match (true) {
            (bool) config('chargeguard.test_mode'), (bool) $settings?->test_mode => 'Customer emails are blocked while test mode is enabled.',
            ! $settings?->onboarded_at => 'Complete onboarding to enable automatic follow-ups.',
            ! app(MerchantSenderService::class)->managedConfigured() => 'Email delivery is unavailable. Please contact app support.',
            ! $entitled => config('chargeguard.billing_enabled')
                ? 'Subscription verification is required.'
                : 'Automation is unavailable for this shop during private prelaunch.',
            $usage === null => 'Usage period is unavailable.',
            $usage['remaining'] === 0 => 'Monthly follow-up limit reached.',
            default => null,
        };
        $automationState = ! $settings?->auto_email_enabled
            ? ['label' => 'Disabled', 'description' => 'Automatic customer follow-ups are currently disabled.']
            : ($pauseReason !== null
                ? ['label' => 'Automation paused', 'description' => $pauseReason.' Your automation preference is unchanged.']
                : ['label' => 'Enabled', 'description' => 'Eligible new disputes receive customer follow-ups after safety checks.']);
        $q = $shop->disputes()->where('source', 'shopify');
        $metrics = [
            'Open disputes' => (clone $q)->whereIn('status', DisputeStatus::open())->count(),
            'Manual reviews' => (clone $q)->where('automation_status', 'MANUAL_REVIEW')->count(),
            'Emails sent' => $shop->emailLogs()->where('type', 'automatic')->whereHas('dispute', fn ($q) => $q->where('source', 'shopify'))->where('status', 'SENT')->count(),
            'Email failures' => $shop->emailLogs()->where('type', 'automatic')->whereHas('dispute', fn ($q) => $q->where('source', 'shopify'))->where('status', 'FAILED')->count(),
        ];
        foreach (['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $state) {
            $metrics[ucwords(strtolower(str_replace('_', ' ', $state)))] = (clone $q)->where('shipping_state', $state)->count();
        }
        $risk = (clone $q)->whereIn('status', DisputeStatus::open())->selectRaw('currency, SUM(amount) as total')->groupBy('currency')->get();

        return $this->page('dashboard.index', ['metrics' => $metrics, 'risk' => $risk, 'disputes' => (clone $q)->latest()->limit(8)->get(),
            'usage' => $usage, 'automationState' => $automationState]);
    }
}

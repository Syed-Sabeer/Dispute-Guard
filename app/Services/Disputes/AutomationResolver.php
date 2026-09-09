<?php

declare(strict_types=1);

namespace App\Services\Disputes;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\OrderShippingState;
use App\Models\Dispute;
use App\Models\EmailTemplate;
use App\Models\Shop;
use App\Services\Billing\BillingServiceInterface;
use App\Services\Email\MerchantSenderService;

class AutomationResolver
{
    public function template(Shop $shop, string $reason, string $state): ?EmailTemplate
    {
        $stateEnum = OrderShippingState::tryFrom($state);
        if (! DisputeReason::tryFrom($reason) || ! $stateEnum) {
            return null;
        }
        if ($stateEnum === OrderShippingState::UNKNOWN) {
            return null;
        }

        return $shop->emailTemplates()->where('dispute_reason', $reason)->where('shipping_state', $state)->where('enabled', true)->first();
    }

    public function blocked(Shop $shop, Dispute $dispute, ?EmailTemplate $template): ?string
    {
        if (! $shop->active()) {
            return 'Shop is inactive or uninstalled.';
        }
        if ($dispute->redacted_at) {
            return 'Customer data has been redacted.';
        }
        if ($dispute->source !== 'shopify') {
            return 'Synthetic data cannot send automatic emails.';
        }
        if (! DisputeReason::tryFrom($dispute->reason)) {
            return 'Unsupported dispute reason; manual review required.';
        }
        if ($dispute->shipping_state === 'UNKNOWN') {
            return 'Shipment cannot be safely classified; manual review required.';
        }
        if (! in_array($dispute->status, DisputeStatus::open(), true)) {
            return 'Dispute is closed or its status is unsupported.';
        }
        $settings = $shop->settings()->first();
        if (config('chargeguard.test_mode') || ! $settings || $settings->test_mode) {
            return 'Test mode prevents production email.';
        }
        if (! $settings->auto_email_enabled || ! $settings->onboarded_at) {
            return 'Merchant automation is disabled or onboarding is incomplete.';
        }
        if (! $template) {
            return 'Matching template is disabled or missing.';
        }
        if (! app(MerchantSenderService::class)->managedConfigured()) {
            return 'Email delivery is not configured. Please contact app support.';
        }
        if (! app(BillingServiceInterface::class)->entitled($shop)) {
            return 'Active subscription could not be verified.';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DisputeStatus;

class DashboardController extends MerchantController
{
    public function __invoke()
    {
        $shop = $this->shop();
        $q = $shop->disputes()->where('source', 'shopify');
        $metrics = [
            'Open disputes' => (clone $q)->whereIn('status', DisputeStatus::open())->count(),
            'Manual reviews' => (clone $q)->where('automation_status', 'MANUAL_REVIEW')->count(),
            'Emails sent' => $shop->emailLogs()->where('type', 'automatic')->where('status', 'SENT')->count(),
            'Email failures' => $shop->emailLogs()->where('type', 'automatic')->where('status', 'FAILED')->count(),
        ];
        foreach (['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $state) {
            $metrics[ucwords(strtolower(str_replace('_', ' ', $state)))] = (clone $q)->where('shipping_state', $state)->count();
        }
        $risk = (clone $q)->whereIn('status', DisputeStatus::open())->selectRaw('currency, SUM(amount) as total')->groupBy('currency')->get();

        return $this->page('dashboard.index', ['metrics' => $metrics, 'risk' => $risk, 'disputes' => $shop->disputes()->latest()->limit(8)->get()]);
    }
}

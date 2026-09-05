<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivacyRequest;

class PrivacyRequestController extends MerchantController
{
    public function index()
    {
        return $this->page('settings.privacy', ['requests' => PrivacyRequest::forShop($this->shop())->latest()->paginate(20)]);
    }

    public function export(PrivacyRequest $privacyRequest)
    {
        $this->owned($privacyRequest);

        return response()->streamDownload(function () use ($privacyRequest) {
            echo json_encode($privacyRequest->export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }, 'customer-data-request.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    public function complete(PrivacyRequest $privacyRequest)
    {
        $this->owned($privacyRequest);
        $privacyRequest->update(['status' => 'COMPLETED', 'completed_at' => now(), 'export' => null]);

        return response()->json(['message' => 'Request marked fulfilled; stored export removed.']);
    }
}

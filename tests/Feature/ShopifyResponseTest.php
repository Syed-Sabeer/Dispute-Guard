<?php

namespace Tests\Feature;

use App\Services\Shopify\ShopifyRequestVerifier;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ShopifyResponseTest extends TestCase
{
    public function test_empty_sdk_failure_is_visible_and_logs_only_safe_metadata(): void
    {
        Log::spy();
        $result = (object) [
            'ok' => false,
            'log' => (object) ['code' => 'invalid_client', 'detail' => 'sensitive-detail', 'req' => ['url' => '/?id_token=sensitive-token']],
            'response' => (object) ['body' => '', 'status' => 500, 'headers' => []],
        ];
        $response = ShopifyRequestVerifier::response($result);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('could not establish its Shopify connection', $response->getContent());
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        Log::shouldHaveReceived('log')->once()->with('warning', 'Shopify authentication response', [
            'code' => 'invalid_client', 'status' => 500, 'path' => '/', 'secure' => false,
        ]);
    }
}

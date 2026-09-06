<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbeddedEntryTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    public function test_root_verifies_the_document_token_and_renders_dashboard(): void
    {
        $shop = $this->shop();
        $this->get('/?'.http_build_query(['shop' => $shop->shop_domain, 'embedded' => '1', 'id_token' => $this->token($shop)]))
            ->assertOk()->assertSee('Open disputes')
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://'.$shop->shop_domain.' https://admin.shopify.com;');
    }

    public function test_missing_document_token_uses_https_patch_url_behind_local_proxy(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://preview.example.com/?shop=example.myshopify.com&embedded=1');

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://preview.example.com/auth/patch-id-token?', $response->headers->get('Location'));
    }

    public function test_invalid_document_token_cannot_render_merchant_data(): void
    {
        $shop = $this->shop();
        $response = $this->get('/?'.http_build_query(['shop' => $shop->shop_domain, 'id_token' => 'invalid']));
        $this->assertNotSame(200, $response->status());
        $response->assertDontSee('Open disputes');
    }

    public function test_public_root_still_renders_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}

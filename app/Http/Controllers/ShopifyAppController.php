<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyAppService;
use App\Services\Shopify\ShopifyRequestVerifier;
use Illuminate\Http\Request;

class ShopifyAppController extends Controller
{
    public function patch(Request $request, ShopifyAppService $app)
    {
        return ShopifyRequestVerifier::response($app->sdk()->appHomePatchIdToken(ShopifyRequestVerifier::request($request)));
    }
}

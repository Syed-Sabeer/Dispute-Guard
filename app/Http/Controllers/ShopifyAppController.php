<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateShopify;
use App\Services\Shopify\ShopifyAppService;
use App\Services\Shopify\ShopifyRequestVerifier;
use Illuminate\Http\Request;

class ShopifyAppController extends Controller
{
    public function home(Request $request, AuthenticateShopify $authenticate)
    {
        if (! $request->has('shop') && ! $request->has('id_token') && ! $request->bearerToken()) {
            return view('landing');
        }

        // Verify the original document request before consuming its id_token.
        return $authenticate->handle($request, fn () => response(app(DashboardController::class)()));
    }

    public function patch(Request $request, ShopifyAppService $app)
    {
        return ShopifyRequestVerifier::response($app->sdk()->appHomePatchIdToken(ShopifyRequestVerifier::request($request)));
    }
}

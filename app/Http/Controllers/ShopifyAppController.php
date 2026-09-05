<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\Shopify\{ShopifyAppService,ShopifyRequestVerifier};
class ShopifyAppController extends Controller
{
    public function patch(Request $request, ShopifyAppService $app)
    {
        return ShopifyRequestVerifier::response($app->sdk()->appHomePatchIdToken(ShopifyRequestVerifier::request($request)));
    }
}

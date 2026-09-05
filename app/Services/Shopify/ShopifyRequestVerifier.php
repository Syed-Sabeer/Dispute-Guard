<?php
declare(strict_types=1);
namespace App\Services\Shopify;
use Illuminate\Http\Request;
final class ShopifyRequestVerifier
{
    public static function domain(string $domain): string
    {
        abort_unless(preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $domain) && strlen($domain) <= 255, 400, 'Invalid Shopify domain.');
        return $domain;
    }
    public static function request(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            abort_unless(count($values) === 1, 400, 'Multiple header values are not supported.');
            $headers[$name] = $values[0];
        }
        return ['method'=>$request->method(),'headers'=>$headers,'url'=>$request->fullUrl(),'body'=>$request->getContent()];
    }
    public static function response(object $result): \Illuminate\Http\Response
    {
        return response($result->response->body, $result->response->status)->withHeaders((array) $result->response->headers);
    }
}

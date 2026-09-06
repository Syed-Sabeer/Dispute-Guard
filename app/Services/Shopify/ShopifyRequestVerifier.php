<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class ShopifyRequestVerifier
{
    public static function domain(string $domain): string
    {
        abort_unless(preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com\z/D', $domain), 400, 'Invalid Shopify domain.');

        return $domain;
    }

    public static function request(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            abort_unless(count($values) === 1, 400, 'Multiple header values are not supported.');
            $headers[$name] = $values[0];
        }

        return ['method' => $request->method(), 'headers' => $headers, 'url' => $request->fullUrl(), 'body' => $request->getContent()];
    }

    public static function response(object $result): Response
    {
        if ($result->response->status >= 400 || app()->environment(['local', 'development'])) {
            // Never log SDK request objects: their URLs can contain session tokens.
            Log::log($result->response->status >= 400 ? 'warning' : 'debug', 'Shopify authentication response', [
                'code' => $result->log->code ?? 'unknown',
                'status' => $result->response->status,
                'path' => request()->path(),
                'secure' => request()->isSecure(),
            ]);
        }

        if ($result->response->status >= 500 && empty($result->response->body)) {
            return response()->view('errors.shopify', [
                'message' => 'The app could not establish its Shopify connection. Please try again. If this continues, check the server authentication log.',
            ], $result->response->status)->withHeaders((array) $result->response->headers)
                ->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response($result->response->body, $result->response->status)->withHeaders((array) $result->response->headers);
    }
}

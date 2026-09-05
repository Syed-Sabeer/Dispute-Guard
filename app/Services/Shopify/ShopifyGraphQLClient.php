<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;

class ShopifyGraphQLClient
{
    public function __construct(private ShopifyAppService $app) {}

    public function query(Shop $shop, string $document, array $variables = []): array
    {
        $token = $this->app->token($shop);
        try {
            $result = $this->app->sdk()->adminGraphQLRequest(
                file_get_contents(resource_path('graphql/'.$document.'.graphql')),
                shop: $shop->handle(), accessToken: $token['token'], apiVersion: config('shopify.api_version'),
                variables: $variables, maxRetries: 0, httpClient: $this->app->http()
            );
        } catch (\Throwable) {
            throw new ShopifyApiException(true);
        }
        $body = json_decode($result->response->body, true);
        if (! $result->ok || ! is_array($result->data)) {
            $transient = $result->response->status === 429 || $result->response->status >= 500 || $result->response->status === 0
                || collect($body['errors'] ?? [])->contains(fn ($e) => in_array(data_get($e, 'extensions.code'), ['THROTTLED', 'INTERNAL_SERVER_ERROR'], true));
            throw new ShopifyApiException($transient);
        }
        $this->checkUserErrors($result->data);

        return $result->data;
    }

    private function checkUserErrors(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'userErrors' && ! empty($value)) {
                throw new ShopifyApiException;
            }
            if (is_array($value)) {
                $this->checkUserErrors($value);
            }
        }
    }
}

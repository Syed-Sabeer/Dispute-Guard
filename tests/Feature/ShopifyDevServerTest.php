<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ShopifyDevServerTest extends TestCase
{
    public function test_http_worker_preserves_shopify_cli_credentials(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = substr(strrchr($address, ':'), 1);
        $process = new Process([PHP_BINARY, 'scripts/shopify-serve.php'], base_path(), [
            'PORT' => $port,
            'APP_ENV' => 'testing',
            'SHOPIFY_API_KEY' => 'cli-injected-test-client',
            'SHOPIFY_API_SECRET' => 'cli-injected-test-secret',
            'APP_URL' => 'https://cli-preview.example.com',
        ]);
        $process->start();

        try {
            $client = new Client(['base_uri' => 'http://127.0.0.1:'.$port, 'timeout' => 2]);
            $response = null;
            for ($attempt = 0; $attempt < 40; $attempt++) {
                try {
                    $response = $client->get('/auth/patch-id-token?shop=test.myshopify.com&shopify-reload=%2F');
                    break;
                } catch (ConnectException) {
                    usleep(250000);
                }
            }

            $this->assertNotNull($response, 'Development HTTP worker did not start.');
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('data-api-key="cli-injected-test-client"', (string) $response->getBody());
        } finally {
            $process->stop();
        }
    }
}

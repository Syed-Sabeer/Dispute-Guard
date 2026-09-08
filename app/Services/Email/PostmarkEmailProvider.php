<?php

namespace App\Services\Email;

use App\Exceptions\EmailProviderException;
use Illuminate\Support\Facades\Http;

class PostmarkEmailProvider implements EmailProviderInterface
{
    private function request(string $method, string $path, array $data = [], bool $sending = false): array
    {
        $token = config('services.postmark.'.($sending ? 'token' : 'account_token'));
        if (! $token || ($sending && app()->environment('production') && $token === 'POSTMARK_API_TEST')) {
            throw new EmailProviderException('CONFIGURATION');
        }
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(2)->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([($sending ? 'X-Postmark-Server-Token' : 'X-Postmark-Account-Token') => $token])
                ->send($method, 'https://api.postmarkapp.com'.$path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (\Throwable) {
            // No request/response bodies or chained HTTP exceptions may escape.
            throw new EmailProviderException($sending ? 'DELIVERY_OUTCOME_UNKNOWN' : 'TRANSIENT_BEFORE_SEND');
        }
        $body = $response->json();
        if ($response->status() === 401) {
            throw new EmailProviderException('CONFIGURATION');
        }
        if (! $response->successful() || ! is_array($body) || ($body['ErrorCode'] ?? 0) !== 0) {
            // A create conflict can be safely reconciled with the account's list.
            if (! $sending && $path === '/domains' && $method === 'POST' && ($body['ErrorCode'] ?? null) === 512) {
                return ['already_exists' => true];
            }
            $definite = in_array($response->status(), [400, 404, 413, 415, 422, 429], true);
            throw new EmailProviderException($sending ? ($definite ? 'DEFINITE_REJECTION' : 'DELIVERY_OUTCOME_UNKNOWN') : 'TRANSIENT_BEFORE_SEND');
        }

        return $body;
    }

    public function ensureDomain(string $domain): array
    {
        $created = $this->request('POST', '/domains', ['Name' => $domain, 'ReturnPathDomain' => 'pm-bounces.'.$domain]);
        if (! isset($created['already_exists'])) {
            return $created;
        }
        // Bounded pagination; never create again or take a browser-supplied ID.
        for ($offset = 0; $offset < 5000; $offset += 500) {
            $page = $this->request('GET', '/domains', ['count' => 500, 'offset' => $offset]);
            foreach ($page['Domains'] ?? [] as $item) {
                if (strtolower($item['Name'] ?? '') === $domain && is_int($item['ID'] ?? null)) {
                    return $this->request('GET', '/domains/'.$item['ID']);
                }
            }
            if ($offset + 500 >= ($page['TotalCount'] ?? 0)) {
                break;
            }
        }
        throw new EmailProviderException('CONFIGURATION');
    }

    public function verifyDomain(int $id): array
    {
        $this->request('PUT', '/domains/'.$id.'/verifyDkim');
        return $this->request('PUT', '/domains/'.$id.'/verifyReturnPath');
    }

    public function send(array $message): string
    {
        $result = $this->request('POST', '/email', $message + ['MessageStream' => 'outbound', 'TrackOpens' => false, 'TrackLinks' => 'None'], true);
        if (($result['ErrorCode'] ?? null) !== 0 || ! preg_match('/\A[0-9a-f-]{36}\z/i', $result['MessageID'] ?? '')) {
            throw new EmailProviderException('DELIVERY_OUTCOME_UNKNOWN');
        }
        return $result['MessageID'];
    }
}

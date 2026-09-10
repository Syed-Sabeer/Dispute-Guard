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
            throw new EmailProviderException($sending ? 'DELIVERY_OUTCOME_UNKNOWN' : 'TRANSIENT_VERIFICATION_FAILURE');
        }
        $body = $response->json();
        if ($response->status() === 401) {
            throw new EmailProviderException('CONFIGURATION');
        }
        if (! $response->successful() || ! is_array($body) || ($body['ErrorCode'] ?? 0) !== 0) {
            if (! $sending && $path === '/senders' && $method === 'POST' && ($body['ErrorCode'] ?? null) === 504) {
                return ['already_exists' => true];
            }
            if (! $sending && str_ends_with($path, '/resend') && ($body['ErrorCode'] ?? null) === 506) {
                return [];
            }
            // A create conflict can be safely reconciled with the account's list.
            if (! $sending && $path === '/domains' && $method === 'POST' && ($body['ErrorCode'] ?? null) === 512) {
                return ['already_exists' => true];
            }
            if (! $sending && $response->status() === 404) {
                throw new EmailProviderException('SENDER_NOT_VERIFIED');
            }
            if (! $sending && in_array($response->status(), [400, 403, 413, 415, 422], true)) {
                throw new EmailProviderException('CONFIGURATION');
            }
            $definite = in_array($response->status(), [400, 404, 413, 415, 422, 429], true);
            throw new EmailProviderException($sending ? ($definite ? 'DEFINITE_REJECTION' : 'DELIVERY_OUTCOME_UNKNOWN') : 'TRANSIENT_VERIFICATION_FAILURE');
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

    private function signature(array $data, string $email, ?int $id = null): array
    {
        if (! is_int($data['ID'] ?? null) || $data['ID'] <= 0 || ($id !== null && $data['ID'] !== $id)
            || ! is_string($data['EmailAddress'] ?? null) || strtolower($data['EmailAddress']) !== $email
            || ! is_bool($data['Confirmed'] ?? null)) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }

        return ['id' => $data['ID'], 'email' => $email, 'confirmed' => $data['Confirmed']];
    }

    public function createSenderSignature(string $email, string $name): array
    {
        $data = $this->request('POST', '/senders', ['FromEmail' => $email, 'Name' => $name,
            'ConfirmationPersonalNote' => 'Confirm your business email for Dispute Guard customer follow-ups.']);

        return isset($data['already_exists']) ? $this->findSenderSignatureByEmail($email)
            : $this->signature($data, $email);
    }

    public function getSenderSignature(int $id, string $email): array
    {
        if ($id <= 0) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }

        return $this->signature($this->request('GET', '/senders/'.$id), $email, $id);
    }

    public function findSenderSignatureByEmail(string $email): array
    {
        for ($offset = 0; $offset < 5000; $offset += 500) {
            $page = $this->request('GET', '/senders', ['count' => 500, 'offset' => $offset]);
            if (! is_array($page['SenderSignatures'] ?? null) || ! is_int($page['TotalCount'] ?? null)) {
                throw new EmailProviderException('CONFIGURATION');
            }
            foreach ($page['SenderSignatures'] as $item) {
                if (is_string($item['EmailAddress'] ?? null) && strtolower($item['EmailAddress']) === $email) {
                    if (! is_int($item['ID'] ?? null) || $item['ID'] <= 0) {
                        throw new EmailProviderException('SENDER_NOT_VERIFIED');
                    }

                    return $this->getSenderSignature($item['ID'], $email);
                }
            }
            if ($offset + 500 >= $page['TotalCount']) {
                break;
            }
        }
        throw new EmailProviderException('SENDER_NOT_VERIFIED');
    }

    public function resendSenderSignatureConfirmation(int $id, string $email): void
    {
        $signature = $this->getSenderSignature($id, $email);
        if (! $signature['confirmed']) {
            $this->request('POST', '/senders/'.$id.'/resend');
        }
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

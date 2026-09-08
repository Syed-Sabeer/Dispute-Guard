<?php

namespace App\Services\Email;

use App\Exceptions\EmailProviderException;
use Illuminate\Support\Facades\Http;

class SenderDnsVerifier
{
    public function matches(string $host, string $expected, string $type): bool
    {
        try {
            $response = Http::accept('application/dns-json')->connectTimeout(2)->timeout(4)
                ->withOptions(['allow_redirects' => false])
                ->get('https://cloudflare-dns.com/dns-query', ['name' => $host, 'type' => $type]);
            if (! $response->successful() || ! in_array($response->json('Status'), [0, 3], true)) {
                throw new \RuntimeException;
            }
            foreach ($response->json('Answer', []) as $record) {
                if (($record['type'] ?? null) !== ($type === 'TXT' ? 16 : 5)) {
                    continue;
                }
                $value = $record['data'] ?? '';
                if ($type === 'TXT') {
                    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $value, $parts);
                    $value = $parts[1] ? implode('', array_map('stripcslashes', $parts[1])) : $value;
                    if (hash_equals($expected, $value)) {
                        return true;
                    }
                } elseif (strtolower(rtrim($value, '.')) === strtolower(rtrim($expected, '.'))) {
                    return true;
                }
            }
        } catch (\Throwable) {
            throw new EmailProviderException('TRANSIENT_BEFORE_SEND');
        }
        return false;
    }
}

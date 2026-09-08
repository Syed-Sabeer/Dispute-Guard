<?php

namespace Tests\Feature;

use App\Exceptions\EmailProviderException;
use App\Services\Email\SenderDnsVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SenderDnsVerifierTest extends TestCase
{
    public function test_split_txt_and_case_insensitive_cname_are_supported(): void
    {
        Http::preventStrayRequests();
        Http::fake(['cloudflare-dns.com/*' => Http::sequence()
            ->push(['Status' => 0, 'Answer' => [['name' => 'proof.store.com.', 'type' => 16, 'data' => '"first" "second"']]])
            ->push(['Status' => 0, 'Answer' => [['name' => 'bounce.store.com.', 'type' => 5, 'data' => 'PROVIDER.COM.']]])]);
        $dns = new SenderDnsVerifier;
        $this->assertTrue($dns->matches('proof.store.com', 'firstsecond', 'TXT'));
        $this->assertTrue($dns->matches('bounce.store.com', 'provider.com', 'CNAME'));
    }

    public function test_missing_or_unrelated_records_do_not_verify(): void
    {
        Http::preventStrayRequests();
        Http::fake(['cloudflare-dns.com/*' => Http::sequence()
            ->push(['Status' => 3])
            ->push(['Status' => 0, 'Answer' => [['name' => 'other.store.com.', 'type' => 16, 'data' => '"proof"']]])]);
        $dns = new SenderDnsVerifier;
        $this->assertFalse($dns->matches('proof.store.com', 'proof', 'TXT'));
        $this->assertFalse($dns->matches('proof.store.com', 'proof', 'TXT'));
    }

    public function test_resolver_timeout_fails_closed_without_raw_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('sensitive response'));
        try {
            (new SenderDnsVerifier)->matches('proof.store.com', 'proof', 'TXT');
            $this->fail('Timeout accepted');
        } catch (EmailProviderException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('sensitive', $e->getMessage());
        }
    }
}

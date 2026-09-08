<?php

namespace App\Services\Email;

use App\Exceptions\EmailProviderException;
use App\Models\EmailSendingDomain;
use App\Models\MerchantEmailSender;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MerchantSenderService
{
    public function __construct(private EmailProviderInterface $provider, private SenderDnsVerifier $dns) {}

    public static function normalize(string $email): string
    {
        if (preg_match('/[\x00-\x20\x7f-\xff]/', $email) || ! Recipient::valid($email) || strlen($email) > 254) {
            throw ValidationException::withMessages(['sender_email' => 'Use a valid email address on a domain you own, without control characters.']);
        }
        $email = strtolower($email);
        $domain = substr(strrchr($email, '@'), 1);
        $blocked = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'myshopify.com', 'example.com', 'example.org', 'example.net'];
        if (strlen($domain) > 190 || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $domain)
            || preg_match('/\.(local|localhost|invalid|test|example)\z/', $domain)
            || collect($blocked)->contains(fn ($item) => $domain === $item || str_ends_with($domain, '.'.$item))) {
            throw ValidationException::withMessages(['sender_email' => 'Use a business domain whose DNS you control. Public mailboxes and Shopify domains cannot be used. Use ASCII or punycode for international domains.']);
        }
        return $email;
    }

    private function locked(Shop $shop, callable $callback): mixed
    {
        return Cache::lock('merchant-sender-'.$shop->id, 120)->block(2, $callback);
    }

    public function save(Shop $shop, string $name, string $email): MerchantEmailSender
    {
        $email = self::normalize($email);
        if (! trim($name) || mb_strlen($name) > 100 || preg_match('/[\p{C}<>]/u', $name)) {
            throw ValidationException::withMessages(['sender_name' => 'Enter a sender name without control characters or markup.']);
        }
        return $this->locked($shop, function () use ($shop, $name, $email) {
            abort_unless($shop->fresh()->active(), 403);
            $domainName = substr(strrchr($email, '@'), 1);
            $domain = EmailSendingDomain::firstOrCreate(['domain' => $domainName]);
            $sender = $shop->emailSender()->first();
            $sameDomain = $sender && $sender->email_sending_domain_id === $domain->id && $sender->verification_status !== 'REMOVED';
            $values = ['sender_name' => trim($name), 'sender_email' => $email, 'email_sending_domain_id' => $domain->id, 'revision' => ($sender?->revision ?? 0) + 1];
            if (! $sameDomain) {
                $values += ['verification_status' => 'PENDING', 'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false,
                    'last_checked_at' => null, 'verified_at' => null, 'ownership_host' => '_disputeguard-'.bin2hex(random_bytes(8)).'.'.$domainName,
                    'ownership_value' => 'disputeguard-verification='.bin2hex(random_bytes(24))];
                $shop->settings()->update(['auto_email_enabled' => false]);
            }
            // Invalidate the previous From before any provider request can fail.
            $sender = $shop->emailSender()->updateOrCreate([], $values);
            Cache::lock('sending-domain-'.$domain->id, 120)->block(2, function () use ($domain) {
                $domain->refresh();
                if (! $domain->provider_domain_id) {
                    $this->persistDomain($domain, $this->provider->ensureDomain($domain->domain));
                }
            });
            return $sender->fresh('sendingDomain');
        });
    }

    private function persistDomain(EmailSendingDomain $domain, array $data): void
    {
        if (strtolower($data['Name'] ?? '') !== $domain->domain || ! is_int($data['ID'] ?? null) || $data['ID'] <= 0
            || ($domain->provider_domain_id && $domain->provider_domain_id !== $data['ID'])) {
            throw new EmailProviderException('CONFIGURATION');
        }
        $domain->update(['provider_domain_id' => $data['ID'],
            'dkim_host' => ($data['DKIMPendingHost'] ?? '') ?: ($data['DKIMHost'] ?? null),
            'dkim_value' => ($data['DKIMPendingTextValue'] ?? '') ?: ($data['DKIMTextValue'] ?? null),
            'return_path_host' => $data['ReturnPathDomain'] ?? null,
            'return_path_value' => $data['ReturnPathDomainCNAMEValue'] ?? null]);
    }

    public function check(Shop $shop): MerchantEmailSender
    {
        return $this->locked($shop, fn () => $this->refresh($shop));
    }

    private function refresh(Shop $shop): MerchantEmailSender
    {
        abort_unless($shop->fresh()->active(), 403);
        $sender = $shop->emailSender()->with('sendingDomain')->firstOrFail();
        abort_if($sender->verification_status === 'REMOVED', 422, 'Save your email sender before checking verification.');
        try {
            $domain = $sender->sendingDomain;
            if (! $domain->provider_domain_id) {
                throw new EmailProviderException('CONFIGURATION');
            }
            $data = $this->provider->verifyDomain($domain->provider_domain_id);
            $this->persistDomain($domain, $data);
            $host = ($data['DKIMHost'] ?? '') ?: ($data['DKIMPendingHost'] ?? '');
            $value = ($data['DKIMTextValue'] ?? '') ?: ($data['DKIMPendingTextValue'] ?? '');
            // DKIMVerified is historical in Postmark, so also check current DNS.
            $dkim = ($data['DKIMVerified'] ?? false) === true && ! ($data['WeakDKIM'] ?? true)
                && $this->domainHost($host, $domain->domain) && $value && $this->dns->matches($host, $value, 'TXT');
            $rp = ($data['ReturnPathDomainVerified'] ?? false) === true
                && $this->domainHost($domain->return_path_host ?? '', $domain->domain) && $domain->return_path_value
                && $this->dns->matches($domain->return_path_host, $domain->return_path_value, 'CNAME');
            $ownership = $this->dns->matches($sender->ownership_host, $sender->ownership_value, 'TXT');
            $verified = $dkim && $rp && $ownership;
            $sender->update(['verification_status' => $verified ? 'VERIFIED' : 'PENDING', 'dkim_verified' => $dkim, 'return_path_verified' => (bool) $rp,
                'ownership_verified' => $ownership, 'last_checked_at' => now(), 'verified_at' => $verified ? now() : null]);
            if (! $verified) {
                $shop->settings()->update(['auto_email_enabled' => false]);
            }
        } catch (EmailProviderException $e) {
            $sender->update(['verification_status' => 'FAILED', 'verified_at' => null, 'last_checked_at' => now(),
                'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false]);
            $shop->settings()->update(['auto_email_enabled' => false]);
            throw $e;
        }
        return $sender->fresh('sendingDomain');
    }

    private function domainHost(string $host, string $domain): bool
    {
        return str_ends_with(strtolower($host), '.'.$domain) && ! preg_match('/[\x00-\x20]/', $host);
    }

    public function ready(Shop $shop): bool
    {
        $sender = $shop->emailSender()->with('sendingDomain')->first();
        if (! $shop->active() || ! $sender || $sender->verification_status !== 'VERIFIED'
            || ! $sender->dkim_verified || ! $sender->return_path_verified || ! $sender->ownership_verified
            || ! $sender->last_checked_at || $sender->last_checked_at->lt(now()->subMinutes(config('senders.verification_ttl_minutes')))) {
            return false;
        }
        try {
            $email = self::normalize($sender->sender_email);
        } catch (ValidationException) {
            return false;
        }
        if (! trim($sender->sender_name) || preg_match('/[\p{C}<>]/u', $sender->sender_name)) {
            return false;
        }
        return substr(strrchr($email, '@'), 1) === $sender->sendingDomain->domain && (bool) $sender->sendingDomain->provider_domain_id;
    }

    public function configured(): bool
    {
        return config('mail.default') === 'postmark' && config('services.postmark.token') && config('services.postmark.account_token');
    }

    public function identity(Shop $shop, bool $test = false, bool $refresh = true): array
    {
        $sender = $shop->emailSender()->first();
        if ($refresh && $sender && $sender->verification_status !== 'REMOVED' && (! $sender->last_checked_at || $sender->last_checked_at->lt(now()->subMinutes(config('senders.verification_ttl_minutes'))))) {
            try {
                $this->check($shop);
            } catch (EmailProviderException $e) {
                if (! $test && config('senders.required')) {
                    throw $e;
                }
            }
        }
        if ($this->configured() && $this->ready($shop)) {
            $sender = $shop->emailSender()->first();
            return ['email' => $sender->sender_email, 'name' => $sender->sender_name, 'revision' => $sender->revision, 'id' => $sender->id];
        }
        if (! $test && config('senders.required')) {
            throw new EmailProviderException($this->configured() ? 'SENDER_NOT_VERIFIED' : 'CONFIGURATION');
        }
        if (! Recipient::valid(config('mail.from.address')) || preg_match('/[\r\n\x00]/', config('mail.from.name', ''))) {
            throw new EmailProviderException('CONFIGURATION');
        }
        return ['email' => config('mail.from.address'), 'name' => config('mail.from.name'), 'revision' => null, 'id' => null];
    }

    public function guard(Shop $shop, array $identity, bool $test, callable $send): mixed
    {
        return $this->locked($shop, function () use ($shop, $identity, $test, $send) {
            if (! $shop->fresh()->active() || $this->identity($shop, $test, false) !== $identity) {
                throw new EmailProviderException('SENDER_NOT_VERIFIED');
            }
            return $send();
        });
    }

    public function disconnect(Shop $shop): void
    {
        $this->locked($shop, function () use ($shop) {
            $shop->emailSender()->update(['verification_status' => 'REMOVED', 'verified_at' => null, 'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false]);
            $shop->settings()->update(['auto_email_enabled' => false]);
        });
    }
}

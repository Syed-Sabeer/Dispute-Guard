<?php

namespace App\Services\Email;

use App\Exceptions\EmailProviderException;
use App\Models\EmailSendingDomain;
use App\Models\MerchantEmailSender;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function save(Shop $shop, string $name, string $email, string $mode = 'SIGNATURE'): MerchantEmailSender
    {
        $email = self::normalize($email);
        if (! trim($name) || mb_strlen($name) > 100 || preg_match('/[\p{C}<>]/u', $name)) {
            throw ValidationException::withMessages(['sender_name' => 'Enter a sender name without control characters or markup.']);
        }

        if (! in_array($mode, ['SIGNATURE', 'DOMAIN'], true)) {
            throw ValidationException::withMessages(['sender_mode' => 'Choose standard email verification or advanced domain authentication.']);
        }

        return $this->locked($shop, function () use ($shop, $name, $email, $mode) {
            abort_unless($shop->fresh()->active(), 403);
            $domainName = substr(strrchr($email, '@'), 1);
            $domain = EmailSendingDomain::firstOrCreate(['domain' => $domainName]);
            $sender = $shop->emailSender()->first();
            $sameDomain = $sender && $sender->sender_mode === $mode && $sender->sender_email === $email && $sender->email_sending_domain_id === $domain->id && $sender->verification_status !== 'REMOVED';
            $values = ['sender_mode' => $mode, 'sender_name' => trim($name), 'sender_email' => $email, 'email_sending_domain_id' => $domain->id, 'revision' => ($sender?->revision ?? 0) + 1,
                'verification_token_hash' => null, 'verification_expires_at' => null];
            if (! $sameDomain) {
                $values += ['verification_status' => 'PENDING', 'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false,
                    'provider_signature_id' => null, 'provider_confirmed_at' => null,
                    'last_checked_at' => null, 'verified_at' => null, 'verification_refresh_failed_at' => null, 'ownership_host' => '_disputeguard-'.bin2hex(random_bytes(8)).'.'.$domainName,
                    'ownership_value' => 'disputeguard-verification='.bin2hex(random_bytes(24))];
            }
            // Invalidate the previous From before any provider request can fail.
            $sender = $shop->emailSender()->updateOrCreate([], $values);
            if ($mode === 'SIGNATURE') {
                if (! $sender->provider_signature_id) {
                    $signature = $this->provider->createSenderSignature($email, $name);
                    $this->validateSignature($signature, $email);
                    // Even a newly created, already confirmed signature needs this shop's mailbox proof.
                    $sender->update(['provider_signature_id' => $signature['id']]);
                }

                return $sender->fresh('sendingDomain');
            }
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
        if ($sender->sender_mode === 'SIGNATURE') {
            return $this->refreshSignature($sender);
        }
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
                'ownership_verified' => $ownership, 'last_checked_at' => now(), 'verified_at' => $verified ? now() : null,
                'verification_refresh_failed_at' => null]);
        } catch (EmailProviderException $e) {
            if ($e->category === 'SENDER_NOT_VERIFIED' || (isset($dkim) && ! $dkim) || (isset($rp) && ! $rp)) {
                // Preserve confirmed negative evidence even if a later lookup failed.
                $sender->update(['verification_status' => 'PENDING', 'verified_at' => null,
                    'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false]);
            }
            // An unavailable service provides no evidence that DNS ownership was lost.
            // The failure marker invalidates freshness, including a failed manual recheck.
            $sender->update(['verification_refresh_failed_at' => now()]);
            throw $e;
        }

        return $sender->fresh('sendingDomain');
    }

    private function domainHost(string $host, string $domain): bool
    {
        return str_ends_with(strtolower($host), '.'.$domain) && ! preg_match('/[\x00-\x20]/', $host);
    }

    public function isVerified(Shop $shop): bool
    {
        $sender = $shop->emailSender()->with('sendingDomain')->first();
        if (! $shop->active() || ! $sender || $sender->verification_status !== 'VERIFIED'
            || ! $sender->ownership_verified || ! $sender->sendingDomain) {
            return false;
        }
        try {
            $email = self::normalize($sender->sender_email);
        } catch (ValidationException) {
            return false;
        }
        if (! trim($sender->sender_name) || mb_strlen($sender->sender_name) > 100 || preg_match('/[\p{C}<>]/u', $sender->sender_name)) {
            return false;
        }

        if ($sender->sender_mode === 'SIGNATURE') {
            return $sender->provider_signature_id > 0 && $sender->provider_confirmed_at !== null;
        }

        return $sender->sender_mode === 'DOMAIN' && $sender->dkim_verified && $sender->return_path_verified
            && substr(strrchr($email, '@'), 1) === $sender->sendingDomain->domain && (bool) $sender->sendingDomain->provider_domain_id;
    }

    public function isVerificationFresh(Shop $shop): bool
    {
        $sender = $shop->emailSender()->first();

        return $this->isVerified($shop) && ! $sender->verification_refresh_failed_at
            && $sender->last_checked_at && $sender->last_checked_at->gte(now()->subMinutes(config('senders.verification_ttl_minutes')));
    }

    // Backwards-compatible strict eligibility; UI must use persisted state instead.
    public function ready(Shop $shop): bool
    {
        return $this->isVerificationFresh($shop);
    }

    public function statusLabel(Shop $shop): string
    {
        $sender = $shop->emailSender()->first();
        if (! $sender) {
            return 'Not configured';
        }
        if ($sender->verification_status === 'REMOVED') {
            return 'Disconnected';
        }
        if ($sender->verification_refresh_failed_at) {
            return $this->isVerified($shop) ? 'Verified — latest re-check temporarily unavailable' : 'Temporarily unable to re-check';
        }

        return $this->isVerified($shop) ? 'Verified' : 'Verification required';
    }

    public function revisionKey(Shop $shop): string
    {
        return $this->identityKey($shop, $this->identity($shop));
    }

    public function identityKey(Shop $shop, array $identity): string
    {
        return hash('sha256', json_encode(['merchant-only-v2', $shop->id, $identity]));
    }

    public function managedAddress(): string
    {
        $address = config('senders.managed_address') ?? config('mail.from.address');
        $domain = config('senders.managed_domain') ?? substr(strrchr((string) $address, '@') ?: '', 1);
        if (! Recipient::valid($address) || preg_match('/[\x00-\x20\x7f]/', $address)
            || ! is_string($domain) || ! str_contains($domain, '.')
            || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || strtolower(substr(strrchr($address, '@'), 1)) !== strtolower($domain)) {
            throw new EmailProviderException('CONFIGURATION');
        }

        return $address;
    }

    public function managedConfigured(): bool
    {
        try {
            $this->managedAddress();

            return ! app()->environment('production') || (config('mail.default') === 'postmark'
                && config('services.postmark.token') && config('services.postmark.token') !== 'POSTMARK_API_TEST');
        } catch (EmailProviderException) {
            return false;
        }
    }

    private function withReplyTo(Shop $shop, array $identity): array
    {
        $settings = $shop->settings()->first();
        foreach ([$settings?->reply_to_email, $settings?->support_email, $identity['email']] as $reply) {
            if (Recipient::valid($reply) && ! preg_match('/[\x00-\x20\x7f]/', $reply)) {
                return $identity + ['reply_to' => $reply];
            }
        }
        throw new EmailProviderException('CONFIGURATION');
    }

    public function configured(): bool
    {
        return config('mail.default') === 'postmark' && config('services.postmark.token') && config('services.postmark.account_token');
    }

    public function identity(Shop $shop, bool $test = false, bool $refresh = true): array
    {
        // Kept for old callers; neither boolean permits an application sender fallback.
        return $this->merchantIdentity($shop, $refresh);
    }

    public function merchantIdentity(Shop $shop, bool $refresh = true): array
    {
        if (app()->environment('production') && (! $this->configured() || config('services.postmark.token') === 'POSTMARK_API_TEST')) {
            throw new EmailProviderException('CONFIGURATION');
        }
        $sender = $shop->emailSender()->first();
        if ($refresh && $sender && $sender->verification_status !== 'REMOVED' && ($sender->verification_refresh_failed_at || ! $sender->last_checked_at || $sender->last_checked_at->lt(now()->subMinutes(config('senders.verification_ttl_minutes'))))) {
            try {
                $this->check($shop);
            } catch (EmailProviderException $e) {
                throw new EmailProviderException('SENDER_NOT_VERIFIED');
            }
        }
        if (! $this->ready($shop)) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }
        $sender = $shop->emailSender()->first();
        $name = $shop->settings()->first()?->store_display_name ?: $shop->store_name;
        // These addresses are reserved for system verification/diagnostics, even if saved as a merchant sender.
        if (in_array(strtolower($sender->sender_email), array_map(fn ($email) => strtolower((string) $email),
            [config('senders.managed_address'), config('mail.from.address')]), true)) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }
        $name = trim(preg_replace('/[\p{C}<>]+/u', ' ', (string) $name) ?? '');
        $name = mb_substr($name, 0, 100) ?: $sender->sender_name;

        return $this->withReplyTo($shop, ['email' => $sender->sender_email, 'name' => $name, 'revision' => $sender->revision, 'id' => $sender->id]);
    }

    public function systemIdentity(): array
    {
        if (! $this->managedConfigured()) {
            throw new EmailProviderException('CONFIGURATION');
        }

        return ['email' => $this->managedAddress(), 'name' => 'Dispute Guard'];
    }

    public function eligible(Shop $shop): bool
    {
        try {
            $this->merchantIdentity($shop);

            return true;
        } catch (EmailProviderException) {
            return false;
        }
    }

    private function validateSignature(array $signature, string $email, ?int $id = null): void
    {
        if (! is_int($signature['id'] ?? null) || $signature['id'] <= 0 || ($id !== null && $signature['id'] !== $id)
            || ($signature['email'] ?? null) !== $email || ! is_bool($signature['confirmed'] ?? null)) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }
    }

    private function refreshSignature(MerchantEmailSender $sender): MerchantEmailSender
    {
        try {
            $signature = $this->provider->getSenderSignature((int) $sender->provider_signature_id, $sender->sender_email);
            $this->validateSignature($signature, $sender->sender_email, $sender->provider_signature_id);
            $verified = $signature['confirmed'] && $sender->ownership_verified;
            $sender->update(['provider_confirmed_at' => $signature['confirmed'] ? now() : null,
                'verification_status' => $verified ? 'VERIFIED' : 'PENDING', 'verified_at' => $verified ? now() : null,
                'last_checked_at' => now(), 'verification_refresh_failed_at' => null]);
        } catch (EmailProviderException $e) {
            $sender->update(['verification_status' => 'PENDING', 'verified_at' => null,
                'provider_confirmed_at' => null, 'verification_refresh_failed_at' => now()]);
            throw $e;
        }

        return $sender->fresh('sendingDomain');
    }

    public function sendMailboxVerification(Shop $shop): void
    {
        $this->locked($shop, function () use ($shop) {
            abort_unless($shop->fresh()->active(), 403);
            $sender = $shop->emailSender()->firstOrFail();
            abort_unless($sender->sender_mode === 'SIGNATURE' && $sender->verification_status !== 'REMOVED', 422, 'Save a standard email sender first.');
            abort_if($sender->verification_sent_at?->gt(now()->subMinute()), 429, 'Wait a minute before requesting another verification email.');
            $sender->update(['verification_sent_at' => now()]);
            $system = $this->systemIdentity();
            $signature = $this->provider->getSenderSignature((int) $sender->provider_signature_id, $sender->sender_email);
            $this->validateSignature($signature, $sender->sender_email, $sender->provider_signature_id);
            if (! $signature['confirmed']) {
                $this->provider->resendSenderSignatureConfirmation($signature['id'], $sender->sender_email);
            }
            $token = bin2hex(random_bytes(32));
            $sender->update(['verification_token_hash' => hash('sha256', $token), 'verification_expires_at' => now()->addMinutes(15)]);
            // Fragment keeps the bearer token out of web access logs and HTTP referrers.
            $url = rtrim(config('app.url'), '/').'/sender-verification#'.$token;
            if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
                throw new EmailProviderException('CONFIGURATION');
            }
            $this->provider->send(['From' => $system['name'].' <'.$system['email'].'>', 'To' => $sender->sender_email,
                'Subject' => 'Verify your Dispute Guard sender mailbox',
                'TextBody' => 'Confirm that this mailbox may send customer follow-ups for Shopify store '.$shop->shop_domain.'. Only confirm if you manage this store. This link expires in 15 minutes: '.$url]);
        });
    }

    public function confirmMailbox(string $token): bool
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return false;
        }
        $hash = hash('sha256', $token);
        $sender = MerchantEmailSender::where('verification_token_hash', $hash)->first();
        if (! $sender || ! $sender->shop) {
            return false;
        }

        return $this->locked($sender->shop, fn () => DB::transaction(function () use ($sender, $hash) {
            $current = MerchantEmailSender::whereKey($sender->id)->lockForUpdate()->first();
            if (! $current || ! $current->shop->active() || $current->sender_mode !== 'SIGNATURE'
                || $current->verification_status === 'REMOVED' || ! hash_equals($hash, (string) $current->verification_token_hash)
                || ! $current->verification_expires_at || $current->verification_expires_at->lte(now())) {
                return false;
            }
            $current->update(['ownership_verified' => true, 'verification_token_hash' => null,
                'verification_expires_at' => null, 'last_checked_at' => null]);

            return true;
        }));
    }

    public function guard(Shop $shop, array $identity, bool $test, callable $send): mixed
    {
        return $this->locked($shop, function () use ($shop, $identity, $test, $send) {
            // Already holding the sender lock: refresh directly, without reacquiring it.
            if ($identity['id'] !== null && ! $this->isVerificationFresh($shop)) {
                $this->refresh($shop);
            }
            if (! $shop->fresh()->active() || $this->identity($shop, $test, false) !== $identity) {
                throw new EmailProviderException('SENDER_NOT_VERIFIED');
            }

            return $send();
        });
    }

    public function disconnect(Shop $shop): void
    {
        $this->locked($shop, function () use ($shop) {
            $shop->emailSender()->update(['verification_status' => 'REMOVED', 'verified_at' => null, 'dkim_verified' => false, 'return_path_verified' => false, 'ownership_verified' => false,
                'verification_token_hash' => null, 'verification_expires_at' => null, 'provider_confirmed_at' => null]);
        });
    }
}

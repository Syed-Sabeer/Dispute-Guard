# Merchant email sending (current architecture)

The filename is retained for existing links. This document supersedes the former managed-sender-first architecture.

## Standard: exact merchant email, no DNS

Customer follow-ups and Test Automation always use the current shop's verified business email. There is no application-owned From fallback for either path.

1. In Settings, confirm the store display name, sender email, support email and Reply-To.
2. Save the sender with Standard email verification. This creates a Postmark Sender Signature and initiates its confirmation process. Saving is not verification.
3. Select Send verification email. Complete both Postmark confirmation and the Dispute Guard mailbox ownership link.
4. The Dispute Guard message identifies the Shopify store being authorized. Confirm only if you manage that store.
5. Return to Shopify and select Check verification. The provider ID and exact normalized email must match, Postmark must report Confirmed=true, and this shop must have proven mailbox ownership.
6. Only then may Test Automation send or Settings activate automatic follow-ups. Test tools, global/merchant pauses, templates, billing and quota restrictions still apply.

Fresh shop-specific mailbox proof is required for both newly created and reused Postmark signatures. Account-level confirmation never transfers ownership between shops. Provider IDs come from the server, never merchant requests. Reconciliation uses exact email matching and at most ten pages of 500 signatures; malformed or missing data fails closed.

Mailbox links use a random 256-bit token. Only its SHA-256 hash is stored, it expires in 15 minutes, and it is consumed once under a sender lock and database transaction. Saving invalidates outstanding tokens. Email/mode changes clear ownership and provider confirmation and increment the revision. Disconnect/uninstall blocks verification. Resending is limited by per-shop route limits and a persisted one-minute cooldown; it rotates the token.

The public verification page needs no Shopify authentication. The token travels in the URL fragment, outside the HTTP request/access log, is removed from browser history on loading, and is submitted by an explicit CSRF-protected confirmation form. Responses use no-store/no-referrer. A link is a bearer credential: do not share it. Never enable request-body tracing for these endpoints.

## Advanced: exact merchant email with domain authentication

Existing DOMAIN senders keep their mode and proof through the additive migration. Advanced authentication requires current Postmark DKIM and Return-Path flags, matching public DNS, and a shop-specific ownership TXT record. Every shop must prove ownership separately. Standard senders need no DNS checks.

Changing email or mode requires new verification. A failing DOMAIN sender never falls back automatically. Select Standard explicitly and complete its mailbox verification to switch methods.

## Identity and transport

From uses the store display name and exact verified merchant email. Shopify store name and the valid saved sender name are name alternatives. Names are bounded and sanitized against control/header injection.

Reply-To precedence:
1. Valid merchant reply_to_email.
2. Valid merchant support_email.
3. The same verified merchant From email.

MerchantSenderService::merchantIdentity() serves both customer and test automation. It never falls back to systemIdentity(). Application-managed and MAIL_FROM addresses are reserved for system use and cannot become merchant From identities.

Verification freshness remains 15 minutes. Failed refresh invalidates eligibility. Missing, pending, stale, mismatched or uncertain verification blocks transport. Stored auto_email_enabled is preserved; a new activation request requires a verified sender. Dashboard explains the pause, and onboarding requires verified sender, support details and reviewed templates.

Live and Test Automation jobs carry a versioned hash of shop, exact From/name, Reply-To, sender ID and revision. The final guard serializes with sender edits and revalidates before transport. Changes cancel queued identities. Old managed/pre-versioned snapshots are rejected; old test jobs without snapshots are cancelled. Cancelled historical disputes never replay.

Pre-transport live cancellation releases reserved quota. SENT/UNKNOWN consume quota and uncertainty is never retried automatically. Recipient refetch, tenant isolation, shipping/reason rules, privacy, billing and queue claims remain unchanged.

## System sender configuration

No new environment variable names are required. Existing names are retained for compatibility:

    MAIL_MAILER=postmark
    POSTMARK_SERVER_TOKEN=
    POSTMARK_ACCOUNT_TOKEN=
    MANAGED_SENDER_ADDRESS=
    MANAGED_SENDER_DOMAIN=
    MAIL_FROM_ADDRESS=
    MAIL_FROM_NAME="Dispute Guard"
    CHARGEGUARD_TEST_MODE=true
    BILLING_ENABLED=false
    DISPUTEGUARD_PRELAUNCH=true

Account Token manages signatures/domains; Server Token submits email. Both are required in production. The application-owned sender must already be authenticated in the same Postmark account. It is used ONLY for mailbox verification and explicit operator diagnostics. Legacy MAIL_FROM supplies system configuration when managed settings are absent, never customer/test From. Explicit invalid managed configuration fails health checks.

Health-check requires both tokens, Postmark mailer, a valid system sender, required migrations and existing production safeguards. It makes no provider calls. HTTP uses bounded timeouts, disabled redirects, strict response validation and sanitized exceptions.

References: [Postmark Sender signatures API](https://postmarkapp.com/developer/api/signatures-api) and [error codes](https://postmarkapp.com/developer/api/overview). Creating a signature can send a Postmark confirmation email; automated tests fake these APIs.

## Safe production deployment

Keep CHARGEGUARD_TEST_MODE=true, BILLING_ENABLED=false and DISPUTEGUARD_PRELAUNCH=true throughout this release and controlled validation. Do not overwrite production .env, rotate APP_KEY, run seeders or destructive migrations.

Back up database and APP_KEY through your hosting backup procedure. Pause scheduler/queue cron and let in-flight workers finish before deploying. Upload code and run from the application directory using PHP 8.2+:

    php artisan down
    composer install --no-dev --optimize-autoloader
    php artisan config:clear
    php artisan chargeguard:repair-quota-migration
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan chargeguard:health-check
    php artisan queue:restart
    php artisan up

Stop if any command fails. Keep workers paused until schema, caches and health checks succeed, then restore existing cron. No customer email is needed to validate deployment. Controlled Test Automation requires a verified merchant sender, explicitly enabled test tools and an operator-owned recipient. No real messages were sent during implementation.

New additive migration: 2026_09_12_000001_add_merchant_sender_signatures.php. Existing migrations remain unchanged. The recovery command handles the reported interrupted quota migration using DATETIME for the missing table. It retains previously added columns and records the old migration only after expected columns/constraints exist. It never resets allowance, counters, periods or delivery rows. If the old migration is already recorded, it does nothing.

On a fresh legacy MySQL installation, run migrations; if the quota migration fails, use this recovery command and resume migrate. Do not roll back sender columns after deploying jobs that depend on them. Preserve queue and deduplication records. Old identity snapshots intentionally become ineligible; review cancellations instead of replaying them.

## Validation

Run php artisan test, php scripts/test-mysql.php, php vendor/bin/pint --test and configuration/route/view cache checks. The MySQL helper uses an isolated local test database. No production data is used.

Coverage includes creation/reconciliation, per-shop proof, token secrecy/expiry/reuse, exact From/Reply-To, no fallback, verified test transport, revision changes, cancellation/release, provider failures and advanced DNS. Existing billing, quota concurrency, Shopify auth, privacy, deduplication and shipping tests remain.

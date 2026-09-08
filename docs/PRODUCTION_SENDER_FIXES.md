# Production sender fixes and validation report

## A. Files changed

Production configuration: `shopify.app.toml`, `.env.example`. Sender logic: `MerchantSenderService`, `PostmarkEmailProvider`, `SenderDnsVerifier`, `EmailProviderException`, `MerchantEmailSender`. Queue snapshots: `DisputeProcessor`, `AutomationDelivery`, `SendDisputeCustomerEmail`. UI: dashboard and settings/edit, settings/onboarding, settings/email-sender views, `SettingsController`. Operations: `HealthCheck`, new `PostmarkSmokeTest` command. Tests: `MerchantSenderTest`, `PostmarkSmokeTest`, `ProductionModeTest`. Documentation: this report and deployment, readiness, sender and architecture guides. One new additive migration is described below.

## B. Shopify URL changes

Application URL is `https://disputeguard.deveoninc.com`; the authentication redirect is `https://disputeguard.deveoninc.com/auth/patch-id-token`. Client ID, embedded mode, three scopes and six webhook subscriptions are preserved. Local `.env` and its credentials were not changed.

## C–D. Verified state and freshness

`isVerified()` validates persisted VERIFIED status, all three proof flags, active shop, valid name/email, domain match and provider domain ID. It ignores age. `isVerificationFresh()` additionally requires a successful check within 15 minutes and no subsequent refresh failure. `ready()` remains a strict freshness alias for compatibility; merchant UI uses persisted state.

After 15 minutes, dashboard, settings, onboarding and sender settings still show Verified. Rendering does not call Postmark or DNS. The next automatic eligibility check refreshes before sending. The final sender lock also refreshes if freshness expired after composition, then compares the identity again. No merchant must manually verify every 15 minutes.

## E–H. Failure and recovery behavior

Postmark timeout/5xx and DNS resolver timeout/SERVFAIL are `TRANSIENT_VERIFICATION_FAILURE`. They block that email, retain the previous verification evidence, record only `verification_refresh_failed_at`, and preserve `auto_email_enabled`. The UI shows the last recheck unavailable and automation temporarily paused when appropriate. An explicit failed recheck also invalidates otherwise fresh cached verification.

On the next eligible attempt, refresh runs again. Successful verification clears the error marker and restores eligibility without changing merchant preferences. A dispute already cancelled or moved to manual review is not replayed automatically.

Successful checks finding missing/mismatched DKIM, Return-Path or ownership records set PENDING and block merchant From. Confirmed negative evidence is retained even if a later lookup fails. Provider domain-not-found also revokes local eligibility. Invalid configuration blocks sending with sanitized support guidance. None of these runtime checks turns off the stored automation preference. Actual DNS loss can recover through an explicit check or the next check after the TTL; outage recovery is eligible for the next attempt immediately.

Changing to a different domain, disconnecting and uninstalling continue to disable automation as deliberate lifecycle actions. No code automatically enables automation. Global safety mode, merchant pause, onboarding, billing, current recipient/shipping checks, redaction and delivery claims still apply.

## I. Operator smoke-test command

```sh
php artisan chargeguard:postmark-smoke-test YOUR_OWN_EMAIL
```

Production prompts for confirmation, defaulting to no. An operator may explicitly approve one send non-interactively with `--force`. The recipient argument is mandatory, must contain exactly one valid address and is never fetched from orders. This command uses the application fallback From through the existing provider abstraction; it does not require merchant verification or the domain-management account token. It does require a real server token and an authenticated fallback sender.

The subject starts `[TEST]`; the body identifies a Dispute Guard production email-provider smoke test. There is no CC/BCC, attachment, dispute, delivery row or merchant state mutation. Output contains only acceptance and MessageID, or sanitized failure. An ambiguous result prints UNKNOWN and does not retry. Check provider records before deciding whether another invocation is appropriate. Acceptance is not proof of inbox delivery.

## J. Health check

`chargeguard:health-check` stays local/read-only: no provider send or domain creation. Production checks require the Postmark mailer, real server token, account token, safe fallback name/address and `MERCHANT_SENDER_REQUIRED=true`, in addition to existing production checks. Optional fallback remains a supported application mode, but fails the required-sender production preflight. No optional connectivity command was added.

## K. Migration

`2026_09_09_000002_separate_sender_verification_refresh.php` adds a nullable failure timestamp to merchant senders and a nullable `sender_identity_hash` to automation deliveries. This durably separates recheck errors from DNS evidence and records the queued sender revision without storing a name, email or secret in the snapshot. Previous migrations and records are untouched. The migration was applied locally.

Legacy queued rows without a snapshot are conservatively cancelled to manual review, never silently rebound to the current sender. Pause workers before deploying and review any such pending work. Same-domain identity changes while queued now cancel mail, even though domain verification remains valid. The existing immediate pre-send identity guard remains in place.

## L. Validation

Run `php artisan test`, `php scripts/test-mysql.php` (isolated local database), Pint, PHP syntax checks and isolated config/route/view cache checks. Regression coverage includes stale production UI, refresh success/failure, provider and DNS recovery, real record loss, sender changes while queued, final-guard expiry, smoke-test confirmation/recipient/output safety, and all prior Shopify, shipping, privacy, billing and uncertain-send protections. All provider responses in automated tests are mocked; no real email was sent during implementation. Final validation: 162 tests and 730 assertions pass on both SQLite and isolated local MySQL. Pint, syntax checks for all 15 changed/new PHP files, and config/route/Blade cache checks pass.

## M–N. Deployment and environment

Back up database and APP_KEY. Pause cron workers while code and schema are updated. Keep existing Shopify/DB/billing credentials and use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://disputeguard.deveoninc.com
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
MAIL_MAILER=postmark
POSTMARK_SERVER_TOKEN=
POSTMARK_ACCOUNT_TOKEN=
MERCHANT_SENDER_REQUIRED=true
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Dispute Guard"
CHARGEGUARD_TEST_MODE=true
CHARGEGUARD_DEMO_MODE=false
DISPUTEGUARD_ENABLE_TEST_TOOLS=false
BILLING_ENABLED=false
DISPUTEGUARD_PRELAUNCH=true
DISPUTEGUARD_PRELAUNCH_SHOPS=
BILLING_ENFORCED=true
```

Fill server-side secrets, your authenticated fallback From and the exact invited-shop allowlist. Empty allowlist fails closed. Billing-disabled prelaunch must remain private.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
php artisan queue:restart
```

Resume the documented cron worker only after these succeed. Do not run `migrate:fresh`, restore old delivery claims, or automatically resend uncertain messages.

## O. Shopify deployment

From the repository linked to the intended Shopify app, verify its identity/configuration, then run:

```sh
shopify app deploy
```

This publishes the Shopify configuration; it does not upload Laravel code. This command was not run by the implementation. A development CLI session can replace development URLs, so check the intended production configuration before publishing.

## P. Exact production validation procedure

1. Configure both Postmark tokens and a verified fallback sender. Keep `CHARGEGUARD_TEST_MODE=true` and `DISPUTEGUARD_ENABLE_TEST_TOOLS=false`.
2. Run config cache and the health check above.
3. Invoke the smoke-test command once with your own address and confirm. Check receipt, `[TEST]` subject, fallback From and matching Postmark MessageID. Never blindly retry UNKNOWN.
4. In Shopify Admin configure one merchant sender. Publish its per-shop ownership TXT, provider DKIM TXT and Return-Path CNAME exactly as shown. Click Check verification and confirm Verified.
5. Wait more than 15 minutes, reload dashboard/onboarding and confirm they still show Verified. Click Check verification to exercise the refresh while global customer mail remains blocked. Normal future sending performs this refresh automatically.
6. In tests/staging simulate provider and DNS outages. Confirm no customer send, a refresh-error indication, and unchanged `auto_email_enabled`. Restore services and confirm eligibility recovers without re-enabling the preference. Do not create an outage in production for this test.
7. After smoke tests and the existing hosting/Shopify/privacy/billing checks pass, explicitly set `CHARGEGUARD_TEST_MODE=false`, cache configuration and restart workers. Merchant automation remains under merchant control. Only eligible new disputes can send.

## Q. Remaining blockers

Live Postmark receipt/DNS verification, production hosting checks and Shopify publishing remain operator steps. Existing documented Laravel security advisories remain unresolved public-launch blockers. No account credentials were changed, no real provider send was made and no Shopify deployment was published. DNS caches and the 15-minute freshness window still bound detection latency. Already accepted messages cannot be recalled.

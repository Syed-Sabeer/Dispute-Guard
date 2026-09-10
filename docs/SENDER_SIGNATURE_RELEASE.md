# Sender signature release report

Implemented merchant-only sending. Both live customer follow-ups and Test Automation use the exact verified merchant email and store display name. Reply-To uses merchant reply-to, support, then that same verified sender. Neither path has a managed/application sender fallback.

Standard flow: save business email; Postmark creates/reconciles the exact Sender Signature; request mailbox verification; confirm the shop-specific Dispute Guard link and Postmark confirmation; select Check verification. Every shop proves ownership independently, including reused confirmed account signatures. Standard setup requires no DNS. Existing advanced DOMAIN senders retain their DKIM, Return-Path and per-shop TXT proof.

Missing, pending, stale/failed, mismatched or disconnected senders block customer/test transport and new activation. Automation preference is preserved. Live pre-transport cancellation releases quota; uncertain delivery still consumes quota and never retries automatically. Test jobs now also require queue identity snapshots. Old snapshots cannot silently switch to a new identity.

System sending is limited to mailbox verification and explicit internal operator diagnostics. No real emails or provider management requests were made during implementation.

## Database and environment

One new additive migration: 2026_09_12_000001_add_merchant_sender_signatures.php.

Old migrations were not changed. The recovery command chargeguard:repair-quota-migration safely completes the reported partial quota migration with DATETIME period boundaries, preserving existing rows and values. It validates expected constraints before recording completion, and does nothing when the migration is already recorded. Recovery was tested against isolated MySQL as well as SQLite.

No new environment variable names are needed. Existing POSTMARK_ACCOUNT_TOKEN is now required in production alongside POSTMARK_SERVER_TOKEN and MAIL_MAILER=postmark. MANAGED_SENDER_ADDRESS / MANAGED_SENDER_DOMAIN and legacy MAIL_FROM identify only the system verification sender. Production .env, productionenv.md, productionenv.env and APP_KEY were not modified. CHARGEGUARD_TEST_MODE=true, BILLING_ENABLED=false and private prelaunch remain the controlled-validation requirements.

## Executed validation

| Check | Result |
| --- | --- |
| php artisan test --compact | 239 passed, 2 MySQL-only skips; 1,310 assertions |
| php scripts/test-mysql.php | 241 passed; 1,324 assertions |
| MySQL quota concurrency | Passed |
| MySQL partial-migration recovery | Passed; existing rows preserved |
| php vendor/bin/pint --test | Passed across repository |
| php artisan config:cache | Passed |
| php artisan route:cache | Passed |
| php artisan view:cache | Passed |

Temporary config/route verification caches were removed. AppServiceProvider changed only for Pint import formatting. Existing test fixtures now represent verified merchant senders; tests for missing senders explicitly omit them. Former fallback assertions were replaced with fail-closed assertions; advanced DNS, privacy, deduplication, quota, billing, authentication and shipping regressions remain.

## Deployment and remaining work

No production deployment or real mailbox validation was performed. Upload the release, back up database/APP_KEY, pause scheduler/queue cron, and let in-flight workers finish. From the production application directory, using the host's PHP 8.2+ binary:

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

Stop on any failure. Resume existing cron only after schema/configuration/health checks succeed. Do not enable billing or disable global safety mode. Both server-side Postmark tokens and an authenticated system verification sender must be available; merchants must verify their own exact mailboxes. Keep historical cancelled/quota-blocked disputes in manual review. Never replay old queues or reset production data.

For full security behavior and setup details see [Merchant email sending](MANAGED_SENDING.md).

## Files created
- app/Console/Commands/RepairQuotaMigration.php
- app/Http/Controllers/SenderMailboxVerificationController.php
- database/migrations/2026_09_12_000001_add_merchant_sender_signatures.php
- docs/SENDER_SIGNATURE_RELEASE.md
- resources/views/settings/sender-form.blade.php
- resources/views/settings/verify-mailbox.blade.php
- storage/framework/lsp-c0fab73aed3fc742.php
- tests/Feature/QuotaMigrationRecoveryTest.php
- tests/Feature/SenderSignatureTest.php

## Files modified

- .env.example
- app/Console/Commands/HealthCheck.php
- app/Console/Commands/PostmarkSmokeTest.php
- app/Exceptions/EmailProviderException.php
- app/Exceptions/Handler.php
- app/Http/Controllers/DashboardController.php
- app/Http/Controllers/EmailSenderController.php
- app/Http/Controllers/SettingsController.php
- app/Http/Controllers/TestAutomationController.php
- app/Http/Requests/UpdateShopSettingsRequest.php
- app/Jobs/SendTestAutomationEmail.php
- app/Mail/DisputeCustomerMail.php
- app/Models/MerchantEmailSender.php
- app/Providers/AppServiceProvider.php
- app/Services/Disputes/AutomationResolver.php
- app/Services/Email/EmailProviderInterface.php
- app/Services/Email/MerchantSenderService.php
- app/Services/Email/PostmarkEmailProvider.php
- config/senders.php
- docs/ARCHITECTURE.md
- docs/MANAGED_SENDING.md
- docs/MERCHANT_SENDING_DOMAINS.md
- docs/PRODUCTION_DEPLOYMENT.md
- docs/PRODUCTION_READINESS_REPORT.md
- docs/PRODUCTION_SENDER_FIXES.md
- docs/SUBSCRIPTION_QUOTAS.md
- README.md
- resources/views/billing/plans.blade.php
- resources/views/dashboard/index.blade.php
- resources/views/settings/edit.blade.php
- resources/views/settings/email-sender.blade.php
- resources/views/settings/onboarding.blade.php
- routes/merchant.php
- routes/web.php
- tests/Feature/DashboardAutomationStatusTest.php
- tests/Feature/ManagedSenderTest.php
- tests/Feature/MerchantSenderTest.php
- tests/Feature/PostmarkSmokeTest.php
- tests/Feature/ProductionModeTest.php
- tests/Fixtures/ShopifyData.php
- website/index.html

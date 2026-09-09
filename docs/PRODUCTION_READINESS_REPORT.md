Current sender architecture and validation procedure: [Managed sending](MANAGED_SENDING.md). Merchant DNS is optional.

# Dispute Guard production readiness report

## A. Files changed

- Configuration: `.env.example`, `config/chargeguard.php`, `config/mail.php`, `config/shopify.php`, `shopify.app.toml`.
- Access and billing: `app/Services/DeploymentMode.php` (new), `app/Http/Middleware/EnsureTestToolsEnabled.php` (new), `app/Http/Middleware/AuthenticateShopify.php`, `app/Services/Billing/ShopifyAppPricingService.php`, `routes/merchant.php`.
- Controllers: `app/Http/Controllers/BillingController.php`, `DashboardController.php`, `DisputeController.php`, `EmailLogController.php`, `EmailTemplateController.php`, `SettingsController.php`.
- Validation/jobs/logging: `app/Http/Requests/UpdateShopSettingsRequest.php`, `app/Jobs/SendDisputeCustomerEmail.php`, `app/Jobs/SendTestAutomationEmail.php`, `app/Exceptions/Handler.php`.
- UI: `resources/views/layouts/app.blade.php`, `dashboard/index.blade.php`, `disputes/index.blade.php`, `disputes/table.blade.php`, `email-logs/index.blade.php`, `settings/edit.blade.php`, `settings/onboarding.blade.php`, `templates/edit.blade.php`, `templates/index.blade.php`, `public/js/chargeguard.js`.
- Operations/tests: `app/Console/Commands/HealthCheck.php`, `tests/Feature/ProductionModeTest.php` (new), `docs/PRODUCTION_DEPLOYMENT.md` (new), `docs/CPANEL_DEPLOYMENT.md`, this report.

## B. Production behavior

Dispute Guard branding replaces the legacy default, including sender display name. Internal class/config/table names remain. Dashboard recent disputes and metrics use real Shopify records; production list/detail access excludes demo/synthetic records. Currency risk totals remain separated. Production navigation hides disabled billing/test tools. Empty states no longer request test data. Template preview uses labelled fields rather than a fake customer/order in production.

The dashboard shows automation state without a TEST MODE banner; global and merchant delivery pauses still prevent sending. Settings warns before activation. Onboarding completes when validated support details and reviewed templates are saved, without enabling automation or requiring test mail. Production test endpoints are 404 unless explicitly enabled; queued test mail is cancelled when disabled. Explicitly enabled test tools permit inspection of their labelled email logs.

Billing implementation remains intact. Private prelaunch skips Partner lookups only for explicitly allowlisted shops. Other shops fail closed. Normal billing restores strict subscription enforcement. Existing Shopify verification, privacy, encryption, rendering sanitization, sender/Reply-To separation, deduplication, stale-state and email provider uncertainty safeguards remain.

Exception reporting logs class/file/line rather than potentially sensitive messages or request objects. No live customer emails were sent during this work. Local `.env` and merchant database records were not changed.

## Câ€“D. Environment variables and required values

New: `BILLING_ENABLED`, `DISPUTEGUARD_PRELAUNCH`, `DISPUTEGUARD_PRELAUNCH_SHOPS`, `DISPUTEGUARD_ENABLE_TEST_TOOLS`. Code defaults are billing on, prelaunch off, empty allowlist, tools enabled only in local/development/testing when unset.

For this private deployment use billing false, prelaunch true, an exact invited-shop allowlist, test tools false, demo false, APP_ENV production, APP_DEBUG false, permanent HTTPS APP_URL, database queue and secure SameSite=None cookies. Configure real DB/Shopify/email provider credentials and authenticated sender. Keep the global email safety switch true during setup; set false only when ready. The full copyable configuration is in [Production deployment](PRODUCTION_DEPLOYMENT.md#configuration) and `.env.example`.

## E. Database

No migrations or indexes added. Existing shop-domain uniqueness, tenant/dispute uniqueness, status/date lookup indexes, webhook ID uniqueness, email log indexes and delivery deduplication constraints were retained. No data was deleted or seeded.

## F. Shopify configuration

Name: Dispute Guard. Embedded mode retained. Scopes: read_customers, read_orders, read_shopify_payments_disputes. Webhook version aligned with tested GraphQL 2026-07. Six explicit subscriptions: disputes/create, disputes/update, app/uninstalled, customers/data_request, customers/redact, shop/redact. Compliance topics use compliance_topics. App root and auth patch URLs use a deliberately invalid production-domain placeholder pending the real domain. Linked client ID retained. No Shopify configuration was published.

## Gâ€“H. cPanel commands and cron

Use the host's verified PHP 8.2+ binary:

```sh
cd /home/USER/disputeguard
/path/to/php /path/to/composer install --no-dev --optimize-autoloader
/path/to/php artisan migrate --force
/path/to/php artisan config:cache
/path/to/php artisan route:cache
/path/to/php artisan view:cache
/path/to/php artisan chargeguard:health-check
```

Generate APP_KEY only for a new empty installation, never an update. Public storage link is optional and unnecessary for the current embedded screens. Document root must be `public`, with writable private storage/cache.

```cron
* * * * * cd /home/USER/disputeguard && /path/to/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/disputeguard && /path/to/php artisan queue:work database --stop-when-empty --max-time=55 --tries=1 --timeout=50 >> /dev/null 2>&1
```

Existing per-job preparation retry limits override the worker default; no uncertain email provider delivery is retried automatically. See the deployment guide for optional flock, runtime limits, monitoring and rollback.

## I. Verification

- Full `php artisan test`: **99 passed, 471 assertions** (88 existing plus 11 production regression tests).
- Pint changed-file checks: pass.
- JavaScript syntax: pass.
- Configuration cache, route cache and Blade compilation: pass. Temporary verification config/route caches removed afterward.
- Composer audit: not clean; Laravel framework advisories remain (see blockers). Tests and compilation are local verification, not a cPanel deployment or authenticated production browser certification.

## Jâ€“K. Remaining blockers and manual deployment work

Resolve Laravel's reported security advisories before public launch. Provide permanent domain, hosting/database, production app credentials/permissions, exact private shop allowlist and verified email provider domain. Follow all 23 ordered steps in [Production deployment](PRODUCTION_DEPLOYMENT.md#deployment-order): upload, PHP/DB/env, install/migrate/cache, cron, HTTPS, Shopify URLs/deploy, health-check, Admin authentication, real order access, optional controlled operator email, queue/webhook/privacy checks, then disable tools. No actual cPanel/DNS/Shopify release was performed. Back up APP_KEY/database and preserve delivery claims on rollback.

## L. Later billing activation

Configure real Shopify App Pricing plans/items and Partner credentials/handles. Set BILLING_ENABLED=true and BILLING_ENFORCED=true, set DISPUTEGUARD_PRELAUNCH=false and clear the allowlist. Cache config/restart workers, health-check, then verify both subscribed and unsubscribed stores. Complete this before paid public launch.

## M. Live customer automation

After operational validation, set CHARGEGUARD_TEST_MODE=false, cache config/restart workers, leave demo/test tools false. Each merchant confirms support/Reply-To and templates, turns off its delivery pause, and explicitly activates automatic emails. No install or deployment script enables automation. Only eligible new disputes can send; unknown, unsupported, stale, duplicate, inactive, paused or redacted cases stay blocked.

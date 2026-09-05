# Implementation delivery

## 1. Architecture

Laravel 10/PHP 8.2 with isolated Shopify services, verified embedded App Home, encrypted offline token bundles, tenant-scoped merchant screens, 20 safe email templates, conservative fulfillment classification, durable database webhook jobs, one initial delivery per dispute, SMTP uncertainty handling, Partner API App Pricing verification, and privacy export/redaction workflows.

See [ARCHITECTURE.md](ARCHITECTURE.md) for exact guarantees, concurrency boundaries, retention, and limitations.

## 2. Files created

- [app/Console/Commands/HealthCheck.php](../app/Console/Commands/HealthCheck.php)
- [app/Console/Commands/MaintainChargeGuard.php](../app/Console/Commands/MaintainChargeGuard.php)
- [app/Console/Commands/SeedMissingTemplates.php](../app/Console/Commands/SeedMissingTemplates.php)
- [app/Console/Commands/ShopifyConfig.php](../app/Console/Commands/ShopifyConfig.php)
- [app/Console/Commands/SyncDispute.php](../app/Console/Commands/SyncDispute.php)
- [app/Enums/AutomationStatus.php](../app/Enums/AutomationStatus.php)
- [app/Enums/DisputeReason.php](../app/Enums/DisputeReason.php)
- [app/Enums/DisputeStatus.php](../app/Enums/DisputeStatus.php)
- [app/Enums/OrderShippingState.php](../app/Enums/OrderShippingState.php)
- [app/Exceptions/ShopifyApiException.php](../app/Exceptions/ShopifyApiException.php)
- [app/Http/Controllers/BillingController.php](../app/Http/Controllers/BillingController.php)
- [app/Http/Controllers/DashboardController.php](../app/Http/Controllers/DashboardController.php)
- [app/Http/Controllers/DisputeController.php](../app/Http/Controllers/DisputeController.php)
- [app/Http/Controllers/EmailLogController.php](../app/Http/Controllers/EmailLogController.php)
- [app/Http/Controllers/EmailTemplateController.php](../app/Http/Controllers/EmailTemplateController.php)
- [app/Http/Controllers/MerchantController.php](../app/Http/Controllers/MerchantController.php)
- [app/Http/Controllers/PrivacyRequestController.php](../app/Http/Controllers/PrivacyRequestController.php)
- [app/Http/Controllers/SettingsController.php](../app/Http/Controllers/SettingsController.php)
- [app/Http/Controllers/ShopifyAppController.php](../app/Http/Controllers/ShopifyAppController.php)
- [app/Http/Controllers/ShopifyWebhookController.php](../app/Http/Controllers/ShopifyWebhookController.php)
- [app/Http/Controllers/TestAutomationController.php](../app/Http/Controllers/TestAutomationController.php)
- [app/Http/Middleware/AuthenticateShopify.php](../app/Http/Middleware/AuthenticateShopify.php)
- [app/Http/Middleware/EmbeddedCsrf.php](../app/Http/Middleware/EmbeddedCsrf.php)
- [app/Http/Middleware/EnsureActiveSubscription.php](../app/Http/Middleware/EnsureActiveSubscription.php)
- [app/Http/Middleware/LocalDemo.php](../app/Http/Middleware/LocalDemo.php)
- [app/Http/Requests/MerchantRequest.php](../app/Http/Requests/MerchantRequest.php)
- [app/Http/Requests/SendTestAutomationRequest.php](../app/Http/Requests/SendTestAutomationRequest.php)
- [app/Http/Requests/UpdateEmailTemplateRequest.php](../app/Http/Requests/UpdateEmailTemplateRequest.php)
- [app/Http/Requests/UpdateShopSettingsRequest.php](../app/Http/Requests/UpdateShopSettingsRequest.php)
- [app/Jobs/ProcessDisputeCreated.php](../app/Jobs/ProcessDisputeCreated.php)
- [app/Jobs/ProcessDisputeUpdated.php](../app/Jobs/ProcessDisputeUpdated.php)
- [app/Jobs/ProcessPrivacyWebhook.php](../app/Jobs/ProcessPrivacyWebhook.php)
- [app/Jobs/QueuedJob.php](../app/Jobs/QueuedJob.php)
- [app/Jobs/ResyncDispute.php](../app/Jobs/ResyncDispute.php)
- [app/Jobs/SendDisputeCustomerEmail.php](../app/Jobs/SendDisputeCustomerEmail.php)
- [app/Jobs/SendTestAutomationEmail.php](../app/Jobs/SendTestAutomationEmail.php)
- [app/Mail/DisputeCustomerMail.php](../app/Mail/DisputeCustomerMail.php)
- [app/Models/AutomationDelivery.php](../app/Models/AutomationDelivery.php)
- [app/Models/Dispute.php](../app/Models/Dispute.php)
- [app/Models/EmailLog.php](../app/Models/EmailLog.php)
- [app/Models/EmailTemplate.php](../app/Models/EmailTemplate.php)
- [app/Models/PrivacyRequest.php](../app/Models/PrivacyRequest.php)
- [app/Models/Shop.php](../app/Models/Shop.php)
- [app/Models/ShopSetting.php](../app/Models/ShopSetting.php)
- [app/Models/TenantModel.php](../app/Models/TenantModel.php)
- [app/Models/WebhookEvent.php](../app/Models/WebhookEvent.php)
- [app/Policies/TenantPolicy.php](../app/Policies/TenantPolicy.php)
- [app/Services/Billing/BillingServiceInterface.php](../app/Services/Billing/BillingServiceInterface.php)
- [app/Services/Billing/ShopifyAppPricingService.php](../app/Services/Billing/ShopifyAppPricingService.php)
- [app/Services/CurrentShop.php](../app/Services/CurrentShop.php)
- [app/Services/Disputes/AutomationResolver.php](../app/Services/Disputes/AutomationResolver.php)
- [app/Services/Disputes/DisputeProcessor.php](../app/Services/Disputes/DisputeProcessor.php)
- [app/Services/Email/DefaultEmailTemplateFactory.php](../app/Services/Email/DefaultEmailTemplateFactory.php)
- [app/Services/Email/EmailComposer.php](../app/Services/Email/EmailComposer.php)
- [app/Services/Email/Recipient.php](../app/Services/Email/Recipient.php)
- [app/Services/Email/TemplateRenderer.php](../app/Services/Email/TemplateRenderer.php)
- [app/Services/Orders/OrderShippingStateResolver.php](../app/Services/Orders/OrderShippingStateResolver.php)
- [app/Services/Privacy/PrivacyService.php](../app/Services/Privacy/PrivacyService.php)
- [app/Services/Shopify/ShopifyAppService.php](../app/Services/Shopify/ShopifyAppService.php)
- [app/Services/Shopify/ShopifyDisputeService.php](../app/Services/Shopify/ShopifyDisputeService.php)
- [app/Services/Shopify/ShopifyGraphQLClient.php](../app/Services/Shopify/ShopifyGraphQLClient.php)
- [app/Services/Shopify/ShopifyOrderService.php](../app/Services/Shopify/ShopifyOrderService.php)
- [app/Services/Shopify/ShopifyRequestVerifier.php](../app/Services/Shopify/ShopifyRequestVerifier.php)
- [app/Services/Shopify/ShopifyShopService.php](../app/Services/Shopify/ShopifyShopService.php)
- [config/chargeguard.php](../config/chargeguard.php)
- [config/shopify.php](../config/shopify.php)
- [database/factories/DisputeFactory.php](../database/factories/DisputeFactory.php)
- [database/factories/EmailLogFactory.php](../database/factories/EmailLogFactory.php)
- [database/factories/EmailTemplateFactory.php](../database/factories/EmailTemplateFactory.php)
- [database/factories/ShopFactory.php](../database/factories/ShopFactory.php)
- [database/migrations/2026_09_05_000001_create_chargeguard_tables.php](../database/migrations/2026_09_05_000001_create_chargeguard_tables.php)
- [database/seeders/DemoDataSeeder.php](../database/seeders/DemoDataSeeder.php)
- [docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md)
- [docs/CPANEL_DEPLOYMENT.md](../docs/CPANEL_DEPLOYMENT.md)
- [docs/SHOPIFY_SETUP.md](../docs/SHOPIFY_SETUP.md)
- [docs/TESTING.md](../docs/TESTING.md)
- [public/js/chargeguard.js](../public/js/chargeguard.js)
- [resources/graphql/active-subscription.graphql](../resources/graphql/active-subscription.graphql)
- [resources/graphql/dispute.graphql](../resources/graphql/dispute.graphql)
- [resources/graphql/order.graphql](../resources/graphql/order.graphql)
- [resources/graphql/shop.graphql](../resources/graphql/shop.graphql)
- [resources/views/billing/index.blade.php](../resources/views/billing/index.blade.php)
- [resources/views/dashboard/index.blade.php](../resources/views/dashboard/index.blade.php)
- [resources/views/disputes/index.blade.php](../resources/views/disputes/index.blade.php)
- [resources/views/disputes/show.blade.php](../resources/views/disputes/show.blade.php)
- [resources/views/disputes/table.blade.php](../resources/views/disputes/table.blade.php)
- [resources/views/email-logs/index.blade.php](../resources/views/email-logs/index.blade.php)
- [resources/views/email-logs/show.blade.php](../resources/views/email-logs/show.blade.php)
- [resources/views/emails/dispute.blade.php](../resources/views/emails/dispute.blade.php)
- [resources/views/errors/shopify.blade.php](../resources/views/errors/shopify.blade.php)
- [resources/views/landing.blade.php](../resources/views/landing.blade.php)
- [resources/views/layouts/app.blade.php](../resources/views/layouts/app.blade.php)
- [resources/views/layouts/pagination.blade.php](../resources/views/layouts/pagination.blade.php)
- [resources/views/settings/edit.blade.php](../resources/views/settings/edit.blade.php)
- [resources/views/settings/onboarding.blade.php](../resources/views/settings/onboarding.blade.php)
- [resources/views/settings/privacy.blade.php](../resources/views/settings/privacy.blade.php)
- [resources/views/templates/edit.blade.php](../resources/views/templates/edit.blade.php)
- [resources/views/templates/index.blade.php](../resources/views/templates/index.blade.php)
- [resources/views/test-automation/index.blade.php](../resources/views/test-automation/index.blade.php)
- [routes/demo.php](../routes/demo.php)
- [routes/merchant.php](../routes/merchant.php)
- [routes/shopify.php](../routes/shopify.php)
- [scripts/shopify-serve.php](../scripts/shopify-serve.php)
- [scripts/test-mysql.php](../scripts/test-mysql.php)
- [shopify.app.toml](../shopify.app.toml)
- [shopify.web.toml](../shopify.web.toml)
- [tests/Feature/AutomationTest.php](../tests/Feature/AutomationTest.php)
- [tests/Feature/MerchantTest.php](../tests/Feature/MerchantTest.php)
- [tests/Feature/SafetyRegressionTest.php](../tests/Feature/SafetyRegressionTest.php)
- [tests/Feature/ShopifyApiBillingTest.php](../tests/Feature/ShopifyApiBillingTest.php)
- [tests/Feature/WebhookPrivacyTest.php](../tests/Feature/WebhookPrivacyTest.php)
- [tests/Fixtures/ShopifyData.php](../tests/Fixtures/ShopifyData.php)
- [tests/Unit/ShippingStateResolverTest.php](../tests/Unit/ShippingStateResolverTest.php)
- [tests/Unit/TemplateRendererTest.php](../tests/Unit/TemplateRendererTest.php)

## 3. Files modified

- [.env.example](../.env.example)
- [.gitignore](../.gitignore)
- [composer.json](../composer.json)
- [composer.lock](../composer.lock)
- [package.json](../package.json)
- [phpunit.xml](../phpunit.xml)
- [README.md](../README.md)
- [app/Console/Kernel.php](../app/Console/Kernel.php)
- [app/Exceptions/Handler.php](../app/Exceptions/Handler.php)
- [app/Http/Kernel.php](../app/Http/Kernel.php)
- [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php)
- [app/Providers/RouteServiceProvider.php](../app/Providers/RouteServiceProvider.php)
- [config/mail.php](../config/mail.php)
- [config/queue.php](../config/queue.php)
- [config/session.php](../config/session.php)
- [routes/web.php](../routes/web.php)
- [tests/CreatesApplication.php](../tests/CreatesApplication.php)

No Laravel upgrade or project replacement. Existing user/auth tables, Sanctum, and Vite setup were retained.

## 4. Composer packages

- shopify/shopify-app-php **1.0.2**: requested official Shopify authentication, token, HMAC, and GraphQL primitives.
- firebase/php-jwt **7.1.0**: required transitive package.
- PHP root constraint raised from ^8.1 to **^8.2**.
- Laravel remains **10.50.3**.

## 5. Database tables

shops, shop_settings, email_templates, disputes, automation_deliveries, email_logs, webhook_events, privacy_requests, jobs. Existing failed_jobs is reused.

Migrations were executed successfully against a fresh SQLite preview database and isolated temporary MySQL databases. The configured application database was not migrated by this verification work; run php artisan migrate after creating/configuring it.

## 6. Environment variables

See the complete table in [README](../README.md#environment-variables), placeholder [.env.example](../.env.example), and [production example](CPANEL_DEPLOYMENT.md#production-environment).

Critical settings: SHOPIFY_API_KEY/SECRET, SHOPIFY_API_VERSION=2026-07, SHOPIFY_APP_HANDLE, Partner organization/token/App GID, plan **and item** handles, database, APP_KEY/URL, authenticated SMTP From identity, QUEUE_CONNECTION=database, and production BILLING_ENFORCED=true/CHARGEGUARD_TEST_MODE=false.

## 7. Shopify scopes

read_orders, read_shopify_payments_disputes. Customer name/email need protected customer data approval. No write, evidence, or external gateway scopes.

## 8. Manual Shopify Dashboard actions

Create/link a public app and dev store, fill real credentials, configure embedded App Home and redirect URL, review webhooks/scopes, request Level 2 protected customer data access, configure App Pricing plans and subscription items, create a permitted Partner API client, verify live subscription responses, and complete listing/privacy/support/reviewer requirements. [Step-by-step guide](SHOPIFY_SETUP.md).

## 9. Local commands

```sh
composer install
php artisan key:generate
php artisan migrate
php artisan chargeguard:health-check
php artisan test
php artisan queue:work database
```

Copy .env.example only if .env is absent; configure MySQL and credentials first. Generate APP_KEY only for a new installation. Do not overwrite an existing key.

Optional local demo: seed DemoDataSeeder, enable CHARGEGUARD_DEMO_MODE locally, run php artisan serve, open /demo from loopback. No demo mutations or production authentication bypass exist.

## 10. Shopify CLI commands

```sh
npm install -g @shopify/cli@latest
shopify app config link
shopify app dev --reset
shopify app dev
shopify app webhook trigger
php artisan chargeguard:shopify-config
shopify app deploy
```

## 11. Development store

Create a dev store in your Partner organization, add sample products/customer/orders using addresses you control, install the app via CLI/Admin, and finish onboarding. A dev store and synthetic CLI events do not necessarily provide genuine accessible Shopify Payments disputes.

## 12. Sample dispute

Use the 20-case [testing matrix](TESTING.md). CLI synthetic IDs may return manual review. For a real accessible dispute, php artisan chargeguard:sync-dispute your-store.myshopify.com NUMERIC_ID fetches current information **without emailing**. Genuine initial-dispute sending requires explicit activation and an actual eligible new dispute.

## 13. Test email

Open Test Automation, select reason/shipment, enter a controlled test address, and send. Run queue:work database --stop-when-empty; inspect Email Logs filtered to tests. Messages use the actual template/renderer/Mailable and are labelled TEST; no fake dispute is created.

## 14. cPanel

Upload Laravel outside the webroot; point the subdomain to /home/USERNAME/chargeguard/public. Set PHP 8.2+, MySQL, HTTPS, private .env, authenticated SMTP, writable storage/bootstrap/cache. Run production Composer, migrate --force, config:cache, route:cache, view:cache. [Complete deployment guide](CPANEL_DEPLOYMENT.md).

## 15. Cron

```cron
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan queue:work database --stop-when-empty --tries=3 --timeout=50 >> /dev/null 2>&1
```

Check your host's PHP binary and cron runtime. Optional flock and operational monitoring are documented.

## 16. External work and release limitations

No live Shopify install/approval, real SMTP delivery, Partner entitlement verification against your account, DNS updates, cPanel upload, cron installation, or App Store publication was performed. Credentials and account permissions are still required.

Composer audit reports **3 advisories affecting Laravel 10.50.3**, covering email CRLF and temporary signed URL path confusion. Strict app email validation and avoiding temporary signed URLs reduce this app's exposure but do not make the dependency audit clean. Laravel 10 was retained as requested. Public production release needs a security-maintenance/backport decision plus live end-to-end checks.

SMTP cannot promise exactly-once acceptance across a process crash. Uncertain claims are flagged and never automatically resent.

## 17. Verification results

- SQLite: **77 tests passed, 358 assertions**.
- Isolated local MySQL: **77 tests passed, 358 assertions**.
- All 20 combinations exercised through template selection, queueing, and the Mailable send path with Mail::fake.
- PHPUnit tests use mocked Shopify/Partner HTTP; no real emails sent.
- PHP syntax, Pint, JavaScript syntax, Composer manifest/lock validation passed.
- Route cache and Blade compilation passed; route cache cleared after checking.
- Scheduler registers maintenance every five minutes and daily failed-job pruning.
- Local read-only dashboard visually inspected in headless Chrome with real Polaris CDN components. This does not validate Shopify's actual embedded browser context.

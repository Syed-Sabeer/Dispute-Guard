# ChargeGuard

Embedded Shopify Payments dispute automation, built inside the existing **Laravel 10** project.

Customer and Test Automation emails require the merchant's exact verified business email. Standard Postmark Sender Signature verification requires mailbox ownership for each shop and no DNS; advanced domain authentication remains optional. Application-owned sending is only for verification/system operations. See [merchant sender setup and safe deployment](docs/MANAGED_SENDING.md).

ChargeGuard detects new Shopify Payments disputes, retrieves the associated order through GraphQL, classifies shipment state, selects a merchant template, and queues one transactional customer email when eligible. Merchants can review disputes, edit 20 templates, test messages, inspect masked logs, manage billing/settings, and fulfill privacy requests.

V1 does not support external gateways, submit evidence, accept disputes, contact banks, or send automatic follow-up sequences.

## Stack and requirements

Implementation inventory, verification results, and the requested delivery checklist: [DELIVERY.md](docs/DELIVERY.md).

- Laravel **10.50.3**, retained on 10.x; **PHP 8.2+**.
- MySQL, Laravel database queues, Scheduler, Laravel Mail with Postmark API transport.
- Official **shopify/shopify-app-php v1.0.2**, with firebase/php-jwt v7.1.0 transitively.
- Shopify GraphQL Admin API **2026-07**, Partner API for App Pricing verification.
- Blade, Shopify App Bridge, current Polaris web components, vanilla JavaScript.
- PHPUnit, PDO SQLite for fast isolated tests; optional isolated MySQL tests.
- Composer, SSL, outbound HTTPS, writable private storage and bootstrap/cache.

Enable PDO MySQL, cURL, mbstring, openssl, fileinfo, tokenizer, ctype, XML/DOM, and standard Laravel extensions.

No React, Vue, Redis, Horizon, Supervisor, Docker, VPS, or Node backend. Node/npm is only for Shopify CLI or optional existing Vite tooling.

## Installation in this existing project

```sh
composer install
```

The official Shopify dependency is already recorded and locked. In a checkout without it, use:

```sh
composer require shopify/shopify-app-php
```

It supplies current Shopify authentication, token exchange/refresh, HMAC and GraphQL primitives. No archived Shopify Laravel package or REST Admin client is used.

Copy .env.example to .env **only if .env does not already exist**. On PowerShell use Copy-Item; on Linux use cp. Create an empty MySQL database and configure its credentials, APP_URL, and private app credentials.

```sh
php artisan key:generate
php artisan migrate
php artisan chargeguard:health-check
```

Generate APP_KEY only once. Existing encrypted tokens, content and queued test input depend on it. Never use migrate:fresh against application data.

No Vite build is required for the embedded screens; CDN components and public/js/chargeguard.js are used. Existing optional frontend tooling is preserved:

```sh
npm install
npm run build
```

## Environment variables

.env.example has placeholders only. Keep secrets out of source control.

| Variables | Purpose |
| --- | --- |
| APP_NAME, CHARGEGUARD_NAME | Laravel and centralized product branding |
| APP_ENV, APP_DEBUG, APP_URL, APP_KEY | Environment, origin, encryption |
| DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD | MySQL application and queue database |
| QUEUE_CONNECTION=database, CACHE_DRIVER=file | Cron queues and single-host locks |
| SESSION_DRIVER, SESSION_SECURE_COOKIE, SESSION_SAME_SITE | Existing Laravel cookie routes |
| SHOPIFY_API_KEY, SHOPIFY_API_SECRET | App client credentials |
| SHOPIFY_API_VERSION=2026-07 | Centralized Admin API version |
| SHOPIFY_APP_HANDLE, SHOPIFY_BILLING_MODE=app_pricing | Hosted pricing configuration |
| SHOPIFY_PARTNER_ID, SHOPIFY_PARTNER_TOKEN, SHOPIFY_APP_ID | Organization, Partner token, gid://shopify/App/... |
| SHOPIFY_PARTNER_API_VERSION=2026-07 | Partner API version |
| SHOPIFY_PLAN_STARTER/GROWTH/PRO | Your plan handles |
| SHOPIFY_ITEM_STARTER/GROWTH/PRO | Active subscription item handles mapped to plans |
| BILLING_ENFORCED | Local/testing bypass only; production always verifies |
| CHARGEGUARD_TEST_MODE | Global automatic-email block, default true |
| CHARGEGUARD_DEMO_MODE | Local loopback-only read-only demo, default false |
| CHARGEGUARD_RETENTION_DAYS | Sensitive content retention, default 90 days |
| MAIL_MAILER=postmark, POSTMARK_SERVER_TOKEN, POSTMARK_ACCOUNT_TOKEN | Postmark transport and sender management |
| MANAGED_SENDER_ADDRESS, MANAGED_SENDER_DOMAIN, MAIL_FROM_ADDRESS, MAIL_FROM_NAME | Application sender for mailbox verification/system diagnostics only |

APP_URL is the origin, such as https://app.example.com, without /dashboard. Shopify application_url includes /dashboard.

Controlled production validation requires APP_ENV=production, APP_DEBUG=false, HTTPS, CHARGEGUARD_TEST_MODE=true, BILLING_ENABLED=false, DISPUTEGUARD_PRELAUNCH=true, an exact shop allowlist and both Postmark tokens. Shop automation defaults off. Live/billing launch requires a separate operator decision after validation.

## Shopify CLI and development store

```sh
npm install -g @shopify/cli@latest
shopify app config link
shopify app dev --reset
```

Choose your own app and development store. client_id in the checked-in TOML is deliberately blank. Review CLI changes to retain required webhooks/scopes. Put your app key/secret in .env, align APP_URL with the tunnel origin, and clear cached config when local values change.

Later:

```sh
shopify app dev
```

shopify.web.toml starts PHP through scripts/shopify-serve.php, honoring PORT or SERVER_PORT. App Home is embedded at /dashboard. /auth/patch-id-token uses the official SDK token recovery flow. Verified installation creates an isolated Shop, settings, and all missing default templates.

Required scopes:

```
read_orders
read_shopify_payments_disputes
```

**Customer name/email require Shopify protected customer data access. Plan for Level 2 approval before public production use.** Missing permission may make customer/order data unavailable and cause manual review. No approval is bypassed or fabricated. V1 requests no unnecessary write/evidence scopes or read_all_orders; older orders can therefore be unavailable.

Full Partner/developer account, public app, dev store, callback, pricing, and approval steps: [SHOPIFY_SETUP.md](docs/SHOPIFY_SETUP.md).

## Onboarding and template behavior

Open /onboarding. Confirm connection, set store/sender/support/reply-to details, verify the merchant sender and review all 20 templates. Test mail is optional and requires that same verified sender. Keep global safety mode enabled during controlled validation. Later activation remains explicit and subject to delivery pause, subscription/prelaunch and quota checks.

Reasons: PRODUCT_NOT_RECEIVED, PRODUCT_UNACCEPTABLE, FRAUDULENT, CREDIT_NOT_PROCESSED.

Shipment states: UNFULFILLED, TRACKING_ADDED, IN_TRANSIT, OUT_FOR_DELIVERY, DELIVERED. UNKNOWN, unsupported reasons, inconsistent/mixed/partial data, or missing customer/order information require review and never generate automatic email.

Each combination supports editing, enabling/disabling, safe preview, confirmed default restoration, and test sending. Templates use whitelisted substitutions and basic paragraph/bold/italic/list HTML with no attributes or executable code. Tracking URLs appear as text.

```sh
php artisan chargeguard:seed-missing-templates
```

This creates missing combinations without overwriting merchant edits.

## Mail and sample automation

From is the merchant's exact verified business email with the store display name. Reply-To uses the merchant reply-to, support, then the same verified sender email. Configure Postmark Account and Server tokens privately. Standard mailbox verification needs no DNS; advanced domain authentication remains optional. Neither live automation nor Test Automation may use an application sender fallback.

For local sample data:

```dotenv
MAIL_MAILER=log
CHARGEGUARD_TEST_MODE=true
BILLING_ENFORCED=false
```

A sandbox SMTP inbox is another option. Log mail contains message data and is unsuitable for real production customer records.

Open **Test Automation**, choose a reason/state, enter your controlled test address and sample order/tracking details, then send:

```sh
php artisan queue:work database --stop-when-empty
```

The actual merchant template, renderer, composer, Mailable and mail transport are used. The message is marked [TEST] and logged separately; no fake Shopify dispute is created. Tests work with automatic sending disabled.

Production jobs fetch the recipient from the real Shopify order and never accept a replacement address from browser input. Changed/missing recipients cause review.

## Webhooks, jobs and resync

Configured topics: disputes/create, disputes/update, app/uninstalled, customers/data_request, customers/redact, shop/redact. Endpoints verify HMAC over the raw body.

Webhook IDs are unique. Event persistence and queue insertion share a database transaction. A stronger unique dispute delivery constraint permits at most one initial automatic email. Duplicate events, updates and resync never initiate a second email. Jobs use encrypted offline tokens, bounded retries, and database queues.

```sh
php artisan queue:work database
php artisan chargeguard:sync-dispute your-store.myshopify.com 123456789
php artisan queue:failed
```

SMTP cannot guarantee exactly-once delivery after a process crash. An uncertain claimed send is flagged for review and never blindly retried. Check provider logs. Pre-send transient API errors can retry safely.

## Billing

Use Shopify App Pricing in the Partner Dashboard. Configure plan handles and **separate subscription item handles**. The Partner API activeSubscription query verifies an active allowed item; browser plan_handle parameters never grant entitlement. No legacy recurring charge mutations or external subscription checkouts are implemented.

Missing credentials, null subscriptions, errors, or unknown items block production automation. BILLING_ENFORCED=false bypasses billing only in local/testing, even if accidentally false in production. The Billing page opens Shopify's hosted pricing URL and refreshes verified status.

## Privacy, metrics and retention

Customers/data_request prepares an encrypted export under Settings → Customer data requests. Verify the requester, deliver it through a secure channel, then mark it fulfilled. Customers/redact clears app-held identifiers/content and cancels sending. Shop/redact deletes inactive tenant data. Uninstall immediately removes tokens and disables automation.

Scheduler maintenance expires content and payloads, prunes failed jobs, deletes inactive shops, and flags interrupted SMTP claims. Handle pending privacy requests promptly. External backups and provider retention require operational handling.

Revenue at risk sums only real disputes with NEEDS_RESPONSE or UNDER_REVIEW, separately per currency. Closed/unknown states and demo/synthetic records are excluded. Test EmailLogs are excluded from production mail metrics.

Details: [ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Tests and local UI preview

```sh
php artisan test
php scripts/test-mysql.php
php vendor/bin/pint --test
```

Default tests use SQLite memory and Mail::fake. The MySQL helper creates/removes a randomly named isolated local database; tests refuse to run destructive migrations against a non-test MySQL database. No Shopify account or real email is needed.

For a local read-only demo:

```sh
php artisan db:seed --class=DemoDataSeeder
php artisan serve
```

Set CHARGEGUARD_DEMO_MODE=true locally, clear config cache, and visit http://127.0.0.1:8000/demo. It is APP_ENV=local, loopback-only, has no mutation routes, and displays a separate demo shop. Production authentication remains required for merchant routes.

[TESTING.md](docs/TESTING.md) contains the exact 20-case matrix and three testing levels: PHPUnit, internal Test Automation, and Shopify dev-store/CLI webhooks.

```sh
shopify app webhook trigger
```

Synthetic CLI dispute/order IDs may not exist in your dev store, so manual review is expected. The internal test screen is the primary sample email test; synthetic payloads are not proof of live dispute processing.

## Shared cPanel deployment

Preferred document root: /home/USERNAME/chargeguard/public.

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
```

Keep .env private, preserve APP_KEY, and make storage and bootstrap/cache writable.

Scheduler cron:

```cron
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

Database queue cron:

```cron
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan queue:work database --stop-when-empty --tries=3 --timeout=50 >> /dev/null 2>&1
```

Your PHP path may be /opt/cpanel/ea-php82/root/usr/bin/php. Detailed installation, SSL, permissions, environment, cron/flock, monitoring and SMTP instructions: [CPANEL_DEPLOYMENT.md](docs/CPANEL_DEPLOYMENT.md).

Deploy Shopify configuration separately:

```sh
php artisan chargeguard:shopify-config
shopify app deploy
```

This does not upload Laravel to cPanel.

## Tables and preserved functionality

New tables: shops, shop_settings, email_templates, disputes, automation_deliveries, email_logs, webhook_events, privacy_requests, jobs. Existing failed_jobs, user/auth tables, Sanctum and Vite functionality remain.

## External actions and release limitations

Supply real Shopify/Partner credentials, install a development store, verify live embedded navigation and API responses, configure plans, obtain protected data approval, authenticate SMTP/DNS, upload cPanel files, and configure cron. App Store listing/review/publication and support/privacy policy URLs remain external actions.

**Laravel 10 remains as requested.** Composer audit currently reports framework advisories for email validation CRLF and temporary signed URL path confusion. This app uses strict email/control-character validation and no temporary signed URLs, but the dependency audit is not clean. Resolve maintained security support/backports before public production launch. Passing tests do not resolve unsupported framework security.

No Shopify approval, credentials, live SMTP delivery, cPanel deployment, or publication has been fabricated.


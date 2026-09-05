# Shared cPanel deployment

## Prerequisites

PHP 8.2+, Composer, MySQL, cron, SSL, outbound HTTPS to Shopify/Partner API, outbound SMTP, and a writable private application directory. Enable PDO MySQL, cURL, mbstring, openssl, fileinfo, tokenizer, XML/DOM, ctype, and PHP's usual Laravel extensions. Use the same supported PHP release for web and CLI. A single shared filesystem is required for file-cache locks.

No Redis, Horizon, Supervisor, Docker, VPS, Node backend, or permanently running worker is required.

## Document root and upload

Prefer:

```
/home/USERNAME/chargeguard
/home/USERNAME/chargeguard/public   ← app.example.com document root
```

Upload the Laravel project into chargeguard, then set the subdomain's document root to its public folder. Never serve the project root, .env, vendor, storage, or backups. If the host cannot change a document root, ask it to configure a safe public entry directory; do not copy the whole project into public_html.

Retain public/.htaccess and enable Apache rewrite support. It forwards Authorization to PHP, which App Home requests need. HTTPS requests must reach Laravel with the correct scheme and host. Configure only trusted proxy addresses when your host terminates TLS at a proxy.

## Production environment

Copy .env.example to .env once, outside public. Fill actual credentials without committing them:

```dotenv
APP_NAME=ChargeGuard
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
APP_KEY=YOUR_EXISTING_GENERATED_APPLICATION_KEY

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANELUSER_chargeguard
DB_USERNAME=CPANELUSER_chargeguard
DB_PASSWORD=YOUR_DATABASE_PASSWORD

QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none

SHOPIFY_API_KEY=YOUR_APP_CLIENT_ID
SHOPIFY_API_SECRET=YOUR_APP_SECRET
SHOPIFY_API_VERSION=2026-07
SHOPIFY_APP_HANDLE=YOUR_APP_HANDLE
SHOPIFY_BILLING_MODE=app_pricing
SHOPIFY_PARTNER_ID=YOUR_PARTNER_ORGANIZATION_ID
SHOPIFY_PARTNER_TOKEN=YOUR_PARTNER_API_TOKEN
SHOPIFY_APP_ID=gid://shopify/App/YOUR_NUMERIC_APP_ID
SHOPIFY_PARTNER_API_VERSION=2026-07
SHOPIFY_PLAN_STARTER=YOUR_STARTER_PLAN_HANDLE
SHOPIFY_PLAN_GROWTH=YOUR_GROWTH_PLAN_HANDLE
SHOPIFY_PLAN_PRO=YOUR_PRO_PLAN_HANDLE
SHOPIFY_ITEM_STARTER=YOUR_STARTER_SUBSCRIPTION_ITEM_HANDLE
SHOPIFY_ITEM_GROWTH=YOUR_GROWTH_SUBSCRIPTION_ITEM_HANDLE
SHOPIFY_ITEM_PRO=YOUR_PRO_SUBSCRIPTION_ITEM_HANDLE

CHARGEGUARD_TEST_MODE=false
CHARGEGUARD_RETENTION_DAYS=90
BILLING_ENFORCED=true

MAIL_MAILER=smtp
MAIL_HOST=YOUR_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USERNAME
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=notifications@YOUR_AUTHENTICATED_DOMAIN
MAIL_FROM_NAME=ChargeGuard
LOG_LEVEL=warning
```

Shop-level automation still defaults off. Configure support email, review templates, send a test, verify billing, turn merchant test mode off, then explicitly activate.

## Install and migrate

Create the MySQL database and restricted database user in cPanel. Grant the application the schema privileges needed for migrations. Run in Terminal/SSH:

```sh
cd /home/USERNAME/chargeguard
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
```

Run key:generate **only for the initial installation**. Existing encrypted access tokens, email content, privacy exports, and queued test input depend on APP_KEY. Keep it backed up securely; do not regenerate on deploy.

Make storage and bootstrap/cache writable by the PHP user; use owner/group permissions appropriate to your host, usually directories 775 and files 664, not world-writable 777. Limit .env access.

The app's embedded UI uses CDN Polaris/App Bridge and a checked-in JS file. Vite builds are unnecessary for these screens. Existing Vite functionality can still be built locally and public/build uploaded if used.

Back up database and application key before an update. Pause new work with maintenance mode where appropriate, drain in-flight SMTP work, upload code, run migrations and cache commands, then resume. Never use migrate:fresh on production.

## Required cron jobs

Find PHP with `which php`, then verify `/path/to/php -v`. cPanel may use `/opt/cpanel/ea-php82/root/usr/bin/php` or a host-specific selector path rather than `/usr/bin/php`.

Scheduler, every minute:

```cron
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

Database queue, every minute:

```cron
* * * * * cd /home/USERNAME/chargeguard && /path/to/php artisan queue:work database --stop-when-empty --tries=3 --timeout=50 >> /dev/null 2>&1
```

If the host supports flock, avoid overlapping worker processes:

```cron
* * * * * cd /home/USERNAME/chargeguard && /usr/bin/flock -n /home/USERNAME/chargeguard/storage/queue.lock /path/to/php artisan queue:work database --stop-when-empty --max-time=55 --tries=3 --timeout=50 >> /dev/null 2>&1
```

The basic command is valid without flock; database reservation and conditional delivery claims guard duplicate sends. retry_after is 90 seconds, longer than the 50-second worker timeout. Maintenance runs every five minutes with withoutOverlapping; failed payload pruning runs daily. Do not delete cache locks while workers are active.

For setup diagnostics, temporarily redirect cron output to a protected log and inspect it. Then configure monitoring rather than permanently discarding all failures. Check failed_jobs, pending privacy requests, stale queue age, unknown mail outcomes, scheduler execution, and SMTP provider events.

Manual queue test:

```sh
php artisan queue:work database --stop-when-empty
php artisan schedule:list
php artisan queue:failed
```

Shared-host PHP may not have pcntl, so worker process timeout enforcement can be limited. HTTP and SMTP client timeouts are independently bounded. Confirm the host permits the required cron runtime and memory; monitor queue backlog.

## Shopify and email switch-over

Set production application URL and auth patch URL in shopify.app.toml, link the real client ID, synchronize version, and run shopify app deploy from your development machine. This config deployment does **not** upload Laravel to cPanel.

Authenticate your From domain with the SMTP provider, publish its SPF/DKIM records, configure DMARC appropriately, and verify a sample email. Merchant support is Reply-To; merchant domains are never spoofed as From.

Open the app in a Shopify development store, exercise onboarding and all 20 tests, verify Partner subscription data, check uninstall/privacy webhooks, and then complete App Store preparation. Keep production automation off until these checks pass.

## Security release gate

Run composer audit on the deployment artifact. The preserved Laravel 10 framework currently has published advisories; see ARCHITECTURE.md. Do not treat successful migrations or feature tests as a clean security audit. No cPanel, DNS, Shopify approval, or SMTP account setup was performed automatically.

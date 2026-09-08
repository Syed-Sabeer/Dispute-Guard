**Sender-domain release:** Follow [Merchant sending domains](MERCHANT_SENDING_DOMAINS.md) for the current Postmark configuration, verified merchant From rules, migration, and release report. Earlier application-From descriptions apply only to explicitly enabled fallback.

# Dispute Guard production deployment

This release preserves Laravel 10, MySQL, the official Shopify SDK and Laravel Mail, with a Postmark API transport. No Redis, Horizon, Supervisor or permanent daemon is required. This guide supersedes the older cPanel guide's mandatory billing/test-email onboarding instructions.

## Release blockers

Provide the permanent HTTPS domain, cPanel database/credentials, Shopify production credentials and authenticated email provider sender. Replace the reserved `.invalid` URLs; do not deploy temporary tunnels or invent a domain. Confirm production protected customer data approval and granted scopes.

`composer audit` on 2026-09-08 reports Laravel framework signed URL path confusion (GHSA-crmm-hgp2-wgrp) and CRLF email validation (GHSA-5vg9-5847-vvmq, listed by two feeds). Merchant email validation already rejects control characters and app authentication does not use signed URLs, but these mitigations do not make the framework advisory-free. Resolve the security maintenance/framework upgrade decision before public launch. This release does not silently upgrade Laravel or claim a clean audit.

## Configuration

Copy `.env.example` once, outside public, and fill actual credentials. Keep APP_KEY unchanged on updates: tokens, mail content and privacy exports depend on it. Code defaults billing to enabled and prelaunch to false.

```dotenv
APP_NAME="Dispute Guard"
APP_ENV=production
APP_DEBUG=false
APP_KEY=YOUR_EXISTING_APPLICATION_KEY
APP_URL=https://REPLACE-WITH-PRODUCTION-DOMAIN.invalid
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=CPANEL_DATABASE
DB_USERNAME=CPANEL_DATABASE_USER
DB_PASSWORD=YOUR_DATABASE_PASSWORD
QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
CHARGEGUARD_NAME="Dispute Guard"
CHARGEGUARD_TEST_MODE=true
CHARGEGUARD_DEMO_MODE=false
DISPUTEGUARD_ENABLE_TEST_TOOLS=false
BILLING_ENABLED=false
DISPUTEGUARD_PRELAUNCH=true
DISPUTEGUARD_PRELAUNCH_SHOPS=YOUR-INVITED-STORE.myshopify.com
BILLING_ENFORCED=true
SHOPIFY_API_KEY=YOUR_APP_CLIENT_ID
SHOPIFY_API_SECRET=YOUR_APP_CLIENT_SECRET
SHOPIFY_API_VERSION=2026-07
MAIL_MAILER=postmark
POSTMARK_SERVER_TOKEN=YOUR_SERVER_TOKEN
POSTMARK_ACCOUNT_TOKEN=YOUR_ACCOUNT_TOKEN
MERCHANT_SENDER_REQUIRED=true
MAIL_FROM_ADDRESS=notifications@YOUR_AUTHENTICATED_DOMAIN
MAIL_FROM_NAME="Dispute Guard"
```

Start with the global safety switch true during installation, then set false only when ready. Set CHARGEGUARD_TEST_MODE=false for completed setup; merchant automation still defaults off and its delivery pause remains unchanged.

The three controls are independent: APP_ENV selects environment behavior; CHARGEGUARD_TEST_MODE blocks automatic customer mail; DISPUTEGUARD_ENABLE_TEST_TOOLS enables developer routes and test jobs only. If the tools flag is unset, only local/development/testing environments enable it. Demo routes always fail closed in production, even with their flag true.

Billing disabled requires BOTH the explicit prelaunch flag and an exact allowlist entry. The comma-separated allowlist accepts shop domains, not URLs or wildcards. Other stores receive 403 before token exchange. Queued automation checks the same entitlement. No Partner API request is needed and UNVERIFIED billing is valid in this mode. Billing routes return 404. Keep this deployment private; this is not free public access. The legacy BILLING_ENFORCED=false bypass remains local/testing only and is ignored in production.

## Deployment order

1. Back up files, database and APP_KEY. Upload/clone to `/home/USER/disputeguard`, outside public_html. Exclude local `.env`, `.shopify`, logs, demo databases, local caches and test artifacts. Do not run production seeders.
2. Select PHP 8.2+ for web and CLI. Verify the binary, e.g. `/opt/cpanel/ea-php82/root/usr/bin/php -v`. Enable PDO MySQL, cURL, OpenSSL, mbstring, fileinfo, XML/DOM, tokenizer and ctype. Verify outbound HTTPS to Shopify, Postmark and the DNS resolver.
3. Create the MySQL database and restricted user in cPanel.
4. Configure `.env` above with real values.
5. Install dependencies using the correct PHP binary:

   ```sh
   cd /home/USER/disputeguard
   /path/to/php /path/to/composer install --no-dev --optimize-autoloader
   /path/to/php /path/to/composer audit --no-dev
   # NEW empty installation only; never rotate an existing key:
   /path/to/php artisan key:generate
   ```

6. Run `/path/to/php artisan migrate --force`. This hardening adds no schema/index migrations; existing shop/domain, dispute/status/date, webhook ID, email log and delivery uniqueness indexes remain. Never run migrate:fresh.
7. `php artisan storage:link` is optional; current embedded pages need no public uploads. Never expose private logs/exports.
8. Make storage and bootstrap/cache writable by the PHP user with appropriate owner/group permissions, typically 775/664, never 777. Restrict `.env` and backups.
9. Run `/path/to/php artisan config:cache`.
10. Run `/path/to/php artisan route:cache`.
11. Run `/path/to/php artisan view:cache`.
12. Set up the scheduler cron below.
13. Set up the finite queue cron below. Avoid workers from multiple releases during cutover.
14. Configure permanent HTTPS and document root `/home/USER/disputeguard/public`. Preserve public/.htaccess and Authorization forwarding. If TLS terminates upstream, configure only the provider's actual proxy addresses in TrustProxies. Never trust arbitrary forwarded headers.
15. Replace both `.invalid` URLs in shopify.app.toml with the permanent root URL and `/auth/patch-id-token`. Keep embedded=true, the intended client ID, all three scopes and all six webhook subscriptions. Keep webhook and GraphQL API versions aligned at the tested 2026-07 version.
16. On the development machine run `shopify app deploy` against the intended app. This publishes Shopify configuration, not Laravel files. Do not use `shopify app dev` to serve production merchants; use a separate linked app/config for later development.
17. Run `php artisan chargeguard:health-check`, `schedule:list` and `queue:failed`. Fix every FAIL; the billing-disabled prelaunch WARN is expected.
18. Open from Shopify Admin using an allowlisted shop. Verify authentication, navigation, HTTPS and iframe CSP. Do not copy session-token URLs into logs/tickets.
19. Fetch a known order through ShopifyOrderService in Tinker and inspect only the normalized shipping result. Verify read_customers, read_orders, read_shopify_payments_disputes. Do not dump tokens or customer records. FULFILLED plus tracking/no carrier event must remain TRACKING_ADDED.
20. For a controlled email test, temporarily enable DISPUTEGUARD_ENABLE_TEST_TOOLS, cache config, and send only to an operator-owned address. Verify provider delivery and the database email-log status. Test mail is labelled [TEST] and creates no dispute.
21. Verify cron, queue age, HMAC rejection, webhook deduplication, and privacy/uninstall on an isolated validation shop. Investigate failed jobs and UNKNOWN email provider outcomes; never blindly retry potentially delivered mail.
22. Disable test tools, cache config and run `queue:restart`. Queued developer emails are cancelled when tools are disabled. Health-check again. Never delete merchant records to clean up production UI; synthetic/test records are excluded.
23. Enable real customer automation only using the sequence below.

The Blade UI uses checked-in JS and Shopify CDN assets; no Vite build is required for these pages.

## Required cron jobs

Replace paths with the host's verified PHP binary:

```cron
* * * * * cd /home/USER/disputeguard && /path/to/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/disputeguard && /path/to/php artisan queue:work database --stop-when-empty --max-time=55 --tries=1 --timeout=50 >> /dev/null 2>&1
```

If available, prefix the queue command with `/usr/bin/flock -n /home/USER/disputeguard/storage/queue.lock`. Database claims still protect concurrent workers. Existing jobs specify three bounded preparation retries, which override worker --tries=1; email provider uncertainty is never automatically retried. retry_after=90 stays greater than job timeout=50. A running job may finish after max-time; verify host runtime limits. Without pcntl, process timeout enforcement is limited, so retain independent network timeouts and monitor backlog.

During setup, send cron errors to a protected operator log instead of /dev/null. Monitor failed jobs, pending privacy requests, oldest queue age and unknown deliveries. Maintenance prunes failed jobs after 24 hours. Failed-job storage is sensitive; never serve it publicly or clear cache locks while workers run.

## Enable live automation

1. Complete authentication, GraphQL, webhook, email provider domain (SPF/DKIM/DMARC) and cron validation.
2. Set CHARGEGUARD_TEST_MODE=false, leave demo/test tools false, run config:cache and queue:restart.
3. Each merchant confirms store name, support/Reply-To addresses and reviews templates in Settings. Saving reviewed templates/support details completes onboarding without test mail or automatically enabling automation.
4. Turn off the merchant delivery pause and explicitly activate automatic customer emails. Billing must verify a subscription or permit the private allowlisted shop.
5. Observe an eligible NEW dispute. Existing historical disputes are not automatically emailed. Master switch, template, reason, shipment, recipient, active shop, onboarding, entitlement, duplicate/stale-state and privacy checks remain in force.

From identity is the authenticated app email provider domain; merchant addresses are Reply-To only. Production subjects have no app-added [TEST]; merchant template content is preserved. Neutral reason-specific wording and carrier-event precedence are unchanged.

## Enable billing later

Configure actual Shopify App Pricing plans, plan/item handles, SHOPIFY_PARTNER_ID, SHOPIFY_PARTNER_TOKEN, SHOPIFY_APP_ID, SHOPIFY_APP_HANDLE, SHOPIFY_PARTNER_API_VERSION and SHOPIFY_PLAN_*/SHOPIFY_ITEM_*. Set BILLING_ENABLED=true, BILLING_ENFORCED=true, DISPUTEGUARD_PRELAUNCH=false and clear the allowlist. Cache config, restart workers, health-check, then verify subscribed and unsubscribed stores. Billing navigation returns; unverifiable entitlement blocks activation and automatic mail. Existing read-only access behavior remains. Never publicly launch with prelaunch billing disabled.

## Rollback

Pause incoming work/cron and drain in-flight sends. Preserve database, APP_KEY and delivery claims. Restore the previous compatible code, regenerate caches and restart workers. This release adds two sender tables; preserve them on rollback, keep automation disabled, and do not roll back their migration after merchants configure senders. Do not restore an old database snapshot blindly after mail has been sent: lost deduplication claims can cause duplicates. Reconcile email provider provider records before retries. Restore matching Shopify URLs/config if changed, then health-check and verify authentication before resuming.

## Recover a hosting migration error: maximum key length 1000 bytes

This limit is characteristic of MyISAM. The application explicitly selects InnoDB in `config/database.php`; transactions and row locks are essential to mail deduplication. Do not work around it by shrinking every string or disabling foreign keys.

Upload the updated database config, run `php artisan config:clear`, and inspect the selected database in phpMyAdmin:

```sql
SHOW ENGINES;
SELECT TABLE_NAME, ENGINE, ROW_FORMAT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
```

If the failed installation created only `migrations` and `users` using MyISAM, back up first and convert those existing tables (these commands preserve rows):

```sql
ALTER TABLE migrations ENGINE=InnoDB;
ALTER TABLE users ENGINE=InnoDB;
```

Then run `php artisan migrate --force` without `--seed`, followed by `php artisan config:cache`. Laravel skips migrations already recorded as complete. Do not use migrate:fresh. If the failed table exists despite the error or later migrations partially created tables, inspect its structure and migration status before proceeding; do not drop tables blindly. If InnoDB is unavailable or uses a legacy index-limited row format, ask the host to enable a supported InnoDB configuration (DYNAMIC row format, normal 16KB pages). Health-check now flags any existing non-InnoDB base tables.

## References

- [Shopify app configuration](https://shopify.dev/docs/apps/build/cli-for-apps/app-configuration)
- [Shopify webhook subscriptions](https://shopify.dev/docs/apps/build/webhooks/subscribe)
- [Laravel 10 queues](https://laravel.com/framework/docs/10.x/queues)
- [Email validation advisory](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq)
- [Signed URL advisory](https://github.com/advisories/GHSA-crmm-hgp2-wgrp)

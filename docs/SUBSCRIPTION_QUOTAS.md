# Per-shop subscription quotas

| Plan | Monthly price | Automated follow-ups per subscription period |
| --- | ---: | ---: |
| Starter | $59 | 1,000 |
| Growth | $99 | 3,000 |
| Pro | $149 | Up to 10,000 |

`config/quotas.php` defines these limits and display prices. Each Shopify shop has independent usage even though delivery uses the pooled Postmark account. Managed sending remains the default with no merchant DNS requirement; custom domains remain optional under Advanced.

## Billing-period authority

The existing Partner API query now requests `currentBillingCycle.startTime` and `endTime`; these identify the actual shop subscription cycle. The API defines them as the period start and next charge time. [Shopify BillingCycle reference](https://shopify.dev/docs/api/partner/latest/objects/BillingCycle).

Recognized active subscription item handles map to Starter/Growth/Pro. Missing, expired or malformed periods do not receive an invented calendar-month allowance. Billing verification still fails closed. The dashboard and billing/plans pages refresh subscription entitlement when billing is enabled. No billable Shopify plan is created or modified by this change; configure matching prices, monthly intervals and item handles in Shopify App Pricing before enabling billing.

Within the same cycle start, plan changes retain consumed and reserved usage. Upgrades increase capacity; downgrades reduce remaining capacity, potentially to zero. In-flight attempts retain capacity first, then earlier queued reservations have priority. A downgrade cannot recall mail already submitted, so consumed usage can exceed the new lower cap; additional sends remain blocked. A new verified cycle start creates a separate usage ledger. Counts never reset globally.

## Atomic reservations and outcomes

The initial-dispute transaction locks the dispute, shop and usage period. It atomically increments reserved capacity only when `reserved + consumed < allowance`, then creates the AutomationDelivery and email log with its reservation. The database job is inserted on the same connection in that transaction, so it becomes visible to workers only at commit. Health-check requires the queue to share the application database. Transaction rollback returns capacity. Concurrent shops do not share a counter; concurrent disputes within a shop contend on its database rows.

Quota is checked again when claiming the delivery and immediately before transport. Locking reads avoid stale MySQL REPEATABLE READ snapshots. No provider network call occurs inside these transactions. Existing sender identity snapshots, recipient/order refetch, shipping/reason checks, entitlement, privacy, global safety mode and deduplication remain required.

| Outcome | Quota behavior |
| --- | --- |
| QUEUED / preparing | Reserved; unavailable to other new disputes |
| SENT | Consumed once |
| UNKNOWN / transport interruption | Consumed once; no automatic retry |
| Sender/recipient/shipping change or other pre-transport cancellation | Released |
| Definite provider rejection | Released |
| Worker interruption before transport starts | Cancelled and released by maintenance |
| Privacy redaction before transport | Released |
| Privacy redaction after transport starts | Conservatively consumed; later definite rejection can release it |

Settlement is idempotent. The transport-start timestamp distinguishes unstarted preparation from uncertain provider acceptance. The maintenance command reconciles unfinished terminal reservations. Tenant redaction deletes no other shop's usage; customer redaction preserves aggregate counts without requiring customer data. Shop deletion cascades its periods.

Quota exhaustion stores a clear manual-review reason, preserves `auto_email_enabled`, creates no new delivery/job and exposes an Upgrade plan link. The initial-dispute marker remains set. Resetting a period, increasing a plan or freeing a reservation never replays that historical dispute. Queued messages reserved in an old period are cancelled/released rather than transferred to a new allowance.

## Rollout and legacy deliveries

The additive migration `2026_09_10_000001_create_subscription_usage_periods.php` adds subscription period metadata on shops, a `subscription_usage_periods` ledger and delivery quota references/status/transport timestamp. Existing migrations are unchanged. No reset or destructive data migration is used.

Existing SENT/UNKNOWN deliveries in the current cycle are adopted into consumed usage exactly once using their send/claim/create time. Legacy in-flight rows without a transport marker are conservatively consumed because acceptance cannot be disproved. Legacy queued rows have no quota reservation and require manual review; they are not assigned fresh capacity automatically. Preserve historical claims/counters on rollback.

## Dashboard and pricing

Dashboard shows plan, consumed usage/allowance, separately labelled pending reservations, remaining capacity, percentage committed and reset date/time in UTC. Remaining capacity subtracts both consumed and reserved usage. `/plans` displays prices and allowances, and remains readable during private validation. `/billing` keeps its existing billing-enabled restriction. Paid upgrade URLs are exposed only when billing is enabled and the Shopify app handle is configured. Website pricing is updated to the same tiers.

## Controlled validation and deployment

Keep these settings during implementation and controlled validation:

```dotenv
CHARGEGUARD_TEST_MODE=true
BILLING_ENABLED=false
```

These remain set in the local runtime and supplied production environment files. Tests explicitly simulate live eligibility with mocked provider/billing responses; they do not change operational flags or send real email.

Prelaunch shops do not have real subscriptions. An operator can assign an explicit validation period only to an active, allowlisted private shop, while billing is disabled:

```sh
php artisan chargeguard:quota-period YOUR-SHOP.myshopify.com starter START_ISO8601 END_ISO8601
```

Use timestamps such as `2026-09-10T00:00:00Z`, with start at/before now and end after now. The command refuses to move the start of an ongoing period and never changes automation, billing or test-mode flags. There is no automatic prelaunch period reset. Existing private shops without an assigned period remain blocked for automatic customer sending, as does global test mode.

Pause queue cron, back up the database and APP_KEY, upload code, then run:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
php artisan queue:restart
```

Resume the documented cron, including `chargeguard:maintain`. The additive migration was applied locally; production still needs it. Configure and validate the matching Shopify App Pricing plans before later enabling billing. Do not enable customer sending until controlled validation and the existing launch checks are complete. No Shopify pricing deployment, real charge or live provider send was performed here.

## Tests

Coverage includes each plan limit, per-shop boundaries, atomic rollback, reservation release, SENT/UNKNOWN consumption, downgrade with in-flight usage, cycle reset, no historical replay, sender snapshot changes, privacy/uninstall, prelaunch assignment and legacy adoption. A separate two-process test races for the final slot in an isolated MySQL database and asserts one winner and one rejection. SQLite skips only that MySQL-specific test; the MySQL runner executes it. Existing Shopify, billing, managed/custom sender and shipping regression tests remain.

Existing documented Laravel security advisories remain a public-launch blocker independent of this feature. Live Shopify period responses, configured prices and Postmark delivery still require controlled production verification.

Final validation: SQLite 191 passed / 862 assertions, with the single MySQL-specific concurrency test skipped. Isolated MySQL: 192 passed / 868 assertions, including the two-process last-slot race. Pint, PHP syntax checks and config/route/Blade cache checks passed.

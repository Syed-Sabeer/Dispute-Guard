Current production sender behavior and deployment steps: [Production sender fixes](PRODUCTION_SENDER_FIXES.md).

**Sender-domain release:** Follow [Merchant sending domains](MERCHANT_SENDING_DOMAINS.md) for the current Postmark configuration, verified merchant From rules, migration, and release report. Earlier application-From descriptions apply only to explicitly enabled fallback.

# ChargeGuard V1 architecture

## Scope and boundaries

ChargeGuard supports **Shopify Payments disputes only**. It detects a dispute, fetches the associated order, classifies shipment state, sends at most one initial transactional customer email when eligible, and records the result. It does not submit evidence, accept disputes, contact banks, or integrate external payment processors.

Laravel remains on 10.x; PHP requires 8.2+. The official `shopify/shopify-app-php` package is isolated in Shopify services. MySQL stores tenant data and queued work. Blade, App Bridge, Polaris web components, and vanilla JavaScript render the embedded app. File cache locks work on one shared cPanel filesystem; this is not a distributed multi-host design.

## Authentication and request boundaries

1. App Home opens `/dashboard`.
2. `AuthenticateShopify` delegates request verification to `verifyAppHomeReq`. Missing/stale document tokens use the official `/auth/patch-id-token` response.
3. The verified destination and issuer are constrained to the same valid myshopify.com domain. Browser-supplied shop IDs never determine tenancy.
4. Exchange for an **expiring offline** access token. Persist the entire token bundle with Laravel's encrypted array cast, including refresh token and expirations. Background calls invoke the package's refresh method under a per-shop lock.
5. Idempotently create settings and 20 templates; sync shop identity via GraphQL. Master automation defaults off and merchant test mode defaults on.
6. Resolve a request-scoped CurrentShop. TenantPolicy and tenant queries protect record routes. Authentication precedes throttling and model binding.
7. Preserve SDK security/refresh response headers and set a shop-specific frame-ancestors policy. Access tokens never reach JavaScript.

The embedded merchant routes are **stateless bearer-authenticated routes**, separate from Laravel's cookie-based web routes. All mutations require a valid Shopify bearer token, JSON, an XMLHttpRequest header, and a matching Origin when supplied. This provides CSRF resistance without relying on third-party cookies. Existing Laravel web CSRF middleware remains enabled. Webhook routes have their own HMAC verification and no session authentication. No global CSRF exception was added.

Use HTTPS in production, APP_DEBUG=false, secure HttpOnly session cookies, and preserve Authorization headers in Apache. Configure the host/proxy correctly; never let an untrusted client set the effective proxy host.

## GraphQL and billing

Centralized documents live in resources/graphql. Admin queries use API version config/shopify.php, default 2026-07. A synchronization command updates Shopify TOML's webhook version. QueryRoot.dispute, reasonDetails.reason, order fulfillment arrays, fulfillment event connections, and refund transaction statuses follow the 2026-07 reference.

Admin HTTP failures, top-level GraphQL errors, and nested userErrors are checked. Rate limits, network failures, and 5xx errors produce sanitized transient exceptions; queue jobs use 3 attempts with 30/120/300 second backoff. Permanent processing failures become manual review. API network calls do not occur inside database transactions. SDK HTTP timeouts are bounded.

Billing uses the Partner API `activeSubscription(appId:, shopId:)` query, not Admin activeSubscriptions or a legacy charge mutation. No browser plan parameter grants access. An active returned subscription must include an allowed, active price item. Configure **subscription item handles separately from plan handles**: SHOPIFY_ITEM_STARTER/GROWTH/PRO map returned items to SHOPIFY_PLAN_STARTER/GROWTH/PRO. Verify this mapping using your actual Partner API response.

A missing subscription, unrecognized item, bad credentials, errors, or unavailable Partner API blocks production sending. The only billing bypass is APP_ENV=local/testing with BILLING_ENFORCED=false. Production ignores that bypass flag. Each eligibility check queries fresh state rather than trusting a stale cached subscription.

## Durable webhook and queue flow

- Verify HMAC over the untouched raw body through the official package. Flatten Laravel header arrays for package v1.0.2; reject duplicate header values.
- Validate domain, topic/path match, webhook ID, and JSON.
- In one short MySQL transaction, create a globally unique webhook record and insert the database queue job. Both use the same default database connection; do not change this arrangement to separate queue storage without an outbox.
- Duplicate delivery IDs acknowledge successfully without enqueueing again.
- Unknown shops acknowledge with an IGNORED audit record and no retained payload.
- Dispute webhooks retain only the dispute ID and a synthetic marker, encrypted. Fetch current authoritative data via GraphQL.
- A dispute-level lock serializes processing. A unique shop/dispute key prevents duplicate records.
- All unsupported reasons remain stored. Unknown, mixed, partial, truncated, or inconsistent shipping information requires manual review.
- A row lock claims initial processing. There is both the requested combination uniqueness and a stronger **unique dispute_id delivery constraint**, enforcing only one initial automatic email across all combinations.
- Update webhooks and manual/CLI resync never initiate customer email.
- Queue payloads contain record IDs. Test input is encrypted; no plain production recipient travels in a job.
- Queue connection is explicitly database for business jobs even if a local .env still says sync.

## Shipment policy

Five public states: UNFULFILLED, TRACKING_ADDED, IN_TRANSIT, OUT_FOR_DELIVERY, DELIVERED. UNKNOWN is internal/manual review.

No fulfillments with an unfulfilled status means UNFULFILLED. Valid tracking with label/confirmed status or no movement means TRACKING_ADDED. Carrier pickup, movement, and delays mean IN_TRANSIT. Attempted delivery maps to OUT_FOR_DELIVERY with raw data preserved. Only an explicit delivered signal yields DELIVERED.

Mixed states across shipments, partially fulfilled orders, non-success fulfillments, missing usable tracking, unsupported events, and potentially truncated data fail conservatively to UNKNOWN. We do not guess that the whole order was delivered. A representative tracking number is displayed; raw statuses preserve all classified fulfillments. Orders beyond Shopify's ordinary read_orders history window can be unavailable and require review; read_all_orders is not requested in V1.

Refund records and their transaction statuses are displayed separately. A refund record, delivery, or shipping state is not proof that funds reached the customer.

## Email safety and uncertainty

Immediately before sending, refresh shop/settings/dispute/template eligibility. Fetch the actual Shopify order email and require it to match the hash captured during processing. A recipient change causes manual review, not redirection.

A short transaction changes a delivery from QUEUED to SENDING and creates its log. Only one worker can win this conditional claim. email provider executes outside transactions. The successful result marks the delivery/log SENT and the dispute EMAIL_SENT. Mail uses the verified merchant From name/address and a validated merchant Reply-To. Application From is available only for test mail or explicitly enabled optional fallback.

**email provider cannot provide exactly-once delivery across a process crash.** If email provider throws after the claim, the result becomes UNKNOWN/manual review; it is not automatically retried. If a worker dies after email provider accepted the email, maintenance marks stale SENDING records uncertain after five minutes. Check provider records. Do not reset a claim or blindly retry uncertain mail. Pre-send transient Shopify failures can retry safely.

Disabling automation, uninstalling, or redacting prevents subsequent sends at the final eligibility check. An email already accepted by email provider cannot be recalled. This unavoidable boundary is distinct from queue idempotency.

Templates use a whitelist of substitutions without eval, PHP execution, Blade evaluation, or arbitrary template engines. Substituted HTML values are escaped. Only p/br/strong/b/em/i/ul/ol/li survive and every attribute is removed. Tracking URLs are plain text. Stored previews are encrypted and sanitized again on display.

## Privacy, retention, and operations

- customers/data_request creates a tenant-isolated, encrypted export with a READY workflow in Settings Ã¢â€ â€™ Customer data requests. The merchant/operator verifies the requester and provides the export through a secure channel, then marks it completed. This is a real preparation workflow; the app does not automatically email exports.
- customers/redact matches requested order IDs and/or keyed email hash; clears tracking, order references, recipient details and previews; cancels deliveries; marks disputes redacted to prevent re-fetching. Existing exports are invalidated. Concurrent fetched data cannot overwrite a redacted row.
- shop/redact cascades tenant deletion after uninstall. A delayed event for a prior installation does not erase an active verified reinstall.
- Uninstall immediately clears tokens, disables automation, and marks the shop inactive.
- Maintenance also purges inactive shops after 48 hours. Retained email bodies, subjects, recipient identifiers, and tracking references expire after CHARGEGUARD_RETENTION_DAYS (default 90).
- Webhook payloads expire after 7 days; outstanding privacy exports after 30 days. Monitor unfinished privacy requests before expiry and fulfill them within Shopify's applicable deadline.
- Failed jobs are pruned after 24 hours. Test job input is encrypted even in failed payloads.
- Restrict and rotate webserver/application logs; omit authorization headers and query-string ID tokens from access logging. Never enable HTTP wire/body logging in production. MAIL_MAILER=log is only for local sample data.
- Review backup retention and provider-held mail records as part of privacy fulfillment; database deletion does not erase external backups or an email provider provider's records.

## Metrics

Revenue at risk is the database SUM of real Shopify dispute amounts whose current status is NEEDS_RESPONSE or UNDER_REVIEW, grouped by currency. WON, LOST, ACCEPTED, PREVENTED, unknown statuses, and demo/synthetic data are excluded. This is a dispute exposure metric, not an accounting report. No PHP floating-point money calculations are used. Test EmailLogs do not contribute to production sent/failure metrics.

## Known release prerequisites

External Shopify install, protected customer data approval, live GraphQL/Partner API checks, embedded browser behavior, email provider delivery, cPanel upload, and public publication must be verified with real accounts. Unit/feature mocks are not evidence of those actions.

Composer audit currently reports advisories against Laravel 10.50.3 (email validation CRLF and temporary signed URL path confusion). This app adds strict FILTER_VALIDATE_EMAIL/control-character checks and does not use temporary signed URLs. Laravel 10 was retained as requested; these application defenses do not make the framework audit clean. Resolve the supported-maintenance/security-backport decision before a public production launch.

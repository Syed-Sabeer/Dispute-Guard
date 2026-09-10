Subscription plan limits, period assignment and quota rollout: [Per-shop quotas](SUBSCRIPTION_QUOTAS.md). Keep customer test mode enabled and billing disabled during controlled validation.

# Managed sending (current production architecture)

Dispute Guard sends normal dispute emails without merchant DNS setup. Postmark remains the delivery provider. The operator authenticates a Dispute Guard-owned domain once; merchants configure their store display name and support/Reply-To address, review templates and enable automation. Advanced custom sending domains are optional.

## Configuration

```dotenv
MAIL_MAILER=postmark
MANAGED_SENDER_ADDRESS=disputeguard@deveoninc.com
MANAGED_SENDER_DOMAIN=deveoninc.com
MAIL_FROM_NAME="Dispute Guard"
POSTMARK_SERVER_TOKEN=
# Optional: only needed for Advanced custom-domain management
POSTMARK_ACCOUNT_TOKEN=
CHARGEGUARD_TEST_MODE=true
DISPUTEGUARD_ENABLE_TEST_TOOLS=false
```

The address above comes from the supplied local production configuration, not an application-code constant. Authenticate that sender/domain in Postmark before live use. Configuration validation checks a single valid address, a DNS hostname and exact address-domain match; production also requires Postmark and a non-test server token. A configuration check does not prove live provider authentication or delivery.

If the new managed fields are absent, existing operator-controlled `MAIL_FROM_ADDRESS` supplies the address and its domain for compatibility. Empty explicit fields fail validation. `MERCHANT_SENDER_REQUIRED` is retired and ignored; it cannot restore mandatory merchant DNS. `.env.example` leaves managed fields empty for operator setup. The supplied `productionenv.md` was updated locally with its existing sender address/domain and remains excluded from Git along with `productionenv.env`. Credentials are preserved. The runtime `.env` was not overwritten.

## Sender selection

For each new email, prefer a valid custom sender whose verification is fresh. Recheck stale custom verification using the existing provider flags, DKIM, Return-Path and per-shop ownership proof. If no usable custom identity exists, use the managed address. A missing account token, custom verification outage or missing DNS record never makes custom DNS mandatory.

Managed From name is the shop's configured display name, then Shopify store name, then `Dispute Guard`. Control characters and angle brackets are stripped, whitespace at the ends is removed and length is bounded to 100 characters. Symfony formats/escapes the display name as a mailbox header. Merchants cannot supply the managed mailbox or domain. Stores share the operator-controlled mailbox while retaining distinct display names.

Reply-To uses the first valid merchant reply address, support address, or selected From as a last resort. Normal onboarding requires a support address. Invalid persisted Reply-To cannot inject headers or prevent a valid support address from being selected. Custom From retains the verified custom name/address. `[TEST]` labels remain exclusive to test messages.

## Automation and queues

Custom absence, stale verification, outage, DNS loss, edits and disconnect do not change `auto_email_enabled`. The persisted setting is the merchant's preference. Genuine custom DNS loss revokes that custom sender's eligibility; new messages can use managed sending. Uninstall and privacy lifecycle protections still stop mail.

Each new queue row stores a versioned hash of the selected identity: shop, From name/address, Reply-To, custom sender ID and revision when applicable. Selection occurs before queuing and is compared again during job preparation. Changes to managed configuration, store alias, Reply-To, or selected custom sender cancel the queued message to manual review. Both managed-to-custom and custom-to-managed switching are prohibited for queued mail. Verification refresh alone does not alter an otherwise identical identity.

The existing lock around the final identity check/send remains. A custom verification failure at that boundary prevents sending; it never falls back inside an already prepared send. Existing recipient refetch/hash, current shipping/reason checks, billing/prelaunch, onboarding, pause/global safety mode, redaction, claims, deduplication and uncertain-delivery no-retry behavior remain in force.

No new migration is needed: the existing `sender_identity_hash` column is reused. Snapshots from the previous algorithm are intentionally incompatible and cancel to manual review. Do not rewrite old snapshots to the current identity or blindly requeue cancelled/uncertain messages. Future eligible disputes use the new policy automatically.

## Merchant UI

Onboarding and settings show **Managed by Dispute Guard — no DNS setup required**. Sender verification is absent from activation requirements. Dashboard enablement is independent of custom-domain status and remains subject to general safety checks. Settings links to **Advanced: Custom sending domain (optional)**; its DNS forms and status are inside the Advanced section. Custom ownership/verification endpoints retain tenant authentication, CSRF and throttling.

## Deployment and controlled validation

Keep `CHARGEGUARD_TEST_MODE=true` throughout setup. Pause cron workers, back up existing data/APP_KEY, deploy code, and set the managed fields in the real server environment. Preserve existing production Shopify, database and billing/prelaunch configuration.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
php artisan queue:restart
```

There is no feature-specific migration; the command applies any older pending migrations. Health checks validate managed sending configuration. A missing account token is a warning about Advanced custom-domain management, not a normal-sending failure. Health checks do not create domains or send messages.

Run one controlled operator test, which now uses the explicit managed address:

```sh
php artisan chargeguard:postmark-smoke-test YOUR_OWN_EMAIL
```

Production requires confirmation unless the operator explicitly supplies `--force`. Verify receipt, `[TEST]` subject, expected managed From and matching Postmark MessageID. An UNKNOWN result must not be blindly retried. This operator command does not touch shop preferences, disputes or deliveries.

In staging, verify a merchant can complete onboarding with zero domain records, inspect its store-name From and merchant Reply-To, and exercise custom preference/fallback. Confirm queued messages cancel on identity changes in both directions. After controlled validation and existing production launch checks, explicitly set `CHARGEGUARD_TEST_MODE=false`, cache config and restart workers. No process enables merchant automation automatically.

## Validation and limitations

Regression coverage includes no-DNS onboarding/activation, managed transport headers, per-shop aliases, safe Reply-To, custom preference, new-message fallback after confirmed DNS loss, snapshot changes in both directions, optional account-token preflight, and legacy snapshot rejection. Existing shipping, Shopify, billing, privacy and delivery safety tests remain. Automated provider tests use mocks; no real message or DNS change was made during implementation.

Postmark account approval, domain authentication and live receipt remain operator validation steps. Provider acceptance does not guarantee inbox delivery. Existing documented Laravel security advisories remain separate public-launch blockers. The earlier sender-domain reports describe historical releases; this document supersedes their mandatory-domain and outage-blocking policy for new messages.

Validated locally: 175 tests / 776 assertions pass on both SQLite and isolated MySQL. Pint, PHP syntax checks (15 changed/new PHP files), and configuration, route and Blade cache checks pass.

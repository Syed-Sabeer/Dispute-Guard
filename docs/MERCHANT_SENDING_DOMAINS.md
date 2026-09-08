# Merchant sending domains — implementation report

## A. Architecture implemented

The existing Laravel Mail pipeline uses a small Symfony-compatible Postmark API transport. No additional Composer package or merchant SMTP credentials are needed. A central account manages domains; its server sends transactional mail. Domain provisioning is shared safely, while each shop must independently prove DNS ownership. Verification expires after 15 minutes and refreshes on demand before sending. Failed refresh blocks required-sender mail.

Postmark's DKIM verification flag is historical, so the app also checks current DKIM TXT, Return-Path CNAME and a per-shop ownership TXT through Cloudflare DNS-over-HTTPS. This introduces a public DNS resolver dependency; cached DNS and the 15-minute verification window mean removal is not instantaneous. See [Postmark Domains API](https://postmarkapp.com/developer/api/domains-api).

## B. Files changed

- Provider boundary: `app/Services/Email/{EmailProviderInterface,PostmarkEmailProvider,PostmarkTransport,SenderDnsVerifier,MerchantSenderService}.php` and `app/Exceptions/EmailProviderException.php`.
- Storage: `app/Models/{EmailSendingDomain,MerchantEmailSender,Shop}.php` and the additive migration below.
- Integration: `AppServiceProvider`, `EmailComposer`, `DisputeCustomerMail`, `AutomationResolver`, both email jobs, settings validation/controller, webhook controller, exception handler and `TrimStrings`.
- Merchant UI: `EmailSenderController`, `routes/web.php`, sender/settings/onboarding/dashboard Blade views.
- Configuration: `config/senders.php`, `config/services.php`, `.env.example`, health check, `phpunit.xml`.
- Tests: `MerchantSenderTest`, `SenderDnsVerifierTest`, updated `ProductionModeTest`. Deployment, architecture and readiness guides link here.

## C. New migration

`2026_09_09_000001_create_merchant_email_senders.php` adds `email_sending_domains` (unique normalized domain and provider ID, DNS records) and `merchant_email_senders` (unique shop, sender identity, independent proof, verification state/times and revision). Existing migrations are unchanged. Existing `email_logs.provider_message_id` is reused. Shop deletion cascades its sender; shared domain records are retained. No credentials are stored in these tables.

## D. Environment variables

```dotenv
MAIL_MAILER=postmark
POSTMARK_SERVER_TOKEN=
POSTMARK_ACCOUNT_TOKEN=
MERCHANT_SENDER_REQUIRED=true
MAIL_FROM_ADDRESS=notifications@YOUR_AUTHENTICATED_DOMAIN
MAIL_FROM_NAME="Dispute Guard"
```

Tokens remain server-side. Existing `.env` secrets have not been edited. The normal default requires a merchant sender; the legacy test fixtures explicitly select optional fallback, while sender tests exercise the required mode. Keep existing billing, Shopify, global safety-switch and production settings.

## E. Postmark setup required

Provide an approved transactional Postmark account, an outbound server in that account, its server token and the account token. Verify the application's fallback sender/domain if test or optional fallback mail is needed. Account and server tokens serve different APIs; see [Postmark Email API](https://postmarkapp.com/developer/api/email-api). Allow outbound HTTPS to Postmark and Cloudflare DNS. Do not enable HTTP request/body logging. No provider credentials belong in merchant forms.

## F. Merchant workflow

Open Settings ? email sender, enter sender name and a business-domain address, save, publish the displayed DNS records, then check verification. Once verified, review templates and explicitly enable automation. Verification never enables it automatically. Same-domain edits retain valid proof; a new domain requires new proof and disables automation. Disconnect requires confirmation, disables automation and retains the shared provider domain.

## G. Exact DNS records shown

| Type | Host | Value |
| --- | --- | --- |
| TXT | `_disputeguard-<random shop challenge>.<domain>` | `disputeguard-verification=<random proof>` |
| TXT | Provider `DKIMPendingHost`, otherwise `DKIMHost` | Matching provider pending/active TXT value |
| CNAME | Provider `ReturnPathDomain` (new domains request `pm-bounces.<domain>`) | Provider `ReturnPathDomainCNAMEValue` |

The page displays the actual generated/provider values, not these placeholders. Publish each record exactly as shown; DNS consoles may require relative hostnames. Keep the records after verification. DKIM rotation requires publishing the new record shown. No fabricated SPF record is required by this implementation.

## H. Automatic From and Reply-To

From is the persisted, verified merchant name/email whose domain exactly matches its verified domain record. For example: `ABC Store <support@store-one.com>`. Reply-To uses the merchant's valid configured reply address, then support address, then selected From. A sender revision is checked under the same lock used by edits/disconnect immediately before transmission. Recipient lookup, current shipping checks, billing and delivery claims remain in force.

## I. Fallback

With `MERCHANT_SENDER_REQUIRED=true`, missing, stale, failed or mismatched verification prevents automatic mail. With false, the app's configured From may be used, never an unverified merchant From. Test mail may use app fallback and retains `[TEST]` and its test banner; existing test-tool restrictions still apply.

## J. Security protections

Authenticated tenant-scoped routes retain embedded CSRF protection. Save/verify endpoints are throttled; forged shop/provider IDs and browser verification claims are rejected. Names and addresses reject header/control injection. Domain IDs and API tokens are absent from merchant pages. API exceptions are sanitized without chained request exceptions. Fixed HTTPS API hosts disable redirects. Shops sharing a domain cannot inherit one another's ownership proof. Uninstall disables the sender; redaction deletes tenant sender data without deleting a shared provider domain.

Provider rejection becomes FAILED/manual review. A timeout or ambiguous acceptance becomes UNKNOWN and is never automatically resent. Successful provider acceptance stores the MessageID; SENT does not assert inbox delivery. No delivery/bounce webhook processing is added. A message already accepted by the provider cannot be recalled.

## K. Validation

The full existing suite and new sender/DNS regressions run on SQLite and isolated local MySQL. Coverage includes verified From and Reply-To, cross-shop proof, unsafe input, DNS removal, stale verification, provider failure, deduplication, disconnect, fallback, missing configuration and existing Shopify/billing/shipping protections. No real provider mail or domain provisioning is performed by these tests. Final results: 141 tests / 613 assertions passed on both SQLite and isolated MySQL; Pint and configuration, route and Blade cache checks passed.

## L. Deployment commands

Back up the database and APP_KEY, pause cron workers and keep the global mail safety switch enabled during setup. Configure the variables above on the server, then:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan chargeguard:health-check
php artisan queue:restart
```

Resume the existing documented database-queue cron after validation. All processes must use a shared lock-capable cache; the current single-host file cache is suitable, while multiple hosts require shared locking. Never use `migrate:fresh` on application data. Preserve claims and sender tables during rollback.

## M. Migration commands

```sh
php artisan migrate:status
php artisan migrate --force
```

The additive migration has been applied successfully to the local application database. Production still requires the same migration during deployment.

## N. Manual production validation

Configure account/server tokens, verify a real merchant domain through the UI, inspect a controlled test message's From/Reply-To and authentication headers, and reconcile its MessageID with Postmark. Confirm verification failure blocks sends, then explicitly enable merchant automation only after all existing launch checks. Test shared-domain proof with separate shops if using that workflow. Check health, queue cron and production embedded authentication. No live deployment or DNS changes were performed here.

## O. Remaining blockers and limits

Live account approval/credentials, published DNS, real mail acceptance/delivery and hosting checks remain external steps. Existing documented Laravel security advisories remain a public-launch blocker; this feature does not upgrade Laravel. Verification is bounded by DNS caches and a 15-minute application TTL. Existing account-domain recovery scans at most 5,000 domains; larger accounts require operational reconciliation. Disconnect intentionally leaves provider records; operators must consider other shops before provider cleanup. Review provider retention separately from local privacy deletion.

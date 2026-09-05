# Testing ChargeGuard

## Level 1 — PHPUnit

```sh
php artisan test
```

Default tests use isolated SQLite memory databases and Mail::fake/Queue::fake or injected HTTP responses. No Shopify credentials, real customer records, or external emails are needed. The local MySQL helper creates a random chargeguard_test_* database, runs the suite, then drops only that database:

```sh
php scripts/test-mysql.php
```

It uses local MySQL credentials from configuration and requires CREATE/DROP DATABASE privileges. Tests refuse to run destructive migrations against non-test MySQL databases. The application database is not used for these tests.

The suite covers actual SDK signature/JWT verification and mocked token exchange, tenant page authorization, all 20 combinations, shipping ambiguities, escaped rendering/injection, master switch, test isolation, recipient integrity, duplicate event/delivery protection, update-only processing, uninstall, privacy exports/redaction, database queue insertion, SMTP uncertainty, GraphQL failures, and Partner billing checks.

Additional checks:

```sh
php vendor/bin/pint --test
php artisan view:cache
php artisan route:cache
php artisan route:clear
composer validate --no-check-publish
composer audit
```

The audit currently reports Laravel 10 advisories. It is a release concern, not a test failure to hide.

## Level 2 — internal Test Automation

Open the embedded app from your installed development store. Open **Test Automation**, select the reason/state, enter a test customer name and your controlled test address, adjust sample order/tracking information, and send.

Run:

```sh
php artisan queue:work database --stop-when-empty
```

Inspect Email Logs filtered to tests. The subject has [TEST], body shows TEST MODE, and no Dispute record is created. The same stored merchant template, TemplateRenderer, EmailComposer, Laravel Mailable and configured mail transport are used. Test messages work with the master switch or individual production template disabled; they never grant production activation by themselves.

Local manual mail options:

- MAIL_MAILER=log: inspect storage/logs locally, using sample data only.
- SMTP: use a sandbox inbox such as Mailtrap, or your configured Postmark/SES/other SMTP provider. Configure credentials privately.
- Automated tests use Mail::fake(), not real SMTP.

## Exact 20-case production rule matrix

All Yes results below assume a supported open **initial real Shopify** dispute, a reliable associated order/customer email, active installed shop, completed onboarding, master switch enabled, test modes off, enabled template, verified billing, and no prior initial delivery. Internal test sends are separately marked tests.

| Dispute reason | Shipping state | Expected template | Auto email? | Manual review? |
| --- | --- | --- | --- | --- |
| PRODUCT_NOT_RECEIVED | UNFULFILLED | PRODUCT_NOT_RECEIVED + UNFULFILLED | Yes, once | No |
| PRODUCT_NOT_RECEIVED | TRACKING_ADDED | PRODUCT_NOT_RECEIVED + TRACKING_ADDED | Yes, once | No |
| PRODUCT_NOT_RECEIVED | IN_TRANSIT | PRODUCT_NOT_RECEIVED + IN_TRANSIT | Yes, once | No |
| PRODUCT_NOT_RECEIVED | OUT_FOR_DELIVERY | PRODUCT_NOT_RECEIVED + OUT_FOR_DELIVERY | Yes, once | No |
| PRODUCT_NOT_RECEIVED | DELIVERED | PRODUCT_NOT_RECEIVED + DELIVERED | Yes, once | No |
| PRODUCT_UNACCEPTABLE | UNFULFILLED | PRODUCT_UNACCEPTABLE + UNFULFILLED | Yes, once | No |
| PRODUCT_UNACCEPTABLE | TRACKING_ADDED | PRODUCT_UNACCEPTABLE + TRACKING_ADDED | Yes, once | No |
| PRODUCT_UNACCEPTABLE | IN_TRANSIT | PRODUCT_UNACCEPTABLE + IN_TRANSIT | Yes, once | No |
| PRODUCT_UNACCEPTABLE | OUT_FOR_DELIVERY | PRODUCT_UNACCEPTABLE + OUT_FOR_DELIVERY | Yes, once | No |
| PRODUCT_UNACCEPTABLE | DELIVERED | PRODUCT_UNACCEPTABLE + DELIVERED | Yes, once | No |
| FRAUDULENT | UNFULFILLED | FRAUDULENT + UNFULFILLED | Yes, once | No |
| FRAUDULENT | TRACKING_ADDED | FRAUDULENT + TRACKING_ADDED | Yes, once | No |
| FRAUDULENT | IN_TRANSIT | FRAUDULENT + IN_TRANSIT | Yes, once | No |
| FRAUDULENT | OUT_FOR_DELIVERY | FRAUDULENT + OUT_FOR_DELIVERY | Yes, once | No |
| FRAUDULENT | DELIVERED | FRAUDULENT + DELIVERED | Yes, once | No |
| CREDIT_NOT_PROCESSED | UNFULFILLED | CREDIT_NOT_PROCESSED + UNFULFILLED | Yes, once | No |
| CREDIT_NOT_PROCESSED | TRACKING_ADDED | CREDIT_NOT_PROCESSED + TRACKING_ADDED | Yes, once | No |
| CREDIT_NOT_PROCESSED | IN_TRANSIT | CREDIT_NOT_PROCESSED + IN_TRANSIT | Yes, once | No |
| CREDIT_NOT_PROCESSED | OUT_FOR_DELIVERY | CREDIT_NOT_PROCESSED + OUT_FOR_DELIVERY | Yes, once | No |
| CREDIT_NOT_PROCESSED | DELIVERED | CREDIT_NOT_PROCESSED + DELIVERED | Yes, once | No |

Also verify:

| Case | Expected result |
| --- | --- |
| Unsupported dispute reason | Store raw reason; manual review; no automatic mail |
| Unknown/mixed/partial/truncated fulfillment data | Manual review; no automatic mail |
| Missing order/customer email | Manual review with explanation |
| No movement, tracking available | TRACKING_ADDED |
| DELAYED | IN_TRANSIT, raw DELAYED retained |
| ATTEMPTED_DELIVERY | OUT_FOR_DELIVERY, raw status retained |
| Updated dispute or resync | Sync only; no initial email |
| Duplicate webhook ID or a second create delivery ID | One initial delivery maximum |
| Shop inactive/uninstalled | Cancel queued sending |
| Recipient changed after processing | Manual review; no substitute address |
| SMTP outcome uncertain or worker interruption | Review provider records; no automatic resend |
| Customer redacted while Shopify fetch is running | Protected data stays redacted |
| Test log or demo dispute | Excluded from production metrics |
| Wrong tenant ID | 404; no disclosure or mutation |
| URL plan_handle changed | No entitlement granted without Partner API verification |

## Level 3 — development store and CLI webhooks

```sh
npm install -g @shopify/cli@latest
shopify app dev --reset
shopify app dev
shopify app webhook trigger
```

The interactive webhook command lets you choose the topic/version/delivery address. For an explicit create test, current CLI flags can be inspected with `shopify app webhook trigger --help`; choose disputes/create, API 2026-07, and `https://YOUR_TUNNEL/webhooks/shopify/disputes/create`.

Shopify CLI's synthetic dispute/order IDs may not exist in your store. A successful verified webhook therefore may produce manual review instead of a real email. The app deliberately does not trust a synthetic payload as the source of a customer address. Internal Test Automation is the primary complete reason/state/template/mail test.

For a real accessible Shopify Payments dispute, verify the order association using GraphQL, keep automation disabled initially, then resync:

```sh
php artisan chargeguard:sync-dispute your-store.myshopify.com 123456789
```

Resync intentionally never emails. To exercise automatic sending, a genuine new supported dispute must arrive **after** onboarding and explicit activation, with test modes off and billing verified. Do not use a fabricated sample as proof of a live dispute integration.

Check all six webhook topics, duplicate delivery responses, queue processing, uninstall/reinstall, privacy export fulfillment, and the Shopify-native desktop/mobile UI in the real embedded context. This workspace cannot simulate Shopify approval, carrier data availability, or deliverability.

## Demo data

```sh
php artisan db:seed --class=DemoDataSeeder
```

Allowed only in local/testing. It creates a separate demo shop and 20 visibly demo disputes with automation off. Factories support custom local fixtures without needing a genuine dispute. Demo records never create customer emails. See README for the local read-only preview.

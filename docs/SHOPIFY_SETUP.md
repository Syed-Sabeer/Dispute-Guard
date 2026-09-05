# Shopify setup

1. Create or sign into your Shopify developer/Partner organization. The app and development store should belong to your organization for the simplest pricing tests.
2. Create an app intended for public App Store distribution in Shopify's Dev/Partner dashboards. Record its client ID, secret, numeric App GID, app handle, and Partner organization ID privately.
3. Create a development store. Add sample products, a sample customer using an address you control, and sample orders. Do not rely on the development store to create a genuine card-network dispute.
4. Install the current Shopify CLI using `npm install -g @shopify/cli@latest`. No Node backend is used.
5. Link this existing project: `shopify app config link`. Review generated changes so the repository's webhook subscriptions and minimum scopes remain present. The checked-in empty client_id is a placeholder, not a valid configured app.
6. Set SHOPIFY_API_KEY and SHOPIFY_API_SECRET in Laravel's private .env. Clear config cache when changing local credentials. Start `shopify app dev --reset` and choose your development store; use `shopify app dev` thereafter.
7. Configure App Home as embedded with application URL `https://YOUR_HOST/dashboard`. The CLI tunnel may update the URL; ensure Laravel APP_URL matches its origin and has no /dashboard suffix. APP_URL is the origin, while Shopify application_url includes /dashboard.
8. Register `https://YOUR_HOST/auth/patch-id-token` in allowed redirect URLs. The app uses managed installation + token exchange, not an invented legacy OAuth callback. The patch route is the official SDK's ID-token recovery page.
9. Request only `read_orders,read_shopify_payments_disputes`. No write/evidence scopes. Without read_all_orders, orders outside Shopify's normal order-access window can require manual review.
10. Review the six webhook subscriptions in shopify.app.toml. Each URI maps to its HMAC-verified Laravel endpoint. Dispute topics and uninstall use topics; the three mandatory privacy topics use compliance_topics.
11. Request the required **Level 2 protected customer data** access for customer email/name before public production usage. Explain data minimization, encrypted token/content storage, retention, recipient use, and privacy workflows. Missing approval can make order/customer information unavailable and block automation.
12. Configure **Shopify App Pricing in the Partner Dashboard**. Create Starter, Growth, and Pro or your preferred plans, with plan handles stored in SHOPIFY_PLAN_*. Set the post-selection/welcome link to the embedded billing page. Configure distinct entitlement item handles and store them in SHOPIFY_ITEM_*. An item handle is not necessarily the same as a plan handle.
13. As organization owner, create a Partner API client with permissions appropriate for active subscription access (review the current API permission panel, including Manage apps). Set SHOPIFY_PARTNER_ID, SHOPIFY_PARTNER_TOKEN, SHOPIFY_APP_ID and version. Use the Partner GraphiQL explorer to run resources/graphql/active-subscription.graphql for the actual App and Shop GIDs. Confirm returned active price item handles match configuration.
14. Select a plan on the hosted pricing page and return to /billing. The backend verifies activeSubscription; the plan_handle redirect parameter is not trusted. Test null, cancelled/frozen/unavailable states. Shopify App Pricing no longer uses subscription-change webhooks after April 28, 2026.
15. For no-charge tests, use plans available to your development store in your Partner organization or the private test plan. Configure an allowed item mapping for the test plan if needed. A development bypass exists only for APP_ENV=local/testing with BILLING_ENFORCED=false; it is not a production solution.
16. In the app, set support/reply-to, review all templates, send labelled test emails, and inspect logs. Keep merchant automation off during setup.
17. Set real production HTTPS URLs, SMTP credentials and authenticated domain, MySQL and cron. Keep APP_DEBUG=false, CHARGEGUARD_TEST_MODE=false, BILLING_ENFORCED=true.
18. Run `php artisan chargeguard:shopify-config`, then `shopify app deploy`. This publishes Shopify app configuration/subscriptions; upload Laravel separately to cPanel as described in CPANEL_DEPLOYMENT.md.
19. Before App Store submission, verify actual installation and reinstall, merchant navigation/mobile layout, minimum permissions, privacy webhook behavior, secure export fulfillment, billing gating, protected data access, test email delivery, uninstall cancellation, support/privacy policy URLs, listing content, and reviewer instructions. Review the Laravel 10 security maintenance issue documented in ARCHITECTURE.md.

## Webhook endpoints

| Shopify topic | POST URI |
| --- | --- |
| disputes/create | /webhooks/shopify/disputes/create |
| disputes/update | /webhooks/shopify/disputes/update |
| app/uninstalled | /webhooks/shopify/app/uninstalled |
| customers/data_request | /webhooks/shopify/customers/data_request |
| customers/redact | /webhooks/shopify/customers/redact |
| shop/redact | /webhooks/shopify/shop/redact |

Unknown shops are acknowledged and ignored; install through verified App Home before testing processing. An invalid signature returns 401. Duplicate X-Shopify-Webhook-Id deliveries cannot enqueue twice.

## References checked for this implementation

- [Official PHP package](https://github.com/Shopify/shopify-app-php)
- [Shopify Payments dispute, API 2026-07](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/ShopifyPaymentsDispute)
- [Order and fulfillments](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Order)
- [Refund transaction context](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Refund)
- [App Pricing](https://shopify.dev/docs/apps/launch/billing/shopify-app-pricing)
- [Partner activeSubscription](https://shopify.dev/docs/api/partner/latest/queries/activeSubscription)
- [Partner authentication](https://shopify.dev/docs/api/partner/latest)
- [App configuration](https://shopify.dev/docs/apps/build/cli-for-apps/app-configuration)
- [Current App Home navigation](https://shopify.dev/docs/api/app-home/app-bridge-web-components/app-nav)

These are schema/documentation checks. Real account approval, live API responses, app installation, and App Store publication remain external verification tasks.

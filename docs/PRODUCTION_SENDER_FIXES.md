# Sender production safeguards

The current design and deployment procedure are documented in [Merchant email sending](MANAGED_SENDING.md).

Customer follow-ups and Test Automation require the exact verified merchant sender. Application-owned sending is limited to mailbox verification and explicit internal operator diagnostics. There is no customer/test fallback mode or flag.

Both tokens are required by production health-check. Account Token manages signatures/domains; Server Token sends mail. Never print token values or provider bodies. Keep global customer safety mode enabled and billing disabled during controlled validation.

The operator-only chargeguard:postmark-smoke-test remains a separate infrastructure diagnostic. It requires an explicitly supplied operator recipient and production confirmation. It is not Test Automation and cannot certify a merchant sender. Never automatically retry uncertain outcomes. No smoke test was sent during implementation.

Queued identities are immutable. Per-shop ownership, verification freshness, privacy/uninstall, tenant scope, bounded network calls and uncertain-delivery no-retry remain required.

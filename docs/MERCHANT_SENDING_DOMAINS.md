# Advanced merchant domain authentication

Standard setup uses exact-email Postmark Sender Signatures and per-shop mailbox verification without DNS. See [current merchant sending](MANAGED_SENDING.md) for configuration, deployment and the standard flow.

Advanced DOMAIN mode remains available for merchants who control DNS and want DKIM + Return-Path authentication. It always uses the exact saved merchant business email; customer and test automation never use an application fallback.

Save using Advanced domain authentication. Publish the displayed DKIM TXT, Return-Path CNAME and shop-specific ownership TXT records, then select Check verification. Postmark flags alone are insufficient: current public records and this shop's ownership proof must match. Keep records published.

Shared domains reuse the provider domain record, but each Shopify shop has its own TXT challenge. A second shop cannot inherit proof. Provider IDs and verified status cannot be set in the browser.

Freshness lasts 15 minutes; stale verification is refreshed before transport. Failed checks block sending while preserving automation preference. Email/mode changes reset proof and invalidate queued snapshots. No automatic fallback is permitted.

Account Token manages domains/signatures; Server Token sends mail. Provider responses and sensitive exceptions remain sanitized. DNS and provider lookups have bounded timeouts.

Existing DOMAIN records retain their mode and proof through the additive sender-signature migration. Selecting Standard explicitly requires fresh mailbox verification and Postmark confirmation.

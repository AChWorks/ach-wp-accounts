# Development and verification

The canonical product contract is [PROJECT-SPEC.md](PROJECT-SPEC.md). This guide describes the currently implemented foundation and how to test it. It is not a production installation or migration guide.

## Local test fixture

From a Linux checkout with PHP CLI (8.1 or newer), mysqli, MariaDB server/client tooling, curl, tar and unzip:

    scripts/test-integration.sh

The script downloads exact WordPress 7.1.5 and WooCommerce 11.3.1 archives by default. Override PHP_BIN, WP_VERSION and WC_VERSION for other explicit combinations; provide WP_ARCHIVE and WC_ARCHIVE to reuse the corresponding downloaded archives. The fixture checks their installed versions. It creates a private temporary WordPress installation and MariaDB data directory, starts its own socket-only server and removes that fixture on exit. It does not require an existing database or production credentials. Run as an ordinary development user. No external provider requests are needed: HTTP response fixtures intercept all adapter test requests.

The workflow exercises PHP syntax, actual database transactions and concurrent processes, provider HTTP request/response handling, native Abilities permissions, plugin activation/deactivation, and coexistence with WooCommerce. The WooCommerce fixture also proves the distinct native registration contracts: wp_insert_user accepts an empty email, while wc_create_new_customer requires a valid email. This is not proof of a completed authentication or checkout flow.

## Foundation boundaries

- Plugin-owned transactional tables enforce unique native user IDs and canonical mobile numbers, durable send reservations, attempt counts and short-lived proof consumption.
- Verification is internal, not a public REST/login endpoint. Application adapters must enforce flow creation, freshness and binding to the authorized browser/account/session, protect CSRF, derive network identity from trusted server context, and control active challenge selection and replay after retention. A raw challenge, phone, code or proof supplied to an unrelated route does not authorize account access.
- The initial policy uses a 300-second code lifetime, 600-second continuation lifetime, five verification attempts and 60-second identity cooldown; hourly mobile/network/site issuance limits are 5/60/300 and daily site limit is 2000. These are development defaults, not a production traffic or financial-cost policy. Fixed-window quotas count reserved send attempts (not SMS segments or money). The settings/application slice must validate operational policy and account for provider costs and proxy/NAT topology. WordPress auth salt rotation invalidates outstanding proof/hash-derived quota keys; coordinate it with account recovery and provider operations.
- The normalization check accepts Iranian mobile number families (90, 91, 92, 93, 99 except public-trunk 9950), not fixed or service families. It does not assert that a number is allocated or active. Reference: [CRA numbering plan published by ITU, 2025-04-20](https://www.itu.int/dms_pub/itu-t/oth/02/02/T02020000660032PDFE.pdf). Maintain this check as numbering classifications evolve. Kavenegar can convert a fixed-line verification request into a voice call, so this distinction matters.
- The provider registry implements only the two approved providers. Neither account login nor checkout gates, registration, identity assignment/recovery, production settings, privacy export/erasure, complete Persian translations, or Digits migration are implemented by this slice. Multisite activation is blocked in this development build until site/network identity ownership is resolved. State-preserving activation is tested; subsequent schema upgrades and uninstall/purge require their own validation.

## Messaging contracts

Official sources:
- [Kavenegar REST](https://kavenegar.com/rest.html): Verify Lookup, SMS Send, Account Info, response and message status tables, template configuration.
- [Melipayamak pattern sending](https://www.melipayamak.com/api/sendbybasenumber2/) and [ordinary SMS](https://www.melipayamak.com/api/sendsimplesms2/): HTTPS REST endpoints, ordered pattern arguments separated by semicolons, response codes and provider receipt identifiers.

Kavenegar uses an API key and a named template/token set, or an explicitly selected ordinary message. Melipayamak uses username/API key in the provider credential fields and an approved body ID with ordered values, or ordinary text. The internal registry accepts a default configuration and explicit overrides for login/link/step-up purposes. No automatic fallback, retry or live request happens during plugin activation, discovery, or tests.

Transport uses WordPress HTTP, HTTPS certificate verification, no redirects, bounded response size and timeout. Treat known request rejection or terminal non-delivery differently from acceptance and uncertain outcomes; keep machine status/reference only. An accepted provider receipt does not prove SMS delivery. The test connection operations do not send SMS. Actual credentials, response shapes, templates, delivery and provider-side retry/fallback settings need authorized staging verification. In particular ensure that Kavenegar templates and provider account options do not unexpectedly enable voice fallback or automatic duplicate sends. Do not enable HTTP request tracing that records OTPs, provider credentials or credential-bearing URLs; WordPress third-party HTTP hooks must also preserve these secrets.

## Checkout integration evidence

In WooCommerce 11.3.1 source, classic checkout executes woocommerce_checkout_process / woocommerce_after_checkout_validation, then woocommerce_checkout_order_processed before process_payment. Store API Checkout runs woocommerce_store_api_checkout_order_processed before its payment processing, but after order creation/update; guard the relevant customer request earlier as well as the actual payment boundary. Native order-pay runs woocommerce_before_pay_action after its nonce/order ownership checks; this hook is outside the payment try/catch, so do not use an uncaught exception as the gate. Verify exact native hooks, cart/session behavior, relevant express/payment plugin paths and negative requests in the subsequent commerce slice. These are observed integration points, not established bypass coverage.

## Native discovery

On WordPress versions exposing the Abilities API, ach-wp-accounts/status is registered as a read-only ability requiring manage_options. It returns the stored and expected schema versions, implemented provider capabilities and the current availability of authentication and checkout policy. No provider credentials, identity data or proof material are included. The status capability does not claim table integrity, provider connectivity or production readiness. Native execution permission checks are tested; actual MCP/Bridge discovery remains a staging acceptance item.

# ACh WP Accounts

A focused WordPress account extension: unified passwordless mobile login and registration, verified account identities, WooCommerce integration, and extensible messaging providers.

Use native WordPress users, roles, sessions and WooCommerce account/order ownership. The plugin supports SMS sign-in and registration with Kavenegar and Melipayamak, verified mobile identities, optional native recovery credentials, WooCommerce checkout verification, protected administration and diagnostics, and English/Persian localization.

Ordinary guest sign-in and signup use a standalone minimal mobile-first page. Operators can independently permit public email registration and customer password sign-in. The mandatory mobile checkout policy keeps native checkout visible and requests verification when payment is attempted. Native user and WooCommerce customer lists expose the stored mobile identity, verification status/date and mobile search without copying identity into user metadata.

The plugin also supports reviewed Digits migration directly on the site through native Abilities (including Gateway/MCP) or WP-CLI. The existing native accounts and data remain authoritative. Provider delivery, target-specific integrations, exact site mapping, backup/recovery and customer-session cutover must be validated before enabling the affected live policies.

- [Project specification and architecture](docs/PROJECT-SPEC.md) — canonical product intent, contracts, implementation boundaries and acceptance criteria.
- [Development and verification](docs/DEVELOPMENT.md) — reproducible fixtures, implemented boundaries and provider contracts.
- [GitHub Issues](https://github.com/AChWorks/ach-wp-accounts/issues) — implementation work and unresolved decisions.

## Migrating from Digits

Install the ZIP and leave mobile sign-in, signup and mandatory checkout verification disabled (the defaults) until configuration and migration are validated. Use the [site-local migration workflow](docs/DEVELOPMENT.md#direct-site-local-migration-recommended) via the native Abilities or WP-CLI. Preview first, confirm the exact mapping digest, apply and verify the results; existing WordPress user IDs, orders and roles are preserved. Imported phone mappings do not establish possession; fresh OTP is required. Imported accounts without an existing native email can add a confirmed recovery email and optional password after fresh mobile proof, without creating a new account. Retain Digits data until the separately approved cutover is verified.

## Distribution

Requires WordPress **6.3 or newer** and PHP **8.1 or newer**. WooCommerce is optional; its own compatibility requirements apply. Download `ach-wp-accounts.zip` from the [latest stable release](https://github.com/AChWorks/ach-wp-accounts/releases/latest) and install it using **Plugins → Add New → Upload Plugin**. The active plugin uses native WordPress update checks. Do not install GitHub's automatically generated source archives. No GitHub token is required. See [release preparation and verification](docs/DEVELOPMENT.md#release-packaging-and-native-updates).

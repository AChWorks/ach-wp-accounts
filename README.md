# ACh WP Accounts

A focused WordPress account extension: unified passwordless mobile login and registration, verified account identities, WooCommerce integration, and extensible messaging providers.

**Development status:** the candidate implements mobile authentication and registration, native account linking and recovery, both SMS adapters, shared customer/admin UI, WooCommerce checkout qualification, operator Abilities, reviewed Digits mapping import, interrupted-registration reconciliation, versioned installation, privacy operations and explicit local purge, runtime-only release packaging and native GitHub updates. Isolated WordPress-only and WooCommerce integration fixtures exercise the native account lifecycle, successful fixture-gateway payments (including first mobile linking after native email login), migration and maintenance operations. Local HTTPS Chromium checks exercise actual mobile signup, cart preservation, classic checkout and recovery-email confirmation; optional isolated tests use the real WP AI Bridge and MCP Adapter and a Redis Object Cache drop-in. The installed plugin supports on-demand Digits source preview and confirmed import through Gateway/MCP or local WP-CLI, without transferring raw customer phone lists or treating legacy numbers as proof; the Blinko mapping audit is recorded in Issue #1. Independent review and owner approval remain release gates. Exact site mapping, SMS delivery, customer-session cutover and site-specific integrations must be validated before enabling the affected production paths. No stable release is currently published.

- [Project specification and architecture](docs/PROJECT-SPEC.md) — canonical product intent, contracts, implementation boundaries and acceptance criteria.
- [Development and verification](docs/DEVELOPMENT.md) — reproducible fixtures, implemented boundaries and provider contracts.
- [GitHub Issues](https://github.com/AChWorks/ach-wp-accounts/issues) — implementation work and unresolved decisions.

The plugin reuses WordPress users, roles, sessions, and WooCommerce account/order ownership. WooCommerce is optional. English source and Persian translations are included.

## Migrating from Digits

Install the ZIP and leave mobile sign-in, signup and mandatory checkout verification disabled until configuration and migration are validated. Use the [site-local migration workflow](docs/DEVELOPMENT.md#direct-site-local-migration-recommended) via the native Abilities or WP-CLI. Preview first, confirm the exact mapping digest, apply and verify the results; existing WordPress user IDs, orders and roles are preserved. Imported phone mappings do not establish possession; fresh OTP is required. Imported accounts without an existing native email can add a confirmed recovery email and optional password after fresh mobile proof, without creating a new account. Retain Digits data until the separately approved cutover is verified.

## Distribution

Requires WordPress **6.3 or newer** and PHP **8.1 or newer**. WooCommerce is optional; its own compatibility requirements apply. A stable GitHub Release will provide `ach-wp-accounts.zip` for **Plugins → Add New → Upload Plugin** and native update checks for the active plugin. Do not install GitHub's automatically generated source archives. No GitHub token is required. See [release preparation and verification](docs/DEVELOPMENT.md#release-packaging-and-native-updates). No stable release is currently published.

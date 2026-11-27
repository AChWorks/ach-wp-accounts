# ACh WP Accounts

A focused WordPress account extension: unified passwordless mobile login and registration, verified account identities, WooCommerce integration, and extensible messaging providers.

**Development status:** the candidate implements mobile authentication and registration, native account linking and recovery, both SMS adapters, shared customer/admin UI, WooCommerce checkout qualification, operator Abilities, reviewed Digits mapping import, interrupted-registration reconciliation, versioned installation, privacy operations and explicit local purge, runtime-only release packaging and native GitHub updates. Isolated WordPress-only and WooCommerce integration fixtures exercise the native account lifecycle, successful fixture-gateway payments (including first mobile linking after native email login), migration and maintenance operations. Local HTTPS Chromium checks exercise actual mobile signup, cart preservation, classic checkout and recovery-email confirmation; optional isolated tests use the real WP AI Bridge and MCP Adapter and a Redis Object Cache drop-in. The development-only Digits audit and scale rehearsal support private migration preparation without treating legacy numbers as proof; the Blinko mapping audit is recorded in Issue #1. Actual legacy proof/recovery approval, real provider delivery, target-store integrations, the remaining browser/cache staging matrix and independent review remain separate release gates. No production-ready release exists.

- [Project specification and architecture](docs/PROJECT-SPEC.md) — canonical product intent, contracts, implementation boundaries and acceptance criteria.
- [Development and verification](docs/DEVELOPMENT.md) — reproducible fixtures, implemented boundaries and provider contracts.
- [GitHub Issues](https://github.com/AChWorks/ach-wp-accounts/issues) — implementation work and unresolved decisions.

The plugin reuses WordPress users, roles, sessions, and WooCommerce account/order ownership. WooCommerce is optional. English source and Persian translations are included.

## Distribution

Requires PHP **8.1 or newer**. A stable GitHub Release will provide `ach-wp-accounts.zip` for **Plugins → Add New → Upload Plugin** and native update checks for the active plugin. Do not install GitHub's automatically generated source archives. No GitHub token is required. See [release preparation and verification](docs/DEVELOPMENT.md#release-packaging-and-native-updates). No stable release is currently published.

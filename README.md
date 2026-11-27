# ACh WP Accounts

A focused WordPress account extension: unified passwordless mobile login and registration, verified account identities, WooCommerce integration, and extensible messaging providers.

**Development status:** verification, messaging, native account/session flows, shared customer UI, optional native recovery enrollment and core WooCommerce checkout guards are implemented in the development candidate. Native email registration/login remains subject to site policy independently of the mobile checkout requirement. Native administration, the shared operator settings service, protected native Abilities, provider tests, bounded diagnostics, runtime-only release packaging and native GitHub update discovery/installation are implemented in the development candidate and exercised in isolated fixtures. Controlled recovery/lifecycle, migration, staging acceptance and independent review remain in progress. No production-ready release exists.

- [Project specification and architecture](docs/PROJECT-SPEC.md) — canonical product intent, contracts, implementation boundaries and acceptance criteria.
- [Development and verification](docs/DEVELOPMENT.md) — reproducible fixtures, implemented boundaries and provider contracts.
- [GitHub Issues](https://github.com/AChWorks/ach-wp-accounts/issues) — implementation work and unresolved decisions.

The plugin will reuse WordPress users, roles, sessions, and WooCommerce account/order ownership. It will work without WooCommerce; commerce integrations are optional. English source and Persian translations are required.

## Distribution

Requires PHP **8.1 or newer**. A stable GitHub Release will provide `ach-wp-accounts.zip` for **Plugins → Add New → Upload Plugin** and native update checks for the active plugin. Do not install GitHub's automatically generated source archives. No GitHub token is required. See [release preparation and verification](docs/DEVELOPMENT.md#release-packaging-and-native-updates). No stable release is currently published.

# ACh WP Accounts

A focused WordPress account extension: unified passwordless mobile login and registration, verified account identities, WooCommerce integration, and extensible messaging providers.

**Development status:** verification and messaging foundation under development. Native authentication, customer-facing UI, checkout policy and migration are not available. No production-ready release exists.

- [Project specification and architecture](docs/PROJECT-SPEC.md) — canonical product intent, contracts, implementation boundaries and acceptance criteria.
- [Development and verification](docs/DEVELOPMENT.md) — reproducible fixtures, implemented boundaries and provider contracts.
- [GitHub Issues](https://github.com/AChWorks/ach-wp-accounts/issues) — implementation work and unresolved decisions.

The plugin will reuse WordPress users, roles, sessions, and WooCommerce account/order ownership. It will work without WooCommerce; commerce integrations are optional. English source and Persian translations are required.

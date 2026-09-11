# Changelog

## 0.9.1 (2026-09-11)

- The "Configure Updates" links in the plugin list and the activation notice point to the settings page under Settings on single sites. They used to link to the non-existent `wp-admin/settings.php`.

## 0.9.0 (2026-09-11)

- The plugin update progress reports the verified package signature, along with the signing key used, right after the package download.

## 0.8.0 (2026-09-11)

- The `update_pilot__plugins` filter accepts `file` as an alias of the `plugin` key. Agent Pilot 0.10.0, OAuth Pilot 0.7.1 and Object Cache Pilot 3.1.0 register with `file`, so Update Pilot ignored their license and signing keys until now.

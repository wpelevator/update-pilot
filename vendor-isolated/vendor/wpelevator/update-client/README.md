# Update Pilot Client Library

Enable updates for your WordPress plugins and themes from a custom update server like [WP Elevator Update Server](https://wpelevator.com/plugins/update-pilot-server).

## Usage

To check for updates for plugins and themes hosted outside of the WordPress.org repository, the plugin doing the update check must be active. Importantly, in WordPress multisites the plugin must be active on the main network site as the updates happen only there.

This library enables the following update workflows:

1. Require a dedicated updater plugin to be installed and active (such as [Update Pilot](https://wpelevator.com/plugins/update-pilot)). Best approach since it enables network-only instalation which is decoupled from the plugin activation state.

2. Bundle the updater functionality with your plugin. Requires the plugin to be active (also on the main network site on multisite) to check for updates. Possible to move the updater logic into a separate plugin file within your plugin (yes, WordPress does support this) to enable the updater logic even when the core plugin is selectively disabled.

### Option 1: Require the Updater Plugin

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$update_notice = new WPElevator\Update_Client\Plugin_Require(
	[
		'download_url' => 'https://updates.wpelevator.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
		'basename' => 'update-pilot/update-pilot.php',
		'name' => 'Update Pilot',
		'notice' => 'The Update Pilot plugin is required to enable updates to Your Plugin.', // "Install" or "Activate" button is appended to this notice.
		'network' => true,
		'signing_key' => 'y6BGnVLNL2AZLLKJzRHrDBTvMri+cLtvmMHBnf1M2S8=', // Optional. Base64 encoded Ed25519 public key used to sign the plugin package.
		'license_key' => 'your-license-key', // Optional. License key used to authenticate the plugin download.
	]
);

add_action( 'plugins_loaded', [ $update_notice, 'init' ] );
```

### Option 2: Bundle the Updater

Register your plugin with the update server by adding the following to the main plugin file:

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$plugin_update = new WPElevator\Update_Client\Plugin_Update(
	plugin_basename( __FILE__ ),
	'https://updates.example.com/wp-json/update-pilot/v1/plugins',
	[
		'signing_key' => 'y6BGnVLNL2AZLLKJzRHrDBTvMri+cLtvmMHBnf1M2S8=', // Optional. Base64 encoded Ed25519 public key used to sign the update packages.
		'license_key' => 'your-license-key', // Optional. License key used to authenticate the update requests.
	]
);

add_action( 'plugins_loaded', [ $plugin_update, 'init' ] );
```

The update URL must implement the [WordPress plugin update API endpoint](https://wpelevator.com/guides/replace-wordpress-update-apis). The client posts the current plugin headers as a `plugins` JSON payload and returns the matching update object for the configured plugin basename. It also handles the WordPress plugin information modal through the same endpoint.

## License Key Authentication

Both `Plugin_Update` and `Plugin_Require` accept an optional `license_key` configuration value -- the license key required by the update server to authorize the requests. When set, the key is sent as an HTTP basic authorization header with the site hostname as the username (`base64( site-host:license-key )`), matching the WordPress application passwords convention used by the [Update Pilot](https://wpelevator.com/plugins/update-pilot) plugin and server.

For `Plugin_Update` the header is added to both the update check request and the update package download. For `Plugin_Require` the header is added to the download request of the required plugin (the `download_url`); once the required plugin is installed, it manages any license keys for the subsequent updates itself.

Instead of hardcoding the key in the configuration, it can also be resolved dynamically (from an option, a constant, a secrets store, etc.) using the configuration filters which can adjust any of the configuration values. The filter names are distinct for each class and include the basename of the plugin being updated (for `Plugin_Update`) or the basename of the required plugin (for `Plugin_Require`):

```php
// Adjust the Plugin_Update configuration for your plugin:
add_filter(
	'wpelevator_update_client__update_config__your-plugin/your-plugin.php',
	function ( array $config ): array {
		$config['license_key'] = get_option( 'your_plugin_license_key' );

		return $config;
	}
);

// Adjust the Plugin_Require configuration for the required plugin:
add_filter(
	'wpelevator_update_client__require_config__update-pilot/update-pilot.php',
	function ( array $config ): array {
		$config['license_key'] = get_option( 'your_plugin_license_key' );

		return $config;
	}
);
```

## Package Signature Verification

Both `Plugin_Require` and `Plugin_Update` accept an optional `signing_key` configuration value -- the base64 encoded Ed25519 public key matching the private key used by the update server (such as the [Update Pilot Server](https://wpelevator.com/plugins/update-pilot-server)) to sign the package ZIP files.

When the `signing_key` is set, the client downloads and verifies the associated packages itself through the `upgrader_pre_download` filter, and hands the verified file over to WP core for the install. `Plugin_Require` verifies the initial install of the required plugin (matched by the configured `download_url`), after which the installed plugin manages the signatures for its own updates. `Plugin_Update` verifies the update packages of the registered plugin (matched by its basename).

The signature handling itself is left to WP core: the client calls `download_url( $url, 300, true )` to request the verification that `WP_Upgrader` no longer asks for. `WP_Upgrader::run()` calls `download_package()` with `$check_signatures` set to `false` (changeset 58319 in WP 6.6, see [Trac #47315](https://core.trac.wordpress.org/ticket/47315)), so `download_url()` skips the whole verification step and every package install and update on WP 6.6+ is unverified unless the client requests it itself.

The `wp_signature_hosts`, `wp_signature_softfail` and `wp_trusted_keys` filters are registered around that one call only, at `PHP_INT_MAX` so nothing that registered earlier can undo them:

- The download host is added to the hosts requiring verification, which is limited to WordPress.org by default.

- The signature soft-fail is disabled, so a package with a missing or invalid signature is never installed. Should a soft-fail happen anyway, the client deletes the file and reports the error rather than passing it back to `WP_Upgrader::run()`, which would otherwise install the package regardless.

- The configured public key is added to the trusted signing keys only while our own package is verified, so it is never accepted for an unrelated package.

Because `download_url()` returns the same file path whether it verified the package or never looked at it, the client also treats a skipped verification as a failure. WP core calls `wp_trusted_keys()` from `verify_file_signature()` and nowhere else, so the trusted keys filter doubles as proof that the check actually ran.

The update server must provide the package signature as a base64 encoded Ed25519 signature of the raw SHA-384 digest of the package ZIP file, as expected by `verify_file_signature()` in WP core. The signature is read from the `X-Content-Signature` HTTP response header of the package download, which is how the Update Pilot Server serves it. Alternatively WP core fetches newline-separated signatures from a `{package-url}.sig` file, but only if the package URL path ends with `.zip` or `.tar.gz` (or if a custom signature URL is provided via the `wp_signature_url` filter), so this does not apply to the typical REST API download endpoints.

`Signed_Package::download()` throws a `RuntimeException` when the package cannot be downloaded or verified. `Plugin_Update` and `Plugin_Require` convert it into the `WP_Error` that the `upgrader_pre_download` filter expects.

Note that signature verification requires the PHP Sodium extension (or the `sodium_compat` polyfill bundled with WordPress core). `Plugin_Require` displays a warning notice on the Plugins screen if signature verification is not supported by the environment.

## TODO

- Document the namespace isolation.

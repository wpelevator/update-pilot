<?php

namespace WPElevator\Update_Pilot;

class Plugin_Signature_Test extends \WP_UnitTestCase {

	const PLUGIN_FILE = 'example-plugin/example-plugin.php';

	const OTHER_PLUGIN_FILE = 'other-plugin/other-plugin.php';

	const THEME = 'example-theme';

	const PACKAGE_URL = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

	const PACKAGE_CONTENTS = 'example-plugin-package-contents';

	const LICENSE_KEY = 'example-license-key';

	private Plugin $plugin;

	/**
	 * Request arguments of the package downloads made during the test.
	 *
	 * @var array
	 */
	private array $package_requests = [];

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$this->package_requests = [];

		$this->plugin = new Plugin( 'update-pilot/update-pilot.php' );

		// Matches what Plugin::init() registers for the package downloads.
		add_filter( 'http_request_args', [ $this->plugin, 'filter_package_download_add_auth_headers' ], 10, 2 );
	}

	/**
	 * Serve the package from the mocked update host, optionally signed.
	 */
	private function fake_package_download( ?string $signature ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $signature ) {
				if ( self::PACKAGE_URL !== $url ) {
					return $pre;
				}

				$this->package_requests[] = $args;

				file_put_contents( $args['filename'], self::PACKAGE_CONTENTS );

				return [
					'response' => [
						'code' => 200,
					],
					'headers' => $signature ? [ 'X-Content-Signature' => $signature ] : [],
				];
			},
			10,
			3
		);
	}

	/**
	 * Sign the raw SHA-384 digest of the package as expected by verify_file_signature().
	 */
	private function sign_package( string $secret_key ): string {
		return base64_encode(
			sodium_crypto_sign_detached(
				hash( 'sha384', self::PACKAGE_CONTENTS, true ),
				$secret_key
			)
		);
	}

	private function public_key( string $keypair ): string {
		return base64_encode( sodium_crypto_sign_publickey( $keypair ) );
	}

	/**
	 * Store the signing key where the Update Pilot settings screen writes it.
	 */
	private function set_plugin_signing_key( string $plugin_file, string $public_key ): void {
		update_site_option(
			sprintf(
				'update_pilot__vendor_signing_key_plugin__%s',
				str_replace( [ '/', '.' ], [ '--', '-' ], $plugin_file )
			),
			$public_key
		);
	}

	private function set_theme_signing_key( string $theme, string $public_key ): void {
		update_site_option(
			sprintf( 'update_pilot__vendor_signing_key_theme__%s', sanitize_key( $theme ) ),
			$public_key
		);
	}

	private function set_plugin_license_key( string $plugin_file, string $license_key ): void {
		add_filter(
			'update_pilot__plugins',
			function ( array $plugins ) use ( $plugin_file, $license_key ): array {
				$plugins[] = [
					'plugin' => $plugin_file,
					'license_key' => $license_key,
				];

				return $plugins;
			}
		);
	}

	/**
	 * @return string|bool|\WP_Error
	 */
	private function download_plugin_package( string $plugin_file = self::PLUGIN_FILE, $pre = false ) {
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->init();

		return $this->plugin->filter_upgrader_pre_download(
			$pre,
			self::PACKAGE_URL,
			$upgrader,
			[ 'plugin' => $plugin_file ]
		);
	}

	/**
	 * @return string|bool|\WP_Error
	 */
	private function download_theme_package( string $theme = self::THEME ) {
		$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->init();

		return $this->plugin->filter_upgrader_pre_download(
			false,
			self::PACKAGE_URL,
			$upgrader,
			[ 'theme' => $theme ]
		);
	}

	private function get_authorization_header( array $request_args ): ?string {
		return $request_args['headers']['Authorization'] ?? null;
	}

	private function get_expected_authorization_header(): string {
		return sprintf(
			'Basic %s',
			base64_encode( sprintf( '%s:%s', wp_parse_url( home_url(), PHP_URL_HOST ), self::LICENSE_KEY ) )
		);
	}

	public function test_signed_plugin_package_is_downloaded_and_returned() {
		$this->skip_without_sodium();

		$keypair = sodium_crypto_sign_keypair();

		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( $keypair ) );
		$this->set_plugin_license_key( self::PLUGIN_FILE, self::LICENSE_KEY );
		$this->fake_package_download( $this->sign_package( sodium_crypto_sign_secretkey( $keypair ) ) );

		$file = $this->download_plugin_package();

		$this->assertIsString( $file, 'The verified package file is handed over to the upgrader' );

		$this->assertSame(
			self::PACKAGE_CONTENTS,
			file_get_contents( $file ),
			'The package downloaded from the vendor is the one being installed'
		);

		$this->assertCount( 1, $this->package_requests, 'The package is downloaded by Update Pilot itself' );

		$this->assertSame(
			$this->get_expected_authorization_header(),
			$this->get_authorization_header( $this->package_requests[0] ),
			'The license key is sent with the package download started from the filter'
		);

		$this->unlink( $file );
	}

	public function test_package_signed_by_another_vendor_is_rejected() {
		$this->skip_without_sodium();

		$vendor_a = sodium_crypto_sign_keypair();
		$vendor_b = sodium_crypto_sign_keypair();

		// Both vendors are configured, as they would be on a site updating plugins from each.
		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( $vendor_a ) );
		$this->set_plugin_signing_key( self::OTHER_PLUGIN_FILE, $this->public_key( $vendor_b ) );

		// Vendor A's package arrives signed with vendor B's key.
		$this->fake_package_download( $this->sign_package( sodium_crypto_sign_secretkey( $vendor_b ) ) );

		$result = $this->download_plugin_package();

		$this->assertWPError( $result, 'A package signed by a different vendor is never installed' );

		$this->assertEmpty(
			$result->get_error_data( 'softfail-filename' ),
			'No package file is left behind for the upgrader to install anyway'
		);

		$this->assertNotContains(
			$this->public_key( $vendor_b ),
			apply_filters( 'wp_trusted_keys', [] ),
			'The key of one vendor is never trusted for the packages of another'
		);
	}

	public function test_unsigned_plugin_package_is_rejected() {
		$this->skip_without_sodium();

		$keypair = sodium_crypto_sign_keypair();

		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( $keypair ) );
		$this->fake_package_download( null );

		$result = $this->download_plugin_package();

		$this->assertWPError( $result, 'A package without a signature is never installed' );

		$this->assertEmpty(
			$result->get_error_data( 'softfail-filename' ),
			'No package file is left behind for the upgrader to install anyway'
		);
	}

	public function test_unsigned_plugin_package_is_rejected_even_when_softfail_is_forced() {
		$this->skip_without_sodium();

		$keypair = sodium_crypto_sign_keypair();

		// Other code cannot re-enable the soft-fail for the packages we verify.
		add_filter( 'wp_signature_softfail', '__return_true', PHP_INT_MAX );

		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( $keypair ) );
		$this->fake_package_download( null );

		$this->assertWPError(
			$this->download_plugin_package(),
			'A forced soft-fail does not install an unsigned package'
		);
	}

	public function test_plugin_package_without_signing_key_is_left_to_wp_core() {
		$this->set_plugin_license_key( self::PLUGIN_FILE, self::LICENSE_KEY );
		$this->fake_package_download( null );

		$this->assertFalse(
			$this->download_plugin_package(),
			'WP core downloads the package when no signing key is configured'
		);

		$this->assertCount( 0, $this->package_requests, 'Update Pilot does not download the package itself' );

		$this->assertSame(
			$this->get_expected_authorization_header(),
			$this->get_authorization_header( apply_filters( 'http_request_args', [], self::PACKAGE_URL ) ),
			'The license key is still sent with the package download made by WP core'
		);
	}

	public function test_theme_package_is_left_to_wp_core_until_theme_updates_are_implemented() {
		$this->set_theme_signing_key( self::THEME, self::LICENSE_KEY );

		$this->assertFalse(
			$this->download_theme_package(),
			'Theme package verification is not handled until theme updates are implemented'
		);
	}

	public function test_download_short_circuited_by_another_filter_is_respected() {
		$this->skip_without_sodium();

		$keypair = sodium_crypto_sign_keypair();

		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( $keypair ) );
		$this->fake_package_download( $this->sign_package( sodium_crypto_sign_secretkey( $keypair ) ) );

		$this->assertSame(
			'/tmp/package-from-another-filter.zip',
			$this->download_plugin_package( self::PLUGIN_FILE, '/tmp/package-from-another-filter.zip' ),
			'The package provided by another filter is used as is'
		);

		$this->assertCount( 0, $this->package_requests, 'No package is downloaded when the filter is short-circuited' );
	}

	public function test_local_package_file_is_left_to_wp_core() {
		$this->skip_without_sodium();

		$this->set_plugin_signing_key( self::PLUGIN_FILE, $this->public_key( sodium_crypto_sign_keypair() ) );

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->init();

		$this->assertFalse(
			$this->plugin->filter_upgrader_pre_download(
				false,
				'/var/www/uploads/example-plugin.zip',
				$upgrader,
				[ 'plugin' => self::PLUGIN_FILE ]
			),
			'Local package files are installed by WP core without a download'
		);
	}

	private function skip_without_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->markTestSkipped( 'The PHP Sodium extension is not available.' );
		}
	}
}

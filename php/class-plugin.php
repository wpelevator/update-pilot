<?php

namespace WPElevator\Update_Pilot;

use WP_Error;

class Plugin {

	private string $plugin_file;

	private array $update_errors = [];

	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public function init() {
		// Use this hook to register the custom Update URIs only when needed.
		add_filter( 'site_transient_update_plugins', [ $this, 'register_hostnames' ] );

		add_filter( 'plugins_api', [ $this, 'filter_plugins_api' ], 10, 3 );

		add_action( 'admin_notices', [ $this, 'show_update_errors' ] );
	}

	private function is_update_pilot_url( $url ) {
		return ( false !== strpos( $url, '/update-pilot/' ) );
	}

	private function get_plugin_slug_from_file( $plugin_file ) {
		if ( false !== strpos( $plugin_file, '/' ) ) {
			return dirname( $plugin_file );
		}

		return str_replace( '.php', '', $plugin_file ); // These are top-level plugins.
	}

	public function register_hostnames( $updates ) {
		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_data ) {
			$plugin_update_url = $plugin_data['UpdateURI'];

			if ( $this->is_update_pilot_url( $plugin_update_url ) ) {
				add_filter(
					sprintf( 'update_plugins_%s', wp_parse_url( $plugin_update_url, PHP_URL_HOST ) ),
					[ $this, 'filter_update_by_hostname' ],
					10,
					4
				);
			}
		}

		return $updates;
	}

	/**
	 * Filters the plugins API response.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested from the Plugin Installation API.
	 * @param object             $args   Plugin API arguments.
	 */
	public function filter_plugins_api( $response, $action, $args ) {
		if ( empty( $response ) && 'plugin_information' === $action ) {
			$plugin_file = $this->get_plugin_file_by_slug( $args->slug );
			$plugin_data = $this->get_plugin_data( $plugin_file );

			if ( ! empty( $plugin_data['UpdateURI'] ) && $this->is_update_pilot_url( $plugin_data['UpdateURI'] ) ) {
				return $this->get_plugin_information( $plugin_file, $args );
			}
		}

		return $response;
	}

	private function get_plugin_file_by_slug( string $slug ) {
		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( $this->get_plugin_slug_from_file( $plugin_file ) === $slug ) {
				return $plugin_file;
			}
		}

		return null;
	}

	private function get_plugin_data( string $plugin_file ) {
		$plugins = get_plugins();

		if ( ! empty( $plugins[ $plugin_file ] ) ) {
			return $plugins[ $plugin_file ];
		}

		return null;
	}

	public function get_plugin_information( string $plugin_file, object $args ) {
		$plugin_data = $this->get_plugin_data( $plugin_file );

		$info_url = apply_filters(
			'update_pilot__plugin_update_url', // TODO: Should this be different from update?
			$plugin_data['UpdateURI'],
			$plugin_file,
			$plugin_data
		);

		if ( empty( $info_url ) ) {
			return false;
		}

		$payload = [
			'action'  => 'plugin_information',
			'request' => $args,
		];

		$info_url = add_query_arg( $payload, $info_url ); // Account for not supporting TLS.

		$request = wp_remote_get(
			$info_url,
			[
				'timeout' => 15,
			]
		);

		if ( ! is_wp_error( $request ) ) {
			$plugin_info = json_decode( wp_remote_retrieve_body( $request ), true );

			if ( ! empty( $plugin_info ) ) {
				return (object) $plugin_info; // Match the WP core info response.
			}
		}

		return false;
	}

	public function show_update_errors() {
		if ( empty( $this->update_errors ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$error_messages = [];

		foreach ( $this->update_errors as $error ) {
			$error_messages[] = sprintf(
				'<li>%s</li>',
				esc_html( $error->get_error_message() )
			);
		}

		printf(
			'<div class="notice notice-error">
				<p>%s</p>
				<ul>%s</ul>
			</div>',
			esc_html__( 'The following errors occured when checking for updates:', 'update-pilot' ),
			implode( '', $error_messages )
		);
	}

	/**
	 * Do the custom update checks.
	 *
	 * @param array|false $update The update data.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin filename.
	 * @param string[]    $locales Installed locales to look up translations for.
	 */
	public function filter_update_by_hostname( $update, $plugin_data, $plugin_file, $locales ) {
		$update = $this->get_update_for_version( $plugin_file, $plugin_data, $locales );

		if ( is_wp_error( $update ) ) {
			$this->update_errors[] = $update;
		} elseif ( is_object( $update ) ) {
			return $update;
		}

		return false;
	}

	private function get_update_for_version( $plugin_file, $plugin_data, $locales ) {
		$plugins = [
			$plugin_file => $plugin_data,
		];

		$payload = [
			'body' => [
				'plugins' => wp_json_encode( $plugins ),
				'locale' => wp_json_encode( $locales ),
			],
		];

		$update_url = apply_filters(
			'update_pilot__plugin_update_url',
			$plugin_data['UpdateURI'],
			$plugin_file,
			$plugin_data
		);

		$response = wp_remote_post( $update_url, $payload );

		if ( is_wp_error( $response ) ) {
			$response->add(
				'update_pilot__update_error',
				sprintf(
					/* translators: 1: Plugin file, 2: Error message */
					__( 'Update for %1$s failed: %2$s' ),
					$plugin_file,
					$response->get_error_message()
				)
			);

			return $response;
		}

		$updates = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $updates[ $plugin_file ] ) ) {
			return (object) $updates[ $plugin_file ];
		}

		return false;
	}
}

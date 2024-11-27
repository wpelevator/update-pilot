<?php

namespace WPElevator\Update_Pilot;

use WPElevator\Update_Pilot\Settings\Field;
use WPElevator\Update_Pilot\Settings\Store_Site_Option;
use WPElevator\Update_Pilot\Settings\Update_Key;

class Plugin {

	private const TRANSIENT_NAME_ACTIVATED = 'update_pilot__activated';

	private const TRANSIENT_NAME_UPDATE_ERRORS = 'update_pilot__update_errors';

	private const OPTION_PREFIX = 'update_pilot__';

	private const SETTINGS_SLUG = 'update-pilot';

	private string $plugin_file;

	private array $update_errors = [];

	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	private function get_plugin_basename(): string {
		return plugin_basename( $this->plugin_file );
	}

	public function init() {
		// Update happen only on the main site if on multisite.
		if ( is_main_site() ) {
			// Use this hook to register the custom Update URIs only when needed.
			add_filter( 'site_transient_update_plugins', [ $this, 'register_hostnames' ] );

			add_filter( 'plugins_api', [ $this, 'filter_plugins_api' ], 10, 3 );

			add_action( 'admin_notices', [ $this, 'show_update_errors' ] );
			add_action( 'network_admin_notices', [ $this, 'show_update_errors' ] );

			add_action( 'admin_notices', [ $this, 'action_activate_notice' ] );
			add_action( 'network_admin_notices', [ $this, 'action_activate_notice' ] );

			if ( ! is_multisite() ) { // Only show this on single sites.
				add_action( 'admin_menu', [ $this, 'register_settings_pages' ] );
			}

			add_action( 'network_admin_menu', [ $this, 'register_settings_pages' ] ); // When on multisites.
			add_action( 'network_admin_edit_update', [ $this, 'action_update_network_settings' ] );
		}

		if ( is_admin() && $this->current_user_can_manage_updates() ) {
			add_filter( 'plugin_action_links_' . $this->get_plugin_basename(), [ $this, 'filter_plugin_action_links' ], 10, 3 );
			add_filter( 'network_admin_plugin_action_links_' . $this->get_plugin_basename(), [ $this, 'filter_plugin_action_links' ], 10, 3 );
		}
	}

	public static function uninstall() {
		global $wpdb;

		// Delete all single-site options.
		$options_to_delete = array_filter(
			array_keys( wp_load_alloptions() ),
			fn( $option_name ) => 0 === strpos( $option_name, self::OPTION_PREFIX )
		);

		foreach ( $options_to_delete as $option_name ) {
			delete_option( $option_name );
		}

		if ( is_multisite() ) {
			$site_option_keys = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM $wpdb->sitemeta WHERE site_id = %d", get_current_network_id() ) );

			if ( is_array( $site_option_keys ) ) {
				$site_option_keys = array_filter(
					$site_option_keys,
					fn( $option_name ) => 0 === strpos( $option_name, self::OPTION_PREFIX )
				);

				foreach ( $site_option_keys as $option_name ) {
					delete_site_option( $option_name );
				}
			}
		}
	}

	public static function activate() {
		set_transient( self::TRANSIENT_NAME_ACTIVATED, true, MINUTE_IN_SECONDS );
	}

	public function action_activate_notice() {
		if ( ! $this->current_user_can_manage_updates() ) {
			return;
		}

		$is_just_activated = (bool) get_transient( self::TRANSIENT_NAME_ACTIVATED );

		if ( ! $is_just_activated ) {
			return;
		}

		printf(
			'<div class="notice notice-success">
				<p>%s %s</p>
			</div>',
			__( 'Visit the Update Pilot settings to configure the updates!', 'update-pilot' ),
			sprintf(
				'<a href="%s" class="button">%s</a>',
				esc_url( $this->get_settings_url() ),
				esc_html__( 'Configure Updates', 'update-pilot' )
			)
		);

		delete_transient( self::TRANSIENT_NAME_ACTIVATED );
	}

	private function get_manage_updates_cap() {
		if ( is_multisite() ) {
			return 'manage_network_options';
		}

		return 'update_plugins';
	}

	/**
	 * If the current user can manage updates.
	 *
	 * @return bool
	 */
	private function current_user_can_manage_updates() {
		return (bool) apply_filters(
			'update_pilot__current_user_can_manage_updates',
			current_user_can( $this->get_manage_updates_cap() )
		);
	}

	private function is_update_pilot_url( $url ) {
		return apply_filters(
			'update_pilot__is_update_pilot_url',
			( false !== strpos( $url, '/update-pilot/' ) ),
			$url
		);
	}

	private function get_plugin_slug_from_file( $plugin_file ) {
		if ( false !== strpos( $plugin_file, '/' ) ) {
			return dirname( $plugin_file );
		}

		return str_replace( '.php', '', $plugin_file ); // These are top-level plugins.
	}

	public function get_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			return [];
		}

		return array_filter(
			get_plugins(),
			fn( $plugin_data ) => $this->is_update_pilot_url( $plugin_data['UpdateURI'] )
		);
	}

	public function get_themes(): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return [];
		}

		return array_filter(
			wp_get_themes(),
			fn( $theme ) => $this->is_update_pilot_url( $theme->get( 'UpdateURI' ) )
		);
	}

	public function register_hostnames( $updates ) {
		foreach ( $this->get_plugins() as $plugin_data ) {
			$plugin_update_url = $plugin_data['UpdateURI'];

			add_filter(
				sprintf( 'update_plugins_%s', wp_parse_url( $plugin_update_url, PHP_URL_HOST ) ),
				[ $this, 'filter_update_by_hostname' ],
				10,
				4
			);
		}

		// Persist any errors after all update checks are done.
		add_action( 'shutdown', [ $this, 'action_maybe_persist_update_errors' ] );

		return $updates;
	}

	/**
	 * Persist any update errors.
	 *
	 * @return void
	 */
	public function action_maybe_persist_update_errors() {
		set_site_transient( self::TRANSIENT_NAME_UPDATE_ERRORS, $this->update_errors, DAY_IN_SECONDS );
	}

	private function get_update_errors() {
		if ( ! empty( $this->update_errors ) ) {
			return $this->update_errors;
		}

		$errors = get_site_transient( self::TRANSIENT_NAME_UPDATE_ERRORS );

		if ( is_array( $errors ) ) {
			return $errors;
		}

		return [];
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

			if ( $plugin_file ) { // Plugin might not be local, yet.
				$plugin_data = $this->get_plugin_data( $plugin_file );

				if ( ! empty( $plugin_data['UpdateURI'] ) && $this->is_update_pilot_url( $plugin_data['UpdateURI'] ) ) {
					return $this->get_plugin_information( $plugin_file, $args );
				}
			}
		}

		return $response;
	}

	private function get_plugin_file_by_slug( string $slug ) {
		foreach ( $this->get_plugins() as $plugin_file => $plugin_data ) {
			if ( $this->get_plugin_slug_from_file( $plugin_file ) === $slug ) {
				return $plugin_file;
			}
		}

		return null;
	}

	private function get_plugin_data( string $plugin_file ) {
		$plugins = $this->get_plugins();

		if ( ! empty( $plugins[ $plugin_file ] ) ) {
			return $plugins[ $plugin_file ];
		}

		return null;
	}

	private function get_update_request_headers( string $plugin_file ): array {
		$update_key = $this->get_update_key_for_plugin( $plugin_file );

		if ( $update_key ) {
			$basic_auth_pair = sprintf(
				'%s:%s',
				wp_parse_url( home_url(), PHP_URL_HOST ),
				$update_key
			);

			return [
				'Authorization' => sprintf( 'Basic %s', base64_encode( $basic_auth_pair ) ),
			];
		}

		return [];
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
				'headers' => $this->get_update_request_headers( $plugin_file ),
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

	private function is_screen_for_update_notice() {
		$screen = get_current_screen();

		$notice_screen_ids = [
			'themes',
			'plugins',
			'update-core',
			sprintf( 'settings_page_%s', self::SETTINGS_SLUG ),
			sprintf( 'admin_page_%s', self::SETTINGS_SLUG ),
		];

		if ( is_multisite() ) {
			$notice_screen_ids = array_map(
				fn( $screen_id ) => sprintf( '%s-network', $screen_id ),
				$notice_screen_ids
			);
		}

		if ( ! empty( $screen->id ) ) {
			return in_array( $screen->id, $notice_screen_ids, true );
		}

		return false;
	}

	public function show_update_errors() {
		if ( ! $this->current_user_can_manage_updates() || ! $this->is_screen_for_update_notice() ) {
			return;
		}

		$error_messages = [];

		foreach ( $this->get_update_errors() as $error ) {
			$error_messages[] = sprintf(
				'<li>%s</li>',
				esc_html( array_pop( $error->get_error_messages() ) ) // Show the last error message with additional details.
			);
		}

		if ( ! empty( $error_messages ) ) {
			printf(
				'<div class="notice notice-error">
					<p><strong>%s</strong></p>
					<ul>%s</ul>
				</div>',
				esc_html__( 'The following errors occured when checking for updates:', 'update-pilot' ),
				implode( '', $error_messages )
			);
		}
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

	private function get_plugin_meta_file( $plugin_file ): ?string {
		// We can't include it for ourselves.
		if ( false !== strpos( $this->plugin_file, $plugin_file ) ) {
			return null;
		}

		$meta_file = sprintf( '%s/update-pilot.php', dirname( $plugin_file ) );

		$lookup_files = array_map(
			function ( $dir ) use ( $meta_file ) {
				$file = sprintf( '%s/%s', rtrim( $dir, '\\/' ), $meta_file );

				return is_readable( $file ) ? $file : null;
			},
			[
				WP_PLUGIN_DIR,
				WPMU_PLUGIN_DIR,
			]
		);

		$lookup_files = array_filter( $lookup_files ); // Remove no-existing files.

		if ( ! empty( $lookup_files ) ) {
			return array_pop( $lookup_files ); // Should be only one match.
		}

		return null;
	}

	private function get_update_for_version( $plugin_file, $plugin_data, $locales ) {
		// Allow inactive plugins to load Update Pilot customizations even when not running.
		$meta_include_file = $this->get_plugin_meta_file( $plugin_file );

		if ( $meta_include_file && is_readable( $meta_include_file ) ) {
			@include_once $$meta_include_file; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, we must prevent any accidental errors.
		}

		$plugins = [
			$plugin_file => $plugin_data,
		];

		$payload = [
			'headers' => $this->get_update_request_headers( $plugin_file ),
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

	private function get_settings_url(): string {
		return add_query_arg(
			[
				'page' => self::SETTINGS_SLUG,
			],
			network_admin_url( 'settings.php' )
		);
	}

	private function get_section_name_for_update_uri( $update_uri ) {
		return sprintf( 'updates-host-%s', wp_parse_url( $update_uri, PHP_URL_HOST ) );
	}

	private function get_package_update_settings_url( $update_uri ) {
		return sprintf(
			'%s#%s',
			$this->get_settings_url(),
			urlencode( $this->get_section_name_for_update_uri( $update_uri ) )
		);
	}

	public function filter_plugin_action_links( $actions, $plugin_file, $plugin_data ) {
		if ( ! empty( $plugin_data['UpdateURI'] ) && $this->is_update_pilot_url( $plugin_data['UpdateURI'] ) ) {
			$actions['update-pilot-configure'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->get_package_update_settings_url( $plugin_data['UpdateURI'] ) ),
				esc_html__( 'Configure Updates', 'update-pilot' )
			);
		}

		return $actions;
	}

	private function option_name( string $name ) {
		return sprintf( '%s%s', self::OPTION_PREFIX, sanitize_key( $name ) );
	}

	private function get_update_key_option_for_plugin( string $plugin_file ) {
		$replace = [
			'/' => '--',
			'.' => '-',
		];

		$key = str_replace( array_keys( $replace ), $replace, $plugin_file );

		return new Store_Site_Option( $this->option_name( sprintf( 'update_key_plugin__%s', $key ) ) );
	}

	private function get_update_key_for_plugin( string $plugin_file ) {
		return apply_filters(
			'update_pilot__plugin_update_key__' . $plugin_file,
			$this->get_update_key_option_for_plugin( $plugin_file )->get()
		);
	}

	public function register_settings_pages() {
		$menu_label = __( 'Update Pilot', 'update-pilot' );
		$menu_page_title = __( 'Update Pilot Settings', 'update-pilot' );

		if ( ! is_multisite() ) {
			add_options_page(
				$menu_page_title,
				$menu_label,
				$this->get_manage_updates_cap(),
				self::SETTINGS_SLUG,
				[ $this, 'settings_page' ]
			);
		} else {
			add_submenu_page(
				'settings.php',
				$menu_page_title,
				$menu_label,
				$this->get_manage_updates_cap(),
				self::SETTINGS_SLUG,
				[ $this, 'settings_page' ]
			);
		}

		foreach ( $this->get_plugins() as $plugin_file => $plugin ) {
			$section_key = sprintf( 'update-pilot-plugin-%s', $plugin_file );

			add_settings_section(
				$section_key,
				$plugin['Name'],
				function () use ( $plugin ) {
					$plugin_name = esc_html( $plugin['Name'] );

					if ( ! empty( $plugin['PluginURI'] ) ) {
						$plugin_name = sprintf(
							'<a href="%s">%s</a>',
							esc_url( $plugin['PluginURI'] ),
							esc_html( $plugin_name )
						);
					}

					if ( ! empty( $plugin['Author'] ) ) {
						$author = esc_html( $plugin['Author'] );

						if ( ! empty( $plugin['AuthorURI'] ) ) {
							$author = sprintf(
								'<a href="%s">%s</a>',
								esc_url( $plugin['AuthorURI'] ),
								esc_html( $plugin['Author'] )
							);
						}
					}

					$parts = [
						sprintf(
							/* translators: %s: Plugin name */
							__( 'Configure updates for the %s plugin', 'update-pilot' ),
							$plugin_name
						),
					];

					if ( isset( $author ) ) {
						$parts[] = sprintf(
							/* translators: %s: Plugin author name */
							__( 'from %s', 'update-pilot' ),
							$author
						);
					}

					printf(
						'<p>%s:</p>',
						wp_kses_post( implode( ' ', $parts ) )
					);
				},
				self::SETTINGS_SLUG
			);

			$plugin_field = new Update_Key(
				$this->get_update_key_option_for_plugin( $plugin_file ),
				[
					'title' => __( 'Update Key', 'update-pilot' ),
					'help' => __( 'Specify the update key only if required for this plugin.', 'update-pilot' ),
				]
			);

			$plugin_field->set_setting(
				'test_key_callback',
				function ( $key ) use ( $plugin_file, $plugin ) {
					add_filter(
						'update_pilot__plugin_update_key__' . $plugin_file,
						fn() => $key,
					);

					return $this->get_update_for_version( $plugin_file, $plugin, [] );
				}
			);

			$this->add_settings_field( $plugin_field, $section_key );
		}
	}

	protected function add_settings_field( Field $field, string $section ) {
		register_setting(
			self::SETTINGS_SLUG,
			$field->id(),
			[ 'sanitize_callback' => [ $field, 'sanitize' ] ] // TODO: Check when this was introduced.
		);

		add_settings_field(
			$field->id(),
			$field->title(),
			function () use ( $field ) {
				foreach ( $field->get_errors() as $error ) {
					$error_type = 'notice';
					$error_data = $error->get_error_data();

					if ( isset( $error_data['type'] ) ) {
						$error_type = $error_data['type'];
					}

					printf(
						'<div class="notice notice-%s inline"><p>%s</p></div>',
						esc_attr( sanitize_key( $error_type ) ),
						esc_html( $error->get_error_message() )
					);
				}

				echo $field->render();
			},
			self::SETTINGS_SLUG,
			$section
		);
	}

	public function settings_page() {
		// Action is required on multisite to trigger the network_admin_edit_* hook.
		$action = is_multisite() ? 'edit.php?action=update' : 'options.php';

		?>
		<div class="wrap" id="update-pilot-settings">
			<h1 class="wp-heading-inline"><?php _e( 'Update Pilot Settings', 'update-pilot' ); ?></h1>
			<a href="https://wpelevator.com/plugins/update-pilot/docs" target="_blank" class="page-title-action">Documentation</a>
			<form method="post" action="<?php echo esc_attr( $action ); ?>">
				<?php
					$this->populate_options_page_errors( self::SETTINGS_SLUG );

					settings_fields( self::SETTINGS_SLUG );
					do_settings_sections( self::SETTINGS_SLUG );
					submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private function populate_options_page_errors( string $option_page ) {
		$key = $this->get_option_page_error_key( $option_page );

		$errors = get_transient( $key );

		if ( is_array( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error(
					$error['setting'],
					$error['code'],
					$error['message'],
					$error['type']
				);
			}
		}
	}

	private function persist_options_page_errors( string $option_page, array $errors ) {
		set_transient( $this->get_option_page_error_key( $option_page ), $errors, 10 );
	}

	private function get_option_page_error_key( string $option_page ) {
		return sprintf( '%s_errors', $option_page );
	}

	/**
	 * Replicate what wp-admin/options.php does for all registered settings
	 * but for multisite network settings.
	 */
	public function action_update_network_settings() {
		$option_page = ! empty( $_REQUEST['option_page'] ) ? sanitize_text_field( $_REQUEST['option_page'] ) : null;

		if ( ! $option_page ) {
			return;
		}

		check_admin_referer( $option_page . '-options' );

		$allowed_options = apply_filters( 'allowed_options', [] );

		if ( ! empty( $allowed_options[ $option_page ] ) ) {
			foreach ( $allowed_options[ $option_page ] as $option_name ) {
				if ( isset( $_POST[ $option_name ] ) ) {
					$value = $_POST[ $option_name ];

					if ( ! is_array( $value ) ) {
						$value = trim( $value );
					}

					update_site_option( $option_name, wp_unslash( $value ) );
				} else {
					delete_site_option( $option_name );
				}
			}

			$this->persist_options_page_errors( $option_page, get_settings_errors() );

			wp_redirect( add_query_arg( 'updated', 'true', wp_get_referer() ) );
			die;
		}
	}
}

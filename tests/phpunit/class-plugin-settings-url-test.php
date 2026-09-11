<?php

namespace WPElevator\Update_Pilot;

class Plugin_Settings_Url_Test extends \WP_UnitTestCase {

	const PLUGIN_FILE = 'example-plugin/example-plugin.php';

	const UPDATE_URI = 'https://updates.example.com/wp-json/update-pilot/v1/plugins';

	private function get_configure_link(): string {
		$plugin = new Plugin( 'update-pilot/update-pilot.php' );

		$actions = $plugin->filter_plugin_action_links( [], self::PLUGIN_FILE, [ 'UpdateURI' => self::UPDATE_URI ] );

		$this->assertArrayHasKey( 'update-pilot-configure', $actions, 'Plugins with an Update Pilot URI get the configure link.' );

		return $actions['update-pilot-configure'];
	}

	public function test_configure_link_points_to_settings_page() {
		if ( is_multisite() ) {
			$settings_url = network_admin_url( 'settings.php?page=update-pilot' );
		} else {
			$settings_url = admin_url( 'options-general.php?page=update-pilot' );
		}

		$this->assertStringContainsString(
			sprintf( 'href="%s#', esc_url( $settings_url ) ),
			$this->get_configure_link(),
			'The configure link points to the settings page registered for the current install type.'
		);
	}
}

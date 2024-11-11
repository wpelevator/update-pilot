<?php
/**
 * Plugin Name: Update Pilot
 * Description: Get updates from third-party plugin and theme vendors.
 * Author: WP Elevator
 * Author URI: https://wpelevator.com
 * Version: 0.0.3
 * Plugin URI: https://wpelevator.com/plugins/update-pilot
 * Update URI: https://updates.wpelevator.com/wp-json/update-pilot/v1/plugins
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Network: true
 */

use WPElevator\Update_Pilot\Plugin;

// Ensure WP core is loaded.
if ( ! function_exists( 'add_action' ) ) {
	return;
}

// Only if there is no project autoloader that knows about us.
if ( ! class_exists( Plugin::class ) && file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

$plugin = new Plugin( __FILE__ );

add_action( 'plugins_loaded', [ $plugin, 'init' ] );

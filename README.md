# Update Pilot

Get updates from all [Update Pilot Server](https://wpelevator.com/plugins/update-pilot-server) supported plugins and themes.

![Update settings](assets/screenshot-1.png)

## Available Hooks

Please note that on WordPress multisite the code using these hooks should be running on the main network site as the WordPress updates happen only there.

Filters:

- `update_pilot__plugins` registers plugin update configuration. Each entry accepts `plugin`, `license_key`, and `signing_key`.

## Integration Guide

1. Setup the [Update Pilot Server](https://wpelevator.com/plugins/update-pilot-server) on your server.

2. Add `Update URI` header to your plugin `https://updates.example.com/wp-json/update-pilot/v1/plugins` or theme `https://updates.example.com/wp-json/update-pilot/v1/themes`.

3. Optinally, include `update-pilot.php` in the root of your plugin or theme directory to customize any of the update functionality. This file will be included by the Update Pilot even if the rest of your plugin is disabled.

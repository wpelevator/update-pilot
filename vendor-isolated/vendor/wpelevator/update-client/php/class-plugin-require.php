<?php

namespace WPElevator\Update_Pilot_Vendor\WPElevator\Update_Client;

use RuntimeException;
use WP_Error;
use WP_Upgrader;
use Plugin_Upgrader;
use Automatic_Upgrader_Skin;
class Plugin_Require
{
    private const INSTALL_ACTION = 'wpelevator-update-client-install-plugin';
    private array $config;
    private array $errors = [];
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    public function init(): void
    {
        add_action('admin_notices', [$this, 'action_render_notice']);
        add_action('network_admin_notices', [$this, 'action_render_notice']);
        add_action('load-plugins.php', [$this, 'action_install_plugin']);
        // Download and verify the package signature when installing the plugin.
        add_filter('upgrader_pre_download', [$this, 'filter_upgrader_pre_download'], 10, 3);
        // Add the authorization header when downloading the plugin.
        add_filter('http_request_args', [$this, 'filter_http_request_add_auth_headers'], 10, 2);
    }
    private function get_config(): array
    {
        $config_default = [
            // TODO: Add the signing_key to the default config.
            'license_key' => null,
            'download_url' => 'https://updates.wpelevator.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
            'basename' => 'update-pilot/update-pilot.php',
            'name' => 'Update Pilot',
            'notice' => __('The Update Pilot plugin is required'),
            'network' => true,
        ];
        $config = array_merge($config_default, $this->config);
        /**
         * Filters the configuration for requiring the plugin.
         *
         * The filter name includes the basename of the required plugin (resolved from
         * the unfiltered configuration) so that multiple bundled copies of the library
         * can be targeted independently. It is distinct from the Plugin_Update
         * configuration filter to allow for plugins that are involved in both roles.
         *
         * @param array $config The required plugin configuration.
         */
        return apply_filters(sprintf('wpelevator_update_client__require_config__%s', $config['basename']), $config);
    }
    private function get_config_value(string $key)
    {
        $config = $this->get_config();
        return $config[$key] ?? null;
    }
    private function is_network_plugin(): bool
    {
        return (bool) $this->get_config_value('network');
    }
    private function get_basename(): string
    {
        return (string) $this->get_config_value('basename');
    }
    private function get_update_plugin(): Plugin
    {
        return new Plugin($this->get_basename());
    }
    private function get_download_url(): string
    {
        return (string) $this->get_config_value('download_url');
    }
    private function get_signing_key(): ?string
    {
        $signing_key = $this->get_config_value('signing_key');
        if (is_string($signing_key) && '' !== trim($signing_key)) {
            return trim($signing_key);
        }
        return null;
    }
    private function get_license_key(): ?string
    {
        $license_key = $this->get_config_value('license_key');
        if (is_string($license_key) && '' !== trim($license_key)) {
            return trim($license_key);
        }
        return null;
    }
    /**
     * The package of the plugin, verified against the configured signing key.
     *
     * Resolved when the install runs rather than when the hooks are registered, since
     * init() runs as early as plugins_loaded where the configuration filters of the
     * required plugin may not be in place yet.
     */
    private function get_signed_package(): ?Signed_Package
    {
        $signing_key = $this->get_signing_key();
        if ($signing_key) {
            return new Signed_Package($signing_key);
        }
        return null;
    }
    /**
     * The basic authorization header for authenticating the download request.
     *
     * Uses the site hostname as the username to match the WordPress application
     * passwords convention, same as the Update Pilot plugin.
     */
    private function get_authorization_header(): ?string
    {
        $license_key = $this->get_license_key();
        if ($license_key) {
            $auth_pair = sprintf('%s:%s', wp_parse_url(home_url(), PHP_URL_HOST), $license_key);
            return sprintf('Basic %s', base64_encode($auth_pair));
        }
        return null;
    }
    /**
     * Require a valid package signature when downloading the required plugin.
     *
     * WP core no longer requests the signature verification when downloading the
     * package, so the download is performed here instead to enforce it.
     *
     * @see Signed_Package::download()
     *
     * @param bool|string|WP_Error $pre      Whether to short-circuit the download.
     * @param string               $package  The package URL being downloaded.
     * @param WP_Upgrader          $upgrader The upgrader instance.
     * @return bool|string|WP_Error
     */
    public function filter_upgrader_pre_download($pre, $package, $upgrader = null)
    {
        if (false !== $pre) {
            return $pre;
            // Another filter has short-circuited the download.
        }
        // Enforce the signature verification only during the initial install since updates are handled by the plugin.
        if ($this->get_download_url() !== $package) {
            return $pre;
        }
        $signed_package = $this->get_signed_package();
        if (!$signed_package) {
            return $pre;
            // No signing key configured so WP core can download the plugin.
        }
        if (!preg_match('!^(http|https|ftp)://!i', $package)) {
            return $pre;
            // Local package files are used as is by WP core.
        }
        if ($upgrader instanceof WP_Upgrader && isset($upgrader->skin)) {
            $upgrader->skin->feedback('downloading_package', $package);
        }
        try {
            return $signed_package->download($package);
        } catch (RuntimeException $e) {
            return new WP_Error('package_signature_verification_failed', $e->getMessage());
        }
    }
    /**
     * Add the license key authorization header when downloading the required plugin.
     *
     * @param array  $args The HTTP request arguments.
     * @param string $url  The request URL.
     * @return array
     */
    public function filter_http_request_add_auth_headers(array $args, string $url): array
    {
        if ($this->get_download_url() !== $url) {
            return $args;
        }
        $authorization = $this->get_authorization_header();
        if ($authorization && empty($args['headers']['Authorization'])) {
            // Do not override existing authorization headers.
            $args['headers']['Authorization'] = $authorization;
        }
        return $args;
    }
    private function get_nonce_action(): string
    {
        return sprintf('%s-%s', self::INSTALL_ACTION, md5($this->get_download_url()));
    }
    private function get_install_url(): ?string
    {
        $url = add_query_arg(['action' => self::INSTALL_ACTION, 'plugin' => md5($this->get_download_url())], is_network_admin() ? network_admin_url('plugins.php') : admin_url('plugins.php'));
        return wp_nonce_url($url, $this->get_nonce_action());
    }
    public function action_install_plugin(): void
    {
        if (!isset($_GET['action'], $_GET['plugin']) || self::INSTALL_ACTION !== $_GET['action']) {
            return;
        }
        $download_url = $this->get_download_url();
        if (empty($download_url) || md5($download_url) !== $_GET['plugin']) {
            return;
        }
        check_admin_referer($this->get_nonce_action());
        $plugin = new Plugin($this->get_basename());
        // Attempt to install the plugin, if not already installed.
        if (!$plugin->is_installed() && current_user_can('install_plugins')) {
            if (!class_exists(Plugin_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }
            $upgrader_skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($upgrader_skin);
            $install = $upgrader->install($download_url);
            if (is_wp_error($install)) {
                $this->errors[] = $install;
            }
            $messages = $upgrader_skin->get_upgrade_messages();
            if (!empty($messages)) {
                $this->errors[] = new WP_Error('plugin_install', implode(' ', $messages), ['type' => true === $install ? 'success' : 'error']);
            }
        }
        // Attempt to activate, if present but not active.
        if ($plugin->is_installed() && !$plugin->is_active() && current_user_can('activate_plugins')) {
            $activated = activate_plugin($this->get_basename(), '', $this->is_network_plugin());
            if (is_wp_error($activated)) {
                $this->errors[] = $activated;
            }
        }
    }
    public function action_render_notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['plugins', 'plugins-network'], true)) {
            return;
            // Show notice on plugin screen only.
        }
        $signed_package = $this->get_signed_package();
        if ($signed_package && !$signed_package->can_verify()) {
            $this->errors[] = new WP_Error('plugin_signature_unsupported', sprintf(
                /* translators: %s: Required plugin name. */
                __('The PHP Sodium extension is required to verify the authenticity of the %s plugin downloads.'),
                $this->get_config_value('name')
            ), ['type' => 'warning']);
        }
        $update_plugin = $this->get_update_plugin();
        $notice = $this->get_config_value('notice');
        if (!$notice) {
            $notice = sprintf(
                /* translators: 1: Required plugin name. */
                __('The %1$s plugin is required.'),
                $this->get_config_value('name')
            );
        }
        if (!$update_plugin->is_installed() && current_user_can('install_plugins')) {
            $notice_action = sprintf(' <a class="button" href="%s">%s</a>', esc_url($this->get_install_url()), esc_html__('Install'));
        } elseif (!$update_plugin->is_active() && current_user_can('activate_plugins')) {
            $notice_action = sprintf(' <a class="button" href="%s">%s</a>', esc_url($update_plugin->get_activate_url()), esc_html__('Activate'));
        }
        if (!$update_plugin->is_active()) {
            $this->errors[] = new WP_Error('plugin_required', sprintf('%s %s', $notice, $notice_action ?? ''), ['type' => 'warning']);
        }
        foreach ($this->errors as $error) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($error->get_error_data()['type'] ?? 'error'), wp_kses_post($error->get_error_message()));
        }
    }
}
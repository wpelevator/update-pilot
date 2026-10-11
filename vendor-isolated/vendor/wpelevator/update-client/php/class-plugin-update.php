<?php

namespace WPElevator\Update_Pilot_Vendor\WPElevator\Update_Client;

use RuntimeException;
class Plugin_Update
{
    private string $api_url;
    private string $plugin_basename;
    private array $config;
    /**
     * Package download URLs that require the license key authorization header.
     *
     * @var string[]
     */
    private array $download_urls_with_auth = [];
    public function __construct(string $plugin_basename, string $api_url, array $config = [])
    {
        $this->plugin_basename = $plugin_basename;
        $this->api_url = $api_url;
        $this->config = $config;
    }
    public function get_slug(): string
    {
        return dirname($this->plugin_basename);
    }
    public function get_api_url(): string
    {
        if (!wp_http_supports(['ssl'])) {
            return set_url_scheme($this->api_url, 'http');
        }
        return $this->api_url;
    }
    public static function from_update_uri_header(string $plugin_basename, array $config = []): self
    {
        $plugins = get_plugins();
        if (!isset($plugins[$plugin_basename]['UpdateURI'])) {
            throw new RuntimeException('Failed to find the Update URI header in the plugin file');
        }
        return new self($plugin_basename, $plugins[$plugin_basename]['UpdateURI'], $config);
    }
    private function get_config(): array
    {
        $config_default = ['signing_key' => null, 'license_key' => null];
        /**
         * Filters the plugin update configuration.
         *
         * The filter name includes the basename of the plugin being updated so that
         * multiple bundled copies of the library can be targeted independently. It is
         * distinct from the Plugin_Require configuration filter to allow for plugins
         * that are involved in both roles.
         *
         * Allows adjusting or resolving the configuration dynamically (from an option,
         * a constant, a secrets store, etc.) instead of passing it to the constructor.
         *
         * @param array $config The plugin update configuration.
         */
        return apply_filters(sprintf('wpelevator_update_client__update_config__%s', $this->plugin_basename), array_merge($config_default, $this->config));
    }
    private function get_config_value(string $key)
    {
        $config = $this->get_config();
        return $config[$key] ?? null;
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
     * Resolved when the update runs rather than when the hooks are registered, since
     * init() runs as early as plugins_loaded where the configuration filters of the
     * plugin being updated may not be in place yet.
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
     * The basic authorization header for authenticating the update requests.
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
    public function init()
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter(sprintf('update_plugins_%s', wp_parse_url($this->api_url, PHP_URL_HOST)), [$this, 'update_by_hostname'], 10, 4);
        // Verify the package signature and track the update package download URLs.
        add_filter('upgrader_pre_download', [$this, 'filter_upgrader_pre_download'], 10, 4);
        // Add the authorization header when downloading the update package.
        add_filter('http_request_args', [$this, 'filter_http_request_add_auth_headers'], 10, 2);
    }
    /**
     * Require a valid package signature when downloading the update package for our plugin.
     *
     * WP core no longer requests the signature verification when downloading the
     * package, so the download is performed here instead to enforce it.
     *
     * @see Signed_Package::download()
     *
     * @param bool|string|\WP_Error $pre        Whether to short-circuit the download.
     * @param string                $package    The package URL being downloaded.
     * @param \WP_Upgrader          $upgrader   The upgrader instance.
     * @param array                 $hook_extra Extra hook arguments including the plugin basename.
     * @return bool|string|\WP_Error
     */
    public function filter_upgrader_pre_download($pre, $package, $upgrader, $hook_extra)
    {
        if (false !== $pre) {
            return $pre;
            // Another filter has short-circuited the download.
        }
        if (empty($hook_extra['plugin']) || $this->plugin_basename !== $hook_extra['plugin']) {
            return $pre;
            // Not the plugin we are responsible for.
        }
        if ($this->get_license_key()) {
            // Registered before the download starts so the auth header filter can match the URL.
            $this->download_urls_with_auth[] = $package;
        }
        $signed_package = $this->get_signed_package();
        if (!$signed_package) {
            return $pre;
            // No signing key configured so WP core can download the package.
        }
        if (!preg_match('!^(http|https|ftp)://!i', $package)) {
            return $pre;
            // Local package files are used as is by WP core.
        }
        if ($upgrader instanceof \WP_Upgrader && isset($upgrader->skin)) {
            $upgrader->skin->feedback('downloading_package', $package);
        }
        try {
            return $signed_package->download($package);
        } catch (RuntimeException $e) {
            return new \WP_Error('package_signature_verification_failed', $e->getMessage());
        }
    }
    /**
     * Add the license key authorization header when downloading the update package
     * for our plugin.
     *
     * @param array  $args The HTTP request arguments.
     * @param string $url  The request URL.
     * @return array
     */
    public function filter_http_request_add_auth_headers(array $args, string $url): array
    {
        if (!in_array($url, $this->download_urls_with_auth, true)) {
            return $args;
        }
        $authorization = $this->get_authorization_header();
        if ($authorization && empty($args['headers']['Authorization'])) {
            // Do not override existing authorization headers.
            $args['headers']['Authorization'] = $authorization;
        }
        return $args;
    }
    /**
     * * @param array|false $update {
     *     The plugin update data with the latest details. Default false.
     *
     *     @type string $id           Optional. ID of the plugin for update purposes, should be a URI
     *                                specified in the `Update URI` header field.
     *     @type string $slug         Slug of the plugin.
     *     @type string $version      The version of the plugin.
     *     @type string $url          The URL for details of the plugin.
     *     @type string $package      Optional. The update ZIP for the plugin.
     *     @type string $tested       Optional. The version of WordPress the plugin is tested against.
     *     @type string $requires_php Optional. The version of PHP which the plugin requires.
     *     @type bool   $autoupdate   Optional. Whether the plugin should automatically update.
     *     @type array  $icons        Optional. Array of plugin icons.
     *     @type array  $banners      Optional. Array of plugin banners.
     *     @type array  $banners_rtl  Optional. Array of plugin RTL banners.
     *     @type array  $translations {
     *         Optional. List of translation updates for the plugin.
     *
     *         @type string $language   The language the translation update is for.
     *         @type string $version    The version of the plugin this translation is for.
     *                                  This is not the version of the language file.
     *         @type string $updated    The update timestamp of the translation file.
     *                                  Should be a date in the `YYYY-MM-DD HH:MM:SS` format.
     *         @type string $package    The ZIP location containing the translation update.
     *         @type string $autoupdate Whether the translation should be automatically installed.
     *     }
     * }
     */
    public function update_by_hostname($update, $plugin_data, $plugin_file, $locales)
    {
        return null;
    }
    /**
     * Append our update after wp_update_plugins().
     * Also called by wp_plugin_update_row().
     *
     * @return object
     */
    public function check_update(object $updates): object
    {
        if (!isset($updates->last_checked)) {
            return $updates;
        }
        $plugins = get_plugins();
        if (!empty($plugins[$this->plugin_basename])) {
            $update = $this->get_update_for_version($plugins[$this->plugin_basename]['Version']);
            if (!empty($update->new_version)) {
                $updates->response[$this->plugin_basename] = $update;
                $updates->checked[$this->plugin_basename] = $update->new_version;
                $updates->last_checked = time();
            }
        }
        return $updates;
    }
    private function get_update_for_version(string $version): ?object
    {
        $payload = ['body' => ['package' => $this->plugin_basename, 'version' => $version]];
        $authorization = $this->get_authorization_header();
        if ($authorization) {
            $payload['headers'] = ['Authorization' => $authorization];
        }
        $response = wp_remote_post($this->get_api_url(), $payload);
        if (!is_wp_error($response)) {
            return json_decode(wp_remote_retrieve_body($response));
        }
        return null;
    }
}
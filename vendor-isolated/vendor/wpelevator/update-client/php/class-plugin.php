<?php

namespace WPElevator\Update_Pilot_Vendor\WPElevator\Update_Client;

class Plugin
{
    private string $plugin_basename;
    public function __construct(string $plugin_basename)
    {
        $this->plugin_basename = $plugin_basename;
    }
    public function get_basename(): string
    {
        return $this->plugin_basename;
    }
    public function get_header(string $field): ?string
    {
        $plugins = $this->get_plugins();
        if (!empty($plugins[$this->plugin_basename][$field])) {
            return $plugins[$this->plugin_basename][$field];
        }
        return null;
    }
    private function get_plugins(): array
    {
        return get_plugins();
        // TODO: should we require any WP core files here?
    }
    public function is_installed(): bool
    {
        return in_array($this->plugin_basename, array_keys($this->get_plugins()), true);
    }
    public function is_active(): bool
    {
        return is_plugin_active($this->plugin_basename);
    }
    public function is_network_active(): bool
    {
        return is_plugin_active_for_network($this->plugin_basename);
    }
    public function is_network_plugin(): bool
    {
        return (bool) $this->get_header('Network');
    }
    public function get_activate_url(): string
    {
        $url = add_query_arg(['action' => 'activate', 'plugin' => $this->plugin_basename], $this->is_network_plugin() ? network_admin_url('plugins.php') : admin_url('plugins.php'));
        return wp_nonce_url($url, 'activate-plugin_' . $this->plugin_basename);
    }
}
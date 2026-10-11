<?php

namespace WPElevator\Update_Pilot_Vendor\WPElevator\Update_Client;

use RuntimeException;
/**
 * A package that is only ever downloaded when signed with a known public key.
 *
 * @see verify_file_signature() and download_url() in WP core.
 */
class Signed_Package
{
    /**
     * Base64 encoded Ed25519 public key used for signing the packages.
     */
    private string $public_key;
    /**
     * Hostname of the package download that is pending verification, if any.
     */
    private ?string $download_host = null;
    /**
     * If WP core verified the signature of the package downloaded by download().
     */
    private bool $signature_verified = false;
    public function __construct(string $public_key)
    {
        $this->public_key = trim($public_key);
    }
    /**
     * If the current environment supports the package signature verification.
     */
    public function can_verify(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }
    /**
     * Download a package and verify its signature before it is installed.
     *
     * WordPress stopped requesting the signature verification in WP_Upgrader::run()
     * which calls download_package() with $check_signatures set to false (changeset
     * 58319 in WP 6.6, see Trac #47315). As a result download_url() skips the whole
     * verification step and none of the wp_signature_hosts, wp_trusted_keys or
     * wp_signature_softfail filters are applied during plugin installs and updates.
     *
     * The package is downloaded here instead with the verification requested, which
     * leaves the signature handling itself to WP core. The filters are registered
     * only around that call so that neither the download host nor the public key is
     * trusted while an unrelated package is verified.
     *
     * A skipped verification is treated as a failure. download_url() returns the very
     * same file path whether it verified the package or never looked at it, so the
     * wp_trusted_keys filter doubles as the proof that the check actually ran: WP core
     * calls wp_trusted_keys() from verify_file_signature() only, and only reaches it
     * once it has a signature to compare against the trusted keys.
     *
     * @param string $url The package URL to download.
     * @return string Path to the verified package file.
     * @throws RuntimeException If the package cannot be downloaded or verified.
     */
    public function download(string $url): string
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $this->download_host = wp_parse_url($url, PHP_URL_HOST);
        $this->signature_verified = false;
        // Registered last so that the verification cannot be skipped or soft-failed by other code.
        add_filter('wp_signature_hosts', [$this, 'filter_enable_signature_hosts'], PHP_INT_MAX);
        add_filter('wp_signature_softfail', [$this, 'filter_disable_signature_softfail'], PHP_INT_MAX, 2);
        add_filter('wp_trusted_keys', [$this, 'filter_extend_trusted_keys'], PHP_INT_MAX);
        $file = download_url($url, 300, true);
        remove_filter('wp_signature_hosts', [$this, 'filter_enable_signature_hosts'], PHP_INT_MAX);
        remove_filter('wp_signature_softfail', [$this, 'filter_disable_signature_softfail'], PHP_INT_MAX);
        remove_filter('wp_trusted_keys', [$this, 'filter_extend_trusted_keys'], PHP_INT_MAX);
        $this->download_host = null;
        if (is_wp_error($file)) {
            // Discard any package kept around by a soft-fail which WP_Upgrader::run() would install anyway.
            $softfail_file = $file->get_error_data('softfail-filename');
            if (is_string($softfail_file) && file_exists($softfail_file)) {
                unlink($softfail_file);
            }
            throw new RuntimeException($file->get_error_message());
        }
        if (!$this->signature_verified) {
            unlink($file);
            throw new RuntimeException(sprintf('The signature of the package downloaded from %s was never verified', $url));
        }
        return $file;
    }
    /**
     * Require a valid signature for the package being downloaded.
     *
     * @param string[] $hosts List of hostnames.
     * @return string[]
     */
    public function filter_enable_signature_hosts(array $hosts): array
    {
        if (!empty($this->download_host) && !in_array($this->download_host, $hosts, true)) {
            $hosts[] = $this->download_host;
        }
        return $hosts;
    }
    /**
     * Prevent installs of the package with a missing or invalid signature.
     *
     * @param bool   $allow_softfail If signature errors can be ignored.
     * @param string $url The URL being downloaded.
     * @return bool
     */
    public function filter_disable_signature_softfail($allow_softfail, $url)
    {
        if (!empty($this->download_host) && wp_parse_url((string) $url, PHP_URL_HOST) === $this->download_host) {
            return false;
        }
        return $allow_softfail;
    }
    /**
     * Add the known public key to the list of trusted signing keys.
     *
     * WP core calls wp_trusted_keys() from verify_file_signature() and nowhere else,
     * and only once it has a signature to compare against the trusted keys, so this
     * also records that the package was really verified rather than waved through.
     *
     * @param string[] $keys List of base64 encoded Ed25519 public keys.
     * @return string[]
     */
    public function filter_extend_trusted_keys(array $keys): array
    {
        $this->signature_verified = true;
        // Assume that signature was verified by WP core if this filter is called.
        if (!in_array($this->public_key, $keys, true)) {
            $keys[] = $this->public_key;
        }
        return $keys;
    }
}
<?php

namespace WPElevator\Vendor_Isolator;

final class Namespace_Checker {

	/**
	 * Discovered namespaces
	 *
	 * @var array
	 */
	private $namespaces;

	/**
	 * Prefix
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Class constructor
	 *
	 * @param string $prefix
	 */
	public function __construct( array $namespaces, $prefix ) {
		$this->namespaces = $namespaces;
		$this->prefix = $prefix;
	}

	/**
	 * Is the given string a valid namespace
	 *
	 * @param string $ns
	 *
	 * @return bool
	 */
	public static function is_namespace( $ns ) {
		// Must contain a backslash, and may only contain alphanumeric and underscore
		if ( ! preg_match( '/^[0-9a-z_\\\]+$/i', $ns ) || ! preg_match( '/[\\\]+/i', $ns ) ) {
			return false;
		}

		// Don't match only slashes...
		if ( preg_match( '/^[\\\]+$/i', $ns ) ) {
			return false;
		}
		// Don't match a single word between slashes...
		if ( preg_match( '/^[\\\]+[0-9a-z_]+[\\\]+$/i', $ns ) ) {
			return false;
		}

		// Sections should not begin with a number
		$parts = array_filter( explode( '\\', $ns ) );
		foreach ( $parts as $part ) {
			if ( preg_match( '/^[0-9]+/', $part ) ) {
				return false;
				break;
			}
		}

		return true;
	}

	/**
	 * Should the given namespace be transformed?
	 *
	 * @param string $ns
	 *
	 * @return bool
	 */
	public function should_transform( $ns ) {
		// Never transform non-namespace strings
		if ( ! self::is_namespace( $ns ) ) {
			return false;
		}

		// Trim ends to ensure valid matches
		$ns = trim( $ns, '\\' );

		// We never want to match our own prefix
		if ( preg_match( '/^' . preg_quote( $this->prefix, '/' ) . '/i', $ns ) ) {
			return false;
		}

		// We only want to match our list of namespaces
		return isset( $this->namespaces[ $ns ] );
	}
}

<?php

namespace WPElevator\Update_Pilot\Settings;

use WP_Error;

abstract class Field {

	protected Store $store;

	protected array $settings;

	public function __construct( Store $store, array $settings = [] ) {
		$this->store = $store;
		$this->settings = $settings;
	}

	public function store(): Store {
		return $this->store;
	}

	public function id(): string {
		return $this->store->id();
	}

	public function name(): string {
		if ( ! empty( $this->settings['name'] ) ) { // This prevents IDs like int/string 0, for example.
			return (string) $this->settings['name'];
		}

		return $this->id();
	}

	public function title(): ?string {
		return $this->setting( 'title' );
	}

	public function help(): ?string {
		return $this->setting( 'help' );
	}

	public function setting( string $key, $_default = null ) {
		return $this->settings[ $key ] ?? $_default;
	}

	public function set_setting( string $key, $value ) {
		$this->settings[ $key ] = $value;
	}

	public function has_errors() {
		$errors = get_settings_errors( $this->id(), false );

		return ! empty( $errors );
	}

	public function add_error( \WP_Error $error, string $type = 'error' ) {
		add_settings_error( $this->id(), $error->get_error_code(), $error->get_error_message(), $type );
	}

	public function get_errors(): array {
		return array_map(
			function ( $error ) {
				return new WP_Error(
					$error['code'],
					$error['message'],
					[
						'type' => $error['type'],
					]
				);
			},
			get_settings_errors( $this->id(), false )
		);
	}

	public function get() {
		return $this->sanitize( $this->store->get() );
	}

	public function save( $value ) {
		return $this->store->set( $this->sanitize( $value ) );
	}

	abstract public function render(): string;

	abstract public function sanitize( $value );
}

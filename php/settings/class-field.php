<?php

namespace WPElevator\Update_Pilot\Settings;

abstract class Field {

	protected Store $store;

	protected array $settings;

	protected array $errors = [];

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
		return ! empty( $this->errors );
	}

	public function add_error( \WP_Error $error ) {
		$this->errors[] = $error;
	}

	public function get_errors(): array {
		return $this->errors;
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

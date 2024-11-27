<?php

namespace WPElevator\Update_Pilot\Settings;

use WP_Error;

class Update_Key extends Field {

	public function sanitize( $value ): ?string {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		$update_key = null;

		if ( isset( $value['key'] ) ) {
			$key = trim( $value['key'] );

			if ( '' !== $key ) {
				$update_key = sanitize_text_field( $key );
			}
		}

		if ( isset( $value['test'] ) ) {
			$test_callback = $this->setting( 'test_key_callback' );

			if ( is_callable( $test_callback ) ) {
				$update = call_user_func( $test_callback, $update_key );

				if ( is_wp_error( $update ) ) {
					$this->add_error(
						new WP_Error(
							'update-pilot-test',
							sprintf(
								/* translators: %s: update test error message */
								__( 'Update test failed: %s', 'update-pilot' ),
								$update->get_error_message()
							)
						)
					);
				} else {
					$this->add_error(
						new WP_Error(
							'update-pilot-test',
							__( 'Update test was successful!', 'update-pilot' )
						),
						'success'
					);
				}
			}
		}

		return $update_key;
	}

	public function render(): string {
		$parts = [];

		$parts[] = sprintf(
			'<input type="text" name="%s[key]" value="%s" class="regular-text" />',
			esc_attr( $this->name() ),
			esc_attr( $this->get() ),
		);

		$parts[] = sprintf(
			'<input type="submit" class="button button-secondary" name="%s[test]" value="%s" />',
			esc_attr( $this->name() ),
			esc_attr__( 'Test Update', 'update-pilot' ),
		);

		$help = $this->help();

		if ( ! empty( $help ) ) {
			$parts[] = sprintf( '<p class="description">%s</p>', wp_kses_post( $help ) );
		}

		return implode( ' ', $parts );
	}
}

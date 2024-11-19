<?php

namespace WPElevator\Update_Pilot\Settings;

class Field_Text extends Field {

	public function sanitize( $value ): ?string {
		if ( ! isset( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		return sanitize_text_field( $value );
	}

	public function render(): string {
		$parts = [];

		$parts[] = sprintf(
			'<input type="text" name="%s" value="%s" class="regular-text" />',
			esc_attr( $this->name() ),
			esc_attr( $this->get() ),
		);

		$help = $this->help();

		if ( ! empty( $help ) ) {
			$parts[] = sprintf( '<p class="description">%s</p>', wp_kses_post( $help ) );
		}

		return implode( '', $parts );
	}
}

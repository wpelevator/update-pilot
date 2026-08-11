<?php

namespace WPElevator\Vendor_IsolatorTest;

use PHPUnit\Framework\TestCase;
use WPElevator\Vendor_Isolator\Namespace_Checker;

class Namespace_Checker_Test extends TestCase {

	public function test_is_namespace() {
		$this->assertTrue( Namespace_Checker::is_namespace( '\\Vendor\\Prefix' ) );
		$this->assertFalse( Namespace_Checker::is_namespace( 'SomeRandomString' ) );
	}

	public function testshould_transform() {
		$namespaces_to_transform = [
			'Vendor\\Package'  => true,
			'NotOur\\Prefix'   => true,
			'Not\\Our\\Prefix' => true,
		];

		$checker = new Namespace_Checker( $namespaces_to_transform, 'Our\\Prefix' );

		$this->assertFalse(
			$checker->should_transform( '\\WP_Post' ),
			'Skip transforming unknown namespaces'
		);

		$this->assertTrue(
			$checker->should_transform( 'Vendor\\Package' ),
			'Transform listed namespaces'
		);

		$this->assertFalse(
			$checker->should_transform( 'Our\\Prefix' ),
			'Skip transforming our own namespace'
		);
	}
}

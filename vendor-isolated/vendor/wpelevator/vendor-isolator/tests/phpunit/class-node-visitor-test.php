<?php

namespace WPElevator\Vendor_IsolatorTest;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;
use WPElevator\Vendor_Isolator\Namespace_Checker;
use WPElevator\Vendor_Isolator\Node_Visitor;

class Node_Visitor_Test extends TestCase {

	public function test_node_visitor() {
		$prefix = 'Custom\\VendorPrefix';

		$namespaces = [
			'Vendor1\Package' => true,
		];

		$map = [
			'<?php namespace Vendor1\Package; class Example { function __construct() { wp_login(); } }'
				=> '<?php namespace Custom\VendorPrefix\Vendor1\Package; class Example { function __construct() { wp_login(); } }',
			'<?php $assumed_root = new Vendor1\Package\AssumedRoot();'
				=> '<?php $assumed_root = new Vendor1\Package\AssumedRoot();',
			'<?php $excplicit_root = new \Vendor1\Package\ExplicitRoot();'
				=> '<?php $excplicit_root = new \Custom\VendorPrefix\Vendor1\Package\ExplicitRoot();',
			'<?php use \Vendor1\Package\ExplicitRoot; $excplicit_root = new ExplicitRoot();'
				=> '<?php use Custom\VendorPrefix\Vendor1\Package\ExplicitRoot; $excplicit_root = new ExplicitRoot();',
			'<?php echo \Vendor1\Package\Classy::SOMETHING;'
				=> '<?php echo \Custom\VendorPrefix\Vendor1\Package\Classy::SOMETHING;',
		];

		$checker = new Namespace_Checker( $namespaces, $prefix );
		$visitor = new Node_Visitor( $prefix, $checker );

		$traverser = new NodeTraverser();
		$traverser->addVisitor( $visitor );

		$parser = ( new ParserFactory() )->createForHostVersion();
		$printer = new Standard();

		foreach ( $map as $from => $to ) {
			$stmts = $traverser->traverse( $parser->parse( $from ) );
			$transformed = $printer->prettyPrintFile( $stmts );

			$this->assertTrue( $visitor->didTransform(), sprintf( 'Did transform %s', $from ) );
			$this->assertEquals( $to, $this->multiline_to_single_line( $transformed ) );
		}
	}

	protected function multiline_to_single_line( $blob ) {
		return preg_replace( '#[\r\s\n]+#i', ' ', $blob );
	}
}

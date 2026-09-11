<?php

namespace WPElevator\Vendor_Isolator\Filehash_Visitor;

use PhpParser\Node;

class Autoload_Static_Visitor extends Abstract_Visitor {

	private $entered = false;

	/**
	 * {@inheritdoc}
	 */
	public function enterNode( Node $node ) {
		if ( $node instanceof Node\Stmt\PropertyProperty and 'files' === $node->name ) {
			$this->transformFilehashArray( $node->default );
		}
	}

	/**
	 * Did we perform a transformation
	 *
	 * @return bool
	 */
	public function didTransform() {
		return $this->transformed;
	}
}

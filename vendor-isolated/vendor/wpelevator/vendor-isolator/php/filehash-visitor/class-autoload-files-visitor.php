<?php

namespace WPElevator\Vendor_Isolator\Filehash_Visitor;

use PhpParser\Node;

class Autoload_Files_Visitor extends Abstract_Visitor {

	private $entered = false;

	/**
	 * {@inheritdoc}
	 */
	public function enterNode( Node $node ) {
		if ( $this->entered and $node instanceof Node\Expr\Array_ ) {
			$this->transformFilehashArray( $node );

			// Don't catch anything more
			$this->entered = false;
		}

		if ( $node instanceof Node\Stmt\Return_ ) {
			$this->entered = true;
		}
	}
}

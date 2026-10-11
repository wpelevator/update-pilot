<?php

namespace WPElevator\Vendor_Isolator\Filehash_Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

abstract class Abstract_Visitor extends NodeVisitorAbstract {

	/**
	 * Did we perform a transform?
	 *
	 * @var bool
	 */
	protected $transformed;

	/**
	 * @var string To handle proper scope
	 */
	protected $vendors_dir;

	public function __construct( $file_path, $vendors_dir ) {
		$this->vendors_dir = $vendors_dir;
	}

	/**
	 * Did we perform a transformation
	 *
	 * @return bool
	 */
	public function didTransform() {
		return $this->transformed;
	}

	protected function transformFilehashArray( Node\Expr\Array_ $array_node ) {
		$printer = new Standard();
		$parser = ( new ParserFactory() )->createForHostVersion();

		foreach ( $array_node->items as $i => $item ) {
			if ( $item->key instanceof Node\Scalar\String_ and false === strpos( $item->key->value, 'isolated-' ) ) {
				// Let's cook some pretty key
				$key = 'isolated-' .
					strtolower( str_replace( dirname( $this->vendors_dir, 3 ), '', $this->vendors_dir ) ) .
					str_replace( [ '$vendorDir . ', '.php' ], '', $printer->prettyPrintExpr( $item->value ) ) .
					$item->key->value;
				$key = preg_replace( '~[^a-z0-9\-]~', '-', $key );
				$key = preg_replace( '~\-+~', '-', $key );
				$key = trim( $key, '-' );
				$item->key->value = $key;

				/*$parsed = $parser->parse(
					'<?php md5('.
					"'" . $item->key->value."' . ".
					$printer->prettyPrintExpr($item->value).
					');');
				$item->key = $parsed[ 0 ];*/

				// Notify the source has been mutated
				$this->transformed = true;
			}
		}
	}
}

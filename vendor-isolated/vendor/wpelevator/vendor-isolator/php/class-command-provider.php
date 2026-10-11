<?php

namespace WPElevator\Vendor_Isolator;

use Composer\Plugin\Capability\CommandProvider as Command_Provider_Capability;

final class Command_Provider implements Command_Provider_Capability {

	/**
	 * {@inheritdoc}
	 */
	public function getCommands() {
		return [ new Command() ];
	}
}

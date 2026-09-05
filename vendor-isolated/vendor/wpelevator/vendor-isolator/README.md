# Vendor Isolator

Composer plugin to isolate project dependencies by prefixing their namespace.

## Requirements

- PHP 7.4 or later
- [Composer v2](https://getcomposer.org/upgrade/UPGRADE-2.0.md)

## How it Works

- It registers itself as a Composer plugin when you add it to your project through the [`extra.class` directive in the `composer.json` file](composer.json) pointing to `WPElevator\Vendor_Isolator\Plugin` in [php/class-plugin.php](php/class-plugin.php).

- It hooks into `pre-autoload-dump` and `post-autoload-dump` Composer events and uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to rewrite the namespaces and classname references for *all non-development dependencies*. It ignores all global function and classes.

## To Do

- Describe how this is different from php-scoper and other projects.

## Features and Limitations

- It only rewrites the non-development dependencies in the `vendor` directory, therefore your application code must reference the isolated dependencies by their prefixed namespace.

- It doesn't replace function definitions and calls in the global namespace. Any definitions and calls to global functions will remain in the global namespace after the transformation.

## Credits

A fork of [and/composer-isolation](https://github.com/logical-and/composer-isolation).

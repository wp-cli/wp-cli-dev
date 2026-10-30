<?php
/**
 * Loads the maintenance commands.
 *
 * Within a full development environment, Composer does that. Without one, any
 * `wp` executable can still run them, so the maintenance tooling only needs
 * WP-CLI, git and the GitHub CLI, not a complete install of every package.
 */

$composer_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_file( $composer_autoloader ) ) {
	require_once $composer_autoloader;
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WP_CLI\\Maintenance\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

require_once __DIR__ . '/bootstrap.php';

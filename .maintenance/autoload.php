<?php
/**
 * Loads the maintenance commands of this checkout.
 *
 * Any `wp` executable can run them, so the maintenance tooling only needs
 * WP-CLI, git and the GitHub CLI, not a complete install of every package.
 *
 * The classes are loaded right away instead of through an autoloader. The
 * `wp` executable may belong to another checkout, such as the main checkout
 * run within a worktree, and its Composer autoloader maps the same namespace.
 * WP-CLI registers that autoloader after this file runs, so it would take
 * precedence and silently run the other checkout's, possibly older, code.
 */

foreach ( glob( __DIR__ . '/src/*.php' ) as $maintenance_class_file ) {
	require_once $maintenance_class_file;
}

require_once __DIR__ . '/bootstrap.php';

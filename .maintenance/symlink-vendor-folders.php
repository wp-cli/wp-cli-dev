<?php

$repositories = glob( '*', GLOB_ONLYDIR );
$pwd          = getcwd();
$symlinks     = glob( 'vendor/*', GLOB_ONLYDIR );

foreach ( $repositories as $repository ) {
	if ( ! is_dir( "{$repository}/vendor" ) && is_file( "{$repository}/composer.json" ) ) {
		printf( "Symlinking \033[33mwp-cli/{$repository}/vendor\033[0m folder:\n" );

		// We don't symlink the vendor folder directly, but rather create a real
		// vendor folder and symlink each of the subfolders instead.
		// This is done so that the "vendor/" rule in .gitignore still works.
		mkdir( "{$repository}/vendor" );
		foreach ( $symlinks as $symlink ) {
			// Symlink entries already contain the `vendor/` folder suffix.
			symlink( "../../{$symlink}", "{$repository}/{$symlink}" );
		}
	}
}

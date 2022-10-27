<?php

if ( ! isset( $argv[1] ) || ! is_dir( $argv[1] ) ) {
	exit( "Usage: php refresh-repository.php <repository-folder>\n" );
}

$repository = $argv[1];
$path       = realpath( getcwd() . "/{$repository}" );

printf( "--- Refreshing repository \033[32m{$repository}\033[0m ---\n" );

printf( "Switching to latest \033[33mdefault\033[0m branch...\n" );
system( "git --git-dir={$path}/.git --work-tree={$path} checkout $(git remote show origin | grep 'HEAD branch' | cut -d' ' -f5)" );

printf( "Pulling latest changes...\n" );
system( "git --git-dir={$path}/.git --work-tree={$path} pull" );

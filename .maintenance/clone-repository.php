<?php

if ( ! isset( $argv[1], $argv[2] ) ) {
	exit( "Usage: php clone-repository.php <destination> <clone_url>\n" );
}

$destination = $argv[1];
$clone_url   = $argv[2];

printf( "Fetching \033[32m%s\033[0m...\n", $destination );
system( 'git clone ' . escapeshellarg( $clone_url ) . ' ' . escapeshellarg( $destination ) );

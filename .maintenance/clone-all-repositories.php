<?php

$skip_list = array(
	'autoload-splitter',
	'composer-changelogs',
	'dash-docset-generator',
	'ideas',
	'package-index',
	'sample-plugin',
	'snapshot-command',
);

$request      = 'https://api.github.com/orgs/wp-cli/repos?per_page=100';
$response     = shell_exec( "curl -s {$request}" );
$repositories = json_decode( $response );


foreach ( $repositories as $repository ) {
	if ( in_array( $repository->name, $skip_list, true ) ) {
		continue;
	}

	if ( is_dir( $repository->name ) ) {
		printf( "Skipping \033[33mwp-cli/{$repository->name}\033[0m, folder exists.\n" );
		continue;
	}

	printf( "Fetching \033[32mwp-cli/{$repository->name}\033[0m:\n" );
	system( "git clone {$repository->clone_url}" );
}

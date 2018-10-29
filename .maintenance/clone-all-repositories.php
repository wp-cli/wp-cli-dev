<?php

$skip_list = array(
	'autoload-splitter',
	'composer-changelogs',
	'dash-docset-generator',
	'ideas',
	'package-index',
	'sample-plugin',
	'snapshot-command',
	'wp-cli-dev',
);

$request      = 'https://api.github.com/orgs/wp-cli/repos?per_page=100';
$response     = shell_exec( "curl -s {$request}" );
$repositories = json_decode( $response );
$pwd          = getcwd();

foreach ( $repositories as $repository ) {
	if ( in_array( $repository->name, $skip_list, true ) ) {
		continue;
	}

	if ( ! is_dir( $repository->name ) ) {
		printf( "Fetching \033[32mwp-cli/{$repository->name}\033[0m:\n" );
		system( "git clone {$repository->clone_url}" );
	} else {
		printf( "Updating \033[33mwp-cli/{$repository->name}\033[0m:\n" );
		system( "cd {$repository->name} && git checkout master && git pull; cd {$pwd}" );
	}
}

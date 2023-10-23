<?php

$skip_list = array(
	'autoload-splitter',
	'composer-changelogs',
	'dash-docset-generator',
	'ideas',
	'package-index',
	'sample-plugin',
	'wp-cli-dev',
	'wp-cli-roadmap',
);

$clone_destination_map = array(
	'.github' => 'dot-github',
);

$request = 'https://api.github.com/orgs/wp-cli/repos?per_page=100';
$headers = '';
$token   = getenv( 'GITHUB_TOKEN' );
if ( ! empty( $token ) ) {
	$headers  = "--header \"Authorization: Bearer $token\"";
	$response = shell_exec( "curl -s {$headers} {$request}" );
} else {
	$response = shell_exec( "curl -s {$request}" );
}
$repositories = json_decode( $response );
if ( ! is_array( $repositories ) && property_exists( $repositories, 'message' ) ) {
	echo 'GitHub responded with: ' . $repositories->message . "\n";
	echo "If you are running into a rate limiting issue during large events please set GITHUB_TOKEN environment variable.\n";
	echo "See https://github.com/settings/tokens\n";
	exit( 1 );
}

$pwd            = getcwd();
$update_folders = [];

foreach ( $repositories as $repository ) {
	if ( in_array( $repository->name, $skip_list, true ) ) {
		continue;
	}

	$destination = isset( $clone_destination_map[ $repository->name ] ) ? $clone_destination_map[ $repository->name ] : $repository->name;

	if ( ! is_dir( $repository->name ) ) {
		printf( "Fetching \033[32mwp-cli/{$repository->name}\033[0m...\n" );
		$clone_url = getenv( 'GITHUB_ACTION' ) ? $repository->clone_url : $repository->ssh_url;
		system( "git clone {$clone_url} {$destination}" );
	}

	$update_folders[] = $destination;
}

$updates = implode( '\n', $update_folders );
system( "echo '$updates' | xargs -n1 -P8 -I% php .maintenance/refresh-repository.php %" );

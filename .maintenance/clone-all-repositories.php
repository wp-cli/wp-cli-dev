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
	'wp-cli-roadmap',
);

$request = 'https://api.github.com/orgs/wp-cli/repos?per_page=100';
$headers = '';
$token   = getenv( 'GITHUB_TOKEN' );
if ( ! empty( $token ) ) {
	$headers  = '--header "Authorization: token $token"';
	$response = shell_exec( "curl -s {$headers} {$request}" );
} else {
	$response = shell_exec( "curl -s {$request}" );
}
$repositories = json_decode( $response );
if ( ! is_array( $repositories ) && property_exists( $repositories, 'message' ) ) {
	echo 'GitHub responded with: ' . $repositories->message . "\n";
	echo "If you are running into a rate limiting issue during large events please set GITHUB_TOKEN environment variable.\n";
	exit( 1 );
}

$pwd            = getcwd();
$update_folders = [];

foreach ( $repositories as $repository ) {
	if ( in_array( $repository->name, $skip_list, true ) ) {
		continue;
	}

	if ( ! is_dir( $repository->name ) ) {
		printf( "Fetching \033[32mwp-cli/{$repository->name}\033[0m...\n" );
		$clone_url = getenv( 'GITHUB_ACTION' ) ? $repository->clone_url : $repository->ssh_url;
		system( "git clone {$clone_url}" );
	}

	$update_folders[] = $repository->name;
}

$updates = implode( '\n', $update_folders );
system( "echo '$updates' | xargs -n1 -P8 -I% php .maintenance/refresh-repository.php %" );

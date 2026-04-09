<?php

$skip_list = array(
	'autoload-splitter',
	'composer-changelogs',
	'dash-docset-generator',
	'ideas',
	'package-index',
	'regenerate-readme',
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
$clone_list     = [];

foreach ( $repositories as $repository ) {
	if ( in_array( $repository->name, $skip_list, true ) ) {
		continue;
	}

	$destination = isset( $clone_destination_map[ $repository->name ] ) ? $clone_destination_map[ $repository->name ] : $repository->name;

	if ( ! is_dir( $destination ) ) {
		$clone_url    = getenv( 'GITHUB_ACTION' ) ? $repository->clone_url : $repository->ssh_url;
		$clone_list[] = "{$destination} {$clone_url}";
	}

	$update_folders[] = $destination;
}

if ( ! empty( $clone_list ) ) {
	$clones = implode( "\n", $clone_list );
	system( "echo '$clones' | xargs -n2 -P8 php .maintenance/clone-repository.php" );
}

$updates = implode( "\n", $update_folders );
system( "echo '$updates' | xargs -n1 -P8 -I% php .maintenance/refresh-repository.php %" );

<?php
/**
 * Records the GitHub API responses the unit tests are built from.
 *
 * The tests take their GitHub objects from these recordings and only override
 * the fields a scenario depends on, so the payloads stay true to the real API.
 * Re-run this script now and then: when GitHub changes a response in a way the
 * maintenance commands rely on, the tests start failing.
 *
 * The requests target data that no longer changes, such as a comparison
 * between two old tags or a closed milestone, so a refresh only picks up
 * changes to the API itself.
 *
 * Usage: php tests/fixtures/github/update.php
 *
 * Authenticates with the GitHub CLI when it is logged in, and with the GH_TOKEN
 * or GITHUB_TOKEN environment variable otherwise.
 */

const FIXTURES = [
	'repository'     => 'repos/wp-cli/block-command',
	'tags-page-1'    => 'repos/wp-cli/block-command/tags?per_page=2',
	'tags-page-2'    => 'repos/wp-cli/block-command/tags?per_page=2&page=2',
	'milestone'      => 'repos/wp-cli/block-command/milestones/4',
	'issues'         => 'repos/wp-cli/block-command/issues?milestone=4&state=all',
	'pull-requests'  => 'repos/wp-cli/block-command/pulls?state=closed&base=main&sort=created&direction=asc&per_page=3',
	'comparison'     => 'repos/wp-cli/block-command/compare/v1.0.1...v1.0.3',
	'release'        => 'repos/wp-cli/block-command/releases/tags/v1.0.4',
	'release-absent' => 'repos/wp-cli/block-command/releases/tags/v0.0.0',
];

/**
 * Headers worth keeping. The rest change on every request.
 */
const KEPT_HEADERS = [ 'link' ];

$token = trim( (string) shell_exec( 'gh auth token --hostname github.com 2>/dev/null' ) );
if ( '' === $token ) {
	$token = (string) ( getenv( 'GH_TOKEN' ) ?: getenv( 'GITHUB_TOKEN' ) );
}

if ( '' === $token ) {
	fwrite( STDERR, "No GitHub credentials found. Log in with `gh auth login`, or set GH_TOKEN or GITHUB_TOKEN.\n" );
	exit( 1 );
}

foreach ( FIXTURES as $name => $path ) {
	$context = stream_context_create(
		[
			'http' => [
				'header'        => implode(
					"\r\n",
					[
						'Accept: application/vnd.github+json',
						"Authorization: Bearer {$token}",
						'User-Agent: wp-cli-dev test fixtures',
						'X-GitHub-Api-Version: 2022-11-28',
					]
				),
				'ignore_errors' => true,
			],
		]
	);

	$body = file_get_contents( "https://api.github.com/{$path}", false, $context );

	// Set by file_get_contents() for HTTP requests.
	$response_headers = isset( $http_response_header ) ? $http_response_header : [];

	if ( false === $body || ! $response_headers || ! preg_match( '#^HTTP/\S+ (\d+)#', $response_headers[0], $matches ) ) {
		fwrite( STDERR, "Could not fetch {$path}.\n" );
		exit( 1 );
	}

	$headers = [];
	foreach ( array_slice( $response_headers, 1 ) as $header ) {
		list( $key, $value ) = array_map( 'trim', explode( ':', $header, 2 ) + [ 1 => '' ] );
		if ( in_array( strtolower( $key ), KEPT_HEADERS, true ) ) {
			$headers[ strtolower( $key ) ] = $value;
		}
	}

	$fixture = [
		'request' => "GET /{$path}",
		'status'  => (int) $matches[1],
		'headers' => (object) $headers,
		'body'    => json_decode( $body ),
	];

	file_put_contents(
		__DIR__ . "/{$name}.json",
		json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
	);

	echo "Recorded {$name}.json ({$fixture['status']})\n";
}

<?php
/**
 * Brings a clone up to date with its remote, without ever touching work in
 * progress: a clone with uncommitted changes, or one on another branch than
 * the default one, is only fetched.
 */

if ( ! isset( $argv[1] ) || ! is_dir( $argv[1] ) ) {
	exit( "Usage: php refresh-repository.php <repository-folder>\n" );
}

$repository = $argv[1];
$git        = 'git -C ' . escapeshellarg( (string) realpath( $repository ) );

/**
 * Runs a git command and returns its output, or null when it fails.
 *
 * @param string $git     Git executable with its working directory.
 * @param string $command Git command and arguments.
 *
 * @return string|null
 */
function git_output( $git, $command ) {
	exec( "{$git} {$command} 2>/dev/null", $output, $exit_code );

	return 0 === $exit_code ? trim( implode( "\n", $output ) ) : null;
}

printf( "--- Refreshing repository \033[32m%s\033[0m ---\n", $repository );

passthru( "{$git} fetch --quiet origin" );

$default_branch = git_output( $git, 'symbolic-ref --short refs/remotes/origin/HEAD' );
if ( null === $default_branch ) {
	// Clones made before the remote HEAD was recorded need it looked up once.
	git_output( $git, 'remote set-head origin --auto' );
	$default_branch = git_output( $git, 'symbolic-ref --short refs/remotes/origin/HEAD' );
}
$default_branch = null === $default_branch ? null : preg_replace( '#^origin/#', '', $default_branch );
$current_branch = git_output( $git, 'rev-parse --abbrev-ref HEAD' );

if ( '' !== (string) git_output( $git, 'status --porcelain' ) ) {
	printf( "Leaving \033[33m%s\033[0m alone, it has uncommitted changes.\n", $repository );
	exit;
}

if ( null === $default_branch || $current_branch !== $default_branch ) {
	printf( "Leaving \033[33m%s\033[0m alone, it is on branch %s instead of %s.\n", $repository, $current_branch, $default_branch ?: 'the default branch' );
	exit;
}

printf( "Pulling latest changes into %s...\n", $default_branch );
passthru( "{$git} pull --quiet --ff-only" );

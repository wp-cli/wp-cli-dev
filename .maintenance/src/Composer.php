<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

/**
 * Runs Composer against the composer.json and composer.lock of a repository,
 * in a throwaway directory and without installing anything.
 */
final class Composer {

	/**
	 * Environment variables that would change what Composer checks, resolves
	 * or reports, such as COMPOSER_NO_DEV dropping the dev packages, or
	 * COMPOSER pointing it at another project.
	 */
	const ENV_OVERRIDES = [
		'COMPOSER',
		'COMPOSER_AUDIT_ABANDONED',
		'COMPOSER_AUTH',
		'COMPOSER_IGNORE_PLATFORM_REQ',
		'COMPOSER_IGNORE_PLATFORM_REQS',
		'COMPOSER_MINIMAL_CHANGES',
		'COMPOSER_NO_AUDIT',
		'COMPOSER_NO_DEV',
		'COMPOSER_NO_SECURITY_BLOCKING',
		'COMPOSER_PREFER_LOWEST',
		'COMPOSER_PREFER_STABLE',
		'COMPOSER_WITH_ALL_DEPENDENCIES',
		'COMPOSER_WITH_DEPENDENCIES',
	];

	/**
	 * Runs a Composer command in a temporary directory holding the given
	 * files. Plugins and scripts stay disabled, so the repository's code
	 * never runs.
	 *
	 * @param string[]              $arguments Command and its arguments, such
	 *                                         as [ 'audit', '--locked' ].
	 * @param array<string, string> $files     File name => contents.
	 * @param string[]              $read_back Files to read back afterwards.
	 * @param array<string, string> $env       Extra environment variables.
	 *
	 * @return array{return_code: int, stdout: string, stderr: string, files: array<string, string|null>}
	 */
	public static function run( array $arguments, array $files, array $read_back = [], array $env = [] ) {
		$dir = Utils\get_temp_dir() . uniqid( 'wp-cli-maintenance-composer-', true );

		$written = mkdir( $dir, 0700 ) && mkdir( "{$dir}/home", 0700 );
		foreach ( $files as $name => $contents ) {
			$written = $written && false !== file_put_contents( "{$dir}/{$name}", $contents );
		}

		if ( ! $written ) {
			self::remove_directory( $dir );
			WP_CLI::error( "Could not write the files for Composer to the temporary directory {$dir}." );
		}

		$arguments = array_merge(
			$arguments,
			[ '--no-interaction', '--no-plugins', '--no-scripts', "--working-dir={$dir}" ]
		);

		$command = 'composer ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) );
		$auth    = GitHub::get_auth();
		$result  = Process::create( $command, null, array_merge( self::get_env( (array) getenv(), "{$dir}/home", $auth['token'] ), $env ) )->run();

		$contents = [];
		foreach ( $read_back as $name ) {
			$contents[ $name ] = is_file( "{$dir}/{$name}" ) ? (string) file_get_contents( "{$dir}/{$name}" ) : null;
		}

		self::remove_directory( $dir );

		if ( '' !== trim( $result->stderr ) ) {
			WP_CLI::debug( trim( $result->stderr ), 'composer' );
		}

		return [
			'return_code' => (int) $result->return_code,
			'stdout'      => (string) $result->stdout,
			'stderr'      => (string) $result->stderr,
			'files'       => $contents,
		];
	}

	/**
	 * Builds the environment for Composer: the current one, without the
	 * variables that change its behavior, and with a Composer home of its
	 * own, so that no global configuration, such as `audit.ignore` or extra
	 * repositories, applies.
	 *
	 * @param array<string, string> $env           Current environment.
	 * @param string                $composer_home Empty directory to use as
	 *                                             Composer home.
	 * @param string|null           $github_token  Token to raise GitHub's API
	 *                                             rate limit for Composer.
	 *
	 * @return array<string, string>
	 */
	public static function get_env( array $env, $composer_home, $github_token = null ) {
		foreach ( self::ENV_OVERRIDES as $variable ) {
			unset( $env[ $variable ] );
		}

		$env['COMPOSER_HOME'] = $composer_home;

		if ( $github_token ) {
			$env['COMPOSER_AUTH'] = (string) json_encode( [ 'github-oauth' => [ 'github.com' => $github_token ] ] );
		}

		return $env;
	}

	private static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $dir );
	}
}

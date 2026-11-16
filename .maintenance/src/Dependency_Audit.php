<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

/**
 * Checks the composer.lock of a repository for packages with known security
 * advisories, and for abandoned packages.
 *
 * The lock is audited by `composer audit`, the same check the Code Quality
 * workflow runs, so both agree on what is affected.
 */
final class Dependency_Audit {

	const OK         = 'ok';
	const ABANDONED  = 'abandoned';
	const VULNERABLE = 'vulnerable';

	/**
	 * Environment variables that would change what `composer audit` checks or
	 * reports, such as COMPOSER_NO_DEV dropping the dev packages, or COMPOSER
	 * pointing it at another project.
	 */
	const COMPOSER_ENV_OVERRIDES = [
		'COMPOSER',
		'COMPOSER_AUDIT_ABANDONED',
		'COMPOSER_NO_AUDIT',
		'COMPOSER_NO_DEV',
	];

	/**
	 * Runs `composer audit`, or null for the real one. Tests swap it out.
	 *
	 * @var callable|null
	 */
	private static $runner;

	/**
	 * Audits the composer.lock of a repository at a ref.
	 *
	 * @param string      $repo Repository name, including the org.
	 * @param string|null $ref  Branch, tag or commit. Defaults to the default
	 *                          branch.
	 * @param bool        $dev  Whether to audit the require-dev packages too.
	 *
	 * @return array|null Audit, see assess(), or null when the repository has
	 *                    no composer.lock at that ref.
	 */
	public static function fetch( $repo, $ref = null, $dev = true ) {
		if ( null === $ref ) {
			$ref = GitHub::get_default_branch( $repo );
		}

		$sha = GitHub::get_commit_sha( $repo, $ref );

		if ( null === $sha ) {
			WP_CLI::error( "{$repo} has no branch, tag or commit {$ref}." );
		}

		// Both files are read at the same commit, which the raw file host does
		// not cache the way it caches branches.
		$lock = GitHub::get_raw_file( $repo, $sha, 'composer.lock' );

		if ( null === $lock ) {
			return null;
		}

		$composer_json = GitHub::get_raw_file( $repo, $sha, 'composer.json' );

		if ( null === $composer_json ) {
			WP_CLI::error( "{$repo} has a composer.lock but no composer.json at {$ref}." );
		}

		$decoded_lock = json_decode( $lock, true );

		if ( ! is_array( $decoded_lock ) ) {
			WP_CLI::error( "The composer.lock of {$repo} at {$ref} is not valid JSON." );
		}

		$report = self::audit_lock( $composer_json, $lock, $decoded_lock, $dev );

		return self::assess( $repo, $ref, $sha, $dev, $report, $decoded_lock );
	}

	/**
	 * Runs `composer audit` on a lock, unless it has no packages to audit.
	 * Composer skips such a lock without writing a report, which would look
	 * like a failure.
	 */
	private static function audit_lock( $composer_json, $composer_lock, array $lock, $dev ) {
		$packages = self::get_locked_packages( $lock );

		if ( ! $dev ) {
			$packages = array_filter(
				$packages,
				static function ( $package ) {
					return ! $package['dev'];
				}
			);
		}

		if ( ! $packages ) {
			return [
				'advisories' => [],
				'abandoned'  => [],
			];
		}

		$runner = self::$runner ? self::$runner : [ __CLASS__, 'run_composer_audit' ];

		return call_user_func( $runner, $composer_json, $composer_lock, $dev );
	}

	/**
	 * Replaces the `composer audit` run, for tests.
	 *
	 * @param callable|null $runner Receives the contents of composer.json and
	 *                              composer.lock and the dev flag, and returns
	 *                              the decoded report. Null restores the real
	 *                              run.
	 */
	public static function set_runner( $runner ) {
		self::$runner = $runner;
	}

	/**
	 * Turns the report of `composer audit` into the findings for a repository.
	 *
	 * @param string $repo   Repository name, including the org.
	 * @param string $ref    Branch, tag or commit that was asked for.
	 * @param string $sha    Commit the ref resolved to.
	 * @param bool   $dev    Whether the require-dev packages were audited.
	 * @param array  $report Decoded JSON output of `composer audit --format=json`.
	 * @param array  $lock   Decoded composer.lock that was audited.
	 *
	 * @return array{repo: string, ref: string, sha: string, dev: bool, status: string, advisories: array, ignored: array, abandoned: array}
	 */
	public static function assess( $repo, $ref, $sha, $dev, array $report, array $lock ) {
		$locked = self::get_locked_packages( $lock );

		$advisories = self::describe_advisories( self::section( $report, 'advisories' ), $locked );
		$ignored    = self::describe_advisories( self::section( $report, 'ignored-advisories' ), $locked );

		$abandoned = [];
		foreach ( self::section( $report, 'abandoned' ) as $package => $replacement ) {
			$abandoned[] = array_merge(
				self::describe_package( (string) $package, $locked ),
				[ 'replacement' => is_string( $replacement ) && '' !== $replacement ? $replacement : null ]
			);
		}

		usort( $abandoned, [ __CLASS__, 'compare' ] );

		if ( $advisories ) {
			$status = self::VULNERABLE;
		} elseif ( $abandoned ) {
			$status = self::ABANDONED;
		} else {
			$status = self::OK;
		}

		return [
			'repo'       => $repo,
			'ref'        => $ref,
			'sha'        => $sha,
			'dev'        => (bool) $dev,
			'status'     => $status,
			'advisories' => $advisories,
			'ignored'    => $ignored,
			'abandoned'  => $abandoned,
		];
	}

	/**
	 * Lists the packages of a lockfile with their versions, and whether they
	 * are only required for development.
	 *
	 * @param array $lock Decoded composer.lock.
	 *
	 * @return array<string, array{version: string, dev: bool}>
	 */
	public static function get_locked_packages( array $lock ) {
		$packages = [];
		$sections = [
			'packages'     => false,
			'packages-dev' => true,
		];

		foreach ( $sections as $section => $dev ) {
			if ( empty( $lock[ $section ] ) ) {
				continue;
			}

			foreach ( $lock[ $section ] as $package ) {
				$packages[ $package['name'] ] = [
					'version' => $package['version'],
					'dev'     => $dev,
				];
			}
		}

		return $packages;
	}

	/**
	 * Builds the environment for `composer audit`: the current one, without
	 * the variables that change what it checks, and with a Composer home of
	 * its own, so that no global configuration, such as `audit.ignore`, hides
	 * an advisory.
	 *
	 * @param array<string, string> $env          Current environment.
	 * @param string                $composer_home Empty directory to use as
	 *                                             Composer home.
	 *
	 * @return array<string, string>
	 */
	public static function get_composer_env( array $env, $composer_home ) {
		foreach ( self::COMPOSER_ENV_OVERRIDES as $variable ) {
			unset( $env[ $variable ] );
		}

		$env['COMPOSER_HOME'] = $composer_home;

		return $env;
	}

	/**
	 * Runs `composer audit` against a composer.json and composer.lock.
	 *
	 * The files are written to a temporary directory and audited from the
	 * lock, without installing anything. Plugins and scripts stay disabled,
	 * so auditing a repository never runs its code.
	 *
	 * @param string $composer_json Contents of composer.json.
	 * @param string $composer_lock Contents of composer.lock.
	 * @param bool   $dev           Whether to audit the require-dev packages too.
	 *
	 * @return array Decoded JSON report.
	 */
	private static function run_composer_audit( $composer_json, $composer_lock, $dev ) {
		$dir = Utils\get_temp_dir() . uniqid( 'wp-cli-dependency-audit-', true );

		if ( ! mkdir( $dir, 0700 ) || ! mkdir( "{$dir}/home", 0700 )
			|| false === file_put_contents( "{$dir}/composer.json", $composer_json )
			|| false === file_put_contents( "{$dir}/composer.lock", $composer_lock ) ) {
			self::remove_directory( $dir );
			WP_CLI::error( "Could not write the files to audit to the temporary directory {$dir}." );
		}

		// --abandoned=report matches the Code Quality workflow, and keeps a
		// composer.json setting from hiding abandoned packages.
		$command = 'composer audit --locked --format=json --abandoned=report --no-interaction --no-plugins --no-scripts'
			. ( $dev ? '' : ' --no-dev' )
			. ' --working-dir=' . escapeshellarg( $dir );

		$result = Process::create( $command, null, self::get_composer_env( (array) getenv(), "{$dir}/home" ) )->run();

		self::remove_directory( $dir );

		if ( '' !== trim( $result->stderr ) ) {
			WP_CLI::debug( trim( $result->stderr ), 'dependencies' );
		}

		// The exit code flags vulnerable and abandoned packages as well as
		// failures, so only a report that can be read tells them apart.
		$report = json_decode( $result->stdout, true );

		if ( ! is_array( $report ) || ! array_key_exists( 'advisories', $report ) ) {
			WP_CLI::error(
				"`composer audit` failed (exit code {$result->return_code}). Composer 2.7 or later is needed.\n"
				. trim( $result->stderr . "\n" . $result->stdout )
			);
		}

		return $report;
	}

	/**
	 * Gets a section of the report. Composer encodes an empty one as `[]`.
	 *
	 * @param array  $report Decoded report.
	 * @param string $key    Section name.
	 *
	 * @return array
	 */
	private static function section( array $report, $key ) {
		return isset( $report[ $key ] ) && is_array( $report[ $key ] ) ? $report[ $key ] : [];
	}

	private static function describe_advisories( array $section, array $locked ) {
		$advisories = [];

		foreach ( $section as $package => $package_advisories ) {
			foreach ( (array) $package_advisories as $advisory ) {
				$advisories[] = array_merge(
					self::describe_package( (string) $package, $locked ),
					[
						'advisory'          => isset( $advisory['advisoryId'] ) ? $advisory['advisoryId'] : null,
						'cve'               => isset( $advisory['cve'] ) ? $advisory['cve'] : null,
						'title'             => isset( $advisory['title'] ) ? $advisory['title'] : '',
						'link'              => isset( $advisory['link'] ) ? $advisory['link'] : null,
						'affected_versions' => isset( $advisory['affectedVersions'] ) ? $advisory['affectedVersions'] : null,
						'severity'          => isset( $advisory['severity'] ) ? $advisory['severity'] : null,
						'reported_at'       => isset( $advisory['reportedAt'] ) ? $advisory['reportedAt'] : null,
						'ignore_reason'     => isset( $advisory['ignoreReason'] ) ? $advisory['ignoreReason'] : null,
					]
				);
			}
		}

		usort( $advisories, [ __CLASS__, 'compare' ] );

		return $advisories;
	}

	/**
	 * Describes a package of the report. A package the lock does not list has
	 * no version, and whether it ships is unknown.
	 */
	private static function describe_package( $package, array $locked ) {
		return [
			'package' => $package,
			'version' => isset( $locked[ $package ] ) ? $locked[ $package ]['version'] : null,
			'dev'     => isset( $locked[ $package ] ) ? $locked[ $package ]['dev'] : null,
		];
	}

	private static function compare( $a, $b ) {
		$by_package = strcmp( $a['package'], $b['package'] );

		if ( 0 !== $by_package || ! isset( $a['advisory'], $b['advisory'] ) ) {
			return $by_package;
		}

		return strcmp( $a['advisory'], $b['advisory'] );
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

<?php namespace WP_CLI\Maintenance;

use WP_CLI;

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
		$files = self::fetch_files( $repo, $ref );

		if ( null === $files ) {
			return null;
		}

		return self::assess(
			$repo,
			$files['ref'],
			$files['sha'],
			$dev,
			self::audit_files( $files['composer_json'], $files['composer_lock'], $dev ),
			$files['lock']
		);
	}

	/**
	 * Gets the composer.json and composer.lock of a repository at a ref.
	 *
	 * @param string      $repo Repository name, including the org.
	 * @param string|null $ref  Branch, tag or commit. Defaults to the default
	 *                          branch.
	 *
	 * @return array{ref: string, sha: string, composer_json: string, composer_lock: string, lock: array}|null
	 *         Both files as committed, the decoded lock and the commit they
	 *         were read at, or null when the repository has no composer.lock
	 *         at that ref.
	 */
	public static function fetch_files( $repo, $ref = null ) {
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

		return [
			'ref'           => $ref,
			'sha'           => $sha,
			'composer_json' => $composer_json,
			'composer_lock' => $lock,
			'lock'          => $decoded_lock,
		];
	}

	/**
	 * Runs `composer audit` against a composer.json and composer.lock, unless
	 * the lock has no packages to audit. Composer skips such a lock without
	 * writing a report, which would look like a failure.
	 *
	 * @param string $composer_json Contents of composer.json.
	 * @param string $composer_lock Contents of composer.lock.
	 * @param bool   $dev           Whether to audit the require-dev packages too.
	 *
	 * @return array Decoded JSON report.
	 */
	public static function audit_files( $composer_json, $composer_lock, $dev = true ) {
		$lock = json_decode( $composer_lock, true );

		if ( is_array( $lock ) ) {
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
		// --abandoned=report matches the Code Quality workflow, and keeps a
		// composer.json setting from hiding abandoned packages.
		$arguments = [ 'audit', '--locked', '--format=json', '--abandoned=report' ];
		if ( ! $dev ) {
			$arguments[] = '--no-dev';
		}

		$result = Composer::run(
			$arguments,
			[
				'composer.json' => $composer_json,
				'composer.lock' => $composer_lock,
			]
		);

		// The exit code flags vulnerable and abandoned packages as well as
		// failures, so only a report that can be read tells them apart.
		$report = json_decode( $result['stdout'], true );

		if ( ! is_array( $report ) || ! array_key_exists( 'advisories', $report ) ) {
			WP_CLI::error(
				"`composer audit` failed (exit code {$result['return_code']}). Composer 2.7 or later is needed.\n"
				. trim( $result['stderr'] . "\n" . $result['stdout'] )
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
}

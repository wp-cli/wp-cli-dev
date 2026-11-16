<?php namespace WP_CLI\Maintenance;

use WP_CLI;

/**
 * Updates locked dependencies of a repository and proposes the new
 * composer.lock as a pull request.
 *
 * Only the lock changes: the update runs within the constraints of the
 * repository's composer.json, and moves the given packages and their
 * dependencies no further than needed.
 */
final class Dependency_Update {

	/**
	 * Prefix of the branches the new composer.lock is pushed to. There is one
	 * branch per kind of update, reused by later runs, so that a refresh of
	 * the framework and a security fix never replace each other.
	 */
	const BRANCH_PREFIX = 'update-dependencies-';

	/**
	 * Title of the commit on such a branch. A branch whose commit has another
	 * title was changed by someone else, and is never overwritten.
	 */
	const COMMIT_TITLE = 'Update locked dependencies';

	const LABEL = 'scope:distribution';

	/**
	 * Runs `composer update`, or null for the real one. Tests swap it out.
	 *
	 * @var callable|null
	 */
	private static $runner;

	/**
	 * Runs `composer why-not`, or null for the real one. Tests swap it out.
	 *
	 * @var callable|null
	 */
	private static $why_not_runner;

	/**
	 * Replaces the `composer update` run, for tests.
	 *
	 * @param callable|null $runner Receives the contents of composer.json and
	 *                              composer.lock, the packages to update and
	 *                              the branch, and returns the new contents of
	 *                              composer.lock. Null restores the real run.
	 */
	public static function set_runner( $runner ) {
		self::$runner = $runner;
	}

	/**
	 * Replaces the `composer why-not` run, for tests.
	 *
	 * @param callable|null $runner Receives the contents of composer.json and
	 *                              composer.lock, a package, a version and the
	 *                              branch, and returns what holds that version
	 *                              back, as for get_held_back_by(). Null
	 *                              restores the real run.
	 */
	public static function set_why_not_runner( $runner ) {
		self::$why_not_runner = $runner;
	}

	/**
	 * Works out what an update of the default branch of a repository changes.
	 *
	 * @param string   $repo     Repository name, including the org.
	 * @param string[] $packages Packages to update; `*` wildcards match the
	 *                           locked packages. Defaults to the packages with
	 *                           advisories.
	 *
	 * @return array{repo: string, branch: string, head: string, sha: string, requested: bool, packages: string[], unmatched: string[], changes: array, fixed: array, remaining: array, composer_lock: string|null}|null
	 *         Plan, or null when the repository has no composer.lock.
	 *         `branch` is the default branch and `head` the branch for the
	 *         pull request. `unmatched` lists the given packages that match no
	 *         locked package. `composer_lock` holds the new lock, or null when
	 *         the update changes no locked version. Each of the `remaining`
	 *         advisories also has a `fixed_in` version, the first one that
	 *         fixes it, and `held_back_by`, what `composer why-not` says keeps
	 *         that version out, see explain().
	 */
	public static function prepare( $repo, array $packages = [] ) {
		$files = Dependency_Audit::fetch_files( $repo );

		if ( null === $files ) {
			return null;
		}

		$plan = self::plan( $repo, $packages, $files );

		$plan['remaining'] = self::explain(
			$plan['remaining'],
			$files['composer_json'],
			null === $plan['composer_lock'] ? $files['composer_lock'] : $plan['composer_lock'],
			$files['ref']
		);

		return $plan;
	}

	/**
	 * Adds to each advisory the first version that fixes it, and what holds
	 * that version back.
	 *
	 * @param array  $advisories    Advisories, see Dependency_Audit.
	 * @param string $composer_json Contents of composer.json.
	 * @param string $composer_lock Contents of the composer.lock they apply to.
	 * @param string $branch        Branch the files are from.
	 *
	 * @return array The advisories, each with `fixed_in`, null when no fixed
	 *               version is known, and `held_back_by`, the reasons
	 *               `composer why-not` gives, an empty list when it finds none,
	 *               or null when it was not asked.
	 */
	public static function explain( array $advisories, $composer_json, $composer_lock, $branch ) {
		$runner  = self::$why_not_runner ? self::$why_not_runner : [ __CLASS__, 'get_held_back_by' ];
		$reasons = [];

		foreach ( $advisories as $index => $advisory ) {
			$fixed_in     = self::get_first_fixed_version( isset( $advisory['affected_versions'] ) ? $advisory['affected_versions'] : null, $advisory['version'] );
			$held_back_by = null;

			if ( null !== $fixed_in ) {
				$key = "{$advisory['package']} {$fixed_in}";

				if ( ! array_key_exists( $key, $reasons ) ) {
					$reasons[ $key ] = call_user_func( $runner, $composer_json, $composer_lock, $advisory['package'], $fixed_in, $branch );
				}

				$held_back_by = $reasons[ $key ];
			}

			$advisories[ $index ]['fixed_in']     = $fixed_in;
			$advisories[ $index ]['held_back_by'] = $held_back_by;
		}

		return $advisories;
	}

	/**
	 * Finds the lowest version above a locked one that none of the affected
	 * ranges of an advisory covers. Advisories often list one range per
	 * minor version, as in `>=5.3.0,<5.4.0|>=5.4.0,<5.4.56`, so the upper
	 * bound of one range can be the start of the next.
	 *
	 * @param string|null $affected Affected versions, as Composer reports them.
	 * @param string|null $version  Locked version.
	 *
	 * @return string|null The version, or null when none is known to be fixed
	 *                     or the ranges cannot be read.
	 */
	public static function get_first_fixed_version( $affected, $version ) {
		if ( ! is_string( $affected ) || ! is_string( $version ) || ! preg_match( '/^v?(\d[\w.\-]*)$/i', $version, $match ) ) {
			return null;
		}

		$ranges = [];
		foreach ( explode( '|', $affected ) as $constraint ) {
			$range = [
				'lower' => null,
				'upper' => null,
			];

			foreach ( preg_split( '/[\s,]+/', trim( $constraint ), -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
				if ( ! preg_match( '/^(>=|>|<=|<|==|=)?v?(\d[\w.\-]*)$/i', $part, $bound ) ) {
					return null;
				}

				if ( '' === $bound[1] || '=' === $bound[1][0] ) {
					$range['lower'] = [ $bound[2], true ];
					$range['upper'] = [ $bound[2], true ];
				} elseif ( '>' === $bound[1][0] ) {
					$range['lower'] = [ $bound[2], '>=' === $bound[1] ];
				} else {
					$range['upper'] = [ $bound[2], '<=' === $bound[1] ];
				}
			}

			$ranges[] = $range;
		}

		$candidate = $match[1];
		$passes    = count( $ranges ) + 1;

		// Each pass but the last moves past a range, so this many will do.
		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$containing = null;
			foreach ( $ranges as $range ) {
				if ( self::in_range( $candidate, $range ) ) {
					$containing = $range;
					break;
				}
			}

			if ( null === $containing ) {
				return $candidate === $match[1] ? null : $candidate;
			}

			if ( null === $containing['upper'] || $containing['upper'][1] ) {
				return null;
			}

			$candidate = $containing['upper'][0];
		}

		return null;
	}

	/**
	 * Asks `composer why-not` what keeps a version of a package from being
	 * locked.
	 *
	 * @param string $composer_json Contents of composer.json.
	 * @param string $composer_lock Contents of composer.lock.
	 * @param string $package       Package name.
	 * @param string $version       Version that should be locked.
	 * @param string $branch        Branch the files are from.
	 *
	 * @return string[]|null The reasons, one per line, such as
	 *                       `symfony/yaml v6.4.50 requires php (>=8.1 but 7.2.24 is installed)`;
	 *                       none when it finds nothing, and null when it fails.
	 */
	public static function get_held_back_by( $composer_json, $composer_lock, $package, $version, $branch ) {
		$result = Composer::run(
			[ 'why-not', $package, $version, '--locked' ],
			[
				'composer.json' => $composer_json,
				'composer.lock' => $composer_lock,
			],
			[],
			[ 'COMPOSER_ROOT_VERSION' => "dev-{$branch}" ]
		);

		// It exits with 1 when it finds something that holds the version back.
		if ( 0 === $result['return_code'] ) {
			return [];
		}

		if ( 1 !== $result['return_code'] ) {
			WP_CLI::debug( "`composer why-not {$package} {$version}` failed (exit code {$result['return_code']}).", 'composer' );
			return null;
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $result['stdout'] ) ), 'strlen' ) );
	}

	private static function plan( $repo, array $packages, array $files ) {
		$before = self::audit( $repo, $files, $files['composer_lock'], $files['lock'] );

		$plan = [
			'repo'          => $repo,
			'branch'        => $files['ref'],
			'head'          => self::get_branch_name( $packages ),
			'sha'           => $files['sha'],
			'requested'     => (bool) $packages,
			'packages'      => [],
			'unmatched'     => [],
			'changes'       => [],
			'fixed'         => [],
			'remaining'     => $before['advisories'],
			'composer_lock' => null,
		];

		if ( $packages ) {
			list( $plan['packages'], $plan['unmatched'] ) = self::expand( $packages, array_keys( self::index( $files['lock'] ) ) );
		} else {
			$plan['packages'] = array_values( array_unique( array_column( $before['advisories'], 'package' ) ) );
		}

		if ( ! $plan['packages'] ) {
			return $plan;
		}

		$packages = $plan['packages'];

		$runner   = self::$runner ? self::$runner : [ __CLASS__, 'run_composer_update' ];
		$new_lock = (string) call_user_func( $runner, $files['composer_json'], $files['composer_lock'], $packages, $files['ref'] );
		$decoded  = json_decode( $new_lock, true );

		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( "`composer update` wrote a composer.lock for {$repo} that is not valid JSON." );
		}

		if ( isset( $files['lock']['plugin-api-version'], $decoded['plugin-api-version'] )
			&& version_compare( $decoded['plugin-api-version'], $files['lock']['plugin-api-version'], '<' ) ) {
			WP_CLI::error(
				sprintf(
					'The composer.lock of %s was written by a newer Composer (plugin API %s) than the one installed here (plugin API %s), which would downgrade it. Run `composer self-update` first.',
					$repo,
					$files['lock']['plugin-api-version'],
					$decoded['plugin-api-version']
				)
			);
		}

		$plan['changes'] = self::diff( $files['lock'], $decoded );

		if ( ! $plan['changes'] ) {
			return $plan;
		}

		$after = self::audit( $repo, $files, $new_lock, $decoded );

		$plan['fixed']         = self::subtract( $before['advisories'], $after['advisories'] );
		$plan['remaining']     = $after['advisories'];
		$plan['composer_lock'] = $new_lock;

		return $plan;
	}

	/**
	 * Resolves the packages to update against the locked ones. Composer
	 * itself would update the packages a wildcard matches only as far as
	 * needed, so the matches are named one by one.
	 *
	 * @param string[] $patterns Package names, `*` matching any characters.
	 * @param string[] $locked   Names of the locked packages.
	 *
	 * @return array{0: string[], 1: string[]} Matched locked packages, and the
	 *                                         patterns that match none.
	 */
	public static function expand( array $patterns, array $locked ) {
		$matched   = [];
		$unmatched = [];

		foreach ( $patterns as $pattern ) {
			$regex = '#^' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			$found = preg_grep( $regex, $locked );

			if ( $found ) {
				$matched = array_merge( $matched, $found );
			} else {
				$unmatched[] = $pattern;
			}
		}

		$matched = array_values( array_unique( $matched ) );
		sort( $matched );

		return [ $matched, $unmatched ];
	}

	/**
	 * Names the branch for an update, after the packages that were asked for.
	 *
	 * @param string[] $packages Packages as given, or none for the packages
	 *                           with advisories.
	 *
	 * @return string
	 */
	public static function get_branch_name( array $packages ) {
		if ( ! $packages ) {
			return self::BRANCH_PREFIX . 'security';
		}

		$names = $packages;
		sort( $names );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', str_replace( '*', 'all', strtolower( implode( '-', $names ) ) ) ), '-' );

		if ( strlen( $slug ) > 60 ) {
			$slug = rtrim( substr( $slug, 0, 51 ), '-' ) . '-' . substr( md5( $slug ), 0, 8 );
		}

		return self::BRANCH_PREFIX . $slug;
	}

	/**
	 * Lists how the locked packages differ between two lockfiles.
	 *
	 * @param array $before Decoded composer.lock before the update.
	 * @param array $after  Decoded composer.lock after the update.
	 *
	 * @return array<int, array{package: string, from: string|null, to: string|null, dev: bool}>
	 *         Changed packages sorted by name; `from` is null for an added
	 *         package and `to` for a removed one.
	 */
	public static function diff( array $before, array $after ) {
		$before = self::index( $before );
		$after  = self::index( $after );
		$names  = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		sort( $names );

		$changes = [];
		foreach ( $names as $name ) {
			$old = isset( $before[ $name ] ) ? $before[ $name ] : null;
			$new = isset( $after[ $name ] ) ? $after[ $name ] : null;

			if ( $old && $new && $old['version'] === $new['version'] && $old['reference'] === $new['reference'] ) {
				continue;
			}

			// The same branch version moved to another commit, as for dev-main.
			$show_reference = $old && $new && $old['version'] === $new['version'];

			$changes[] = [
				'package' => $name,
				'from'    => $old ? self::describe_version( $old, $show_reference ) : null,
				'to'      => $new ? self::describe_version( $new, $show_reference ) : null,
				'dev'     => $new ? $new['dev'] : $old['dev'],
			];
		}

		return $changes;
	}

	/**
	 * Keeps the advisories of the first list that the second one no longer has.
	 *
	 * @param array $before Advisories before the update, see Dependency_Audit.
	 * @param array $after  Advisories after the update.
	 *
	 * @return array
	 */
	public static function subtract( array $before, array $after ) {
		$left = [];
		foreach ( $after as $advisory ) {
			$left[ "{$advisory['package']} {$advisory['advisory']}" ] = true;
		}

		return array_values(
			array_filter(
				$before,
				static function ( $advisory ) use ( $left ) {
					return ! isset( $left[ "{$advisory['package']} {$advisory['advisory']}" ] );
				}
			)
		);
	}

	/**
	 * Writes the title and description of the pull request for a plan.
	 *
	 * @param array $plan Plan, see prepare().
	 *
	 * @return array{0: string, 1: string} Title and description.
	 */
	public static function describe_pull_request( array $plan ) {
		$names = array_column( $plan['changes'], 'package' );

		if ( count( $names ) > 3 ) {
			$title = sprintf( 'Update %d locked dependencies', count( $names ) );
		} elseif ( count( $names ) > 1 ) {
			$title = 'Update ' . implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names );
		} else {
			$title = 'Update ' . reset( $names );
		}

		$lines = [
			'**This is an automated pull request**, opened by `wp maintenance dependencies update`.',
			'',
			( $plan['requested'] ? '' : 'Fixes security advisories. ' )
				. 'Updates `' . implode( '`, `', $plan['packages'] ) . '` in composer.lock, with the dependencies that had to move along. composer.json is unchanged.',
			'',
			'| Package | From | To | Dev |',
			'| --- | --- | --- | --- |',
		];

		foreach ( $plan['changes'] as $change ) {
			$lines[] = sprintf(
				'| %s | %s | %s | %s |',
				$change['package'],
				null === $change['from'] ? '(new)' : $change['from'],
				null === $change['to'] ? '(removed)' : $change['to'],
				$change['dev'] ? 'yes' : 'no'
			);
		}

		if ( $plan['fixed'] ) {
			$lines[] = '';
			$lines[] = '### Fixed security advisories';
			$lines[] = '';
			foreach ( $plan['fixed'] as $advisory ) {
				$lines[] = self::describe_advisory( $advisory );
			}
		}

		if ( $plan['remaining'] ) {
			$lines[] = '';
			$lines[] = '### Still affected';
			$lines[] = '';
			$lines[] = $plan['requested']
				? 'This update leaves these advisories as they are:'
				: 'Fixing these needs a change to the constraints in composer.json:';
			$lines[] = '';
			foreach ( $plan['remaining'] as $advisory ) {
				$lines[] = self::describe_advisory( $advisory );

				foreach ( self::describe_held_back_by( $advisory ) as $line ) {
					$lines[] = '' === $line ? '' : "  {$line}";
				}
			}
		}

		return [ $title, implode( "\n", $lines ) . "\n" ];
	}

	/**
	 * Commits the new composer.lock of a plan to its branch, on top of the
	 * commit the plan was made from, and opens or refreshes the pull request
	 * for it. A branch that already holds the same lock on the same commit is
	 * left as it is, so that a rerun does not restart its checks.
	 *
	 * @param array $plan Plan with a new composer.lock, see prepare().
	 *
	 * @return array{0: object, 1: bool} Pull request, and whether a new commit
	 *                                   was pushed.
	 */
	public static function publish( array $plan ) {
		$repo     = $plan['repo'];
		$head     = $plan['head'];
		$existing = GitHub::get_branch_sha( $repo, $head );
		$push     = true;

		if ( $existing ) {
			$comparison = GitHub::compare( $repo, $plan['branch'], $head );

			if ( ! self::is_own_branch( $comparison ) ) {
				WP_CLI::error( "The {$head} branch of {$repo} has commits that `wp maintenance dependencies update` did not make, so it is not overwritten. Merge or delete it first." );
			}

			$push = 1 !== (int) $comparison->ahead_by
				|| $comparison->merge_base_commit->sha !== $plan['sha']
				|| GitHub::get_raw_file( $repo, $existing, 'composer.lock' ) !== $plan['composer_lock'];
		}

		if ( $push ) {
			$message = self::COMMIT_TITLE . "\n\n" . implode(
				"\n",
				array_map(
					static function ( $change ) {
						return sprintf( '- %s: %s => %s', $change['package'], $change['from'] ?: '(new)', $change['to'] ?: '(removed)' );
					},
					$plan['changes']
				)
			);

			$sha = GitHub::commit_file( $repo, $plan['sha'], 'composer.lock', (string) $plan['composer_lock'], $message );
			GitHub::set_branch( $repo, $head, $sha, (bool) $existing );
		}

		list( $title, $body ) = self::describe_pull_request( $plan );

		$pull_request = GitHub::get_open_pull_request( $repo, $head );

		$pull_request = $pull_request
			? GitHub::update_pull_request( $repo, $pull_request->number, $title, $body )
			: GitHub::create_pull_request( $repo, $head, $plan['branch'], $title, $body );

		// Also on a refresh, in case labelling failed when it was opened.
		GitHub::add_label( $repo, $pull_request->number, self::LABEL );

		return [ $pull_request, $push ];
	}

	/**
	 * Checks whether a branch holds nothing but a change of composer.lock by
	 * this command on top of the default branch.
	 *
	 * @param object $comparison Comparison of the default branch with the
	 *                           branch.
	 *
	 * @return bool
	 */
	public static function is_own_branch( $comparison ) {
		if ( 0 === (int) $comparison->ahead_by ) {
			return true;
		}

		if ( 1 !== (int) $comparison->ahead_by ) {
			return false;
		}

		$commit = end( $comparison->commits );
		$title  = strtok( $commit->commit->message, "\n" );
		$files  = isset( $comparison->files ) ? array_column( (array) $comparison->files, 'filename' ) : [];

		return self::COMMIT_TITLE === $title && [ 'composer.lock' ] === $files;
	}

	private static function audit( $repo, array $files, $composer_lock, array $lock ) {
		return Dependency_Audit::assess(
			$repo,
			$files['ref'],
			$files['sha'],
			true,
			Dependency_Audit::audit_files( $files['composer_json'], $composer_lock, true ),
			$lock
		);
	}

	/**
	 * Runs `composer update` for some packages and returns the new lock.
	 *
	 * Packages that depend on each other move together, but only as far as
	 * needed, and root requirements only when given.
	 */
	private static function run_composer_update( $composer_json, $composer_lock, array $packages, $branch ) {
		$result = Composer::run(
			array_merge(
				[ 'update' ],
				$packages,
				[ '--with-dependencies', '--minimal-changes', '--no-install', '--no-audit' ]
			),
			[
				'composer.json' => $composer_json,
				'composer.lock' => $composer_lock,
			],
			[ 'composer.lock' ],
			[
				'COMPOSER_ROOT_VERSION'         => "dev-{$branch}",
				// Composer 2.9+ would otherwise refuse to resolve at all when a
				// package keeps an advisory that composer.json does not let it
				// fix, and hold back the fixes for all others with it. What is
				// left gets reported by the audit afterwards.
				'COMPOSER_NO_SECURITY_BLOCKING' => '1',
			]
		);

		if ( 0 !== $result['return_code'] || null === $result['files']['composer.lock'] ) {
			WP_CLI::error(
				"`composer update` failed (exit code {$result['return_code']}).\n"
				. trim( $result['stderr'] . "\n" . $result['stdout'] )
			);
		}

		return $result['files']['composer.lock'];
	}

	private static function index( array $lock ) {
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
					'version'   => $package['version'],
					'reference' => self::get_reference( $package ),
					'dev'       => $dev,
				];
			}
		}

		return $packages;
	}

	private static function get_reference( array $package ) {
		foreach ( [ 'source', 'dist' ] as $type ) {
			if ( isset( $package[ $type ]['reference'] ) ) {
				return $package[ $type ]['reference'];
			}
		}

		return null;
	}

	private static function describe_version( array $package, $show_reference ) {
		if ( ! $show_reference || null === $package['reference'] ) {
			return $package['version'];
		}

		return sprintf( '%s (%s)', $package['version'], substr( $package['reference'], 0, 7 ) );
	}

	/**
	 * Says what keeps the fix of an advisory out of the lock, as Markdown.
	 *
	 * @param array $advisory Advisory, see explain().
	 *
	 * @return string[] Lines.
	 */
	private static function describe_held_back_by( array $advisory ) {
		if ( empty( $advisory['fixed_in'] ) ) {
			return [];
		}

		$fixed_in = $advisory['fixed_in'];

		if ( null === $advisory['held_back_by'] ) {
			return [ "Fixed in {$fixed_in}." ];
		}

		if ( ! $advisory['held_back_by'] ) {
			return [ "Fixed in {$fixed_in}. `composer why-not` finds nothing that holds it back; `composer update \"{$advisory['package']}:{$fixed_in}\" --dry-run` may tell more." ];
		}

		return array_merge(
			[ "Fixed in {$fixed_in}, which `composer why-not` says is held back by:", '', '```' ],
			$advisory['held_back_by'],
			[ '```' ]
		);
	}

	private static function in_range( $version, array $range ) {
		if ( null !== $range['lower'] && ! version_compare( $version, $range['lower'][0], $range['lower'][1] ? '>=' : '>' ) ) {
			return false;
		}

		return null === $range['upper'] || version_compare( $version, $range['upper'][0], $range['upper'][1] ? '<=' : '<' );
	}

	private static function describe_advisory( array $advisory ) {
		$id = $advisory['link'] ? "[{$advisory['advisory']}]({$advisory['link']})" : $advisory['advisory'];

		return sprintf(
			'- %s %s: %s%s',
			$advisory['package'],
			$id,
			$advisory['title'],
			$advisory['cve'] ? " ({$advisory['cve']})" : ''
		);
	}
}

<?php namespace WP_CLI\Maintenance;

use cli\Shell;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Checks and updates the locked dependencies of the repositories that commit a
 * composer.lock, such as wp-cli/wp-cli-bundle.
 */
final class Dependencies_Command {

	/**
	 * Checks locked dependencies for known security advisories.
	 *
	 * Runs `composer audit` against the composer.lock of each repository, as
	 * committed at the given ref, without installing anything. This is the
	 * check the Code Quality workflow runs, so a repository it reports as
	 * vulnerable also fails there.
	 *
	 * Packages only required for development are marked as such, as they do
	 * not ship in the Phar. Abandoned packages are reported too, and so are
	 * advisories the composer.json of the repository ignores.
	 *
	 * Exits with an error code when any locked package is affected by an
	 * advisory that is not ignored. Abandoned packages alone do not fail the
	 * audit.
	 *
	 * Needs Composer 2.7 or later. Its global configuration and environment
	 * variables such as COMPOSER_NO_DEV are left out of the audit.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to audit. If no user/org was provided,
	 * 'wp-cli' org is assumed. Defaults to wp-cli/wp-cli-bundle. Repositories
	 * without a composer.lock are skipped with a warning.
	 *
	 * [--ref=<ref>]
	 * : Branch, tag or commit to audit, such as a release tag. Defaults to the
	 * default branch of each repository.
	 *
	 * [--[no-]dev]
	 * : Whether to audit the packages that are only required for development
	 * as well. Pass `--no-dev` to audit only what ships.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a specific format. Defaults to a table in a terminal
	 * and to JSON otherwise. The JSON also holds the commit that was audited,
	 * and the affected version ranges and links of the advisories.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp maintenance dependencies audit
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     | repo                 | package              | version | dev | advisory            | cve             | title                                |
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     | wp-cli/wp-cli-bundle | symfony/yaml         | v5.4.54 | yes | PKSA-nktx-f1n1-b4yd | CVE-2026-123792 | Exponential and Quadratic Parse ...  |
	 *     | wp-cli/wp-cli-bundle | behat/transliterator | v1.5.0  | yes | abandoned           |                 | Abandoned, no replacement suggested. |
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     Error: Locked packages are affected by security advisories in wp-cli/wp-cli-bundle at main.
	 *
	 *     # Audit only what ships in the Phar.
	 *     $ wp maintenance dependencies audit --no-dev
	 *     Success: No known vulnerabilities or abandoned packages in wp-cli/wp-cli-bundle at main.
	 *
	 * @when before_wp_load
	 */
	public function audit( $args, $assoc_args ) {
		$format = Utils\get_flag_value( $assoc_args, 'format', Shell::isPiped() ? 'json' : 'table' );
		$ref    = Utils\get_flag_value( $assoc_args, 'ref', null );
		$dev    = (bool) Utils\get_flag_value( $assoc_args, 'dev', true );
		$repos  = $args ? array_values( array_unique( array_map( [ GitHub::class, 'qualify_repo' ], $args ) ) ) : [ Bundle::REPO ];

		$audits = [];
		foreach ( $repos as $repo ) {
			$audit = Dependency_Audit::fetch( $repo, $ref, $dev );

			if ( null === $audit ) {
				WP_CLI::warning( "{$repo} has no composer.lock" . ( $ref ? " at {$ref}" : '' ) . ', skipping it.' );
				continue;
			}

			$audits[] = $audit;
		}

		if ( ! $audits ) {
			WP_CLI::error( 'None of the repositories has a composer.lock to audit.' );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) json_encode( $audits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$this->render_table( $audits );
		}

		$vulnerable = [];
		foreach ( $audits as $audit ) {
			if ( Dependency_Audit::VULNERABLE === $audit['status'] ) {
				$vulnerable[] = "{$audit['repo']} at {$audit['ref']}";
			}
		}

		if ( $vulnerable ) {
			WP_CLI::error( 'Locked packages are affected by security advisories in ' . implode( ', ', $vulnerable ) . '.' );
		}
	}

	/**
	 * Updates locked dependencies and proposes the new composer.lock as a pull request.
	 *
	 * By default, updates the packages that `wp maintenance dependencies
	 * audit` reports security advisories for. The update runs within the
	 * constraints of composer.json, which stays unchanged, and moves the
	 * dependencies of the updated packages only as far as needed.
	 *
	 * The new composer.lock is audited again, then committed on top of the
	 * default branch, and a pull request is opened from that commit. The branch
	 * is named after the update: `update-dependencies-security` by default,
	 * and after the packages with --packages. A later run of the same update
	 * replaces that commit and refreshes the pull request, but never
	 * overwrites a branch that someone else pushed to.
	 *
	 * Without --packages, exits with an error code when advisories are left
	 * that the update cannot fix, as fixing them needs a change to
	 * composer.json. For each of them, it says which version fixes it, and
	 * what `composer why-not` says holds that version back, both here and in
	 * the pull request.
	 *
	 * Needs Composer 2.7 or later. Works from the files on GitHub, so no clone
	 * of the repository is needed.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to update. If no user/org was provided,
	 * 'wp-cli' org is assumed. Defaults to wp-cli/wp-cli-bundle. Repositories
	 * without a composer.lock are skipped with a warning.
	 *
	 * [--packages=<packages>]
	 * : Comma-separated list of packages to update instead, with their
	 * dependencies. `*` matches any characters, so `symfony/*` updates all
	 * locked Symfony packages and `*` refreshes the whole lock.
	 *
	 * [--dry-run]
	 * : Show what the update changes, without pushing anything.
	 *
	 * [--yes]
	 * : Push and open the pull request without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Fix what the audit reports.
	 *     $ wp maintenance dependencies update --dry-run
	 *     +--------------------+---------+---------+-----+
	 *     | package            | from    | to      | dev |
	 *     +--------------------+---------+---------+-----+
	 *     | symfony/config     | v5.4.46 | v5.4.56 | yes |
	 *     | symfony/filesystem | v5.4.54 | v5.4.56 | yes |
	 *     | symfony/yaml       | v5.4.54 | v5.4.56 | yes |
	 *     +--------------------+---------+---------+-----+
	 *     Fixes 3 security advisories.
	 *     Dry run: not pushing the new composer.lock of wp-cli/wp-cli-bundle.
	 *
	 *     # Move the bundle to the latest commit of the framework.
	 *     $ wp maintenance dependencies update --packages=wp-cli/wp-cli --yes
	 *
	 * @when before_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$dry_run   = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$confirmed = (bool) Utils\get_flag_value( $assoc_args, 'yes', false );
		$packages  = array_values( array_filter( array_map( 'trim', explode( ',', (string) Utils\get_flag_value( $assoc_args, 'packages', '' ) ) ), 'strlen' ) );
		$repos     = $args ? array_values( array_unique( array_map( [ GitHub::class, 'qualify_repo' ], $args ) ) ) : [ Bundle::REPO ];

		foreach ( $packages as $package ) {
			if ( '-' === $package[0] ) {
				WP_CLI::error( "'{$package}' is not a package name." );
			}
		}

		$updated     = false;
		$still_found = [];
		foreach ( $repos as $repo ) {
			$plan = Dependency_Update::prepare( $repo, $packages );

			if ( null === $plan ) {
				WP_CLI::warning( "{$repo} has no composer.lock, skipping it." );
				continue;
			}

			$updated = true;

			if ( $plan['remaining'] ) {
				$still_found[] = $repo;
			}

			foreach ( $plan['unmatched'] as $pattern ) {
				WP_CLI::warning( "{$pattern} matches no package locked in {$repo}." );
			}

			if ( ! $plan['packages'] ) {
				if ( ! $plan['requested'] ) {
					WP_CLI::success( "No locked package of {$repo} has a security advisory, so there is nothing to update." );
				}
				continue;
			}

			if ( ! $plan['changes'] ) {
				WP_CLI::warning( 'Updating ' . implode( ', ', $plan['packages'] ) . " changes no locked version of {$repo}." );
				$this->report_remaining( $plan );
				continue;
			}

			Utils\format_items(
				'table',
				array_map(
					static function ( $change ) {
						return [
							'package' => $change['package'],
							'from'    => null === $change['from'] ? '(new)' : $change['from'],
							'to'      => null === $change['to'] ? '(removed)' : $change['to'],
							'dev'     => $change['dev'] ? 'yes' : 'no',
						];
					},
					$plan['changes']
				),
				[ 'package', 'from', 'to', 'dev' ]
			);

			if ( $plan['fixed'] ) {
				WP_CLI::log( sprintf( 'Fixes %d security %s.', count( $plan['fixed'] ), 1 === count( $plan['fixed'] ) ? 'advisory' : 'advisories' ) );
			}
			$this->report_remaining( $plan );

			if ( $dry_run ) {
				WP_CLI::log( "Dry run: not pushing the new composer.lock of {$repo}." );
				continue;
			}

			if ( ! $confirmed && ! Prompt::confirm( "Push the new composer.lock to the {$plan['head']} branch of {$repo} and open a pull request?" ) ) {
				WP_CLI::log( "Skipping {$repo}." );
				continue;
			}

			list( $pull_request, $pushed ) = Dependency_Update::publish( $plan );

			if ( ! $pushed ) {
				WP_CLI::log( "The {$plan['head']} branch already holds this composer.lock, so only the pull request was refreshed." );
			}
			WP_CLI::success( "Pull request for {$repo}: {$pull_request->html_url}" );
		}

		if ( ! $updated ) {
			WP_CLI::error( 'None of the repositories has a composer.lock to update.' );
		}

		// An explicit list of packages may leave advisories alone on purpose.
		if ( $still_found && ! $packages ) {
			WP_CLI::error( 'Locked packages are still affected by security advisories in ' . implode( ', ', $still_found ) . '. Fixing them needs a change to composer.json.' );
		}
	}

	private function report_remaining( array $plan ) {
		foreach ( $plan['remaining'] as $advisory ) {
			$message = "{$advisory['package']} {$advisory['version']} is still affected by {$advisory['advisory']}: {$advisory['title']}";

			if ( ! empty( $advisory['fixed_in'] ) ) {
				$message .= "\nIt is fixed in {$advisory['fixed_in']}";

				if ( ! empty( $advisory['held_back_by'] ) ) {
					$message .= ', which is held back by:';
					foreach ( $advisory['held_back_by'] as $reason ) {
						$message .= "\n  {$reason}";
					}
				} else {
					$message .= '.';
				}
			}

			WP_CLI::warning( $message );
		}
	}

	private function render_table( array $audits ) {
		$rows = [];
		foreach ( $audits as $audit ) {
			foreach ( $audit['advisories'] as $advisory ) {
				$rows[] = $this->row( $audit['repo'], $advisory, $advisory['advisory'], $advisory['cve'], $advisory['title'] );
			}

			foreach ( $audit['ignored'] as $advisory ) {
				$rows[] = $this->row(
					$audit['repo'],
					$advisory,
					$advisory['advisory'],
					$advisory['cve'],
					'Ignored' . ( $advisory['ignore_reason'] ? " ({$advisory['ignore_reason']})" : '' ) . ": {$advisory['title']}"
				);
			}

			foreach ( $audit['abandoned'] as $abandoned ) {
				$rows[] = $this->row(
					$audit['repo'],
					$abandoned,
					'abandoned',
					null,
					$abandoned['replacement']
						? "Abandoned, use {$abandoned['replacement']} instead."
						: 'Abandoned, no replacement suggested.'
				);
			}
		}

		if ( $rows ) {
			Utils\format_items( 'table', $rows, [ 'repo', 'package', 'version', 'dev', 'advisory', 'cve', 'title' ] );
		}

		foreach ( $audits as $audit ) {
			if ( Dependency_Audit::OK === $audit['status'] ) {
				WP_CLI::success( "No known vulnerabilities or abandoned packages in {$audit['repo']} at {$audit['ref']}." );
			}
		}
	}

	private function row( $repo, array $package, $advisory, $cve, $title ) {
		// A package the lock does not list may or may not ship.
		$dev = '';
		if ( null !== $package['dev'] ) {
			$dev = $package['dev'] ? 'yes' : 'no';
		}

		return [
			'repo'     => $repo,
			'package'  => $package['package'],
			'version'  => $package['version'],
			'dev'      => $dev,
			'advisory' => $advisory,
			'cve'      => $cve,
			'title'    => $title,
		];
	}
}

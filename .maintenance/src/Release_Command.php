<?php namespace WP_CLI\Maintenance;

use cli\Shell;
use WP_CLI;
use WP_CLI\Utils;

final class Release_Command {

	/**
	 * Close the already released milestones.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to close the milestones for. If no user/org was
	 * provided, 'wp-cli' org is assumed.
	 *
	 * [--bundle]
	 * : Close the milestones for the entire bundle.
	 *
	 * [--all]
	 * : Close the milestones for all repositories in the wp-cli organization.
	 *
	 * @subcommand close-released
	 * @when       before_wp_load
	 */
	public function close_released( $args, $assoc_args ) {

		foreach ( $this->get_repos( $args, $assoc_args ) as $repo ) {

			WP_CLI::log( "--- {$repo} ---" );

			$releases   = GitHub::get_project_releases( $repo );
			$milestones = GitHub::get_project_milestones( $repo );

			foreach ( $milestones as $milestone ) {
				WP_CLI::log( "Checking milestone '{$milestone->title}'..." );
				foreach ( $releases as $release ) {
					if ( $release->tag_name === $milestone->title || "v{$milestone->title}" === $release->tag_name ) {
						WP_CLI::log( "Found matching release '{$release->tag_name}', closing milestone '{$milestone->title}'..." );
						GitHub::close_milestone( $repo, $milestone->number );
					}
				}
			}
		}
	}

	/**
	 * Generate a new release out of an open milestone
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to generate a release for. If no user/org was
	 * provided, 'wp-cli' org is assumed.
	 *
	 * [--bundle]
	 * : Generate releases for the entire bundle.
	 *
	 * [--all]
	 * : Generate releases for all repositories in the wp-cli organization.
	 *
	 * @when before_wp_load
	 */
	public function generate( $args, $assoc_args ) {

		foreach ( $this->get_repos( $args, $assoc_args ) as $repo ) {

			WP_CLI::log( "--- {$repo} ---" );

			$releases   = GitHub::get_project_releases( $repo );
			$milestones = GitHub::get_project_milestones( $repo );

			foreach ( $milestones as $milestone ) {
				WP_CLI::log( "Checking milestone '{$milestone->title}'..." );
				foreach ( $releases as $release ) {
					if ( $release->tag_name === $milestone->title || "v{$milestone->title}" === $release->tag_name ) {
						WP_CLI::log( "Found matching release '{$release->tag_name}', skipping milestone '{$milestone->title}'..." );
						continue 2;
					}
				}
				WP_CLI::log( "Milestone '{$milestone->title}' does not have a matching release, generating one..." );

				if ( $this->has_open_items_on_milestone( $repo, $milestone->number ) ) {
					WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it has open issues/PRs assigned to it." );
					continue;
				}

				$title         = "Version {$milestone->title}";
				$tag           = "v{$milestone->title}";
				$release_notes = Release_Notes::render(
					[ Release_Notes::get( $repo, [ $milestone ], 'pull-request' ) ],
					'markdown',
					false
				);

				WP_CLI::log( 'Generating the following release:' );
				WP_CLI::log( '-----' );
				WP_CLI::log( "{$title} ({$tag})\n{$release_notes}" );
				WP_CLI::log( '-----' );

				fwrite( STDOUT, 'Is the above correct? [y/n] ' );
				$answer = fgets( STDIN );
				if ( false === $answer ) {
					WP_CLI::error( 'No answer received. This command needs an interactive terminal.' );
				}
				if ( 'y' !== strtolower( trim( $answer ) ) ) {
					continue;
				}

				$default_branch = GitHub::get_default_branch( $repo );

				WP_CLI::log( "Creating release {$title} {$tag}..." );
				GitHub::create_release( $repo, $tag, $default_branch, $title, $release_notes );

				WP_CLI::log( "Closing milestone '{$milestone->title}'" );
				GitHub::close_milestone( $repo, $milestone->number );
			}
		}
	}

	/**
	 * Checks which repositories have unreleased changes, and whether their
	 * milestones are ready to release them.
	 *
	 * Compares the default branch of each repository against its latest
	 * version tag and looks at the pull requests merged since. A repository is
	 * `up_to_date` when none of them calls for a release, `ready` when they
	 * all sit on one open milestone that `release generate` can release, and
	 * `needs_human` when a maintainer has to decide something first, such as
	 * which milestone a pull request belongs to. The blockers say what.
	 *
	 * Routine pull requests by Dependabot or GitHub Actions only count when
	 * they are on a milestone.
	 *
	 * Makes no changes.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to check. If no user/org was provided,
	 * 'wp-cli' org is assumed.
	 *
	 * [--bundle]
	 * : Check every package of the bundle.
	 *
	 * [--all]
	 * : Check all repositories in the wp-cli organization.
	 *
	 * [--format=<format>]
	 * : Render output in a specific format. Defaults to a table in a terminal
	 * and to JSON otherwise. The JSON lists the unreleased pull requests and
	 * the blockers of each repository as well.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp maintenance release plan cache-command db-command eval-command
	 *     +----------------------+-------------+------------+---------+------------+
	 *     | repo                 | status      | latest_tag | release | blockers   |
	 *     +----------------------+-------------+------------+---------+------------+
	 *     | wp-cli/cache-command | ready       | v3.0.0     | v3.0.1  |            |
	 *     | wp-cli/db-command    | needs_human | v3.0.1     |         | open_items |
	 *     | wp-cli/eval-command  | up_to_date  | v3.0.1     |         |            |
	 *     +----------------------+-------------+------------+---------+------------+
	 *
	 * @when before_wp_load
	 */
	public function plan( $args, $assoc_args ) {
		$format = Utils\get_flag_value( $assoc_args, 'format', Shell::isPiped() ? 'json' : 'table' );

		$plans = array_map( [ Release_Plan::class, 'fetch' ], $this->get_repos( $args, $assoc_args ) );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) json_encode( $plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		Utils\format_items(
			'table',
			array_map(
				static function ( $plan ) {
					return [
						'repo'       => $plan['repo'],
						'status'     => $plan['status'],
						'latest_tag' => $plan['latest_tag'],
						'release'    => $plan['release'] ? $plan['release']['tag'] : '',
						'blockers'   => implode( ', ', array_unique( array_column( $plan['blockers'], 'code' ) ) ),
					];
				},
				$plans
			),
			[ 'repo', 'status', 'latest_tag', 'release', 'blockers' ]
		);
	}

	private function has_open_items_on_milestone( $repo, $milestone ) {
		return GitHub::get_issues(
			$repo,
			[
				'milestone' => $milestone,
				'state'     => 'open',
			]
		);
	}

	/**
	 * Gets the repositories a command applies to, fully qualified.
	 *
	 * @param string[] $args       Repositories passed by name.
	 * @param array    $assoc_args `--bundle` and `--all` flags.
	 *
	 * @return string[]
	 */
	private function get_repos( $args, $assoc_args ) {
		$repos = (array) $args;

		if ( Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$repos = array_merge( $repos, $this->get_all_repos() );
		} elseif ( Utils\get_flag_value( $assoc_args, 'bundle', false ) ) {
			$repos = array_merge( $repos, $this->get_bundle_repos() );
		}

		return array_values( array_unique( array_map( [ GitHub::class, 'qualify_repo' ], $repos ) ) );
	}

	private function get_all_repos() {
		return array_map(
			static function ( $repo ) {
				return $repo->full_name;
			},
			array_filter(
				GitHub::get_organization_repos(),
				static function ( $repo ) {
					return ! $repo->archived && ! $repo->disabled;
				}
			)
		);
	}

	private function get_bundle_repos() {
		$default_branch = GitHub::get_default_branch( Bundle::REPO );

		return array_map(
			[ Bundle::class, 'get_repo' ],
			array_keys( Bundle::get_packages( Bundle::get_lock( $default_branch ) ) )
		);
	}
}

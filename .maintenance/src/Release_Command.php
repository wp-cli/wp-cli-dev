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
	 * Tags the default branch with the version of the milestone, publishes a
	 * release whose notes list the pull requests merged for that milestone,
	 * and closes the milestone.
	 *
	 * A milestone is skipped, with a warning, when it still has open issues or
	 * pull requests, has no merged pull requests, is not a version newer than
	 * the latest tag, or was already tagged. When a repository has several
	 * milestones that could be released, it is skipped unless --milestone says
	 * which one, as they would all tag the same commit.
	 *
	 * Run `wp maintenance release plan` first to see what is ready.
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
	 * [--milestone=<milestone>]
	 * : Only release the milestone with this title. Needs a single repository.
	 *
	 * [--notes-file=<file>]
	 * : Use the contents of this file as release notes, instead of the
	 * generated list of pull requests. Useful to publish notes that were
	 * reviewed and tidied up first. Needs --milestone.
	 *
	 * [--yes]
	 * : Publish without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Review the generated notes, then publish them after fixing a typo.
	 *     $ wp maintenance release-notes cache-command 3.0.1 --source=pull-request > notes.md
	 *     $ wp maintenance release generate cache-command --milestone=3.0.1 --notes-file=notes.md --yes
	 *
	 * @when before_wp_load
	 */
	public function generate( $args, $assoc_args ) {
		$only_milestone = Utils\get_flag_value( $assoc_args, 'milestone' );
		$notes_file     = Utils\get_flag_value( $assoc_args, 'notes-file' );
		$confirmed      = Utils\get_flag_value( $assoc_args, 'yes', false );
		$repos          = $this->get_repos( $args, $assoc_args );

		if ( null !== $only_milestone && 1 !== count( $repos ) ) {
			WP_CLI::error( '--milestone needs exactly one repository.' );
		}

		if ( null !== $notes_file ) {
			if ( null === $only_milestone ) {
				WP_CLI::error( '--notes-file needs --milestone.' );
			}

			$custom_notes = is_readable( $notes_file ) ? trim( (string) file_get_contents( $notes_file ) ) : '';
			if ( '' === $custom_notes ) {
				WP_CLI::error( "Could not read release notes from '{$notes_file}'." );
			}
		}

		foreach ( $repos as $repo ) {

			WP_CLI::log( "--- {$repo} ---" );

			$candidates = $this->get_releasable_milestones( $repo, $only_milestone );

			if ( count( $candidates ) > 1 ) {
				WP_CLI::warning(
					"Skipping {$repo} as several milestones could be released: "
					. implode( ', ', array_keys( $candidates ) ) . '. Pass --milestone to pick one.'
				);
				continue;
			}

			foreach ( $candidates as $candidate ) {
				list( $milestone, $notes ) = $candidate;

				$title         = "Version {$milestone->title}";
				$tag           = 'v' . ltrim( $milestone->title, 'v' );
				$release_notes = isset( $custom_notes )
					? $custom_notes . "\n"
					: Release_Notes::render( [ $notes ], 'markdown', false );

				WP_CLI::log( 'Generating the following release:' );
				WP_CLI::log( '-----' );
				WP_CLI::log( "{$title} ({$tag})\n{$release_notes}" );
				WP_CLI::log( '-----' );

				if ( ! $confirmed ) {
					fwrite( STDOUT, 'Is the above correct? [y/n] ' );
					$answer = fgets( STDIN );
					if ( false === $answer ) {
						WP_CLI::error( 'No answer received. Run this command in an interactive terminal, or pass --yes.' );
					}
					// A piped answer is not echoed, so end the prompt's line here.
					if ( ! stream_isatty( STDIN ) ) {
						fwrite( STDOUT, "\n" );
					}
					if ( 'y' !== strtolower( trim( $answer ) ) ) {
						continue;
					}
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
	 * Finds the open milestones of a repository that can be released, and
	 * warns about the ones that cannot.
	 *
	 * @param string      $repo           Repository name, including the org.
	 * @param string|null $only_milestone Title of the only milestone to look at.
	 *
	 * @return array<string, array> Milestone title => milestone and its notes.
	 */
	private function get_releasable_milestones( $repo, $only_milestone ) {
		$releases = array_map(
			static function ( $release ) {
				return $release->tag_name;
			},
			GitHub::get_project_releases( $repo )
		);
		$tags     = array_map(
			static function ( $tag ) {
				return $tag->name;
			},
			GitHub::get_tags( $repo )
		);
		$latest   = Release_Plan::latest_version_tag( $tags );

		$milestones = GitHub::get_project_milestones( $repo );

		if ( null !== $only_milestone ) {
			$milestones = array_filter(
				$milestones,
				static function ( $milestone ) use ( $only_milestone ) {
					return $milestone->title === $only_milestone;
				}
			);

			if ( ! $milestones ) {
				WP_CLI::warning( "No open milestone '{$only_milestone}' in {$repo}." );
			}
		}

		$candidates = [];
		foreach ( $milestones as $milestone ) {
			$version = ltrim( $milestone->title, 'v' );

			WP_CLI::log( "Checking milestone '{$milestone->title}'..." );

			if ( Release_Plan::has_tag( $releases, $version ) ) {
				WP_CLI::log( "Found a matching release, skipping milestone '{$milestone->title}'..." );
				continue;
			}

			if ( Release_Plan::has_tag( $tags, $version ) ) {
				WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it was already tagged. Its pull requests need a new milestone." );
				continue;
			}

			if ( ! Bundle::is_version( $version )
				|| ( null !== $latest && ! version_compare( $version, ltrim( $latest, 'v' ), '>' ) ) ) {
				WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it is not a version newer than {$latest}." );
				continue;
			}

			if ( $this->has_open_items_on_milestone( $repo, $milestone->number ) ) {
				WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it has open issues/PRs assigned to it." );
				continue;
			}

			$notes = Release_Notes::get( $repo, [ $milestone ], 'pull-request' );

			if ( empty( $notes['milestones'][0]['pull_requests'] ) ) {
				WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it has no merged pull requests." );
				continue;
			}

			$candidates[ $milestone->title ] = [ $milestone, $notes ];
		}

		return $candidates;
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

<?php namespace WP_CLI\Maintenance;

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

		$repos = (array) $args;

		if ( Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$repos = array_unique( array_merge( $repos, $this->get_all_repos() ) );
		} elseif ( Utils\get_flag_value( $assoc_args, 'bundle', false ) ) {
			$repos = array_unique( array_merge( $repos, $this->get_bundle_repos() ) );
		}

		foreach ( $repos as $repo ) {
			$repo = GitHub::qualify_repo( $repo );

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

		$repos = (array) $args;

		if ( Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$repos = array_unique( array_merge( $repos, $this->get_all_repos() ) );
		} elseif ( Utils\get_flag_value( $assoc_args, 'bundle', false ) ) {
			$repos = array_unique( array_merge( $repos, $this->get_bundle_repos() ) );
		}

		foreach ( $repos as $repo ) {
			$repo = GitHub::qualify_repo( $repo );

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

	private function has_open_items_on_milestone( $repo, $milestone ) {
		return GitHub::get_issues(
			$repo,
			[
				'milestone' => $milestone,
				'state'     => 'open',
			]
		);
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

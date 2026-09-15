<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Contrib_List_Command {

	/**
	 * Lists all contributors to this release.
	 *
	 * Run within the main WP-CLI project repository.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>]
	 * : Name of the repository to fetch the release notes for. If no user/org
	 * was provided, 'wp-cli' org is assumed. If no repo is passed, release
	 * notes for the entire org state since the last bundle release are fetched.
	 *
	 * [<milestone>...]
	 * : Name of one or more milestones to fetch the release notes for. If none
	 * are passed, the current open one is assumed.
	 *
	 * [--release=<version>]
	 * : Version of the bundle release, e.g. 3.0.0. Uses the milestone with
	 * that title in wp-cli/wp-cli-bundle, wp-cli/wp-cli and wp-cli/handbook
	 * instead of the currently open one, and reads the bundled packages from
	 * the composer.lock at the release tag of wp-cli/wp-cli-bundle. Only
	 * applies when no repo is passed.
	 *
	 * [--bundle-ref=<ref>]
	 * : Branch, tag or commit of wp-cli/wp-cli-bundle whose composer.lock lists
	 * the packages and versions shipped in this release. Defaults to the
	 * release tag when --release is passed and the tag exists, and to the
	 * default branch otherwise. Only applies when no repo is passed.
	 *
	 * [--format=<format>]
	 * : Render output in a specific format.
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - html
	 * ---
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$repos      = null;
		$use_bundle = false;

		$ignored_contributors = [
			'github-actions[bot]',
		];

		if ( count( $args ) > 0 ) {
			$repos = [ array_shift( $args ) ];
		}

		$milestone_names = $args;

		$release    = Utils\get_flag_value( $assoc_args, 'release' );
		$bundle_ref = Utils\get_flag_value( $assoc_args, 'bundle-ref' );

		if ( empty( $repos ) ) {
			$use_bundle = true;
			$repos      = Bundle::RELEASE_REPOS;
		}

		$contributors       = array();
		$pull_request_count = 0;

		// Get the contributors to the current open large project milestones
		foreach ( $repos as $repo ) {
			if ( $milestone_names ) {
				$milestone_names = (array) $milestone_names;

				$potential_milestones = GitHub::get_project_milestones(
					$repo,
					array( 'state' => 'all' )
				);

				$milestones = array();
				foreach ( $potential_milestones as $potential_milestone ) {
					if ( in_array(
						$potential_milestone->title,
						$milestone_names,
						true
					) ) {
						$milestones[] = $potential_milestone;
						$index        = array_search(
							$potential_milestone->title,
							$milestone_names,
							true
						);
						unset( $milestone_names[ $index ] );
					}
				}

				if ( ! empty( $milestone_names ) ) {
					WP_CLI::warning(
						sprintf(
							"Couldn't find the requested milestone(s) '%s' in repository '%s'.",
							implode( "', '", $milestone_names ),
							$repo
						)
					);
				}
			} else {
				$milestone  = Bundle::get_release_milestone( $repo, $use_bundle ? $release : null );
				$milestones = $milestone ? [ $milestone ] : [];
			}

			foreach ( $milestones as $milestone ) {
				WP_CLI::debug( "Using milestone '{$milestone->title}' for repo '{$repo}'", 'contrib-list' );
				$pull_requests     = GitHub::get_project_milestone_pull_requests( $repo, $milestone->number );
				$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
				WP_CLI::debug( count( $repo_contributors ) . ' contributors, ' . count( $pull_requests ) . " pull requests in '{$repo}' milestone '{$milestone->title}'", 'contrib-list' );
				$pull_request_count += count( $pull_requests );
				$contributors        = array_merge( $contributors, $repo_contributors );
			}
		}

		if ( $use_bundle ) {
			// Identify all command dependencies and their contributors
			list( $ref, $lock )                   = Bundle::get_release_lock( $release, $bundle_ref );
			list( $previous_ref, $previous_lock ) = Bundle::get_previous_release_lock( $release );

			WP_CLI::debug( "Bundled packages read from wp-cli/wp-cli-bundle@{$ref}, previous release: " . ( $previous_ref ?: 'none' ), 'contrib-list' );

			$previous_versions = $previous_lock ? Bundle::get_packages( $previous_lock ) : [];

			foreach ( Bundle::get_packages( $lock ) as $package_name => $version ) {
				$previous_version = isset( $previous_versions[ $package_name ] ) ? $previous_versions[ $package_name ] : null;

				// Closed milestones denote a tagged release
				$milestones = Bundle::get_shipped_milestones( $package_name, $previous_version, $version );

				// No shipped releases for this milestone.
				if ( empty( $milestones ) ) {
					continue;
				}

				$milestone_titles = array_map(
					static function ( $milestone ) {
						return $milestone->title;
					},
					$milestones
				);
				WP_CLI::debug( "Closed {$package_name} milestone(s): " . implode( ', ', $milestone_titles ), 'contrib-list' );

				foreach ( $milestones as $milestone ) {
					$pull_requests     = GitHub::get_project_milestone_pull_requests( $package_name, $milestone->number );
					$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
					WP_CLI::debug( count( $repo_contributors ) . ' contributors, ' . count( $pull_requests ) . " pull requests in '{$package_name}' milestone '{$milestone->title}'", 'contrib-list' );
					$pull_request_count += count( $pull_requests );
					$contributors        = array_merge( $contributors, $repo_contributors );
				}
			}
		}

		$contributors = array_diff( $contributors, $ignored_contributors );

		WP_CLI::log( 'Total contributors: ' . count( $contributors ) );
		WP_CLI::log( 'Total pull requests: ' . $pull_request_count );

		// Sort and render the contributor list
		asort( $contributors, SORT_NATURAL | SORT_FLAG_CASE );
		if ( in_array( $assoc_args['format'], array( 'markdown', 'html' ), true ) ) {
			$contrib_list = '';
			foreach ( $contributors as $url => $login ) {
				if ( 'markdown' === $assoc_args['format'] ) {
					$contrib_list .= '[@' . $login . '](' . $url . '), ';
				} elseif ( 'html' === $assoc_args['format'] ) {
					$contrib_list .= '<a href="' . $url . '">@' . $login . '</a>, ';
				}
			}
			$contrib_list = rtrim( $contrib_list, ', ' );
			WP_CLI::log( $contrib_list );
		}
	}
}

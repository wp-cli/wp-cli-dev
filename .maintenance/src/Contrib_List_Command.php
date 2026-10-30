<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Contrib_List_Command {

	/**
	 * Lists all contributors to this release.
	 *
	 * Only authors of merged pull requests count; bots are left out.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>]
	 * : Name of the repository to list the contributors for. If no user/org
	 * was provided, 'wp-cli' org is assumed. If no repo is passed, the
	 * contributors to the entire org since the last bundle release are listed.
	 *
	 * [<milestone>...]
	 * : Name of one or more milestones to list the contributors for. If none
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
	 *   - json
	 * ---
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$repos      = null;
		$use_bundle = false;

		if ( count( $args ) > 0 ) {
			$repos = [ GitHub::qualify_repo( array_shift( $args ) ) ];
		}

		$milestone_names = $args;

		$release    = Utils\get_flag_value( $assoc_args, 'release' );
		$bundle_ref = Utils\get_flag_value( $assoc_args, 'bundle-ref' );
		$format     = Utils\get_flag_value( $assoc_args, 'format', 'markdown' );

		if ( empty( $repos ) ) {
			$use_bundle = true;
			$repos      = Bundle::RELEASE_REPOS;
		}

		$contributors       = array();
		$pull_request_count = 0;

		// Get the contributors to the current open large project milestones
		foreach ( $repos as $repo ) {
			if ( $milestone_names ) {
				$milestones = Release_Notes::find_milestones( $repo, $milestone_names );
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
					$pull_requests     = GitHub::get_project_milestone_pull_requests( Bundle::get_repo( $package_name ), $milestone->number );
					$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
					WP_CLI::debug( count( $repo_contributors ) . ' contributors, ' . count( $pull_requests ) . " pull requests in '{$package_name}' milestone '{$milestone->title}'", 'contrib-list' );
					$pull_request_count += count( $pull_requests );
					$contributors        = array_merge( $contributors, $repo_contributors );
				}
			}
		}

		asort( $contributors, SORT_NATURAL | SORT_FLAG_CASE );

		if ( 'json' === $format ) {
			$list = [];
			foreach ( $contributors as $url => $login ) {
				$list[] = [
					'login' => $login,
					'url'   => $url,
				];
			}

			WP_CLI::line(
				(string) json_encode(
					[
						'total_contributors'  => count( $contributors ),
						'total_pull_requests' => $pull_request_count,
						'contributors'        => $list,
					],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		WP_CLI::log( 'Total contributors: ' . count( $contributors ) );
		WP_CLI::log( 'Total pull requests: ' . $pull_request_count );

		$contrib_list = [];
		foreach ( $contributors as $url => $login ) {
			$contrib_list[] = 'html' === $format
				? '<a href="' . $url . '">@' . $login . '</a>'
				: '[@' . $login . '](' . $url . ')';
		}
		WP_CLI::log( implode( ', ', $contrib_list ) );
	}
}

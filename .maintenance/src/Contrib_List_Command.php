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

		$repos           = null;
		$milestone_names = null;
		$use_bundle      = false;

		if ( count( $args ) > 0 ) {
			$repos = [ array_shift( $args ) ];
		}

		$milestone_names = $args;

		if ( empty( $repos ) ) {
			$use_bundle = true;
			$repos      = [
				'wp-cli/wp-cli-bundle',
				'wp-cli/wp-cli',
				'wp-cli/handbook',
				'wp-cli/wp-cli.github.com',
			];
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
				$milestones = GitHub::get_project_milestones( $repo );
				// Cheap way to get the latest milestone
				$milestone = array_shift( $milestones );
				if ( ! $milestone ) {
					continue;
				}
			}
			$entries = array();
			foreach ( $milestones as $milestone ) {
				WP_CLI::debug( "Using milestone '{$milestone->title}' for repo '{$repo}'", 'release-notes' );
				WP_CLI::log( 'Current open ' . $repo . ' milestone: ' . $milestone->title );
				$pull_requests     = GitHub::get_project_milestone_pull_requests( $repo, $milestone->number );
				$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
				WP_CLI::log( ' - Contributors: ' . count( $repo_contributors ) );
				WP_CLI::log( ' - Pull requests: ' . count( $pull_requests ) );
				$pull_request_count += count( $pull_requests );
				$contributors        = array_merge( $contributors, $repo_contributors );
			}
		}

		if ( $use_bundle ) {
			// Identify all command dependencies and their contributors

			$bundle = 'wp-cli/wp-cli-bundle';

			$milestones = GitHub::get_project_milestones( $bundle, array( 'state' => 'closed' ) );
			$milestone  = array_reduce(
				$milestones,
				function ( $tag, $milestone ) {
					return version_compare( $milestone->title, $tag, '>' ) ? $milestone->title : $tag;
				}
			);
			$tag        = ! empty( $milestone ) ? "v{$milestone}" : GitHub::get_default_branch( $bundle );

			$composer_lock_url = sprintf( 'https://raw.githubusercontent.com/%s/%s/composer.lock', $bundle, $tag );
			WP_CLI::log( 'Fetching ' . $composer_lock_url );
			$response = Utils\http_request( 'GET', $composer_lock_url );
			if ( 200 !== $response->status_code ) {
				WP_CLI::error( sprintf( 'Could not fetch composer.json (HTTP code %d)', $response->status_code ) );
			}
			$composer_json = json_decode( $response->body, true );

			// TODO: Only need for initial v2.
			$composer_json['packages'][] = array(
				'name'    => 'wp-cli/i18n-command',
				'version' => 'v2',
			);
			usort(
				$composer_json['packages'],
				function ( $a, $b ) {
					return $a['name'] < $b['name'] ? -1 : 1;
				}
			);

			foreach ( $composer_json['packages'] as $package ) {
				$package_name       = $package['name'];
				$version_constraint = str_replace( 'v', '', $package['version'] );
				if ( ! preg_match( '#^wp-cli/.+-command$#', $package_name )
					&& ! in_array(
						$package_name,
						array(
							'wp-cli/wp-cli-tests',
							'wp-cli/regenerate-readme',
							'wp-cli/autoload-splitter',
							'wp-cli/wp-config-transformer',
							'wp-cli/php-cli-tools',
							'wp-cli/spyc',
						),
						true
					) ) {
					continue;
				}
				// Closed milestones denote a tagged release
				$milestones       = GitHub::get_project_milestones( $package_name, array( 'state' => 'closed' ) );
				$milestone_ids    = array();
				$milestone_titles = array();
				foreach ( $milestones as $milestone ) {
					if ( ! version_compare( $milestone->title, $version_constraint, '>' ) ) {
						continue;
					}
					$milestone_ids[]    = $milestone->number;
					$milestone_titles[] = $milestone->title;
				}
				// No shipped releases for this milestone.
				if ( empty( $milestone_ids ) ) {
					continue;
				}
				WP_CLI::log( 'Closed ' . $package_name . ' milestone(s): ' . implode( ', ', $milestone_titles ) );
				foreach ( $milestone_ids as $milestone_id ) {
					$pull_requests     = GitHub::get_project_milestone_pull_requests( $package_name, $milestone_id );
					$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
					WP_CLI::log( ' - Contributors: ' . count( $repo_contributors ) );
					WP_CLI::log( ' - Pull requests: ' . count( $pull_requests ) );
					$pull_request_count += count( $pull_requests );
					$contributors        = array_merge( $contributors, $repo_contributors );
				}
			}
		}

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

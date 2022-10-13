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
	 * : Name(s) of the repository to close the milestoe for. If no user/org was
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
			$repos = array_unique( array_merge( $repos, $this->get_bundle_repos() ) );
		} elseif ( Utils\get_flag_value( $assoc_args, 'bundle', false ) ) {
			$repos = array_unique( array_merge( $repos, $this->get_bundle_repos() ) );
		}

		foreach ( $repos as $repo ) {
			if ( false === strpos( $repo, '/' ) ) {
				$repo = "wp-cli/{$repo}";
			}

			WP_CLI::log( "--- {$repo} ---" );

			$releases   = GitHub::get_project_releases( $repo );
			$milestones = GitHub::get_project_milestones( $repo );

			foreach ( $milestones as $milestone ) {
				WP_CLI::log( "Checking milestone '{$milestone->title}'..." );
				foreach ( $releases as $release ) {
					if ( $release->tag_name === $milestone->title || $release->tag_name === "v{$milestone->title}" ) {
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
			if ( false === strpos( $repo, '/' ) ) {
				$repo = "wp-cli/{$repo}";
			}

			WP_CLI::log( "--- {$repo} ---" );

			$releases   = GitHub::get_project_releases( $repo );
			$milestones = GitHub::get_project_milestones( $repo );

			foreach ( $milestones as $milestone ) {
				WP_CLI::log( "Checking milestone '{$milestone->title}'..." );
				foreach ( $releases as $release ) {
					if ( $release->tag_name === $milestone->title || $release->tag_name === "v{$milestone->title}" ) {
						WP_CLI::log( "Found matching release '{$release->tag_name}', skipping milestone '{$milestone->title}'..." );
						continue 2;
					}
				}
				WP_CLI::log( "Milestone '{$milestone->title}' does not have a matching release, generating one..." );

				if ( $this->has_open_items_on_milestone( $repo, $milestone->number ) ) {
					WP_CLI::warning( "Skipping milestone '{$milestone->title}' as it has open issues/PRs assigned to it." );
					continue 2;
				}

				$title         = "Version {$milestone->title}";
				$tag           = "v{$milestone->title}";
				$release_notes = $this->get_release_notes( $repo, $milestone->title, 'pull-request', 'markdown' );

				WP_CLI::log( 'Generating the following release:' );
				WP_CLI::log( '-----' );
				WP_CLI::log( "{$title} ({$tag})\n{$release_notes}" );
				WP_CLI::log( '-----' );

				fwrite( STDOUT, 'Is the above correct?' . ' [y/n] ' );
				$answer = strtolower( trim( fgets( STDIN ) ) );
				if ( 'y' !== $answer ) {
					continue 2;
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

	private function get_release_notes(
		$repo,
		$milestone_names,
		$source,
		$format
	) {
		if ( false === strpos( $repo, '/' ) ) {
			$repo = "wp-cli/{$repo}";
		}

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

		$entries = array();
		foreach ( $milestones as $milestone ) {

			WP_CLI::debug(
				"Using milestone '{$milestone->title}' for repo '{$repo}'",
				'release generate'
			);

			switch ( $source ) {
				case 'release':
					$tag = 0 === strpos( $milestone->title, 'v' )
						? $milestone->title
						: "v{$milestone->title}";

					$release = GitHub::get_release_by_tag(
						$repo,
						$tag,
						array( 'throw_errors' => false )
					);

					if ( $release ) {
						return $release->body;
					}

					WP_CLI::warning( "Release notes not found for {$repo}@{$tag}, falling back to pull-request source" );
				case 'pull-request':
					$pull_requests = GitHub::get_project_milestone_pull_requests(
						$repo,
						$milestone->number
					);

					foreach ( $pull_requests as $pull_request ) {
						$entries[] = $this->get_pull_request_reference(
							$pull_request,
							$format
						);
					}
					break;
				default:
					WP_CLI::error( "Unknown --source: {$source}" );
			}
		}

		$template = $format === 'html' ? '<ul>%s</ul>' : '%s';

		return sprintf( $template, implode( '', $entries ) );
	}

	private function get_pull_request_reference(
		$pull_request,
		$format
	) {
		$template = $format === 'html' ?
			'<li>%1$s [<a href="%3$s">#%2$d</a>]</li>' :
			'- %1$s [[#%2$d](%3$s)]' . PHP_EOL;

		return sprintf(
			$template,
			$this->format_title( $pull_request->title, $format ),
			$pull_request->number,
			$pull_request->html_url
		);
	}

	private function format_title( $title, $format ) {
		if ( 'html' === $format ) {
			$title = preg_replace( '/`(.*?)`/', '<code>$1</code>', $title );
		}

		return trim( $title );
	}

	private function repo_heading( $repo, $format ) {
		return sprintf(
			'html' === $format
				? '<h4><a href="%2$s">%1$s</a></h4>' . PHP_EOL
				: '#### [%1$s](%2$s)' . PHP_EOL,
			$repo,
			"https://github.com/{$repo}/"
		);
	}

	private function get_all_repos( $exclude = null ) {
		return array_map(
			static function ( $repo ) {
				return $repo->full_name;
			},
			array_filter(
				GitHub::get_organization_repos(),
				static function ( $repo ) use ( $exclude ) {
					if ( null === $exclude ) {
						return $repo->archived === false && $repo->disabled === false;
					}

					return ! in_array( $repo->full_name, (array) $exclude, true );
				}
			)
		);
	}

	private function get_bundle_repos() {
		$repos             = [];
		$default_branch    = GitHub::get_default_branch( 'wp-cli/wp-cli-bundle' );
		$composer_lock_url = "https://raw.githubusercontent.com/wp-cli/wp-cli-bundle/{$default_branch}/composer.lock";
		$response          = Utils\http_request( 'GET', $composer_lock_url );
		if ( 200 !== $response->status_code ) {
			WP_CLI::error( sprintf( 'Could not fetch composer.json (HTTP code %d)', $response->status_code ) );
		}
		$composer_json = json_decode( $response->body, true );

		usort(
			$composer_json['packages'],
			static function ( $a, $b ) {
				return $a['name'] < $b['name'] ? - 1 : 1;
			}
		);

		foreach ( $composer_json['packages'] as $package ) {
			$package_name = $package['name'];
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
			$repos[] = $package_name;
		}

		return $repos;
	}
}

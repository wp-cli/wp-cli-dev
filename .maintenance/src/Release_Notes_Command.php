<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Release_Notes_Command {

	/**
	 * Gets the release notes for one or more milestones of a repository.
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
	 * [--source=<source>]
	 * : Choose source from where to copy content.
	 * ---
	 * default: release
	 * options:
	 *   - release
	 *   - pull-request
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

		$repo = null;

		if ( count( $args ) > 0 ) {
			$repo = array_shift( $args );
		}

		$milestone_names = $args;

		$source     = Utils\get_flag_value( $assoc_args, 'source', 'release' );
		$format     = Utils\get_flag_value( $assoc_args, 'format', 'markdown' );
		$release    = Utils\get_flag_value( $assoc_args, 'release' );
		$bundle_ref = Utils\get_flag_value( $assoc_args, 'bundle-ref' );

		if ( $repo ) {
			$this->get_repo_release_notes(
				$repo,
				$milestone_names,
				$source,
				$format
			);

			return;
		}

		$this->get_bundle_release_notes( $source, $format, $release, $bundle_ref );
	}

	private function get_bundle_release_notes( $source, $format, $release, $bundle_ref ) {
		// Get the release notes for the milestones of the release repositories.
		foreach ( Bundle::RELEASE_REPOS as $repo ) {
			$milestone = Bundle::get_release_milestone( $repo, $release );

			if ( ! $milestone ) {
				WP_CLI::debug( "No milestone found for repo '{$repo}'", 'release-notes' );
				continue;
			}

			WP_CLI::debug( "Using milestone '{$milestone->title}' for repo '{$repo}'", 'release-notes' );

			WP_CLI::log( $this->repo_heading( $repo, $format ) );

			$this->get_repo_release_notes(
				$repo,
				$milestone->title,
				$source,
				$format
			);
		}

		// Identify all command dependencies and their release notes
		list( $ref, $lock )                   = Bundle::get_release_lock( $release, $bundle_ref );
		list( $previous_ref, $previous_lock ) = Bundle::get_previous_release_lock( $release );

		WP_CLI::debug( "Bundled packages read from wp-cli/wp-cli-bundle@{$ref}, previous release: " . ( $previous_ref ?: 'none' ), 'release-notes' );

		$previous_versions = $previous_lock ? Bundle::get_packages( $previous_lock ) : [];

		foreach ( Bundle::get_packages( $lock ) as $package_name => $version ) {
			$previous_version = isset( $previous_versions[ $package_name ] ) ? $previous_versions[ $package_name ] : null;

			// Closed milestones denote a tagged release
			$milestones = Bundle::get_shipped_milestones( $package_name, $previous_version, $version );

			if ( empty( $milestones ) ) {
				WP_CLI::debug( "No releases of '{$package_name}' shipped since " . ( $previous_ref ?: 'the beginning' ), 'release-notes' );
				continue;
			}

			WP_CLI::log( $this->repo_heading( $package_name, $format ) );

			foreach ( $milestones as $milestone ) {
				$this->get_repo_release_notes(
					$package_name,
					$milestone->title,
					$source,
					$format
				);
			}
		}
	}

	private function get_repo_release_notes(
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

			WP_CLI::debug( "Using milestone '{$milestone->title}' for repo '{$repo}'", 'release-notes' );

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
						WP_CLI::log( $release->body );
						break;
					}

					WP_CLI::warning( "Release notes not found for {$repo}@{$tag}, falling back to pull-request source" );
					// Intentionally falling through.
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

		$template = 'html' === $format ? '<ul>%s</ul>' : '%s';

		WP_CLI::log( sprintf( $template, implode( '', $entries ) ) );
	}

	private function get_pull_request_reference(
		$pull_request,
		$format
	) {
		$template = 'html' === $format ?
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
}

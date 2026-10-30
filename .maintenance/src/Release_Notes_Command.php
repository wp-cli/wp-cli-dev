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
	 * ---
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

		$repo = null;

		if ( count( $args ) > 0 ) {
			$repo = GitHub::qualify_repo( array_shift( $args ) );
		}

		$milestone_names = $args;

		$source     = Utils\get_flag_value( $assoc_args, 'source', 'release' );
		$format     = Utils\get_flag_value( $assoc_args, 'format', 'markdown' );
		$release    = Utils\get_flag_value( $assoc_args, 'release' );
		$bundle_ref = Utils\get_flag_value( $assoc_args, 'bundle-ref' );

		$sections = $repo
			? [ $this->get_repo_release_notes( $repo, $milestone_names, $source ) ]
			: $this->get_bundle_release_notes( $source, $release, $bundle_ref );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) json_encode( $sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::line( Release_Notes::render( $sections, $format, null === $repo ) );
	}

	private function get_bundle_release_notes( $source, $release, $bundle_ref ) {
		$sections = [];

		// Get the release notes for the milestones of the release repositories.
		foreach ( Bundle::RELEASE_REPOS as $repo ) {
			$milestone = Bundle::get_release_milestone( $repo, $release );

			if ( ! $milestone ) {
				WP_CLI::debug( "No milestone found for repo '{$repo}'", 'release-notes' );
				continue;
			}

			$sections[] = Release_Notes::get( $repo, [ $milestone ], $source );
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

			$sections[] = Release_Notes::get( $package_name, $milestones, $source );
		}

		return $sections;
	}

	private function get_repo_release_notes( $repo, $milestone_names, $source ) {
		if ( $milestone_names ) {
			return Release_Notes::get( $repo, Release_Notes::find_milestones( $repo, $milestone_names ), $source );
		}

		$milestone = Bundle::get_release_milestone( $repo );

		if ( ! $milestone ) {
			WP_CLI::error( "Repository '{$repo}' has no open milestone. Pass the milestone(s) to fetch the release notes for." );
		}

		return Release_Notes::get( $repo, [ $milestone ], $source );
	}
}

<?php namespace WP_CLI\Maintenance;

use WP_CLI;

final class Release_Date_Command {

	/**
	 * Retrieves the date a given release for a repository was published at.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Name of the repository to fetch the release date for. If no user/org
	 * was provided, 'wp-cli' org is assumed.
	 *
	 * <release>
	 * : Name of the release to fetch the release date for.
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		list( $repo, $milestone_name ) = $args;

		$repo    = GitHub::qualify_repo( $repo );
		$tag     = 0 === strpos( $milestone_name, 'v' ) ? $milestone_name : "v{$milestone_name}";
		$release = GitHub::get_release_by_tag( $repo, $tag );

		if ( ! $release ) {
			WP_CLI::error( "Couldn't find the release '{$tag}' in repository '{$repo}'." );
		}

		WP_CLI::log( $release->published_at );
	}
}

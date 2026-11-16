<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Milestone_Command {

	/**
	 * Puts issues or pull requests on a milestone.
	 *
	 * Replaces the milestone they were on, so this also defers the open items
	 * of a milestone to a later one, or moves pull requests off a milestone
	 * that was released already.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Name of the repository. If no user/org was provided, 'wp-cli' org is
	 * assumed.
	 *
	 * <milestone>
	 * : Title of the milestone, such as 3.0.4.
	 *
	 * <number>...
	 * : Numbers of the issues or pull requests.
	 *
	 * [--create]
	 * : Create the milestone when the repository has none with that title.
	 *
	 * ## EXAMPLES
	 *
	 *     # Defer an open pull request to the next patch release.
	 *     $ wp maintenance milestone assign i18n-command 3.0.4 503 --create
	 *     Created milestone '3.0.4' in wp-cli/i18n-command.
	 *     Added #503 to milestone '3.0.4'.
	 *     Success: Put 1 issue(s) or pull request(s) on milestone '3.0.4'.
	 *
	 * @when before_wp_load
	 */
	public function assign( $args, $assoc_args ) {
		$repo    = GitHub::qualify_repo( array_shift( $args ) );
		$title   = (string) array_shift( $args );
		$create  = Utils\get_flag_value( $assoc_args, 'create', false );
		$numbers = [];

		foreach ( $args as $arg ) {
			$number = filter_var( ltrim( trim( $arg ), '#' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
			if ( false === $number ) {
				WP_CLI::error( "'{$arg}' is not the number of an issue or pull request." );
			}
			$numbers[] = $number;
		}

		$milestone = self::find( GitHub::get_project_milestones( $repo, [ 'state' => 'all' ] ), $title );

		if ( null === $milestone ) {
			if ( ! $create ) {
				WP_CLI::error( "{$repo} has no milestone '{$title}'. Pass --create to create it." );
			}

			$milestone = GitHub::create_milestone( $repo, $title );
			WP_CLI::log( "Created milestone '{$title}' in {$repo}." );
		} elseif ( 'open' !== $milestone->state ) {
			WP_CLI::error( "Milestone '{$title}' of {$repo} is closed." );
		}

		foreach ( $numbers as $number ) {
			GitHub::set_milestone( $repo, $number, $milestone->number );
			WP_CLI::log( "Added #{$number} to milestone '{$title}'." );
		}

		WP_CLI::success( sprintf( "Put %d issue(s) or pull request(s) on milestone '%s'.", count( $numbers ), $title ) );
	}

	/**
	 * Finds a milestone by its title.
	 *
	 * @param object[] $milestones Milestones with a `title`.
	 * @param string   $title      Title to look for.
	 *
	 * @return object|null
	 */
	public static function find( array $milestones, $title ) {
		foreach ( $milestones as $milestone ) {
			if ( $milestone->title === $title ) {
				return $milestone;
			}
		}

		return null;
	}
}

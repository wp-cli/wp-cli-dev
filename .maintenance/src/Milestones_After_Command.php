<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Milestones_After_Command {

	/**
	 * Retrieves the milestones that were closed after a given milestone.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Name of the repository to fetch the milestones for.
	 *
	 * <milestone>
	 * : Milestone to serve as threshold.
	 *
	 * [--format=<format>]
	 * : Render output in a specific format.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - json
	 * ---
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {

		list( $repo, $milestone_name ) = $args;

		$repo = GitHub::qualify_repo( $repo );

		$milestones = self::closed_after(
			GitHub::get_project_milestones( $repo, [ 'state' => 'closed' ] ),
			$milestone_name
		);

		if ( null === $milestones ) {
			WP_CLI::error( "Couldn't find the closed milestone '{$milestone_name}' in repository '{$repo}'." );
		}

		Milestones_Since_Command::output_titles( $milestones, Utils\get_flag_value( $assoc_args, 'format', 'list' ) );
	}

	/**
	 * Picks the milestones that were closed after a given one, in the order
	 * they were closed.
	 *
	 * @param object[] $milestones Closed milestones with `title` and `closed_at`.
	 * @param string   $threshold  Title of the milestone to serve as threshold.
	 *
	 * @return object[]|null Milestones, or null when the threshold is not
	 *                       among them.
	 */
	public static function closed_after( array $milestones, $threshold ) {
		usort(
			$milestones,
			static function ( $a, $b ) {
				return strcmp( $a->closed_at, $b->closed_at );
			}
		);

		foreach ( $milestones as $index => $milestone ) {
			if ( $milestone->title === $threshold ) {
				return array_slice( $milestones, $index + 1 );
			}
		}

		return null;
	}
}

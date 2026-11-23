<?php namespace WP_CLI\Maintenance;

use DateTime;
use WP_CLI;
use WP_CLI\Utils;

final class Milestones_Since_Command {

	/**
	 * Retrieves the milestones that were closed for a given repository after a
	 * specific date threshold.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Name of the repository to fetch the milestones for.
	 *
	 * <date>
	 * : Threshold date to filter by.
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

		list( $repo, $date ) = $args;

		$repo = GitHub::qualify_repo( $repo );
		$date = new DateTime( $date );

		$milestones = array_filter(
			GitHub::get_project_milestones( $repo, [ 'state' => 'closed' ] ),
			static function ( $milestone ) use ( $date ) {
				return new DateTime( $milestone->closed_at ) > $date;
			}
		);

		usort(
			$milestones,
			static function ( $a, $b ) {
				return strcmp( $a->closed_at, $b->closed_at );
			}
		);

		self::output_titles( $milestones, Utils\get_flag_value( $assoc_args, 'format', 'list' ) );
	}

	/**
	 * Prints the titles of milestones, space-separated or as a JSON array.
	 *
	 * @param object[] $milestones
	 * @param string   $format     'list' or 'json'.
	 */
	public static function output_titles( array $milestones, $format ) {
		$titles = array_values(
			array_map(
				static function ( $milestone ) {
					return $milestone->title;
				},
				$milestones
			)
		);

		WP_CLI::line( 'json' === $format ? (string) json_encode( $titles ) : implode( ' ', $titles ) );
	}
}

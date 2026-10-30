<?php namespace WP_CLI\Maintenance;

use WP_CLI;

final class Replace_Label_Command {

	/**
	 * Replaces a label with a different one, and optionally deletes the old
	 * label.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Name of the repository you want to replace a label for.
	 *
	 * <old-label>
	 * : Old label to replace on all issues and pull requests, open or closed.
	 *
	 * <new-label>
	 * : New label to replace it with.
	 *
	 * [--delete]
	 * : Delete the old label after the operation is complete.
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {

		list( $repo, $old_label, $new_label ) = $args;

		$repo = GitHub::qualify_repo( $repo );

		$delete = WP_CLI\Utils\get_flag_value( $assoc_args, 'delete', false );

		$issues = GitHub::get_issues_by_label( $repo, $old_label );

		foreach ( $issues as $issue ) {
			GitHub::remove_label( $repo, $issue->number, $old_label );
			GitHub::add_label( $repo, $issue->number, $new_label );
		}

		if ( $delete ) {
			GitHub::delete_label( $repo, $old_label );
		}

		WP_CLI::success( sprintf( "Label '%s' was replaced with '%s' on %d issue(s) in the '%s' repository.", $old_label, $new_label, count( $issues ), $repo ) );
	}
}

<?php namespace WP_CLI\Maintenance;

/**
 * Works out whether a repository has unreleased changes, and whether its
 * milestones are in a state to release them.
 *
 * Choosing a version is a decision for a maintainer. The plan therefore never
 * picks one: it releases a milestone a maintainer has set up, and reports
 * anything that needs their decision first as a blocker.
 */
final class Release_Plan {

	const UP_TO_DATE  = 'up_to_date';
	const READY       = 'ready';
	const NEEDS_HUMAN = 'needs_human';

	/**
	 * Authors of routine pull requests that do not call for a release by
	 * themselves, unless a maintainer added them to a milestone.
	 */
	const ROUTINE_AUTHORS = [
		'dependabot[bot]',
		'github-actions[bot]',
	];

	/**
	 * Gathers what the plan needs to know about a repository from GitHub.
	 *
	 * @param string $repo Repository name, including the org.
	 *
	 * @return array Plan, see assess().
	 */
	public static function fetch( $repo ) {
		$tags       = array_map(
			static function ( $tag ) {
				return $tag->name;
			},
			GitHub::get_tags( $repo )
		);
		$milestones = GitHub::get_project_milestones( $repo, [ 'state' => 'all' ] );
		$latest_tag = self::latest_version_tag( $tags );

		$pull_requests = [];
		if ( null !== $latest_tag ) {
			$pull_requests = GitHub::get_pull_requests_merged_since( $repo, $latest_tag, GitHub::get_default_branch( $repo ) );
		}

		return self::assess( $repo, $tags, $milestones, $pull_requests );
	}

	/**
	 * Decides whether a repository is up to date, ready for a release, or
	 * needs a maintainer to decide something first.
	 *
	 * @param string   $repo          Repository name, including the org.
	 * @param string[] $tags          Names of all tags of the repository.
	 * @param object[] $milestones    All milestones, open and closed, with
	 *                                `title`, `state` and `open_issues`.
	 * @param object[] $pull_requests Pull requests merged into the default
	 *                                branch since the latest version tag, with
	 *                                `number`, `title`, `user` and `milestone`.
	 *
	 * @return array{repo: string, status: string, latest_tag: string|null, release: array|null, pull_requests: array, blockers: array}
	 */
	public static function assess( $repo, array $tags, array $milestones, array $pull_requests ) {
		$latest_tag = self::latest_version_tag( $tags );
		$changes    = array_values( array_filter( $pull_requests, [ __CLASS__, 'is_change' ] ) );

		$plan = [
			'repo'          => $repo,
			'status'        => self::UP_TO_DATE,
			'latest_tag'    => $latest_tag,
			'release'       => null,
			'pull_requests' => array_map( [ __CLASS__, 'describe_pull_request' ], $changes ),
			'blockers'      => [],
		];

		if ( null === $latest_tag ) {
			$plan['status']     = self::NEEDS_HUMAN;
			$plan['blockers'][] = self::blocker( 'no_version_tag', 'The repository has no version tag to compare the default branch against.' );
			return $plan;
		}

		$milestones_by_title = [];
		foreach ( $milestones as $milestone ) {
			$milestones_by_title[ $milestone->title ] = $milestone;
		}

		$targets     = [];
		$unassigned  = [];
		$on_released = [];
		foreach ( $changes as $pull_request ) {
			if ( empty( $pull_request->milestone ) ) {
				$unassigned[] = $pull_request->number;
				continue;
			}

			$title     = $pull_request->milestone->title;
			$milestone = isset( $milestones_by_title[ $title ] ) ? $milestones_by_title[ $title ] : $pull_request->milestone;

			if ( 'open' !== $milestone->state || self::has_tag( $tags, $title ) ) {
				$on_released[ $title ][] = $pull_request->number;
				continue;
			}

			$targets[ $title ] = $milestone;
		}

		if ( $unassigned ) {
			$plan['blockers'][] = self::blocker(
				'no_milestone',
				self::list_numbers( $unassigned ) . ' not on any milestone, so the release notes would leave them out.',
				$unassigned
			);
		}

		foreach ( $on_released as $title => $numbers ) {
			$plan['blockers'][] = self::blocker(
				'released_milestone',
				self::list_numbers( $numbers ) . " on milestone {$title}, which was already tagged or closed, so these changes would not ship.",
				$numbers
			);
		}

		if ( count( $targets ) > 1 ) {
			$plan['blockers'][] = self::blocker(
				'several_milestones',
				'The unreleased changes are spread over the milestones ' . implode( ', ', array_keys( $targets ) ) . '.'
			);
		}

		foreach ( $targets as $title => $milestone ) {
			if ( ! Bundle::is_version( ltrim( $title, 'v' ) )
				|| ! version_compare( ltrim( $title, 'v' ), ltrim( $latest_tag, 'v' ), '>' ) ) {
				$plan['blockers'][] = self::blocker( 'invalid_version', "Milestone {$title} is not a version newer than {$latest_tag}." );
			}

			if ( $milestone->open_issues > 0 ) {
				$plan['blockers'][] = self::blocker( 'open_items', "Milestone {$title} still has {$milestone->open_issues} open issue(s) or pull request(s)." );
			}
		}

		if ( $plan['blockers'] ) {
			$plan['status'] = self::NEEDS_HUMAN;
		} elseif ( $targets ) {
			$title           = (string) key( $targets );
			$plan['status']  = self::READY;
			$plan['release'] = [
				'milestone' => $title,
				'tag'       => 'v' . ltrim( $title, 'v' ),
			];
		}

		return $plan;
	}

	/**
	 * Finds the highest version among tag names.
	 *
	 * @param string[] $tags Tag names.
	 *
	 * @return string|null Tag name, or null when no tag is a version.
	 */
	public static function latest_version_tag( array $tags ) {
		$latest = null;

		foreach ( $tags as $tag ) {
			if ( ! Bundle::is_version( ltrim( $tag, 'v' ) ) ) {
				continue;
			}

			if ( null === $latest || version_compare( ltrim( $tag, 'v' ), ltrim( $latest, 'v' ), '>' ) ) {
				$latest = $tag;
			}
		}

		return $latest;
	}

	/**
	 * Checks whether a version has been tagged, with or without a leading 'v'.
	 *
	 * @param string[] $tags    Tag names.
	 * @param string   $version Version, such as a milestone title.
	 *
	 * @return bool
	 */
	public static function has_tag( array $tags, $version ) {
		$version = ltrim( $version, 'v' );

		return in_array( $version, $tags, true ) || in_array( "v{$version}", $tags, true );
	}

	/**
	 * Checks whether a merged pull request calls for a release. Routine
	 * automated updates only do when a maintainer put them on a milestone.
	 *
	 * @param object $pull_request Pull request with `user` and `milestone`.
	 *
	 * @return bool
	 */
	private static function is_change( $pull_request ) {
		return ! empty( $pull_request->milestone )
			|| ! in_array( $pull_request->user->login, self::ROUTINE_AUTHORS, true );
	}

	private static function describe_pull_request( $pull_request ) {
		return [
			'number'    => $pull_request->number,
			'title'     => $pull_request->title,
			'author'    => $pull_request->user->login,
			'milestone' => empty( $pull_request->milestone ) ? null : $pull_request->milestone->title,
		];
	}

	private static function blocker( $code, $message, array $pull_requests = [] ) {
		return [
			'code'          => $code,
			'message'       => $message,
			'pull_requests' => $pull_requests,
		];
	}

	private static function list_numbers( array $numbers ) {
		$list = implode(
			', ',
			array_map(
				static function ( $number ) {
					return "#{$number}";
				},
				$numbers
			)
		);

		return count( $numbers ) > 1 ? "{$list} are" : "{$list} is";
	}
}

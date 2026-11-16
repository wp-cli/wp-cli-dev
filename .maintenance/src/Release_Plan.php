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

	const RELEASED  = 'released';
	const TAGGED    = 'tagged';
	const NOT_NEWER = 'not_newer';
	const TAGGABLE  = 'taggable';

	/**
	 * Authors of routine pull requests that do not call for a release by
	 * themselves, unless a maintainer added them to a milestone.
	 */
	const ROUTINE_AUTHORS = [
		'dependabot[bot]',
		'github-actions[bot]',
	];

	/**
	 * Files that only matter for developing a package, not for using it:
	 * the files synced from wp-cli/.github and the generated README.
	 */
	const ROUTINE_PATHS = '#^(\.github/|\.actrc$|\.editorconfig$|AGENTS\.md$|README\.md$)#';

	/**
	 * Gathers what the plan needs to know about a repository from GitHub.
	 *
	 * @param string $repo Repository name, including the org.
	 *
	 * @return array Plan, see assess(), with the `open_items` of the
	 *               milestones the unreleased changes are on.
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
		$branch     = GitHub::get_default_branch( $repo );

		$pull_requests  = [];
		$direct_commits = [];
		if ( null !== $latest_tag ) {
			$comparison    = GitHub::get_comparison( $repo, $latest_tag, $branch );
			$pull_requests = GitHub::get_pull_requests_merged_since( $repo, $latest_tag, $branch, $comparison );

			// Only a pull request without a milestone can be routine because
			// of the files it changes, see is_change().
			foreach ( $pull_requests as $pull_request ) {
				if ( empty( $pull_request->milestone ) && ! self::is_routine_author( $pull_request ) ) {
					$pull_request->files = GitHub::get_pull_request_files( $repo, $pull_request->number );
				}
			}

			$direct_commits = GitHub::get_direct_commits( $repo, $comparison, $pull_requests );
		}

		$plan = self::assess(
			$repo,
			$tags,
			$milestones,
			$pull_requests,
			$direct_commits,
			GitHub::get_open_pull_requests( $repo, $branch )
		);

		$targets = array_filter( array_column( $plan['pull_requests'], 'milestone' ) );
		foreach ( $milestones as $milestone ) {
			if ( 'open' !== $milestone->state || $milestone->open_issues < 1 || ! in_array( $milestone->title, $targets, true ) ) {
				continue;
			}

			$items = GitHub::get_issues(
				$repo,
				[
					'milestone' => $milestone->number,
					'state'     => 'open',
				]
			);

			foreach ( $items as $item ) {
				$plan['open_items'][] = [
					'number'    => $item->number,
					'title'     => $item->title,
					'type'      => empty( $item->pull_request ) ? 'issue' : 'pull_request',
					'draft'     => ! empty( $item->draft ),
					'milestone' => $milestone->title,
				];
			}
		}

		return $plan;
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
	 *                                `number`, `title`, `user` and `milestone`,
	 *                                and the names of the `files` they change
	 *                                when known.
	 * @param object[] $direct_commits Commits on the default branch since the
	 *                                 latest version tag that no pull request
	 *                                 brought in, with `sha`, `commit`,
	 *                                 `author` and `files`.
	 * @param object[] $open_pull_requests Open pull requests against the
	 *                                     default branch, with `number`,
	 *                                     `title`, `user`, `draft` and
	 *                                     `milestone`.
	 *
	 * @return array{repo: string, status: string, latest_tag: string|null, release: array|null, pull_requests: array, blockers: array, direct_commits: array, open_pull_requests: array, open_items: array, open_milestones: array}
	 */
	public static function assess( $repo, array $tags, array $milestones, array $pull_requests, array $direct_commits = [], array $open_pull_requests = [] ) {
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

		$plan['direct_commits']     = array_values( array_map( [ __CLASS__, 'describe_commit' ], array_filter( $direct_commits, [ __CLASS__, 'is_direct_change' ] ) ) );
		$plan['open_pull_requests'] = [];
		$plan['open_items']         = [];
		$plan['open_milestones']    = [];

		foreach ( $open_pull_requests as $pull_request ) {
			if ( ! empty( $pull_request->draft ) || self::is_routine_author( $pull_request ) ) {
				continue;
			}

			if ( ! empty( $pull_request->milestone )
				&& 'open' === $pull_request->milestone->state
				&& ! self::has_tag( $tags, $pull_request->milestone->title ) ) {
				continue;
			}

			$plan['open_pull_requests'][] = self::describe_pull_request( $pull_request );
		}

		foreach ( $milestones as $milestone ) {
			if ( 'open' === $milestone->state && self::TAGGABLE === self::get_tag_status( $milestone->title, [], $tags ) ) {
				$plan['open_milestones'][] = [
					'title'       => $milestone->title,
					'open_issues' => $milestone->open_issues,
				];
			}
		}

		usort(
			$plan['open_milestones'],
			static function ( $a, $b ) {
				return version_compare( ltrim( $a['title'], 'v' ), ltrim( $b['title'], 'v' ) );
			}
		);

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
	 * Tells whether a milestone can still be tagged: not when it has a
	 * release or a tag already, or when it is not a version newer than the
	 * latest tag.
	 *
	 * @param string   $title    Milestone title.
	 * @param string[] $releases Tag names of the published releases.
	 * @param string[] $tags     Names of all tags.
	 *
	 * @return string One of RELEASED, TAGGED, NOT_NEWER or TAGGABLE.
	 */
	public static function get_tag_status( $title, array $releases, array $tags ) {
		$version = ltrim( $title, 'v' );
		$latest  = self::latest_version_tag( $tags );

		if ( self::has_tag( $releases, $version ) ) {
			return self::RELEASED;
		}

		if ( self::has_tag( $tags, $version ) ) {
			return self::TAGGED;
		}

		if ( ! Bundle::is_version( $version )
			|| ( null !== $latest && ! version_compare( $version, ltrim( $latest, 'v' ), '>' ) ) ) {
			return self::NOT_NEWER;
		}

		return self::TAGGABLE;
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
	 * Checks whether a merged pull request calls for a release. One by a
	 * maintainer always does when they put it on a milestone. Otherwise,
	 * routine automated updates and changes to development files only, such
	 * as workflows or the README, do not.
	 *
	 * @param object $pull_request Pull request with `user` and `milestone`,
	 *                             and `files` when known.
	 *
	 * @return bool
	 */
	private static function is_change( $pull_request ) {
		if ( ! empty( $pull_request->milestone ) ) {
			return true;
		}

		if ( self::is_routine_author( $pull_request ) ) {
			return false;
		}

		return ! isset( $pull_request->files ) || ! self::are_routine_files( $pull_request->files );
	}

	/**
	 * Checks whether a commit that no pull request brought in changes more
	 * than development files.
	 *
	 * @param object $commit Commit with `files`.
	 *
	 * @return bool
	 */
	private static function is_direct_change( $commit ) {
		return ! self::are_routine_files( array_column( (array) $commit->files, 'filename' ) );
	}

	/**
	 * Checks whether a list of files only holds development files, see
	 * ROUTINE_PATHS.
	 *
	 * @param string[] $files File names, relative to the repository root.
	 *
	 * @return bool
	 */
	public static function are_routine_files( array $files ) {
		foreach ( $files as $file ) {
			if ( ! preg_match( self::ROUTINE_PATHS, $file ) ) {
				return false;
			}
		}

		return true;
	}

	private static function is_routine_author( $pull_request ) {
		return in_array( $pull_request->user->login, self::ROUTINE_AUTHORS, true );
	}

	private static function describe_commit( $commit ) {
		$message = explode( "\n", $commit->commit->message, 2 );

		return [
			'sha'     => $commit->sha,
			'message' => $message[0],
			'author'  => empty( $commit->author ) ? $commit->commit->author->name : $commit->author->login,
		];
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

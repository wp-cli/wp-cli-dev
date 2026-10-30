<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

class GitHub {

	const API_ROOT = 'https://api.github.com/';

	/**
	 * Minimum number of seconds between two write requests, as GitHub asks
	 * of integrations to stay clear of its secondary rate limits.
	 */
	const WRITE_INTERVAL = 1;

	/**
	 * Credentials in use, resolved on first use.
	 *
	 * @var array{token: string|null, source: string|null}|null
	 */
	private static $auth;

	/**
	 * Time of the last write request.
	 *
	 * @var float
	 */
	private static $last_write = 0.0;

	/**
	 * Gets the credentials to authenticate requests with.
	 *
	 * @return array{token: string|null, source: string|null} Token and where
	 *                                                       it came from, both
	 *                                                       null when there are
	 *                                                       no credentials.
	 */
	public static function get_auth() {
		if ( null === self::$auth ) {
			self::$auth = self::resolve_auth( [ __CLASS__, 'get_gh_token' ], 'getenv' );
		}

		return self::$auth;
	}

	/**
	 * Picks the credentials to use: the login of the GitHub CLI first, so that
	 * an authenticated `gh` just works, then the GH_TOKEN and GITHUB_TOKEN
	 * environment variables.
	 *
	 * @param callable $gh_token Returns the token of the GitHub CLI, or null.
	 * @param callable $getenv   Returns the value of an environment variable.
	 *
	 * @return array{token: string|null, source: string|null}
	 */
	public static function resolve_auth( callable $gh_token, callable $getenv ) {
		$token = $gh_token();

		if ( $token ) {
			return [
				'token'  => $token,
				'source' => 'gh',
			];
		}

		foreach ( [ 'GH_TOKEN', 'GITHUB_TOKEN' ] as $variable ) {
			$token = $getenv( $variable );

			if ( $token ) {
				return [
					'token'  => $token,
					'source' => $variable,
				];
			}
		}

		return [
			'token'  => null,
			'source' => null,
		];
	}

	/**
	 * Gets the token the GitHub CLI is logged in with.
	 *
	 * @return string|null Token, or null when `gh` is missing or logged out.
	 */
	private static function get_gh_token() {
		$result = Process::create( 'gh auth token --hostname github.com', null, null )->run();

		if ( 0 !== $result->return_code ) {
			return null;
		}

		$token = trim( $result->stdout );

		return '' === $token ? null : $token;
	}

	/**
	 * Adds the 'wp-cli' org to a repository name that has none.
	 *
	 * @param string $repo Repository name, with or without org.
	 *
	 * @return string
	 */
	public static function qualify_repo( $repo ) {
		return false === strpos( $repo, '/' ) ? "wp-cli/{$repo}" : $repo;
	}

	/**
	 * Gets the milestones for a given project.
	 *
	 * @param string $project
	 * @param array  $args
	 *
	 * @return array
	 */
	public static function get_project_milestones( $project, $args = [] ) {
		return self::request_all( self::API_ROOT . "repos/{$project}/milestones", $args );
	}

	/**
	 * Gets the releases for a given project.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function get_project_releases( $project ) {
		return self::request_all( self::API_ROOT . "repos/{$project}/releases" );
	}

	/**
	 * Creates a release, and with it its tag.
	 *
	 * @param string $project
	 * @param string $tag_name
	 * @param string $target_commitish
	 * @param string $name
	 * @param string $body
	 * @param bool   $draft
	 * @param bool   $prerelease
	 *
	 * @return object
	 */
	public static function create_release(
		$project,
		$tag_name,
		$target_commitish,
		$name,
		$body,
		$draft = false,
		$prerelease = false
	) {
		list( $release ) = self::request(
			self::API_ROOT . "repos/{$project}/releases",
			[
				'tag_name'         => (string) $tag_name,
				'target_commitish' => (string) $target_commitish,
				'name'             => (string) $name,
				'body'             => (string) $body,
				'draft'            => (bool) $draft,
				'prerelease'       => (bool) $prerelease,
			],
			'POST'
		);

		return $release;
	}

	/**
	 * Gets a release for a given project by its tag name.
	 *
	 * @param string $project
	 * @param string $tag
	 *
	 * @return object|null Release, or null when the tag has none.
	 */
	public static function get_release_by_tag( $project, $tag ) {
		$result = self::request(
			self::API_ROOT . "repos/{$project}/releases/tags/" . rawurlencode( $tag ),
			[],
			'GET',
			[ 404 ]
		);

		return $result ? $result[0] : null;
	}

	/**
	 * Gets the issues and pull requests, open or closed, that carry a label.
	 *
	 * @param string $project
	 * @param string $label
	 *
	 * @return array
	 */
	public static function get_issues_by_label( $project, $label ) {
		return self::get_issues( $project, [ 'labels' => $label ] );
	}

	/**
	 * Removes a label from an issue.
	 *
	 * @param string $project
	 * @param int    $issue
	 * @param string $label
	 */
	public static function remove_label( $project, $issue, $label ) {
		self::request(
			self::API_ROOT . "repos/{$project}/issues/{$issue}/labels/" . rawurlencode( $label ),
			[],
			'DELETE'
		);
	}

	/**
	 * Closes a milestone.
	 *
	 * @param string $project
	 * @param int    $milestone
	 */
	public static function close_milestone( $project, $milestone ) {
		self::request(
			self::API_ROOT . "repos/{$project}/milestones/{$milestone}",
			[ 'state' => 'closed' ],
			'PATCH'
		);
	}

	/**
	 * Adds a label to an issue.
	 *
	 * @param string $project
	 * @param int    $issue
	 * @param string $label
	 */
	public static function add_label( $project, $issue, $label ) {
		self::request(
			self::API_ROOT . "repos/{$project}/issues/{$issue}/labels",
			[ 'labels' => [ $label ] ],
			'POST'
		);
	}

	/**
	 * Deletes a label from a repository.
	 *
	 * @param string $project
	 * @param string $label
	 */
	public static function delete_label( $project, $label ) {
		self::request(
			self::API_ROOT . "repos/{$project}/labels/" . rawurlencode( $label ),
			[],
			'DELETE'
		);
	}

	/**
	 * Gets the merged pull requests assigned to a milestone of a given
	 * project. Pull requests that were closed without merging never shipped,
	 * so they are left out.
	 *
	 * @param string $project
	 * @param int    $milestone_id
	 *
	 * @return array
	 */
	public static function get_project_milestone_pull_requests( $project, $milestone_id ) {
		return self::filter_merged_pull_requests(
			self::get_issues( $project, [ 'milestone' => $milestone_id ] )
		);
	}

	/**
	 * Keeps the merged pull requests out of a list of issues.
	 *
	 * @param object[] $issues Issues as returned by the issues API.
	 *
	 * @return object[]
	 */
	public static function filter_merged_pull_requests( array $issues ) {
		return array_values(
			array_filter(
				$issues,
				static function ( $issue ) {
					return ! empty( $issue->pull_request->merged_at );
				}
			)
		);
	}

	/**
	 * Parses the contributors from pull request objects. Bots are not
	 * contributors.
	 *
	 * @param array $pull_requests
	 *
	 * @return array<string, string> Profile URL => login.
	 */
	public static function parse_contributors_from_pull_requests( $pull_requests ) {
		$contributors = [];

		foreach ( $pull_requests as $pull_request ) {
			if ( empty( $pull_request->user ) || 'Bot' === $pull_request->user->type ) {
				continue;
			}

			$contributors[ $pull_request->user->html_url ] = $pull_request->user->login;
		}

		return $contributors;
	}

	/**
	 * Gets issues and pull requests, open or closed unless filtered otherwise.
	 *
	 * @param string $project
	 * @param array  $args
	 *
	 * @return array
	 */
	public static function get_issues( $project, $args = [] ) {
		return self::request_all(
			self::API_ROOT . "repos/{$project}/issues",
			array_merge( [ 'state' => 'all' ], $args )
		);
	}

	/**
	 * Gets all repositories of the wp-cli organization.
	 *
	 * @return object[]
	 */
	public static function get_organization_repos() {
		return self::request_all(
			self::API_ROOT . 'orgs/wp-cli/repos',
			[
				'sort'      => 'full_name',
				'direction' => 'asc',
			]
		);
	}

	/**
	 * Gets the tags of a repository.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function get_tags( $project ) {
		return self::request_all( self::API_ROOT . "repos/{$project}/tags" );
	}

	/**
	 * Gets the pull requests merged into a branch since a given tag.
	 *
	 * @param string $project
	 * @param string $tag    Tag to compare the branch against.
	 * @param string $branch Branch the pull requests were merged into.
	 *
	 * @return array Pull requests, most recently updated first.
	 */
	public static function get_pull_requests_merged_since( $project, $tag, $branch ) {
		list( $comparison ) = self::request(
			self::API_ROOT . "repos/{$project}/compare/" . rawurlencode( $tag ) . '...' . rawurlencode( $branch )
		);

		$commits = [];
		foreach ( $comparison->commits as $commit ) {
			$commits[ $commit->sha ] = true;
		}

		$tagged_at = $comparison->base_commit->commit->committer->date;

		// The comparison lists at most 250 commits. Past that, the merge date
		// alone has to tell which pull requests are unreleased.
		$complete = count( $commits ) >= $comparison->total_commits;

		$pull_requests = [];
		$url           = self::API_ROOT . "repos/{$project}/pulls";
		$args          = [
			'state'     => 'closed',
			'base'      => $branch,
			'sort'      => 'updated',
			'direction' => 'desc',
			'per_page'  => 100,
		];

		// Pull requests are sorted by their last update, which is never older
		// than their merge, so the listing can stop at the tag.
		while ( $url ) {
			list( $page, $headers ) = self::request( $url, $args );

			foreach ( $page as $pull_request ) {
				if ( $pull_request->updated_at < $tagged_at ) {
					break 2;
				}

				if ( ! $pull_request->merged_at || $pull_request->merged_at < $tagged_at ) {
					continue;
				}

				if ( ! $complete || isset( $commits[ $pull_request->merge_commit_sha ] ) ) {
					$pull_requests[] = $pull_request;
				}
			}

			$url  = self::get_next_page_url( isset( $headers['link'] ) ? $headers['link'] : '' );
			$args = [];
		}

		return $pull_requests;
	}

	/**
	 * Gets the default branch of a repository.
	 *
	 * @param string $project Project to get the default branch for.
	 *
	 * @return string Default branch of the repository.
	 */
	public static function get_default_branch( $project ) {
		list( $repository ) = self::request( self::API_ROOT . "repos/{$project}" );

		return $repository->default_branch;
	}

	/**
	 * Makes a GET request and follows the pagination to collect every item.
	 *
	 * @param string $url
	 * @param array  $args
	 *
	 * @return array
	 */
	public static function request_all( $url, $args = [] ) {
		$args['per_page'] = 100;

		$items = [];
		do {
			list( $body, $headers ) = self::request( $url, $args );

			$items = array_merge( $items, (array) $body );
			$url   = self::get_next_page_url( isset( $headers['link'] ) ? $headers['link'] : '' );
			// The next page URL carries the query arguments already.
			$args = [];
		} while ( $url );

		return $items;
	}

	/**
	 * Extracts the URL of the next page from a Link header.
	 *
	 * @param string $link_header
	 *
	 * @return string|null
	 */
	public static function get_next_page_url( $link_header ) {
		return preg_match( '/<([^>]+)>;\s*rel="next"/', $link_header, $matches ) ? $matches[1] : null;
	}

	/**
	 * Makes a request to the GitHub API. Requests that change something need
	 * credentials, so they fail before reaching GitHub without any.
	 *
	 * @param string $url
	 * @param array  $args           Query arguments, or the JSON body for
	 *                               requests that change something.
	 * @param string $method         HTTP method.
	 * @param int[]  $allowed_errors HTTP status codes that return null instead
	 *                               of failing.
	 *
	 * @return array{0: mixed, 1: mixed}|null Decoded body and headers.
	 */
	public static function request( $url, $args = [], $method = 'GET', $allowed_errors = [] ) {
		$auth = self::get_auth();

		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'WP-CLI',
			'X-GitHub-Api-Version' => '2022-11-28',
		];

		if ( $auth['token'] ) {
			$headers['Authorization'] = 'Bearer ' . $auth['token'];
		}

		if ( 'GET' !== $method ) {
			if ( ! $auth['token'] ) {
				WP_CLI::error( 'No GitHub credentials found, but this change needs them. Log in with `gh auth login`, or set GH_TOKEN or GITHUB_TOKEN.' );
			}

			self::pace_writes();

			if ( $args ) {
				$headers['Content-Type'] = 'application/json';
				$args                    = json_encode( $args );
			}
		}

		$response = Utils\http_request( $method, $url, $args, $headers );
		$status   = (int) $response->status_code;

		if ( $status >= 200 && $status < 300 ) {
			return [ json_decode( $response->body ), $response->headers ];
		}

		if ( in_array( $status, $allowed_errors, true ) ) {
			return null;
		}

		$body    = json_decode( $response->body );
		$message = isset( $body->message ) ? $body->message : $response->body;
		$code    = self::get_error_code( $status, $message, $response->headers['x-ratelimit-remaining'] );

		WP_CLI::error(
			sprintf(
				"GitHub API request failed (%s): %s %s returned HTTP %d: %s\n%s",
				$code,
				$method,
				$url,
				$status,
				$message,
				self::get_error_hint( $code, $auth['source'] )
			)
		);
	}

	/**
	 * Classifies a failed request, so that the reason for a failure can be
	 * acted upon without parsing the message.
	 *
	 * @param int         $status    HTTP status code.
	 * @param string      $message   Error message returned by GitHub.
	 * @param string|null $remaining Value of the X-RateLimit-Remaining header.
	 *
	 * @return string One of 'auth_failed', 'rate_limited', 'forbidden',
	 *                'not_found', 'validation_failed' or 'http_error'.
	 */
	public static function get_error_code( $status, $message, $remaining = null ) {
		if ( 401 === $status ) {
			return 'auth_failed';
		}

		if ( 429 === $status
			|| ( 403 === $status && ( '0' === (string) $remaining || false !== stripos( $message, 'rate limit' ) ) ) ) {
			return 'rate_limited';
		}

		$codes = [
			403 => 'forbidden',
			404 => 'not_found',
			422 => 'validation_failed',
		];

		return isset( $codes[ $status ] ) ? $codes[ $status ] : 'http_error';
	}

	/**
	 * Suggests what to do about a failed request.
	 *
	 * @param string      $code   Error code from get_error_code().
	 * @param string|null $source Source of the credentials in use.
	 *
	 * @return string
	 */
	private static function get_error_hint( $code, $source ) {
		$credentials = $source ? "credentials from {$source}" : 'no credentials';

		switch ( $code ) {
			case 'auth_failed':
				return "The {$credentials} were rejected. Check them with `wp maintenance doctor`.";
			case 'rate_limited':
				return $source
					? 'Wait for the rate limit to reset, see `wp maintenance doctor`.'
					: 'Anonymous requests have a low rate limit. Log in with `gh auth login`, or set GH_TOKEN or GITHUB_TOKEN.';
			case 'forbidden':
			case 'not_found':
				return "Requested with {$credentials}. The repository may not exist, or the account may lack access to it.";
			default:
				return "Requested with {$credentials}.";
		}
	}

	/**
	 * Waits until enough time has passed since the last write request.
	 */
	private static function pace_writes() {
		$wait = self::$last_write + self::WRITE_INTERVAL - microtime( true );

		if ( $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}

		self::$last_write = microtime( true );
	}
}

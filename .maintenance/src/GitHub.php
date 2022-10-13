<?php namespace WP_CLI\Maintenance;

use stdClass;
use WP_CLI;
use WP_CLI\Utils;

class GitHub {

	const API_ROOT = 'https://api.github.com/';

	/**
	 * Gets the milestones for a given project.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function get_project_milestones(
		$project,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/milestones',
			$project
		);

		$args['per_page'] = 100;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Gets the releases for a given project.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function get_project_releases(
		$project,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/releases',
			$project
		);

		$args['per_page'] = 100;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Gets the releases for a given project.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function create_release(
		$project,
		$tag_name,
		$target_commitish,
		$name,
		$body,
		$draft = false,
		$prerelease = false,
		$args = []
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/releases',
			$project
		);

		$args = array_merge(
			$args,
			[
				'tag_name'         => (string) $tag_name,
				'target_commitish' => (string) $target_commitish,
				'name'             => (string) $name,
				'body'             => (string) $body,
				'draft'            => (bool) $draft,
				'prerelease'       => (bool) $prerelease,
			]
		);

		$headers['http_verb'] = 'POST';

		list( $body, $headers ) = self::request( $request_url, $args, $headers );

		return $body;
	}

	/**
	 * Gets a release for a given project by its tag name.
	 *
	 * @param string $project
	 * @param string $tag
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function get_release_by_tag(
		$project,
		$tag,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/releases/tags/%s',
			$project,
			$tag
		);

		$args['per_page'] = 100;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Gets the issues that are labeled with a given label.
	 *
	 * @param string $project
	 * @param string $label
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function get_issues_by_label(
		$project,
		$label,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues',
			$project
		);

		$args['per_page'] = 100;
		$args['labels']   = $label;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Removes a label from an issue.
	 *
	 * @param string $project
	 * @param string $issue
	 * @param string $label
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function remove_label(
		$project,
		$issue,
		$label,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues/%s/labels/%s',
			$project,
			$issue,
			$label
		);

		$headers['http_verb'] = 'DELETE';

		list( $body, $headers ) = self::request( $request_url, $args,
			$headers );

		return $body;
	}

	/**
	 * Close a milestone.
	 *
	 * @param string $project
	 * @param string $milestone
	 *
	 * @return array|false
	 */
	public static function close_milestone(
		$project,
		$milestone
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/milestones/%s',
			$project,
			$milestone
		);

		$headers['http_verb'] = 'PATCH';

		$args = [
			'state' => 'closed',
		];

		list( $body, $headers ) = self::request( $request_url, $args, $headers );

		return $body;
	}

	/**
	 * Adds a label to an issue.
	 *
	 * @param string $project
	 * @param string $issue
	 * @param string $label
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function add_label(
		$project,
		$issue,
		$label,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues/%s/labels',
			$project,
			$issue,
			$label
		);

		$headers['http_verb'] = 'POST';

		$args = array( $label );

		list( $body, $headers ) = self::request( $request_url, $args,
			$headers );

		return $body;
	}

	/**
	 * Delete a label from a repository.
	 *
	 * @param string $project
	 * @param string $label
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function delete_label(
		$project,
		$label,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/labels/%s',
			$project,
			$label
		);

		list( $body, $headers ) = self::request( $request_url, $args, $headers );

		return $body;
	}

	/**
	 * Gets the pull requests assigned to a milestone of a given project.
	 *
	 * @param string  $project
	 * @param integer $milestone_id
     * @param bool    $only_merged
	 *
	 * @return array
	 */
	public static function get_project_milestone_pull_requests(
		$project,
		$milestone_id,
        $only_merged = true
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues',
			$project
		);

		$args = array(
			'per_page'  => 100,
			'milestone' => $milestone_id,
			'state'     => 'all',
		);

		$pull_requests = array();
		do {
			list( $body, $headers ) = self::request( $request_url, $args );
			foreach ( $body as $issue ) {
				if ( ! empty( $issue->pull_request ) ) {
					//if ( ! $only_merged || self::was_pull_request_merged( $project, $issue->number ) ) {
						$pull_requests[] = $issue;
					//} else {
					    //WP_CLI::warning( "Skipping PR {$issue->number} ({$issue->title}), as it was not merged." );
                    //}
				}
			}
			$args        = array();
			$request_url = false;
			// Set $request_url to 'rel="next" if present'
			if ( ! empty( $headers['Link'] ) ) {
				$bits = explode( ',', $headers['Link'] );
				foreach ( $bits as $bit ) {
					if ( false !== stripos( $bit, 'rel="next"' ) ) {
						$hrefandrel  = explode( '; ', $bit );
						$request_url = trim( trim( $hrefandrel[0] ), '<>' );
						break;
					}
				}
			}
		} while ( $request_url );

		return $pull_requests;
	}

    /**
     * Check whether a specific pull request was actually merged.
     *
     * @param $project
     * @param $pull_request_number
     * @return bool
     */
    public static function was_pull_request_merged( $project, $pull_request_number )
    {
        $request_url = sprintf(
            self::API_ROOT . 'repos/%s/pulls/%s',
            $project,
            $pull_request_number
        );

        list( $body, $headers ) = self::request( $request_url );

        return ! empty( $body->merged_at );
	}

	/**
	 * Parses the contributors from pull request objects.
	 *
	 * @param array $pull_requests
	 *
	 * @return array
	 */
	public static function parse_contributors_from_pull_requests(
		$pull_requests
	) {
		$contributors = array();
		foreach ( $pull_requests as $pull_request ) {
			if ( ! empty( $pull_request->user ) ) {
				$contributors[ $pull_request->user->html_url ] = $pull_request->user->login;
			}
		}

		return $contributors;
	}

	/**
	 * Get latest release.
	 *
	 * @param string $project
	 *
	 * @return string
	 */
	public static function get_latest_release( $project ) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/releases/latest',
			$project
		);

		$args = array(
			'per_page'  => 100,
			'state'     => 'all',
		);

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Get issues/PRs.
	 *
	 * @param string $project
	 * @param array  $args
	 *
	 * @return string
	 */
	public static function get_issues( $project, $args = [] ) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues',
			$project
		);

		$args = array_merge(
			[
				'per_page'  => 100,
				'state'     => 'all',
			],
			$args
		);

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Get all repositories of the wp-cli organization.
	 *
	 * @param array  $args
	 *
	 * @return stdClass[]
	 */
	public static function get_organization_repos( $args = [] ) {
		$request_url = self::API_ROOT . 'orgs/wp-cli/repos';

		$args = array_merge(
			[
				'per_page'  => 100,
				'state'     => 'all',
				'sort'      => 'full_name',
				'direction' => 'asc',
			],
			$args
		);

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}


    /**
     * Get the default branch of a repository.
     *
     * @param string $project Project the get the default branch for.
     *
     * @return string Default branch of the repository.
     */
    public static function get_default_branch( $project ) {
        $request_url = self::API_ROOT . "repos/{$project}";

        list( $body, $headers ) = self::request( $request_url );



        return $body->default_branch;
    }

	/**
	 * Makes a request to the GitHub API.
	 *
	 * @param string $url
	 * @param array  $args
	 * @param array  $headers
	 *
	 * @return array|false
	 */
	public static function request(
		$url,
		$args = array(),
		$headers = array()
	) {
		$headers = array_merge(
			$headers,
			array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WP-CLI',
			)
		);
		if ( $token = getenv( 'GITHUB_TOKEN' ) ) {
			$headers['Authorization'] = 'token ' . $token;
		}

		$verb = 'GET';
		if ( isset( $headers['http_verb'] ) ) {
			$verb = $headers['http_verb'];
			unset( $headers['http_verb'] );
		}

		if ( 'POST' === $verb || 'PATCH' === $verb ) {
			$args = json_encode( $args );
		}

		$response = Utils\http_request( $verb, $url, $args, $headers );

		if ( 20 != substr( $response->status_code, 0, 2 ) ) {
			if ( isset( $args['throw_errors'] ) && false === $args['throw_errors'] ) {
				return false;
			}

			WP_CLI::error(
				sprintf(
					"Failed request to $url\nGitHub API returned: %s (HTTP code %d)",
					$response->body,
					$response->status_code
				)
			);
		}

		return array( json_decode( $response->body ), $response->headers );
	}
}

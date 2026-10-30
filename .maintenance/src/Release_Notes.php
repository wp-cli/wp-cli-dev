<?php namespace WP_CLI\Maintenance;

use WP_CLI;

/**
 * Collects the release notes of milestones and renders them.
 */
final class Release_Notes {

	/**
	 * Looks up milestones of a repository by title, open or closed, and warns
	 * about the titles that do not exist.
	 *
	 * @param string   $repo   Repository name.
	 * @param string[] $titles Milestone titles.
	 *
	 * @return object[]
	 */
	public static function find_milestones( $repo, array $titles ) {
		$milestones = array_values(
			array_filter(
				GitHub::get_project_milestones( $repo, [ 'state' => 'all' ] ),
				static function ( $milestone ) use ( $titles ) {
					return in_array( $milestone->title, $titles, true );
				}
			)
		);

		$missing = array_diff(
			$titles,
			array_map(
				static function ( $milestone ) {
					return $milestone->title;
				},
				$milestones
			)
		);

		if ( $missing ) {
			WP_CLI::warning(
				sprintf(
					"Couldn't find the requested milestone(s) '%s' in repository '%s'.",
					implode( "', '", $missing ),
					$repo
				)
			);
		}

		return $milestones;
	}

	/**
	 * Gets the release notes of milestones of a repository.
	 *
	 * @param string   $repo       Repository name.
	 * @param object[] $milestones Milestones of the repository.
	 * @param string   $source     'release' to use the body of the matching
	 *                             GitHub release, falling back to the pull
	 *                             requests when there is none, or
	 *                             'pull-request' to always list them.
	 *
	 * @return array{repo: string, milestones: array}
	 */
	public static function get( $repo, array $milestones, $source ) {
		$notes = [];

		foreach ( $milestones as $milestone ) {
			WP_CLI::debug( "Using milestone '{$milestone->title}' for repo '{$repo}'", 'release-notes' );

			$notes[] = self::get_milestone_notes( $repo, $milestone, $source );
		}

		return [
			'repo'       => $repo,
			'milestones' => $notes,
		];
	}

	/**
	 * Gets the release notes of a single milestone.
	 *
	 * @param string $repo      Repository name.
	 * @param object $milestone Milestone of the repository.
	 * @param string $source    'release' or 'pull-request'.
	 *
	 * @return array{milestone: string, source: string, body: string|null, pull_requests: array}
	 */
	private static function get_milestone_notes( $repo, $milestone, $source ) {
		if ( 'release' === $source ) {
			$tag     = 0 === strpos( $milestone->title, 'v' ) ? $milestone->title : "v{$milestone->title}";
			$release = GitHub::get_release_by_tag( $repo, $tag );

			if ( $release ) {
				return [
					'milestone'     => $milestone->title,
					'source'        => 'release',
					'body'          => $release->body,
					'pull_requests' => [],
				];
			}

			WP_CLI::warning( "Release notes not found for {$repo}@{$tag}, falling back to pull-request source" );
		}

		return [
			'milestone'     => $milestone->title,
			'source'        => 'pull-request',
			'body'          => null,
			'pull_requests' => array_map(
				static function ( $pull_request ) {
					return [
						'title'  => trim( $pull_request->title ),
						'number' => $pull_request->number,
						'url'    => $pull_request->html_url,
					];
				},
				GitHub::get_project_milestone_pull_requests( $repo, $milestone->number )
			),
		];
	}

	/**
	 * Renders release notes as markdown or HTML.
	 *
	 * @param array[] $sections Release notes per repository, from get().
	 * @param string  $format   'markdown' or 'html'.
	 * @param bool    $headings Whether to precede each repository with a
	 *                          heading.
	 *
	 * @return string
	 */
	public static function render( array $sections, $format, $headings ) {
		$html  = 'html' === $format;
		$lines = [];

		foreach ( $sections as $section ) {
			if ( $headings ) {
				$lines[] = sprintf(
					$html ? '<h4><a href="%2$s">%1$s</a></h4>' . PHP_EOL : '#### [%1$s](%2$s)' . PHP_EOL,
					$section['repo'],
					"https://github.com/{$section['repo']}/"
				);
			}

			$entries = [];
			foreach ( $section['milestones'] as $notes ) {
				if ( null !== $notes['body'] ) {
					$lines[] = $notes['body'];
				}

				foreach ( $notes['pull_requests'] as $pull_request ) {
					$entries[] = sprintf(
						$html ? '<li>%1$s [<a href="%3$s">#%2$d</a>]</li>' : '- %1$s [[#%2$d](%3$s)]',
						$html ? preg_replace( '/`(.*?)`/', '<code>$1</code>', $pull_request['title'] ) : $pull_request['title'],
						$pull_request['number'],
						$pull_request['url']
					);
				}
			}

			if ( $entries ) {
				$lines[] = $html
					? '<ul>' . implode( '', $entries ) . '</ul>'
					: implode( PHP_EOL, $entries ) . PHP_EOL;
			}
		}

		return implode( PHP_EOL, $lines );
	}
}

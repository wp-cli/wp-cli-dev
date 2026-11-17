<?php

use WP_CLI\Maintenance\Release_Command;
use WP_CLI\Maintenance\Release_Plan;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class ReleaseCommandTest extends GitHub_TestCase {

	const REPO = 'repos/wp-cli/block-command';

	/**
	 * Sets up a repository whose latest tag is the first of the given tags,
	 * with the given open milestones, each holding the recorded merged pull
	 * requests #18 and #19.
	 *
	 * @param array<int, string> $milestones Milestone number => title.
	 * @param string[]           $tags       Tag names, latest first.
	 * @param array<int, string> $on_branch  Pull requests merged into the
	 *                                       default branch since the latest
	 *                                       tag: number => milestone title.
	 *                                       Defaults to #18 and #19 on the
	 *                                       first milestone.
	 * @param array<int, int>    $open_items Milestone number => open items.
	 */
	private function repository( array $milestones, array $tags = [ 'v1.0.0' ], $on_branch = null, array $open_items = [] ) {
		$comparison = $this->fixture( 'comparison' );
		if ( null === $on_branch ) {
			$on_branch = array_fill_keys( [ 18, 19 ], reset( $milestones ) );
		}

		$this->respond( 'GET', self::REPO . '/releases', [] );
		$this->respond(
			'GET',
			self::REPO . '/tags',
			array_map(
				function ( $tag ) {
					return $this->fixture_object( 'tags-page-1', 0, [ 'name' => $tag ] );
				},
				$tags
			)
		);
		$this->respond( 'GET', self::REPO . "/compare/{$tags[0]}...main", $comparison );

		$pull_requests = [];
		$index         = 0;
		foreach ( $on_branch as $number => $milestone ) {
			$pull_requests[] = $this->fixture_object(
				'pull-requests',
				0,
				[
					'number'           => $number,
					'updated_at'       => '2026-09-01T00:00:00Z',
					'merged_at'        => '2026-09-01T00:00:00Z',
					'merge_commit_sha' => $comparison->commits[ $index++ ]->sha,
					'milestone'        => [ 'title' => $milestone ],
				]
			);
		}
		$this->respond( 'GET', self::REPO . '/pulls?state=open', [] );
		$this->respond( 'GET', self::REPO . '/pulls?state=closed', $pull_requests );
		$this->commits( $comparison );

		$open = [];
		foreach ( $milestones as $number => $title ) {
			$count  = isset( $open_items[ $number ] ) ? $open_items[ $number ] : 0;
			$open[] = $this->fixture_object(
				'milestone',
				null,
				[
					'number'      => $number,
					'title'       => $title,
					'state'       => 'open',
					'open_issues' => $count,
				]
			);
			$this->respond( 'GET', self::REPO . "/issues?milestone={$number}&state=open", array_slice( $this->fixture( 'issues' ), 0, $count ) );
			$this->respond( 'GET', self::REPO . "/issues?milestone={$number}&state=all", $this->fixture( 'issues' ) );
			$this->respond( 'PATCH', self::REPO . "/milestones/{$number}", $this->fixture( 'milestone' ) );
		}

		// The plan asks for all milestones, `generate` for the open ones.
		$this->respond( 'GET', self::REPO . '/milestones?state=all', $open );
		$this->respond( 'GET', self::REPO . '/milestones', $open );
		$this->respond( 'GET', self::REPO, $this->fixture( 'repository' ) );
		$this->respond( 'POST', self::REPO . '/releases', $this->fixture( 'release' ) );
	}

	/**
	 * Answers which pull request each commit of a comparison belongs to.
	 * Commits that no pull request brought in are recorded sync commits,
	 * changing the given files.
	 *
	 * @param object                  $comparison Recorded comparison.
	 * @param array<string, string[]> $direct     Commit SHA => changed files.
	 */
	private function commits( $comparison, array $direct = [] ) {
		foreach ( $comparison->commits as $commit ) {
			if ( ! isset( $direct[ $commit->sha ] ) ) {
				$this->respond( 'GET', self::REPO . "/commits/{$commit->sha}/pulls", $this->fixture( 'commit-pulls' ) );
				continue;
			}

			$files = array_map(
				static function ( $file ) {
					return [ 'filename' => $file ];
				},
				$direct[ $commit->sha ]
			);

			$this->respond( 'GET', self::REPO . "/commits/{$commit->sha}/pulls", $this->fixture( 'commit-direct' ) );
			$this->respond(
				'GET',
				self::REPO . "/commits/{$commit->sha}",
				$this->fixture_object(
					'commit',
					null,
					[
						'sha'   => $commit->sha,
						'files' => $files,
					]
				)
			);
		}
	}

	/**
	 * @return array<int, string> Write requests, as "METHOD URL".
	 */
	private function writes() {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 0 !== strpos( $request, 'GET ' );
				}
			)
		);
	}

	public function test_generate_publishes_the_release_and_closes_the_milestone(): void {
		$this->repository( [ 4 => '1.0.1' ] );

		( new Release_Command() )->generate( [ 'block-command' ], [ 'yes' => true ] );

		$this->assertSame(
			[
				'POST https://api.github.com/repos/wp-cli/block-command/releases',
				'PATCH https://api.github.com/repos/wp-cli/block-command/milestones/4',
			],
			$this->writes()
		);

		$release = $this->sent_data[ array_search( $this->writes()[0], $this->requests, true ) ];
		$this->assertSame( 'v1.0.1', $release->tag_name );
		$this->assertSame( 'Version 1.0.1', $release->name );
		$this->assertSame( 'main', $release->target_commitish );
		$this->assertStringContainsString( '[#18](https://github.com/wp-cli/block-command/pull/18)', $release->body );
		$this->assertStringContainsString( '[#19](https://github.com/wp-cli/block-command/pull/19)', $release->body );
	}

	public function test_generate_publishes_the_notes_file(): void {
		$this->repository( [ 4 => '1.0.1' ] );
		$notes = tempnam( sys_get_temp_dir(), 'notes' );
		file_put_contents( $notes, "- Reviewed notes\n" );

		( new Release_Command() )->generate(
			[ 'block-command' ],
			[
				'milestone'  => '1.0.1',
				'notes-file' => $notes,
				'yes'        => true,
			]
		);
		unlink( $notes );

		$release = $this->sent_data[ array_search( $this->writes()[0], $this->requests, true ) ];
		$this->assertSame( "- Reviewed notes\n", $release->body );
	}

	public function test_generate_skips_a_milestone_that_was_already_tagged(): void {
		$this->repository( [ 4 => '1.0.1' ], [ 'v1.0.1', 'v1.0.0' ] );

		( new Release_Command() )->generate( [ 'block-command' ], [ 'yes' => true ] );

		$this->assertSame( [], $this->writes() );
		$this->assertStringContainsString( "Skipping milestone '1.0.1' as it was already tagged.", $this->logger->stderr );
	}

	public function test_generate_needs_a_choice_between_several_milestones(): void {
		$this->repository(
			[
				4 => '1.0.1',
				5 => '1.1.0',
			]
		);

		( new Release_Command() )->generate( [ 'block-command' ], [ 'yes' => true ] );

		$this->assertSame( [], $this->writes() );
		$this->assertStringContainsString( 'several milestones could be released: 1.0.1, 1.1.0', $this->logger->stderr );
	}

	public function test_generate_refuses_when_changes_of_another_milestone_would_ship(): void {
		$this->repository(
			[
				4 => '1.0.1',
				5 => '1.1.0',
			],
			[ 'v1.0.0' ],
			[
				18 => '1.0.1',
				19 => '1.0.1',
				30 => '1.1.0',
			],
			[ 5 => 1 ]
		);

		( new Release_Command() )->generate( [ 'block-command' ], [ 'yes' => true ] );

		$this->assertSame( [], $this->writes() );
		$this->assertStringContainsString( "Skipping milestone '1.0.1' as wp-cli/block-command needs a maintainer's decision first", $this->logger->stderr );
		$this->assertStringContainsString( 'spread over the milestones 1.0.1, 1.1.0', $this->logger->stdout );
	}

	public function test_generate_refuses_notes_for_pull_requests_missing_from_the_branch(): void {
		$this->repository( [ 4 => '1.0.1' ], [ 'v1.0.0' ], [ 18 => '1.0.1' ] );

		( new Release_Command() )->generate( [ 'block-command' ], [ 'yes' => true ] );

		$this->assertSame( [], $this->writes() );
		$this->assertStringContainsString( "Skipping milestone '1.0.1' as #19 was not merged into the default branch since v1.0.0", $this->logger->stderr );
	}

	public function test_plan_reports_a_ready_milestone(): void {
		$comparison = $this->fixture( 'comparison' );

		$this->respond( 'GET', self::REPO . '/tags', [ $this->fixture_object( 'tags-page-1', 0, [ 'name' => 'v1.0.1' ] ) ] );
		$this->respond(
			'GET',
			self::REPO . '/milestones?state=all',
			[
				$this->fixture_object(
					'milestone',
					null,
					[
						'title'       => '1.0.2',
						'state'       => 'open',
						'open_issues' => 0,
					]
				),
			]
		);
		$this->respond( 'GET', self::REPO, $this->fixture( 'repository' ) );
		$this->respond( 'GET', self::REPO . '/compare/v1.0.1...main', $comparison );
		$this->respond( 'GET', self::REPO . '/pulls?state=open', [] );
		$this->commits( $comparison );
		$this->respond(
			'GET',
			self::REPO . '/pulls?state=closed',
			[
				$this->fixture_object(
					'pull-requests',
					0,
					[
						'number'           => 18,
						'updated_at'       => '2026-09-01T00:00:00Z',
						'merged_at'        => '2026-09-01T00:00:00Z',
						'merge_commit_sha' => $comparison->commits[0]->sha,
						'milestone'        => [ 'title' => '1.0.2' ],
					]
				),
			]
		);

		$plan = Release_Plan::fetch( 'wp-cli/block-command' );

		$this->assertSame( Release_Plan::READY, $plan['status'] );
		$this->assertSame(
			[
				'milestone' => '1.0.2',
				'tag'       => 'v1.0.2',
			],
			$plan['release']
		);
		$this->assertSame( [ 18 ], array_column( $plan['pull_requests'], 'number' ) );
	}

	public function test_plan_lists_what_a_maintainer_still_has_to_decide(): void {
		$comparison = $this->fixture( 'comparison' );
		$commits    = $comparison->commits;

		$this->respond( 'GET', self::REPO . '/tags', [ $this->fixture_object( 'tags-page-1', 0, [ 'name' => 'v1.0.1' ] ) ] );
		$this->respond(
			'GET',
			self::REPO . '/milestones?state=all',
			[
				$this->fixture_object(
					'milestone',
					null,
					[
						'number'      => 5,
						'title'       => '1.0.2',
						'state'       => 'open',
						'open_issues' => 1,
					]
				),
			]
		);
		$this->respond( 'GET', self::REPO, $this->fixture( 'repository' ) );
		$this->respond( 'GET', self::REPO . '/compare/v1.0.1...main', $comparison );
		$this->respond( 'GET', self::REPO . '/issues?milestone=5&state=open', array_slice( $this->fixture( 'issues' ), 0, 1 ) );

		$open_milestone = [
			'title' => '1.0.2',
			'state' => 'open',
		];
		$open           = [
			[
				'number'    => 40,
				'user'      => [ 'login' => 'octocat' ],
				'milestone' => null,
			],
			[
				'number'    => 41,
				'user'      => [ 'login' => 'octocat' ],
				'milestone' => null,
				'draft'     => true,
			],
			[
				'number'    => 42,
				'user'      => [ 'login' => 'octocat' ],
				'milestone' => $open_milestone,
			],
			[
				'number'    => 43,
				'user'      => [ 'login' => 'dependabot[bot]' ],
				'milestone' => null,
			],
		];
		$this->respond(
			'GET',
			self::REPO . '/pulls?state=open',
			array_map(
				function ( $overrides ) {
					return $this->fixture_object( 'pull-requests', 0, $overrides + [ 'draft' => false ] );
				},
				$open
			)
		);
		$this->respond(
			'GET',
			self::REPO . '/pulls?state=closed',
			[
				$this->fixture_object(
					'pull-requests',
					0,
					[
						'number'           => 18,
						'updated_at'       => '2026-09-01T00:00:00Z',
						'merged_at'        => '2026-09-01T00:00:00Z',
						'merge_commit_sha' => $commits[0]->sha,
						'milestone'        => $open_milestone,
					]
				),
				$this->fixture_object(
					'pull-requests',
					0,
					[
						'number'           => 17,
						'user'             => [ 'login' => 'octocat' ],
						'updated_at'       => '2026-09-01T00:00:00Z',
						'merged_at'        => '2026-09-01T00:00:00Z',
						'merge_commit_sha' => $commits[1]->sha,
						'milestone'        => null,
					]
				),
			]
		);
		$this->respond( 'GET', self::REPO . '/pulls/17/files', $this->fixture( 'pull-files' ) );
		$this->commits(
			$comparison,
			[
				$commits[2]->sha => [ '.github/workflows/testing.yml', 'AGENTS.md' ],
				$commits[3]->sha => [ 'src/Block_Command.php' ],
			]
		);

		$plan = Release_Plan::fetch( 'wp-cli/block-command' );

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 18 ], array_column( $plan['pull_requests'], 'number' ), 'A pull request that only changes the README is routine.' );
		$this->assertSame( [ $commits[3]->sha ], array_column( $plan['direct_commits'], 'sha' ) );
		$this->assertSame( 'swissspidy', $plan['direct_commits'][0]['author'] );
		$this->assertSame( [ 40 ], array_column( $plan['open_pull_requests'], 'number' ) );
		$this->assertSame(
			[
				[
					'number'    => 19,
					'title'     => 'Fix PHPCS errors in Behat test files',
					'type'      => 'pull_request',
					'draft'     => false,
					'milestone' => '1.0.2',
				],
			],
			$plan['open_items']
		);
		$this->assertSame(
			[
				[
					'title'       => '1.0.2',
					'open_issues' => 1,
				],
			],
			$plan['open_milestones']
		);
	}
}

<?php

use WP_CLI\Maintenance\Release_Command;
use WP_CLI\Maintenance\Release_Plan;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class ReleaseCommandTest extends GitHub_TestCase {

	const REPO = 'repos/wp-cli/block-command';

	/**
	 * Sets up a repository whose latest tag is v1.0.0, with the given open
	 * milestones, each holding the recorded merged pull requests #18 and #19.
	 *
	 * @param array<int, string> $milestones Milestone number => title.
	 * @param string[]           $tags       Tag names.
	 */
	private function repository( array $milestones, array $tags = [ 'v1.0.0' ] ) {
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

		$open = [];
		foreach ( $milestones as $number => $title ) {
			$open[] = $this->fixture_object(
				'milestone',
				null,
				[
					'number'      => $number,
					'title'       => $title,
					'state'       => 'open',
					'open_issues' => 0,
				]
			);
			$this->respond( 'GET', self::REPO . "/issues?milestone={$number}&state=open", [] );
			$this->respond( 'GET', self::REPO . "/issues?milestone={$number}&state=all", $this->fixture( 'issues' ) );
			$this->respond( 'PATCH', self::REPO . "/milestones/{$number}", $this->fixture( 'milestone' ) );
		}

		$this->respond( 'GET', self::REPO . '/milestones', $open );
		$this->respond( 'GET', self::REPO, $this->fixture( 'repository' ) );
		$this->respond( 'POST', self::REPO . '/releases', $this->fixture( 'release' ) );
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
		$this->respond(
			'GET',
			self::REPO . '/pulls',
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
}

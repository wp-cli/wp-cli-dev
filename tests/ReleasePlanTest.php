<?php

use WP_CLI\Maintenance\Release_Plan;
use WP_CLI\Tests\TestCase;

class ReleasePlanTest extends TestCase {

	private function milestone( $title, $state = 'open', $open_issues = 0 ) {
		return (object) [
			'title'       => $title,
			'state'       => $state,
			'open_issues' => $open_issues,
		];
	}

	private function pull_request( $number, $milestone = null, $author = 'octocat' ) {
		return (object) [
			'number'    => $number,
			'title'     => "Pull request {$number}",
			'user'      => (object) [ 'login' => $author ],
			'milestone' => null === $milestone ? null : (object) [ 'title' => $milestone ],
		];
	}

	/**
	 * @param array $plan Plan returned by Release_Plan::assess().
	 *
	 * @return array<string, int[]> Blocker code => pull request numbers.
	 */
	private function blockers( array $plan ) {
		$blockers = [];
		foreach ( $plan['blockers'] as $blocker ) {
			$blockers[ $blocker['code'] ] = $blocker['pull_requests'];
		}

		return $blockers;
	}

	public function test_latest_version_tag_compares_versions_and_ignores_other_tags(): void {
		$this->assertSame( 'v3.10.0', Release_Plan::latest_version_tag( [ 'v3.9.1', 'v3.10.0', 'nightly', 'v3.2.0' ] ) );
		$this->assertSame( '0.6.7', Release_Plan::latest_version_tag( [ '0.6.6', '0.6.7' ] ) );
		$this->assertNull( Release_Plan::latest_version_tag( [ 'nightly' ] ) );
	}

	public function test_routine_pull_requests_alone_need_no_release(): void {
		$plan = Release_Plan::assess(
			'wp-cli/eval-command',
			[ 'v3.0.1' ],
			[],
			[
				$this->pull_request( 103, null, 'dependabot[bot]' ),
				$this->pull_request( 104, null, 'github-actions[bot]' ),
			]
		);

		$this->assertSame( Release_Plan::UP_TO_DATE, $plan['status'] );
		$this->assertSame( [], $plan['pull_requests'] );
	}

	public function test_changes_on_one_open_milestone_are_ready(): void {
		$plan = Release_Plan::assess(
			'wp-cli/cache-command',
			[ 'v3.0.0' ],
			[ $this->milestone( '3.0.0', 'closed' ), $this->milestone( '3.0.1' ), $this->milestone( '3.1.0', 'open', 1 ) ],
			[
				$this->pull_request( 132, '3.0.1' ),
				$this->pull_request( 128, '3.0.1', 'github-actions[bot]' ),
				$this->pull_request( 133, null, 'github-actions[bot]' ),
			]
		);

		$this->assertSame( Release_Plan::READY, $plan['status'] );
		$this->assertSame(
			[
				'milestone' => '3.0.1',
				'tag'       => 'v3.0.1',
			],
			$plan['release']
		);
		$this->assertSame( [ 132, 128 ], array_column( $plan['pull_requests'], 'number' ) );
	}

	public function test_changes_on_a_closed_milestone_need_a_new_one(): void {
		$plan = Release_Plan::assess(
			'wp-cli/ai-command',
			[ 'v1.0.3' ],
			[ $this->milestone( '1.0.3', 'closed' ) ],
			[ $this->pull_request( 25, '1.0.3' ), $this->pull_request( 27, '1.0.3' ) ]
		);

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'released_milestone' => [ 25, 27 ] ], $this->blockers( $plan ) );
	}

	public function test_an_open_milestone_that_was_already_tagged_cannot_be_released(): void {
		$plan = Release_Plan::assess(
			'wp-cli/block-command',
			[ 'v1.0.1', 'v1.0.2', 'v1.0.3' ],
			[ $this->milestone( '1.0.3' ) ],
			[ $this->pull_request( 18, '1.0.3' ), $this->pull_request( 19, '1.0.3' ) ]
		);

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'released_milestone' => [ 18, 19 ] ], $this->blockers( $plan ) );
	}

	public function test_changes_without_a_milestone_need_one(): void {
		$plan = Release_Plan::assess(
			'wp-cli/package-command',
			[ 'v3.0.1' ],
			[ $this->milestone( '3.0.2' ) ],
			[
				$this->pull_request( 253, '3.0.2' ),
				$this->pull_request( 255 ),
				$this->pull_request( 250, null, 'Copilot' ),
			]
		);

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'no_milestone' => [ 255, 250 ] ], $this->blockers( $plan ) );
		$this->assertNull( $plan['release'] );
	}

	public function test_a_milestone_with_open_items_is_not_ready(): void {
		$plan = Release_Plan::assess(
			'wp-cli/db-command',
			[ 'v3.0.1' ],
			[ $this->milestone( '3.0.2', 'open', 1 ) ],
			[ $this->pull_request( 351, '3.0.2' ) ]
		);

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'open_items' => [] ], $this->blockers( $plan ) );
	}

	public function test_changes_spread_over_several_milestones_need_a_decision(): void {
		$plan = Release_Plan::assess(
			'wp-cli/extension-command',
			[ 'v3.0.0' ],
			[ $this->milestone( '3.0.1' ), $this->milestone( '3.1.0' ) ],
			[ $this->pull_request( 553, '3.0.1' ), $this->pull_request( 560, '3.1.0' ) ]
		);

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'several_milestones' => [] ], $this->blockers( $plan ) );
	}

	public function test_a_milestone_must_be_a_newer_version(): void {
		$plan = Release_Plan::assess(
			'wp-cli/shell-command',
			[ 'v3.0.0' ],
			[ $this->milestone( 'Future' ) ],
			[ $this->pull_request( 95, 'Future' ) ]
		);

		$this->assertSame( [ 'invalid_version' => [] ], $this->blockers( $plan ) );
	}

	public function test_a_repository_without_version_tags_needs_a_decision(): void {
		$plan = Release_Plan::assess( 'wp-cli/batch-command', [], [], [] );

		$this->assertSame( Release_Plan::NEEDS_HUMAN, $plan['status'] );
		$this->assertSame( [ 'no_version_tag' => [] ], $this->blockers( $plan ) );
	}
}

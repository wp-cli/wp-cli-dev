<?php

use WP_CLI\ExitException;
use WP_CLI\Maintenance\GitHub;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class GitHubApiTest extends GitHub_TestCase {

	public function test_lists_follow_the_pagination_links(): void {
		$this->respond(
			'GET',
			'repos/wp-cli/block-command/tags?per_page=100',
			$this->fixture( 'tags-page-1' ),
			200,
			[ 'Link' => '<https://api.github.com/repositories/1/tags?per_page=100&page=2>; rel="next", <https://api.github.com/repositories/1/tags?per_page=100&page=2>; rel="last"' ]
		);
		$this->respond( 'GET', 'repositories/1/tags?page=2', $this->fixture( 'tags-page-2' ) );

		$tags = array_column( GitHub::get_tags( 'wp-cli/block-command' ), 'name' );

		$this->assertSame(
			array_merge(
				array_column( $this->fixture( 'tags-page-1' ), 'name' ),
				array_column( $this->fixture( 'tags-page-2' ), 'name' )
			),
			$tags
		);
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_missing_release_is_null(): void {
		$this->respond( 'GET', 'repos/wp-cli/block-command/releases/tags/v0.0.0', $this->fixture( 'release-absent' ), 404 );

		$this->assertNull( GitHub::get_release_by_tag( 'wp-cli/block-command', 'v0.0.0' ) );
	}

	public function test_a_failed_request_stops_with_an_error_code(): void {
		$this->respond(
			'GET',
			'repos/wp-cli/block-command',
			[ 'message' => 'API rate limit exceeded' ],
			403,
			[ 'X-RateLimit-Remaining' => '0' ]
		);

		try {
			GitHub::get_default_branch( 'wp-cli/block-command' );
			$this->fail( 'The request should have failed.' );
		} catch ( ExitException $exception ) {
			$this->assertSame( 1, $exception->getCode() );
		}

		$this->assertStringContainsString( 'GitHub API request failed (rate_limited)', $this->logger->stderr );
	}

	public function test_writes_are_sent_as_json(): void {
		$this->respond( 'PATCH', 'repos/wp-cli/block-command/milestones/4', $this->fixture( 'milestone' ) );

		GitHub::close_milestone( 'wp-cli/block-command', 4 );

		$this->assertSame( [ 'PATCH https://api.github.com/repos/wp-cli/block-command/milestones/4' ], $this->requests );
		$this->assertEquals( (object) [ 'state' => 'closed' ], $this->sent_data[0] );
	}

	public function test_writes_need_credentials(): void {
		$auth = new ReflectionProperty( GitHub::class, 'auth' );
		if ( PHP_VERSION_ID < 80100 ) {
			$auth->setAccessible( true );
		}
		$auth->setValue(
			null,
			[
				'token'  => null,
				'source' => null,
			]
		);

		try {
			GitHub::close_milestone( 'wp-cli/block-command', 4 );
			$this->fail( 'The write should have been refused.' );
		} catch ( ExitException $exception ) {
			$this->assertSame( [], $this->requests );
		}

		$this->assertStringContainsString( 'No GitHub credentials found', $this->logger->stderr );
	}

	public function test_writes_wait_only_for_what_is_left_of_the_interval(): void {
		$this->respond( 'PATCH', 'repos/wp-cli/block-command/milestones/4', $this->fixture( 'milestone' ) );
		GitHub::set_write_interval( 0.2 );

		$start = microtime( true );
		GitHub::close_milestone( 'wp-cli/block-command', 4 );
		GitHub::close_milestone( 'wp-cli/block-command', 4 );
		$paced = microtime( true ) - $start;

		usleep( 250000 );

		$start = microtime( true );
		GitHub::close_milestone( 'wp-cli/block-command', 4 );
		$unpaced = microtime( true ) - $start;

		$this->assertGreaterThanOrEqual( 0.19, $paced, 'The second write should wait for the interval.' );
		$this->assertLessThan( 0.1, $unpaced, 'A write after the interval has passed should not wait.' );
	}

	public function test_merged_pull_requests_since_a_tag(): void {
		$comparison = $this->fixture( 'comparison' );
		$tagged_at  = $comparison->base_commit->commit->committer->date;
		$in_range   = $comparison->commits[0]->sha;

		$this->respond( 'GET', 'repos/wp-cli/block-command/compare/v1.0.1...main', $comparison );
		$this->respond(
			'GET',
			'repos/wp-cli/block-command/pulls?state=closed&base=main',
			[
				$this->pull_request( 0, 30, '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z', $in_range ),
				// Merged into another branch, so its merge commit is not on main.
				$this->pull_request( 1, 29, '2026-08-01T00:00:00Z', '2026-08-01T00:00:00Z', str_repeat( 'f', 40 ) ),
				// Closed without merging.
				$this->pull_request( 2, 28, '2026-07-01T00:00:00Z', null, null ),
				// Last updated before the tag, which ends the listing.
				$this->pull_request( 0, 27, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', $in_range ),
			],
			200,
			[ 'Link' => '<https://api.github.com/repositories/1/pulls?page=2>; rel="next"' ]
		);

		$pull_requests = GitHub::get_pull_requests_merged_since( 'wp-cli/block-command', 'v1.0.1', 'main' );

		$this->assertGreaterThan( '2026-01-01T00:00:00Z', $tagged_at );
		$this->assertSame( [ 30 ], array_column( $pull_requests, 'number' ) );
		// The second page is never requested.
		$this->assertCount( 2, $this->requests );
	}

	public function test_merged_pull_requests_fall_back_to_the_merge_date_past_250_commits(): void {
		$comparison                = $this->fixture( 'comparison' );
		$comparison->total_commits = 300;

		$this->respond( 'GET', 'repos/wp-cli/block-command/compare/v1.0.1...main', $comparison );
		$this->respond(
			'GET',
			'repos/wp-cli/block-command/pulls?state=closed&base=main',
			[ $this->pull_request( 0, 30, '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z', str_repeat( 'f', 40 ) ) ]
		);

		$pull_requests = GitHub::get_pull_requests_merged_since( 'wp-cli/block-command', 'v1.0.1', 'main' );

		$this->assertSame( [ 30 ], array_column( $pull_requests, 'number' ) );
	}

	private function pull_request( $index, $number, $updated_at, $merged_at, $merge_commit_sha ) {
		return $this->fixture_object(
			'pull-requests',
			$index,
			[
				'number'           => $number,
				'updated_at'       => $updated_at,
				'merged_at'        => $merged_at,
				'merge_commit_sha' => $merge_commit_sha,
			]
		);
	}
}

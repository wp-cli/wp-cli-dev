<?php

use WP_CLI\ExitException;
use WP_CLI\Maintenance\Milestone_Command;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class MilestoneCommandTest extends GitHub_TestCase {

	const REPO = 'repos/wp-cli/block-command';

	private function milestones( array $milestones ) {
		$this->respond(
			'GET',
			self::REPO . '/milestones?state=all',
			array_map(
				function ( $milestone ) {
					return $this->fixture_object( 'milestone', null, $milestone );
				},
				$milestones
			)
		);
	}

	/**
	 * @return array<int, array{0: string, 1: mixed}> Write requests and their data.
	 */
	private function writes() {
		$writes = [];
		foreach ( $this->requests as $index => $request ) {
			if ( 0 !== strpos( $request, 'GET ' ) ) {
				$writes[] = [ $request, $this->sent_data[ $index ] ];
			}
		}

		return $writes;
	}

	public function test_assign_puts_items_on_an_existing_milestone(): void {
		$this->milestones(
			[
				[
					'number' => 4,
					'title'  => '1.0.4',
					'state'  => 'closed',
				],
				[
					'number' => 5,
					'title'  => '1.0.5',
					'state'  => 'open',
				],
			]
		);
		$this->respond( 'PATCH', self::REPO . '/issues/19', $this->fixture( 'issues' )[0] );
		$this->respond( 'PATCH', self::REPO . '/issues/20', $this->fixture( 'issues' )[0] );

		( new Milestone_Command() )->assign( [ 'block-command', '1.0.5', '19', '#20' ], [] );

		$this->assertEquals(
			[
				[ 'PATCH https://api.github.com/repos/wp-cli/block-command/issues/19', (object) [ 'milestone' => 5 ] ],
				[ 'PATCH https://api.github.com/repos/wp-cli/block-command/issues/20', (object) [ 'milestone' => 5 ] ],
			],
			$this->writes()
		);
		$this->assertStringContainsString( "Put 2 issue(s) or pull request(s) on milestone '1.0.5'.", $this->logger->stdout );
	}

	public function test_assign_creates_a_missing_milestone_when_asked(): void {
		$this->milestones( [] );
		$this->respond(
			'POST',
			self::REPO . '/milestones',
			$this->fixture_object(
				'milestone',
				null,
				[
					'number' => 6,
					'title'  => '1.0.6',
					'state'  => 'open',
				]
			)
		);
		$this->respond( 'PATCH', self::REPO . '/issues/19', $this->fixture( 'issues' )[0] );

		( new Milestone_Command() )->assign( [ 'block-command', '1.0.6', '19' ], [ 'create' => true ] );

		$this->assertEquals(
			[
				[ 'POST https://api.github.com/repos/wp-cli/block-command/milestones', (object) [ 'title' => '1.0.6' ] ],
				[ 'PATCH https://api.github.com/repos/wp-cli/block-command/issues/19', (object) [ 'milestone' => 6 ] ],
			],
			$this->writes()
		);
	}

	private function assert_refused( array $args, array $assoc_args, $message ) {
		$this->milestones(
			[
				[
					'number' => 4,
					'title'  => '1.0.4',
					'state'  => 'closed',
				],
			]
		);

		try {
			( new Milestone_Command() )->assign( $args, $assoc_args );
			$this->fail( 'The command should have stopped.' );
		} catch ( ExitException $exception ) {
			$this->assertSame( 1, $exception->getCode() );
		}

		$this->assertSame( [], $this->writes() );
		$this->assertStringContainsString( $message, $this->logger->stderr );
	}

	public function test_assign_needs_create_for_a_missing_milestone(): void {
		$this->assert_refused( [ 'block-command', '1.0.5', '19' ], [], "has no milestone '1.0.5'. Pass --create" );
	}

	public function test_assign_refuses_a_closed_milestone(): void {
		$this->assert_refused( [ 'block-command', '1.0.4', '19' ], [ 'create' => true ], "Milestone '1.0.4' of wp-cli/block-command is closed." );
	}

	public function test_assign_needs_numbers(): void {
		$this->assert_refused( [ 'block-command', '1.0.4', 'abc' ], [], "'abc' is not the number" );
	}

	public function test_assign_checks_every_number_before_any_write(): void {
		$this->assert_refused( [ 'block-command', '1.0.5', '19', '0' ], [ 'create' => true ], "'0' is not the number" );
	}
}

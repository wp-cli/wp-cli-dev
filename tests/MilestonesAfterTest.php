<?php

use WP_CLI\Maintenance\Milestones_After_Command;
use WP_CLI\Tests\TestCase;

class MilestonesAfterTest extends TestCase {

	/**
	 * @param array<string, string> $closed_at Milestone title => closing date.
	 *
	 * @return object[]
	 */
	private function milestones( array $closed_at ) {
		$milestones = [];
		foreach ( $closed_at as $title => $date ) {
			$milestones[] = (object) [
				'title'     => $title,
				'closed_at' => $date,
			];
		}

		return $milestones;
	}

	/**
	 * @param object[] $milestones Milestone objects.
	 *
	 * @return string[]
	 */
	private function titles( array $milestones ) {
		return array_map(
			static function ( $milestone ) {
				return $milestone->title;
			},
			$milestones
		);
	}

	public function test_closed_after_follows_the_closing_order_not_the_api_order(): void {
		$milestones = $this->milestones(
			[
				'2.1.0' => '2026-03-01T00:00:00Z',
				'2.0.1' => '2026-01-15T00:00:00Z',
				'2.0.0' => '2026-01-01T00:00:00Z',
				'2.0.2' => '2026-02-01T00:00:00Z',
			]
		);

		$this->assertSame(
			[ '2.0.2', '2.1.0' ],
			$this->titles( Milestones_After_Command::closed_after( $milestones, '2.0.1' ) )
		);
	}

	public function test_closed_after_returns_null_for_an_unknown_threshold(): void {
		$this->assertNull(
			Milestones_After_Command::closed_after(
				$this->milestones( [ '2.0.0' => '2026-01-01T00:00:00Z' ] ),
				'1.0.0'
			)
		);
	}
}

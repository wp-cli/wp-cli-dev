<?php

use WP_CLI\Maintenance\Bundle;
use WP_CLI\Tests\TestCase;

class BundleTest extends TestCase {

	/**
	 * @param string[] $titles Milestone titles.
	 *
	 * @return object[]
	 */
	private function milestones( array $titles ) {
		return array_map(
			static function ( $title ) {
				return (object) [ 'title' => $title ];
			},
			$titles
		);
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

	public function test_is_version_accepts_comparable_versions(): void {
		$this->assertTrue( Bundle::is_version( '2.12.0' ) );
		$this->assertTrue( Bundle::is_version( '3.0' ) );
		$this->assertTrue( Bundle::is_version( '3.0.0-beta1' ) );
		$this->assertTrue( Bundle::is_version( '3.0.0-rc.2' ) );
	}

	public function test_is_version_rejects_everything_else(): void {
		$this->assertFalse( Bundle::is_version( null ) );
		$this->assertFalse( Bundle::is_version( '' ) );
		$this->assertFalse( Bundle::is_version( 'dev-main' ) );
		$this->assertFalse( Bundle::is_version( 'v3.0.0' ) );
		$this->assertFalse( Bundle::is_version( '3.0.0 (docs)' ) );
		$this->assertFalse( Bundle::is_version( 'Future' ) );
	}

	public function test_is_bundled_package_matches_commands_and_known_libraries(): void {
		$this->assertTrue( Bundle::is_bundled_package( 'wp-cli/cache-command' ) );
		$this->assertTrue( Bundle::is_bundled_package( 'wp-cli/package-command' ) );
		$this->assertTrue( Bundle::is_bundled_package( 'wp-cli/php-cli-tools' ) );
		$this->assertTrue( Bundle::is_bundled_package( 'wp-cli/mustangostang-spyc' ) );
		$this->assertTrue( Bundle::is_bundled_package( 'wp-cli/wp-cli-tests' ) );
		$this->assertFalse( Bundle::is_bundled_package( 'wp-cli/wp-cli' ) );
		$this->assertFalse( Bundle::is_bundled_package( 'wp-cli/wp-cli-bundle' ) );
		$this->assertFalse( Bundle::is_bundled_package( 'wp-cli/process' ) );
		$this->assertFalse( Bundle::is_bundled_package( 'symfony/finder' ) );
	}

	public function test_get_packages_reads_both_sections_and_strips_the_v(): void {
		$lock = [
			'packages'     => [
				[
					'name'    => 'wp-cli/wp-cli',
					'version' => 'dev-main',
				],
				[
					'name'    => 'wp-cli/site-health-command',
					'version' => 'v1.0.0',
				],
				[
					'name'    => 'wp-cli/cache-command',
					'version' => 'v2.1.3',
				],
				[
					'name'    => 'wp-cli/mustangostang-spyc',
					'version' => '0.6.3',
				],
				[
					'name'    => 'symfony/finder',
					'version' => 'v5.4.0',
				],
			],
			'packages-dev' => [
				[
					'name'    => 'wp-cli/package-command',
					'version' => 'v2.5.0',
				],
				[
					'name'    => 'wp-cli/wp-cli-tests',
					'version' => 'v5.0.0',
				],
				[
					'name'    => 'phpunit/phpunit',
					'version' => '9.6.0',
				],
			],
		];

		$this->assertSame(
			[
				'wp-cli/cache-command'       => '2.1.3',
				'wp-cli/mustangostang-spyc'  => '0.6.3',
				'wp-cli/package-command'     => '2.5.0',
				'wp-cli/site-health-command' => '1.0.0',
				'wp-cli/wp-cli-tests'        => '5.0.0',
			],
			Bundle::get_packages( $lock )
		);
	}

	public function test_get_packages_tolerates_a_lock_without_dev_packages(): void {
		$lock = [
			'packages' => [
				[
					'name'    => 'wp-cli/cache-command',
					'version' => 'v2.1.3',
				],
			],
		];

		$this->assertSame( [ 'wp-cli/cache-command' => '2.1.3' ], Bundle::get_packages( $lock ) );
	}

	public function test_find_previous_release_picks_the_highest_version_below_the_release(): void {
		$milestones = $this->milestones( [ '2.9.0', '2.12.0', '2.10.0', '3.0.0', '2.11.0' ] );

		$this->assertSame( '2.12.0', Bundle::find_previous_release( $milestones, '3.0.0' ) );
		$this->assertSame( '2.12.0', Bundle::find_previous_release( $milestones, 'v3.0.0' ) );
		$this->assertSame( '2.10.0', Bundle::find_previous_release( $milestones, '2.11.0' ) );
	}

	public function test_find_previous_release_without_a_release_picks_the_highest_version(): void {
		$milestones = $this->milestones( [ '2.9.0', '3.0.0', '2.12.0' ] );

		$this->assertSame( '3.0.0', Bundle::find_previous_release( $milestones ) );
	}

	public function test_find_previous_release_ignores_titles_that_are_not_versions(): void {
		$milestones = $this->milestones( [ '2.12.0', 'Future', '3.0.0 (docs)', 'v2.11.0' ] );

		$this->assertSame( '2.12.0', Bundle::find_previous_release( $milestones, '3.0.0' ) );
		$this->assertNull( Bundle::find_previous_release( $this->milestones( [ 'Future' ] ), '3.0.0' ) );
	}

	public function test_find_previous_release_returns_null_below_the_first_release(): void {
		$this->assertNull( Bundle::find_previous_release( $this->milestones( [ '3.0.0', '3.1.0' ] ), '3.0.0' ) );
		$this->assertNull( Bundle::find_previous_release( [], '3.0.0' ) );
	}

	public function test_filter_shipped_milestones_keeps_versions_between_previous_and_current(): void {
		$milestones = $this->milestones( [ '2.1.0', '2.1.1', 'v2.1.2', '2.1.3', '2.2.0' ] );

		$this->assertSame(
			[ '2.1.1', 'v2.1.2', '2.1.3' ],
			$this->titles( Bundle::filter_shipped_milestones( $milestones, '2.1.0', '2.1.3' ) )
		);
	}

	public function test_filter_shipped_milestones_includes_everything_for_a_newly_bundled_package(): void {
		$milestones = $this->milestones( [ '0.9.0', '1.0.0', '1.1.0' ] );

		$this->assertSame(
			[ '0.9.0', '1.0.0' ],
			$this->titles( Bundle::filter_shipped_milestones( $milestones, null, '1.0.0' ) )
		);
	}

	public function test_filter_shipped_milestones_does_not_bound_on_dev_versions(): void {
		$milestones = $this->milestones( [ '2.1.0', '2.1.1', '2.2.0' ] );

		$this->assertSame(
			[ '2.1.1', '2.2.0' ],
			$this->titles( Bundle::filter_shipped_milestones( $milestones, '2.1.0', 'dev-main' ) )
		);
		$this->assertSame(
			[ '2.1.0', '2.1.1' ],
			$this->titles( Bundle::filter_shipped_milestones( $milestones, 'dev-main', '2.1.1' ) )
		);
	}

	public function test_filter_shipped_milestones_drops_titles_that_are_not_versions(): void {
		$milestones = $this->milestones( [ '2.1.1', 'Future', '2.1.2 (docs)', '.org' ] );

		$this->assertSame(
			[ '2.1.1' ],
			$this->titles( Bundle::filter_shipped_milestones( $milestones, '2.1.0', '2.1.3' ) )
		);
	}
}

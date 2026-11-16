<?php

use WP_CLI\Maintenance\Dependency_Update;
use WP_CLI\Tests\TestCase;

class DependencyUpdateTest extends TestCase {

	/**
	 * @param array<string, array{0: string, 1?: string}> $packages     Name => [ version, reference ].
	 * @param array<string, array{0: string, 1?: string}> $dev_packages Name => [ version, reference ].
	 *
	 * @return array
	 */
	private function lock( array $packages, array $dev_packages = [] ) {
		$section = static function ( array $versions ) {
			$entries = [];
			foreach ( $versions as $name => $version ) {
				$entries[] = [
					'name'    => $name,
					'version' => $version[0],
					'source'  => [ 'reference' => isset( $version[1] ) ? $version[1] : sha1( $name . $version[0] ) ],
				];
			}

			return $entries;
		};

		return [
			'packages'     => $section( $packages ),
			'packages-dev' => $section( $dev_packages ),
		];
	}

	private function advisory( $package, $id, $cve = null ) {
		return [
			'package'  => $package,
			'version'  => 'v5.4.54',
			'dev'      => true,
			'advisory' => $id,
			'cve'      => $cve,
			'title'    => "Problem in {$package}",
			'link'     => "https://example.com/{$id}",
		];
	}

	private function plan( array $changes, array $fixed = [], array $remaining = [] ) {
		return [
			'repo'          => 'wp-cli/wp-cli-bundle',
			'branch'        => 'main',
			'head'          => 'update-dependencies-security',
			'sha'           => 'cee1f64b6ba7df6fefe41d4e96cb20f53acf2e10',
			'requested'     => false,
			'packages'      => [ 'symfony/yaml' ],
			'unmatched'     => [],
			'changes'       => $changes,
			'fixed'         => $fixed,
			'remaining'     => $remaining,
			'composer_lock' => '{}',
		];
	}

	private function change( $package, $from = 'v5.4.54', $to = 'v5.4.56', $dev = true ) {
		return [
			'package' => $package,
			'from'    => $from,
			'to'      => $to,
			'dev'     => $dev,
		];
	}

	public function test_diff_lists_changed_added_and_removed_packages(): void {
		$before = $this->lock(
			[ 'mustache/mustache' => [ 'v3.0.0' ] ],
			[
				'symfony/yaml'         => [ 'v5.4.54' ],
				'behat/transliterator' => [ 'v1.5.0' ],
			]
		);
		$after  = $this->lock(
			[ 'mustache/mustache' => [ 'v3.0.0' ] ],
			[
				'symfony/yaml'     => [ 'v5.4.56' ],
				'symfony/polyfill' => [ 'v1.33.0' ],
			]
		);

		$this->assertSame(
			[
				$this->change( 'behat/transliterator', 'v1.5.0', null ),
				$this->change( 'symfony/polyfill', null, 'v1.33.0' ),
				$this->change( 'symfony/yaml' ),
			],
			Dependency_Update::diff( $before, $after )
		);
	}

	public function test_diff_shows_the_commit_a_branch_version_moved_to(): void {
		$before = $this->lock( [ 'wp-cli/wp-cli' => [ 'dev-main', '5ec3c0545d30f0f93c49a40a06ba6c1fd0e9fd6c' ] ] );
		$after  = $this->lock( [ 'wp-cli/wp-cli' => [ 'dev-main', '0123456789abcdef0123456789abcdef01234567' ] ] );

		$this->assertSame(
			[ $this->change( 'wp-cli/wp-cli', 'dev-main (5ec3c05)', 'dev-main (0123456)', false ) ],
			Dependency_Update::diff( $before, $after )
		);
	}

	public function test_diff_of_the_same_lock_is_empty(): void {
		$lock = $this->lock( [ 'wp-cli/wp-cli' => [ 'dev-main', '5ec3c05' ] ], [ 'symfony/yaml' => [ 'v5.4.54' ] ] );

		$this->assertSame( [], Dependency_Update::diff( $lock, $lock ) );
	}

	public function test_subtract_keeps_the_advisories_the_update_fixed(): void {
		$yaml       = $this->advisory( 'symfony/yaml', 'PKSA-1' );
		$config     = $this->advisory( 'symfony/config', 'PKSA-2' );
		$filesystem = $this->advisory( 'symfony/filesystem', 'PKSA-3' );

		$this->assertSame( [ $yaml, $filesystem ], Dependency_Update::subtract( [ $yaml, $config, $filesystem ], [ $config ] ) );
		$this->assertSame( [], Dependency_Update::subtract( [ $yaml ], [ $yaml ] ) );
	}

	public function test_the_title_names_up_to_three_packages(): void {
		$titles = array_map(
			function ( array $names ) {
				list( $title ) = Dependency_Update::describe_pull_request( $this->plan( array_map( [ $this, 'change' ], $names ) ) );
				return $title;
			},
			[
				[ 'symfony/yaml' ],
				[ 'symfony/config', 'symfony/yaml' ],
				[ 'symfony/config', 'symfony/filesystem', 'symfony/yaml' ],
				[ 'a/a', 'b/b', 'c/c', 'd/d' ],
			]
		);

		$this->assertSame(
			[
				'Update symfony/yaml',
				'Update symfony/config and symfony/yaml',
				'Update symfony/config, symfony/filesystem and symfony/yaml',
				'Update 4 locked dependencies',
			],
			$titles
		);
	}

	public function test_the_description_lists_changes_and_advisories(): void {
		list( , $body ) = Dependency_Update::describe_pull_request(
			$this->plan(
				[ $this->change( 'symfony/yaml' ), $this->change( 'symfony/new', null, 'v1.0.0', false ) ],
				[ $this->advisory( 'symfony/yaml', 'PKSA-1', 'CVE-2026-1' ) ],
				[ $this->advisory( 'symfony/config', 'PKSA-2' ) ]
			)
		);

		$this->assertStringContainsString( '| symfony/yaml | v5.4.54 | v5.4.56 | yes |', $body );
		$this->assertStringContainsString( '| symfony/new | (new) | v1.0.0 | no |', $body );
		$this->assertStringContainsString( "### Fixed security advisories\n\n- symfony/yaml [PKSA-1](https://example.com/PKSA-1): Problem in symfony/yaml (CVE-2026-1)", $body );
		$this->assertStringContainsString( "### Still affected\n", $body );
		$this->assertStringContainsString( '- symfony/config [PKSA-2](https://example.com/PKSA-2): Problem in symfony/config', $body );
	}

	public function test_the_description_leaves_out_empty_sections(): void {
		list( , $body ) = Dependency_Update::describe_pull_request( $this->plan( [ $this->change( 'wp-cli/wp-cli', 'dev-main (5ec3c05)', 'dev-main (0123456)', false ) ] ) );

		$this->assertStringNotContainsString( '###', $body );
	}

	/**
	 * @param int      $ahead_by Commits on the branch that the default branch lacks.
	 * @param string[] $messages Messages of those commits, oldest first.
	 */
	private function comparison( $ahead_by, array $messages = [], array $files = [ 'composer.lock' ] ) {
		return (object) [
			'ahead_by' => $ahead_by,
			'files'    => array_map(
				static function ( $file ) {
					return (object) [ 'filename' => $file ];
				},
				$files
			),
			'commits'  => array_map(
				static function ( $message ) {
					return (object) [ 'commit' => (object) [ 'message' => $message ] ];
				},
				$messages
			),
		];
	}

	public function test_only_a_branch_with_nothing_but_its_own_commit_is_overwritten(): void {
		$this->assertTrue( Dependency_Update::is_own_branch( $this->comparison( 0 ) ) );
		$this->assertTrue( Dependency_Update::is_own_branch( $this->comparison( 1, [ "Update locked dependencies\n\n- symfony/yaml: v5.4.54 => v5.4.56" ] ) ) );
		$this->assertFalse( Dependency_Update::is_own_branch( $this->comparison( 1, [ 'Pin symfony/yaml' ] ) ) );
		$this->assertFalse( Dependency_Update::is_own_branch( $this->comparison( 2, [ 'Update locked dependencies', 'Fix the build' ] ) ) );
		$this->assertFalse( Dependency_Update::is_own_branch( $this->comparison( 1, [ 'Update locked dependencies' ], [ 'composer.json', 'composer.lock' ] ) ) );
	}

	public function test_expand_names_each_locked_package_a_pattern_matches(): void {
		$locked = [ 'symfony/yaml', 'symfony/config', 'Symfony/Polyfill-PHP84', 'wp-cli/wp-cli', 'behat/behat' ];

		$this->assertSame(
			[ [ 'Symfony/Polyfill-PHP84', 'symfony/config', 'symfony/yaml', 'wp-cli/wp-cli' ], [ 'acme/*' ] ],
			Dependency_Update::expand( [ 'symfony/*', 'WP-CLI/wp-cli', 'acme/*', 'symfony/yaml' ], $locked )
		);
		$this->assertSame( [ [ 'behat/behat', 'symfony/config', 'symfony/yaml', 'wp-cli/wp-cli' ], [] ], Dependency_Update::expand( [ '*' ], [ 'symfony/yaml', 'symfony/config', 'wp-cli/wp-cli', 'behat/behat' ] ) );
		$this->assertSame( [ [], [ 'symfony.yaml' ] ], Dependency_Update::expand( [ 'symfony.yaml' ], [ 'symfony/yaml' ] ) );
	}

	public function test_branch_names_follow_the_requested_packages(): void {
		$this->assertSame( 'update-dependencies-security', Dependency_Update::get_branch_name( [] ) );
		$this->assertSame( 'update-dependencies-wp-cli-wp-cli', Dependency_Update::get_branch_name( [ 'wp-cli/wp-cli' ] ) );
		$this->assertSame( 'update-dependencies-symfony-all-wp-cli-wp-cli', Dependency_Update::get_branch_name( [ 'wp-cli/wp-cli', 'symfony/*' ] ) );
		$this->assertSame( 'update-dependencies-all', Dependency_Update::get_branch_name( [ '*' ] ) );

		$long = Dependency_Update::get_branch_name( [ 'symfony/config', 'symfony/filesystem', 'symfony/yaml', 'symfony/process', 'symfony/finder' ] );
		$this->assertLessThanOrEqual( strlen( Dependency_Update::BRANCH_PREFIX ) + 60, strlen( $long ) );
		$this->assertNotSame( $long, Dependency_Update::get_branch_name( [ 'symfony/config', 'symfony/filesystem', 'symfony/yaml', 'symfony/process', 'symfony/console' ] ) );
	}

	public function test_the_description_of_a_requested_update_does_not_blame_composer_json(): void {
		$plan              = $this->plan( [ $this->change( 'wp-cli/wp-cli', 'dev-main (5ec3c05)', 'dev-main (0123456)', false ) ], [], [ $this->advisory( 'symfony/yaml', 'PKSA-1' ) ] );
		$plan['requested'] = true;

		list( , $body ) = Dependency_Update::describe_pull_request( $plan );

		$this->assertStringContainsString( 'This update leaves these advisories as they are:', $body );
		$this->assertStringNotContainsString( 'composer.json:', $body );
		$this->assertStringNotContainsString( 'Fixes security advisories.', $body );
	}
}

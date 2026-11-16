<?php

use WP_CLI\ExitException;
use WP_CLI\Maintenance\Dependencies_Command;
use WP_CLI\Maintenance\Dependency_Audit;
use WP_CLI\Maintenance\Dependency_Update;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class DependenciesUpdateCommandTest extends GitHub_TestCase {

	const SHA  = 'cee1f64b6ba7df6fefe41d4e96cb20f53acf2e10';
	const REPO = 'repos/wp-cli/wp-cli-bundle';

	/**
	 * Calls to the fake `composer update`: composer.json, composer.lock,
	 * packages, branch.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string[], 3: string}>
	 */
	private $updates = [];

	/**
	 * Whether the fake `composer update` fails to fix the advisory, as when
	 * composer.json does not allow a fixed version.
	 *
	 * @var bool
	 */
	private $unfixable = false;

	/**
	 * Plugin API version of the Composer the fake `composer update` stands
	 * for.
	 *
	 * @var string
	 */
	private $plugin_api = '2.9.0';

	/**
	 * Calls to the fake `composer why-not`: composer.lock, package, version.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private $why_nots = [];

	/**
	 * What the command printed to STDOUT.
	 *
	 * @var string
	 */
	private $stdout = '';

	protected function set_up(): void {
		parent::set_up();

		// symfony/yaml v5.4.54 has an advisory; the update moves it to v5.4.56.
		Dependency_Audit::set_runner(
			static function ( $composer_json, $composer_lock ) {
				$affected = false !== strpos( $composer_lock, '"v5.4.54"' );

				return [
					'advisories' => $affected ? [
						'symfony/yaml' => [
							[
								'advisoryId'       => 'PKSA-nktx-f1n1-b4yd',
								'cve'              => 'CVE-2026-123792',
								'title'            => 'Exponential and Quadratic Parse Times in the YAML Parser',
								'link'             => 'https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx',
								'affectedVersions' => '>=5.0.0,<5.4.0|>=5.4.0,<5.4.56|>=6.0.0,<6.4.50',
							],
						],
					] : [],
					'abandoned'  => [],
				];
			}
		);

		Dependency_Update::set_runner(
			function ( $composer_json, $composer_lock, array $packages, $branch ) {
				$this->updates[] = [ $composer_json, $composer_lock, $packages, $branch ];

				return $this->unfixable ? $composer_lock : $this->lock_file( 'v5.4.56', $this->plugin_api );
			}
		);

		Dependency_Update::set_why_not_runner(
			function ( $composer_json, $composer_lock, $package, $version ) {
				$this->why_nots[] = [ $composer_lock, $package, $version ];

				return [ "wp-cli/wp-cli-bundle dev-main requires {$package} (5.4.54)" ];
			}
		);
	}

	protected function tear_down(): void {
		Dependency_Audit::set_runner( null );
		Dependency_Update::set_runner( null );
		Dependency_Update::set_why_not_runner( null );

		parent::tear_down();
	}

	private function lock_file( $yaml_version, $plugin_api = '2.9.0' ) {
		return (string) json_encode( $this->lock( $yaml_version, $plugin_api ), JSON_PRETTY_PRINT );
	}

	private function lock( $yaml_version, $plugin_api = '2.9.0' ) {
		return [
			'plugin-api-version' => $plugin_api,
			'packages'           => [
				[
					'name'    => 'wp-cli/wp-cli',
					'version' => 'dev-main',
					'source'  => [ 'reference' => '5ec3c0545d30f0f93c49a40a06ba6c1fd0e9fd6c' ],
				],
			],
			'packages-dev'       => [
				[
					'name'    => 'symfony/yaml',
					'version' => $yaml_version,
				],
			],
		];
	}

	/**
	 * Sets up the bundle with symfony/yaml locked at the given version.
	 */
	private function bundle( $yaml_version = 'v5.4.54' ) {
		$this->respond( 'GET', self::REPO, $this->fixture_object( 'repository', null, [ 'default_branch' => 'main' ] ) );
		$this->respond( 'GET', self::REPO . '/commits?sha=main', [ [ 'sha' => self::SHA ] ] );
		$this->respond( 'GET', 'wp-cli/wp-cli-bundle/' . self::SHA . '/composer.lock', $this->lock( $yaml_version ) );
		$this->respond( 'GET', 'wp-cli/wp-cli-bundle/' . self::SHA . '/composer.json', [ 'name' => 'wp-cli/wp-cli-bundle' ] );
	}

	/**
	 * Sets up the requests that commit a file on top of SHA.
	 */
	private function commit() {
		$this->respond( 'GET', self::REPO . '/git/commits/' . self::SHA, [ 'tree' => [ 'sha' => 'base-tree' ] ] );
		$this->respond( 'POST', self::REPO . '/git/trees', [ 'sha' => 'new-tree' ] );
		$this->respond( 'POST', self::REPO . '/git/commits', [ 'sha' => 'new-commit' ] );
	}

	/**
	 * Runs the command and returns its exit code, 0 when it did not halt.
	 */
	private function update( array $args, array $assoc_args ) {
		$exit_code = 0;

		// Tables go to the output stream of php-cli-tools, which tests swap
		// for one in memory.
		$out = new ReflectionProperty( \cli\Streams::class, 'out' );
		if ( PHP_VERSION_ID < 80100 ) {
			$out->setAccessible( true );
		}
		$original = $out->getValue();
		$stream   = fopen( 'php://memory', 'w+' );
		$out->setValue( null, $stream );

		ob_start();
		try {
			( new Dependencies_Command() )->update( $args, $assoc_args );
		} catch ( ExitException $exception ) {
			$exit_code = $exception->getCode();
		} finally {
			$output = (string) ob_get_clean();
			$out->setValue( null, $original );
			rewind( $stream );
			$this->stdout = (string) stream_get_contents( $stream ) . $output;
			fclose( $stream );
		}

		return $exit_code;
	}

	/**
	 * Builds the comparison of the default branch with a branch holding one
	 * commit of this command.
	 *
	 * @param string $merge_base Commit the branch was made from.
	 *
	 * @return array
	 */
	private function comparison( $merge_base ) {
		return [
			'ahead_by'          => 1,
			'merge_base_commit' => [ 'sha' => $merge_base ],
			'commits'           => [ [ 'commit' => [ 'message' => "Update locked dependencies\n\n- symfony/yaml: v5.4.53 => v5.4.54" ] ] ],
			'files'             => [ [ 'filename' => 'composer.lock' ] ],
		];
	}

	/**
	 * @return string[] Write requests made, as "METHOD URL".
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

	/**
	 * @return mixed Decoded body of the write request to a URL.
	 */
	private function sent( $request ) {
		$index = array_search( $request, $this->requests, true );
		$this->assertNotFalse( $index, "No request {$request}." );

		return $this->sent_data[ $index ];
	}

	public function test_a_dry_run_shows_the_changes_without_writing(): void {
		$this->bundle();

		$this->assertSame( 0, $this->update( [], [ 'dry-run' => true ] ) );

		$this->assertStringContainsString( "symfony/yaml\tv5.4.54\tv5.4.56\tyes", $this->stdout );
		$this->assertStringContainsString( 'Fixes 1 security advisory.', $this->logger->stdout );
		$this->assertStringContainsString( 'Dry run: not pushing the new composer.lock of wp-cli/wp-cli-bundle.', $this->logger->stdout );
		$this->assertSame( [ 'symfony/yaml' ], $this->updates[0][2] );
		$this->assertSame( 'main', $this->updates[0][3] );
		$this->assertSame( [], $this->writes() );
	}

	public function test_commits_the_new_lock_and_opens_a_pull_request(): void {
		$this->bundle();
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-security', [ 'message' => 'Not Found' ], 404 );
		$this->commit();
		$this->respond( 'POST', self::REPO . '/git/refs', [ 'ref' => 'refs/heads/update-dependencies-security' ] );
		$this->respond( 'GET', self::REPO . '/pulls?head=wp-cli:update-dependencies-security&state=open', [] );
		$this->respond(
			'POST',
			self::REPO . '/pulls',
			[
				'number'   => 7,
				'html_url' => 'https://github.com/wp-cli/wp-cli-bundle/pull/7',
			]
		);
		$this->respond( 'POST', self::REPO . '/issues/7/labels', [] );

		$this->assertSame( 0, $this->update( [], [ 'yes' => true ] ) );

		$api = 'https://api.github.com/' . self::REPO;
		$this->assertSame(
			[
				"POST {$api}/git/trees",
				"POST {$api}/git/commits",
				"POST {$api}/git/refs",
				"POST {$api}/pulls",
				"POST {$api}/issues/7/labels",
			],
			$this->writes()
		);

		$tree = $this->sent( "POST {$api}/git/trees" );
		$this->assertSame( 'base-tree', $tree->base_tree );
		$this->assertSame( 'composer.lock', $tree->tree[0]->path );
		$this->assertSame( $this->lock( 'v5.4.56' ), json_decode( $tree->tree[0]->content, true ) );

		$commit = $this->sent( "POST {$api}/git/commits" );
		$this->assertSame( [ self::SHA ], $commit->parents );
		$this->assertSame( "Update locked dependencies\n\n- symfony/yaml: v5.4.54 => v5.4.56", $commit->message );

		$this->assertEquals(
			(object) [
				'ref' => 'refs/heads/update-dependencies-security',
				'sha' => 'new-commit',
			],
			$this->sent( "POST {$api}/git/refs" )
		);

		$pull_request = $this->sent( "POST {$api}/pulls" );
		$this->assertSame( 'update-dependencies-security', $pull_request->head );
		$this->assertSame( 'main', $pull_request->base );
		$this->assertSame( 'Update symfony/yaml', $pull_request->title );
		$this->assertStringContainsString( 'PKSA-nktx-f1n1-b4yd', $pull_request->body );

		$this->assertEquals( (object) [ 'labels' => [ 'scope:distribution' ] ], $this->sent( "POST {$api}/issues/7/labels" ) );
		$this->assertStringContainsString( 'Pull request for wp-cli/wp-cli-bundle: https://github.com/wp-cli/wp-cli-bundle/pull/7', $this->logger->stdout );
	}

	public function test_a_later_run_moves_its_own_branch_and_refreshes_the_pull_request(): void {
		$this->bundle();
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-security', [ 'object' => [ 'sha' => 'old-commit' ] ] );
		$this->respond(
			'GET',
			self::REPO . '/compare/main...update-dependencies-security',
			$this->comparison( 'older-default-branch-commit' )
		);
		$this->commit();
		$this->respond( 'PATCH', self::REPO . '/git/refs/heads/update-dependencies-security', [ 'ref' => 'refs/heads/update-dependencies-security' ] );
		$this->respond( 'GET', self::REPO . '/pulls?head=wp-cli:update-dependencies-security&state=open', [ [ 'number' => 7 ] ] );
		$this->respond(
			'PATCH',
			self::REPO . '/pulls/7',
			[
				'number'   => 7,
				'html_url' => 'https://github.com/wp-cli/wp-cli-bundle/pull/7',
			]
		);

		$this->respond( 'POST', self::REPO . '/issues/7/labels', [] );

		$this->assertSame( 0, $this->update( [], [ 'yes' => true ] ) );

		$api = 'https://api.github.com/' . self::REPO;
		$this->assertSame(
			[
				"POST {$api}/git/trees",
				"POST {$api}/git/commits",
				"PATCH {$api}/git/refs/heads/update-dependencies-security",
				"PATCH {$api}/pulls/7",
				"POST {$api}/issues/7/labels",
			],
			$this->writes()
		);
		$this->assertEquals(
			(object) [
				'sha'   => 'new-commit',
				'force' => true,
			],
			$this->sent( "PATCH {$api}/git/refs/heads/update-dependencies-security" )
		);
	}

	public function test_a_branch_someone_else_pushed_to_is_left_alone(): void {
		$this->bundle();
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-security', [ 'object' => [ 'sha' => 'old-commit' ] ] );
		$this->respond(
			'GET',
			self::REPO . '/compare/main...update-dependencies-security',
			[
				'ahead_by'          => 2,
				'merge_base_commit' => [ 'sha' => self::SHA ],
				'commits'           => [
					[ 'commit' => [ 'message' => 'Update locked dependencies' ] ],
					[ 'commit' => [ 'message' => 'Pin symfony/yaml for PHP 7.2' ] ],
				],
				'files'             => [ [ 'filename' => 'composer.lock' ] ],
			]
		);

		$this->assertSame( 1, $this->update( [], [ 'yes' => true ] ) );

		$this->assertStringContainsString( 'The update-dependencies-security branch of wp-cli/wp-cli-bundle has commits that `wp maintenance dependencies update` did not make', $this->logger->stderr );
		$this->assertSame( [], $this->writes() );
	}

	public function test_nothing_is_updated_without_advisories(): void {
		$this->bundle( 'v5.4.56' );

		$this->assertSame( 0, $this->update( [], [ 'yes' => true ] ) );

		$this->assertStringContainsString( 'No locked package of wp-cli/wp-cli-bundle has a security advisory, so there is nothing to update.', $this->logger->stdout );
		$this->assertSame( [], $this->updates );
		$this->assertSame( [], $this->writes() );
	}

	public function test_advisories_the_update_cannot_fix_fail_the_run(): void {
		$this->bundle();
		$this->unfixable = true;

		$this->assertSame( 1, $this->update( [], [ 'dry-run' => true ] ) );

		$this->assertStringContainsString( 'Updating symfony/yaml changes no locked version of wp-cli/wp-cli-bundle.', $this->logger->stderr );
		$this->assertStringContainsString( 'symfony/yaml v5.4.54 is still affected by PKSA-nktx-f1n1-b4yd', $this->logger->stderr );
		$this->assertStringContainsString( "It is fixed in 5.4.56, which is held back by:\n  wp-cli/wp-cli-bundle dev-main requires symfony/yaml (5.4.54)", $this->logger->stderr );
		$this->assertStringContainsString( 'Fixing them needs a change to composer.json.', $this->logger->stderr );
		$this->assertCount( 1, $this->why_nots );
		$this->assertSame( $this->lock( 'v5.4.54' ), json_decode( $this->why_nots[0][0], true ) );
		$this->assertSame( [ 'symfony/yaml', '5.4.56' ], array_slice( $this->why_nots[0], 1 ) );
	}

	public function test_fixed_advisories_are_not_explained(): void {
		$this->bundle();

		$this->assertSame( 0, $this->update( [], [ 'dry-run' => true ] ) );

		$this->assertSame( [], $this->why_nots );
	}

	public function test_given_packages_are_updated_instead_and_may_leave_advisories(): void {
		$this->bundle();
		$this->unfixable = true;

		$this->assertSame(
			0,
			$this->update(
				[],
				[
					'packages' => 'symfony/*, wp-cli/wp-cli',
					'dry-run'  => true,
				]
			)
		);

		// Named one by one, so that Composer updates each of them fully.
		$this->assertSame( [ 'symfony/yaml', 'wp-cli/wp-cli' ], $this->updates[0][2] );
		$this->assertStringContainsString( 'is still affected by PKSA-nktx-f1n1-b4yd', $this->logger->stderr );
	}

	public function test_an_option_is_not_taken_for_a_package(): void {
		$this->assertSame( 1, $this->update( [], [ 'packages' => '--with-all-dependencies' ] ) );

		$this->assertStringContainsString( "'--with-all-dependencies' is not a package name.", $this->logger->stderr );
		$this->assertSame( [], $this->requests );
	}

	public function test_a_rerun_with_the_same_result_only_refreshes_the_pull_request(): void {
		$this->bundle();
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-security', [ 'object' => [ 'sha' => 'old-commit' ] ] );
		$this->respond( 'GET', self::REPO . '/compare/main...update-dependencies-security', $this->comparison( self::SHA ) );
		$this->respond( 'GET', 'wp-cli/wp-cli-bundle/old-commit/composer.lock', $this->lock( 'v5.4.56' ) );
		$this->respond( 'GET', self::REPO . '/pulls?head=wp-cli:update-dependencies-security&state=open', [ [ 'number' => 7 ] ] );
		$this->respond(
			'PATCH',
			self::REPO . '/pulls/7',
			[
				'number'   => 7,
				'html_url' => 'https://github.com/wp-cli/wp-cli-bundle/pull/7',
			]
		);
		$this->respond( 'POST', self::REPO . '/issues/7/labels', [] );

		// The update writes exactly the lock that the branch holds already, as
		// the fake GitHub API encodes it.
		Dependency_Update::set_runner(
			function () {
				return (string) json_encode( $this->lock( 'v5.4.56' ) );
			}
		);

		$this->assertSame( 0, $this->update( [], [ 'yes' => true ] ) );

		$api = 'https://api.github.com/' . self::REPO;
		$this->assertSame( [ "PATCH {$api}/pulls/7", "POST {$api}/issues/7/labels" ], $this->writes() );
		$this->assertStringContainsString( 'The update-dependencies-security branch already holds this composer.lock, so only the pull request was refreshed.', $this->logger->stdout );
	}

	public function test_a_branch_with_changes_beyond_the_lock_is_left_alone(): void {
		$this->bundle();
		$comparison          = $this->comparison( self::SHA );
		$comparison['files'] = [ [ 'filename' => 'composer.json' ], [ 'filename' => 'composer.lock' ] ];
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-security', [ 'object' => [ 'sha' => 'old-commit' ] ] );
		$this->respond( 'GET', self::REPO . '/compare/main...update-dependencies-security', $comparison );

		$this->assertSame( 1, $this->update( [], [ 'yes' => true ] ) );

		$this->assertSame( [], $this->writes() );
	}

	public function test_packages_get_a_branch_of_their_own(): void {
		$this->bundle();
		$this->respond( 'GET', self::REPO . '/git/ref/heads/update-dependencies-wp-cli-wp-cli', [ 'message' => 'Not Found' ], 404 );
		$this->commit();
		$this->respond( 'POST', self::REPO . '/git/refs', [ 'ref' => 'refs/heads/update-dependencies-wp-cli-wp-cli' ] );
		$this->respond( 'GET', self::REPO . '/pulls?head=wp-cli:update-dependencies-wp-cli-wp-cli&state=open', [] );
		$this->respond(
			'POST',
			self::REPO . '/pulls',
			[
				'number'   => 8,
				'html_url' => 'https://github.com/wp-cli/wp-cli-bundle/pull/8',
			]
		);
		$this->respond( 'POST', self::REPO . '/issues/8/labels', [] );

		$this->assertSame(
			0,
			$this->update(
				[],
				[
					'packages' => 'wp-cli/wp-cli',
					'yes'      => true,
				]
			)
		);

		$api = 'https://api.github.com/' . self::REPO;
		$this->assertSame( 'refs/heads/update-dependencies-wp-cli-wp-cli', $this->sent( "POST {$api}/git/refs" )->ref );
		$body = $this->sent( "POST {$api}/pulls" )->body;
		$this->assertStringContainsString( 'Updates `wp-cli/wp-cli` in composer.lock', $body );
		$this->assertStringNotContainsString( 'Fixes security advisories.', $body );
	}

	public function test_packages_that_match_nothing_locked_are_reported(): void {
		$this->bundle();

		$this->assertSame(
			0,
			$this->update(
				[],
				[
					'packages' => 'acme/*',
					'dry-run'  => true,
				]
			)
		);

		$this->assertStringContainsString( 'acme/* matches no package locked in wp-cli/wp-cli-bundle.', $this->logger->stderr );
		$this->assertSame( [], $this->updates );
	}

	public function test_an_older_composer_does_not_downgrade_the_lock(): void {
		$this->respond( 'GET', self::REPO, $this->fixture_object( 'repository', null, [ 'default_branch' => 'main' ] ) );
		$this->respond( 'GET', self::REPO . '/commits?sha=main', [ [ 'sha' => self::SHA ] ] );
		$this->respond( 'GET', 'wp-cli/wp-cli-bundle/' . self::SHA . '/composer.lock', $this->lock( 'v5.4.54', '2.11.0' ) );
		$this->respond( 'GET', 'wp-cli/wp-cli-bundle/' . self::SHA . '/composer.json', [ 'name' => 'wp-cli/wp-cli-bundle' ] );

		$this->assertSame( 1, $this->update( [], [ 'dry-run' => true ] ) );

		$this->assertStringContainsString( 'was written by a newer Composer (plugin API 2.11.0) than the one installed here (plugin API 2.9.0)', $this->logger->stderr );
	}
}

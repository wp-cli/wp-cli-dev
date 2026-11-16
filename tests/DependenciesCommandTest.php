<?php

use WP_CLI\ExitException;
use WP_CLI\Maintenance\Dependencies_Command;
use WP_CLI\Maintenance\Dependency_Audit;
use WP_CLI\Maintenance\Tests\GitHub_TestCase;

class DependenciesCommandTest extends GitHub_TestCase {

	const SHA = 'cee1f64b6ba7df6fefe41d4e96cb20f53acf2e10';

	/**
	 * Calls to the fake `composer audit`: composer.json, composer.lock, dev.
	 *
	 * @var array<int, array{0: string, 1: string, 2: bool}>
	 */
	private $composer_runs = [];

	/**
	 * Report the fake `composer audit` returns.
	 *
	 * @var array
	 */
	private $report = [
		'advisories' => [],
		'abandoned'  => [],
	];

	/**
	 * What the command printed to STDOUT.
	 *
	 * @var string
	 */
	private $stdout = '';

	protected function set_up(): void {
		parent::set_up();

		Dependency_Audit::set_runner(
			function ( $composer_json, $composer_lock, $dev ) {
				$this->composer_runs[] = [ $composer_json, $composer_lock, $dev ];

				return $this->report;
			}
		);
	}

	protected function tear_down(): void {
		Dependency_Audit::set_runner( null );

		parent::tear_down();
	}

	/**
	 * Sets up a repository whose ref resolves to SHA, with the given files at
	 * that commit.
	 *
	 * @param string     $repo          Repository name, including the org.
	 * @param string     $ref           Ref that is asked for.
	 * @param array|null $composer_lock Decoded lock, or null for none.
	 * @param bool       $composer_json Whether there is a composer.json.
	 */
	private function repository( $repo, $ref, $composer_lock, $composer_json = true ) {
		$this->respond( 'GET', "repos/{$repo}", $this->fixture_object( 'repository', null, [ 'default_branch' => 'main' ] ) );
		$this->respond( 'GET', "repos/{$repo}/commits?sha={$ref}", [ [ 'sha' => self::SHA ] ] );
		$this->respond( 'GET', "{$repo}/" . self::SHA . '/composer.lock', $composer_lock, null === $composer_lock ? 404 : 200 );
		$this->respond( 'GET', "{$repo}/" . self::SHA . '/composer.json', [ 'name' => $repo ], $composer_json ? 200 : 404 );
	}

	private function lock() {
		return [
			'packages'     => [
				[
					'name'    => 'wp-cli/wp-cli',
					'version' => 'dev-main',
				],
			],
			'packages-dev' => [
				[
					'name'    => 'symfony/yaml',
					'version' => 'v5.4.54',
				],
			],
		];
	}

	/**
	 * Runs the command and returns its exit code, 0 when it did not halt.
	 */
	private function audit( array $args, array $assoc_args ) {
		$exit_code = 0;

		// WP_CLI::line() writes to STDOUT directly rather than to the logger.
		ob_start();
		try {
			( new Dependencies_Command() )->audit( $args, $assoc_args + [ 'format' => 'json' ] );
		} catch ( ExitException $exception ) {
			$exit_code = $exception->getCode();
		} finally {
			$this->stdout = (string) ob_get_clean();
		}

		return $exit_code;
	}

	/**
	 * @return array Decoded JSON the command printed.
	 */
	private function printed_audits() {
		return json_decode( $this->stdout, true );
	}

	public function test_audits_the_bundle_at_its_default_branch_by_default(): void {
		$this->repository( 'wp-cli/wp-cli-bundle', 'main', $this->lock() );

		$this->assertSame( 0, $this->audit( [], [] ) );

		$this->assertCount( 1, $this->composer_runs );
		list( $composer_json, $composer_lock, $dev ) = $this->composer_runs[0];
		$this->assertSame( [ 'name' => 'wp-cli/wp-cli-bundle' ], json_decode( $composer_json, true ) );
		$this->assertSame( $this->lock(), json_decode( $composer_lock, true ) );
		$this->assertTrue( $dev );

		$output = $this->printed_audits();
		$this->assertSame( 'wp-cli/wp-cli-bundle', $output[0]['repo'] );
		$this->assertSame( 'main', $output[0]['ref'] );
		$this->assertSame( self::SHA, $output[0]['sha'] );
		$this->assertTrue( $output[0]['dev'] );
		$this->assertSame( Dependency_Audit::OK, $output[0]['status'] );
	}

	public function test_an_advisory_fails_the_audit(): void {
		$this->repository( 'wp-cli/wp-cli-bundle', 'main', $this->lock() );
		$this->report['advisories'] = [ 'symfony/yaml' => [ [ 'advisoryId' => 'PKSA-nktx-f1n1-b4yd' ] ] ];

		$this->assertSame( 1, $this->audit( [], [] ) );

		$this->assertSame( Dependency_Audit::VULNERABLE, $this->printed_audits()[0]['status'] );
		$this->assertStringContainsString( 'Locked packages are affected by security advisories in wp-cli/wp-cli-bundle at main.', $this->logger->stderr );
	}

	public function test_abandoned_packages_alone_do_not_fail_the_audit(): void {
		$this->repository( 'wp-cli/wp-cli-bundle', 'main', $this->lock() );
		$this->report['abandoned'] = [ 'behat/transliterator' => null ];

		$this->assertSame( 0, $this->audit( [], [] ) );

		$this->assertSame( Dependency_Audit::ABANDONED, $this->printed_audits()[0]['status'] );
	}

	public function test_no_dev_leaves_out_the_dev_packages(): void {
		$this->repository( 'wp-cli/wp-cli-bundle', 'v2.12.0', $this->lock() );

		$this->assertSame(
			0,
			$this->audit(
				[ 'wp-cli-bundle' ],
				[
					'ref' => 'v2.12.0',
					'dev' => false,
				]
			)
		);

		$this->assertFalse( $this->composer_runs[0][2] );
		$this->assertFalse( $this->printed_audits()[0]['dev'] );
		$this->assertSame( 'v2.12.0', $this->printed_audits()[0]['ref'] );
	}

	public function test_a_lock_with_nothing_to_audit_is_ok_without_asking_composer(): void {
		$lock = $this->lock();
		$this->repository( 'wp-cli/dev-only', 'main', [ 'packages' => [] ] + $lock );
		$this->repository( 'wp-cli/empty', 'main', [ 'packages' => [] ] );

		$this->assertSame( 0, $this->audit( [ 'dev-only', 'empty' ], [ 'dev' => false ] ) );

		$this->assertSame( [], $this->composer_runs );
		$this->assertSame( [ 'ok', 'ok' ], array_column( $this->printed_audits(), 'status' ) );
	}

	public function test_repositories_without_a_lock_are_skipped(): void {
		$this->repository( 'wp-cli/wp-cli', 'main', null );
		$this->repository( 'wp-cli/wp-cli-bundle', 'main', $this->lock() );

		$this->assertSame( 0, $this->audit( [ 'wp-cli', 'wp-cli-bundle' ], [] ) );

		$this->assertStringContainsString( 'Warning: wp-cli/wp-cli has no composer.lock, skipping it.', $this->logger->stderr );
		$this->assertSame( [ 'wp-cli/wp-cli-bundle' ], array_column( $this->printed_audits(), 'repo' ) );
		$this->assertCount( 1, $this->composer_runs );
	}

	public function test_nothing_to_audit_is_an_error(): void {
		$this->repository( 'wp-cli/wp-cli', 'main', null );

		$this->assertSame( 1, $this->audit( [ 'wp-cli' ], [] ) );

		$this->assertStringContainsString( 'None of the repositories has a composer.lock to audit.', $this->logger->stderr );
		$this->assertSame( [], $this->composer_runs );
	}

	public function test_an_unknown_ref_is_an_error_rather_than_a_missing_lock(): void {
		$this->respond( 'GET', 'repos/wp-cli/wp-cli-bundle/commits?sha=v9.9.9', [ 'message' => 'Not Found' ], 404 );

		$this->assertSame( 1, $this->audit( [], [ 'ref' => 'v9.9.9' ] ) );

		$this->assertStringContainsString( 'wp-cli/wp-cli-bundle has no branch, tag or commit v9.9.9.', $this->logger->stderr );
		$this->assertSame( [], $this->composer_runs );
	}

	public function test_a_lock_without_composer_json_is_an_error(): void {
		$this->repository( 'wp-cli/wp-cli-bundle', 'main', $this->lock(), false );

		$this->assertSame( 1, $this->audit( [], [] ) );

		$this->assertStringContainsString( 'has a composer.lock but no composer.json at main', $this->logger->stderr );
	}
}

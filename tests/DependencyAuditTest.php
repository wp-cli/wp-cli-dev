<?php

use WP_CLI\Maintenance\Dependency_Audit;
use WP_CLI\Tests\TestCase;

class DependencyAuditTest extends TestCase {

	const SHA = 'cee1f64b6ba7df6fefe41d4e96cb20f53acf2e10';

	/**
	 * Gets the recorded output of `composer audit --locked --format=json` for
	 * the wp-cli/wp-cli-bundle lock of 2026-11-15.
	 *
	 * @return array
	 */
	private function report() {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/composer/audit.json' ), true );
	}

	/**
	 * @param array<string, string> $packages     Name => version.
	 * @param array<string, string> $dev_packages Name => version.
	 *
	 * @return array
	 */
	private function lock( array $packages, array $dev_packages = [] ) {
		$section = static function ( array $versions ) {
			$entries = [];
			foreach ( $versions as $name => $version ) {
				$entries[] = [
					'name'    => $name,
					'version' => $version,
				];
			}

			return $entries;
		};

		return [
			'packages'     => $section( $packages ),
			'packages-dev' => $section( $dev_packages ),
		];
	}

	public function test_get_locked_packages_tells_dev_packages_apart(): void {
		$locked = Dependency_Audit::get_locked_packages(
			$this->lock( [ 'wp-cli/wp-cli' => 'dev-main' ], [ 'symfony/yaml' => 'v5.4.54' ] )
		);

		$this->assertSame(
			[
				'wp-cli/wp-cli' => [
					'version' => 'dev-main',
					'dev'     => false,
				],
				'symfony/yaml'  => [
					'version' => 'v5.4.54',
					'dev'     => true,
				],
			],
			$locked
		);
	}

	public function test_get_locked_packages_accepts_a_lock_without_dev_section(): void {
		$this->assertSame(
			[
				'mustache/mustache' => [
					'version' => 'v3.0.0',
					'dev'     => false,
				],
			],
			Dependency_Audit::get_locked_packages( [ 'packages' => [ $this->lock( [ 'mustache/mustache' => 'v3.0.0' ] )['packages'][0] ] ] )
		);
	}

	public function test_advisories_carry_the_locked_version_and_whether_they_ship(): void {
		$lock = $this->lock(
			[ 'symfony/filesystem' => 'v5.4.54' ],
			[
				'symfony/config'       => 'v5.4.46',
				'symfony/yaml'         => 'v5.4.54',
				'behat/transliterator' => 'v1.5.0',
			]
		);

		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'main', self::SHA, true, $this->report(), $lock );

		$this->assertSame( Dependency_Audit::VULNERABLE, $audit['status'] );
		$this->assertSame( 'wp-cli/wp-cli-bundle', $audit['repo'] );
		$this->assertSame( 'main', $audit['ref'] );
		$this->assertSame( self::SHA, $audit['sha'] );
		$this->assertTrue( $audit['dev'] );
		$this->assertSame( [], $audit['ignored'] );
		$this->assertSame( [ 'symfony/config', 'symfony/filesystem', 'symfony/yaml' ], array_column( $audit['advisories'], 'package' ) );
		$this->assertSame( [ true, false, true ], array_column( $audit['advisories'], 'dev' ) );

		$this->assertSame(
			[
				'package'           => 'symfony/yaml',
				'version'           => 'v5.4.54',
				'dev'               => true,
				'advisory'          => 'PKSA-nktx-f1n1-b4yd',
				'cve'               => 'CVE-2026-123792',
				'title'             => 'Exponential and Quadratic Parse Times in the YAML Parser Let a Single Document Hold a Process',
				'link'              => 'https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx',
				'affected_versions' => '>=2.0.0,<3.0.0|>=3.0.0,<4.0.0|>=4.0.0,<5.0.0|>=5.0.0,<5.1.0|>=5.1.0,<5.2.0|>=5.2.0,<5.3.0|>=5.3.0,<5.4.0|>=5.4.0,<5.4.56|>=6.0.0,<6.1.0|>=6.1.0,<6.2.0|>=6.2.0,<6.3.0|>=6.3.0,<6.4.0|>=6.4.0,<6.4.50|>=7.0.0,<7.1.0|>=7.1.0,<7.2.0|>=7.2.0,<7.3.0|>=7.3.0,<7.4.0|>=7.4.0,<7.4.23|>=8.0.0,<8.1.0|>=8.1.0,<8.1.11',
				'severity'          => null,
				'reported_at'       => '2026-11-04T10:45:07+00:00',
				'ignore_reason'     => null,
			],
			$audit['advisories'][2]
		);

		// symfony/filesystem has no CVE.
		$this->assertNull( $audit['advisories'][1]['cve'] );
	}

	public function test_abandoned_packages_name_their_replacement(): void {
		$report = [
			'advisories' => [],
			'abandoned'  => [
				'behat/transliterator' => null,
				'symfony/debug'        => 'symfony/error-handler',
			],
		];
		$lock   = $this->lock( [], [ 'symfony/debug' => 'v4.4.44' ] );

		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'v2.12.0', self::SHA, true, $report, $lock );

		$this->assertSame( Dependency_Audit::ABANDONED, $audit['status'] );
		$this->assertSame( [], $audit['advisories'] );
		$this->assertSame(
			[
				[
					'package'     => 'behat/transliterator',
					'version'     => null,
					'dev'         => null,
					'replacement' => null,
				],
				[
					'package'     => 'symfony/debug',
					'version'     => 'v4.4.44',
					'dev'         => true,
					'replacement' => 'symfony/error-handler',
				],
			],
			$audit['abandoned']
		);
	}

	public function test_a_report_without_findings_is_ok(): void {
		// Composer encodes empty sections as JSON arrays, not objects.
		$report = json_decode( '{"advisories":[],"abandoned":[]}', true );

		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'main', self::SHA, true, $report, $this->lock( [ 'wp-cli/wp-cli' => 'dev-main' ] ) );

		$this->assertSame( Dependency_Audit::OK, $audit['status'] );
		$this->assertSame( [], $audit['advisories'] );
		$this->assertSame( [], $audit['abandoned'] );
	}

	public function test_a_report_from_a_composer_without_abandoned_section_is_read(): void {
		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'main', self::SHA, false, [ 'advisories' => [] ], $this->lock( [] ) );

		$this->assertSame( Dependency_Audit::OK, $audit['status'] );
	}

	public function test_ignored_advisories_are_reported_but_do_not_make_the_lock_vulnerable(): void {
		$report                       = $this->report();
		$report['ignored-advisories'] = [ 'symfony/yaml' => $report['advisories']['symfony/yaml'] ];
		$report['ignored-advisories']['symfony/yaml'][0]['ignoreReason'] = 'Only parses our own YAML.';
		$report['advisories'] = [];
		$report['abandoned']  = [];

		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'main', self::SHA, true, $report, $this->lock( [], [ 'symfony/yaml' => 'v5.4.54' ] ) );

		$this->assertSame( Dependency_Audit::OK, $audit['status'] );
		$this->assertSame( [ 'PKSA-nktx-f1n1-b4yd' ], array_column( $audit['ignored'], 'advisory' ) );
		$this->assertSame( 'Only parses our own YAML.', $audit['ignored'][0]['ignore_reason'] );
		$this->assertSame( 'v5.4.54', $audit['ignored'][0]['version'] );
	}

	public function test_advisories_of_one_package_are_sorted_by_id(): void {
		$report = [
			'advisories' => [
				'composer/composer' => [
					[ 'advisoryId' => 'PKSA-zzzz' ],
					[ 'advisoryId' => 'PKSA-aaaa' ],
				],
				'behat/behat'       => [
					[ 'advisoryId' => 'PKSA-mmmm' ],
				],
			],
		];

		$audit = Dependency_Audit::assess( 'wp-cli/wp-cli-bundle', 'v2.12.0', self::SHA, true, $report, $this->lock( [] ) );

		$this->assertSame( [ 'PKSA-mmmm', 'PKSA-aaaa', 'PKSA-zzzz' ], array_column( $audit['advisories'], 'advisory' ) );
	}
}

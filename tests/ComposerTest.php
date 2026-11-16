<?php

use WP_CLI\Maintenance\Composer;
use WP_CLI\Tests\TestCase;

class ComposerTest extends TestCase {

	public function test_the_environment_cannot_change_what_composer_does(): void {
		$env = Composer::get_env(
			[
				'PATH'                       => '/usr/bin',
				'COMPOSER'                   => '/elsewhere/composer.json',
				'COMPOSER_NO_DEV'            => '1',
				'COMPOSER_NO_AUDIT'          => '1',
				'COMPOSER_AUDIT_ABANDONED'   => 'ignore',
				'COMPOSER_PREFER_LOWEST'     => '1',
				'COMPOSER_WITH_DEPENDENCIES' => '1',
				'COMPOSER_AUTH'              => '{"http-basic":{}}',
				'COMPOSER_HOME'              => '/home/me/.composer',
				'COMPOSER_CACHE_DIR'         => '/home/me/.cache/composer',
			],
			'/tmp/composer/home'
		);

		$this->assertSame(
			[
				'PATH'               => '/usr/bin',
				'COMPOSER_HOME'      => '/tmp/composer/home',
				'COMPOSER_CACHE_DIR' => '/home/me/.cache/composer',
			],
			$env
		);
	}

	public function test_the_github_token_is_handed_to_composer(): void {
		$env = Composer::get_env( [], '/tmp/composer/home', 'test-token' );

		$this->assertSame(
			[ 'github-oauth' => [ 'github.com' => 'test-token' ] ],
			json_decode( $env['COMPOSER_AUTH'], true )
		);
	}
}

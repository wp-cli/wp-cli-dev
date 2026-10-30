<?php

use WP_CLI\Maintenance\GitHub;
use WP_CLI\Tests\TestCase;

class GitHubTest extends TestCase {

	/**
	 * @param array<string, string> $variables Environment variables.
	 *
	 * @return callable
	 */
	private function env( array $variables ) {
		return static function ( $name ) use ( $variables ) {
			return isset( $variables[ $name ] ) ? $variables[ $name ] : false;
		};
	}

	public function test_resolve_auth_prefers_the_github_cli(): void {
		$auth = GitHub::resolve_auth(
			static function () {
				return 'gh-token';
			},
			$this->env( [ 'GITHUB_TOKEN' => 'env-token' ] )
		);

		$this->assertSame(
			[
				'token'  => 'gh-token',
				'source' => 'gh',
			],
			$auth
		);
	}

	public function test_resolve_auth_falls_back_to_gh_token_then_github_token(): void {
		$no_gh = static function () {
			return null;
		};

		$this->assertSame(
			[
				'token'  => 'gh-env-token',
				'source' => 'GH_TOKEN',
			],
			GitHub::resolve_auth(
				$no_gh,
				$this->env(
					[
						'GH_TOKEN'     => 'gh-env-token',
						'GITHUB_TOKEN' => 'env-token',
					]
				)
			)
		);

		$this->assertSame(
			[
				'token'  => 'env-token',
				'source' => 'GITHUB_TOKEN',
			],
			GitHub::resolve_auth( $no_gh, $this->env( [ 'GITHUB_TOKEN' => 'env-token' ] ) )
		);
	}

	public function test_resolve_auth_without_credentials(): void {
		$this->assertSame(
			[
				'token'  => null,
				'source' => null,
			],
			GitHub::resolve_auth(
				static function () {
					return null;
				},
				$this->env( [ 'GITHUB_TOKEN' => '' ] )
			)
		);
	}

	public function test_qualify_repo_adds_the_org_only_when_missing(): void {
		$this->assertSame( 'wp-cli/entity-command', GitHub::qualify_repo( 'entity-command' ) );
		$this->assertSame( 'someone/entity-command', GitHub::qualify_repo( 'someone/entity-command' ) );
	}

	public function test_get_next_page_url_reads_the_link_header(): void {
		$this->assertSame(
			'https://api.github.com/repositories/1/issues?page=3',
			GitHub::get_next_page_url(
				'<https://api.github.com/repositories/1/issues?page=1>; rel="prev", <https://api.github.com/repositories/1/issues?page=3>; rel="next", <https://api.github.com/repositories/1/issues?page=5>; rel="last"'
			)
		);
		$this->assertNull( GitHub::get_next_page_url( '<https://api.github.com/repositories/1/issues?page=1>; rel="prev"' ) );
		$this->assertNull( GitHub::get_next_page_url( '' ) );
	}

	public function test_get_error_code_classifies_failures(): void {
		$this->assertSame( 'auth_failed', GitHub::get_error_code( 401, 'Bad credentials' ) );
		$this->assertSame( 'rate_limited', GitHub::get_error_code( 403, 'API rate limit exceeded for user ID 1.', '0' ) );
		$this->assertSame( 'rate_limited', GitHub::get_error_code( 403, 'You have exceeded a secondary rate limit.', '4000' ) );
		$this->assertSame( 'rate_limited', GitHub::get_error_code( 429, 'Too many requests' ) );
		$this->assertSame( 'forbidden', GitHub::get_error_code( 403, 'Resource not accessible by integration', '4000' ) );
		$this->assertSame( 'not_found', GitHub::get_error_code( 404, 'Not Found' ) );
		$this->assertSame( 'validation_failed', GitHub::get_error_code( 422, 'Validation Failed' ) );
		$this->assertSame( 'http_error', GitHub::get_error_code( 502, 'Bad Gateway' ) );
	}

	public function test_parse_contributors_skips_bots(): void {
		$pull_requests = [
			(object) [
				'user' => (object) [
					'login'    => 'swissspidy',
					'type'     => 'User',
					'html_url' => 'https://github.com/swissspidy',
				],
			],
			(object) [
				'user' => (object) [
					'login'    => 'github-actions[bot]',
					'type'     => 'Bot',
					'html_url' => 'https://github.com/apps/github-actions',
				],
			],
			(object) [
				'user' => (object) [
					'login'    => 'Copilot',
					'type'     => 'Bot',
					'html_url' => 'https://github.com/apps/copilot-swe-agent',
				],
			],
		];

		$this->assertSame(
			[ 'https://github.com/swissspidy' => 'swissspidy' ],
			GitHub::parse_contributors_from_pull_requests( $pull_requests )
		);
	}
}

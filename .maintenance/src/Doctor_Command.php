<?php namespace WP_CLI\Maintenance;

use cli\Shell;
use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

final class Doctor_Command {

	/**
	 * Checks whether this environment can run the maintenance commands.
	 *
	 * Reports which GitHub credentials are in use and for which account, the
	 * remaining API rate limit, and whether the tools the maintenance scripts
	 * rely on are available. Exits with an error code when a check fails.
	 *
	 * Credentials come from the GitHub CLI when it is logged in, and from the
	 * GH_TOKEN or GITHUB_TOKEN environment variables otherwise.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a specific format. Defaults to a table in a terminal
	 * and to JSON otherwise.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp maintenance doctor
	 *     +-------------+--------+---------------------------------------------+
	 *     | check       | status | message                                     |
	 *     +-------------+--------+---------------------------------------------+
	 *     | credentials | ok     | Using the login of the GitHub CLI (gh).     |
	 *     | account     | ok     | Authenticated as octocat.                   |
	 *     ...
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$format = Utils\get_flag_value( $assoc_args, 'format', Shell::isPiped() ? 'json' : 'table' );

		$checks = array_merge( $this->check_github(), $this->check_tools() );

		Utils\format_items( $format, $checks, [ 'check', 'status', 'message' ] );

		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	}

	private function check_github() {
		$auth = GitHub::get_auth();

		if ( ! $auth['token'] ) {
			return [
				$this->result( 'credentials', 'error', 'No GitHub credentials found. Log in with `gh auth login`, or set GH_TOKEN or GITHUB_TOKEN.' ),
				$this->check_rate_limit(),
			];
		}

		$checks = [
			$this->result(
				'credentials',
				'ok',
				'gh' === $auth['source']
					? 'Using the login of the GitHub CLI (gh).'
					: "Using the {$auth['source']} environment variable."
			),
		];

		$result = GitHub::request( GitHub::API_ROOT . 'user', [], 'GET', [ 401, 403 ] );

		if ( ! $result ) {
			$checks[] = $this->result( 'account', 'error', 'GitHub rejected the credentials.' );
			return $checks;
		}

		list( $user, $headers ) = $result;

		$checks[] = $this->result( 'account', 'ok', "Authenticated as {$user->login}." );
		$checks[] = $this->check_scopes( $headers['x-oauth-scopes'] );
		$checks[] = $this->check_rate_limit();

		return $checks;
	}

	private function check_scopes( $scopes ) {
		// Fine-grained tokens and GitHub App tokens do not report scopes.
		if ( null === $scopes ) {
			return $this->result( 'scopes', 'ok', 'Token does not report scopes; access is checked per repository.' );
		}

		$scopes = array_map( 'trim', explode( ',', $scopes ) );

		if ( ! array_intersect( [ 'repo', 'public_repo' ], $scopes ) ) {
			return $this->result( 'scopes', 'error', 'Token lacks the `repo` or `public_repo` scope, so it cannot change repositories. Run `gh auth refresh -s repo`.' );
		}

		return $this->result( 'scopes', 'ok', 'Token scopes: ' . implode( ', ', $scopes ) . '.' );
	}

	private function check_rate_limit() {
		list( $rate_limit ) = GitHub::request( GitHub::API_ROOT . 'rate_limit' );

		$core    = $rate_limit->resources->core;
		$message = sprintf(
			'%d of %d requests left, resets at %s.',
			$core->remaining,
			$core->limit,
			gmdate( 'H:i:s \U\T\C', $core->reset )
		);

		if ( 0 === $core->remaining ) {
			return $this->result( 'rate-limit', 'error', $message );
		}

		return $this->result( 'rate-limit', $core->remaining < 500 ? 'warning' : 'ok', $message );
	}

	private function check_tools() {
		$tools = [
			'git'      => [ 'error', 'Needed to work with the repositories.' ],
			'gh'       => [ 'warning', 'Optional; without it, credentials come from GH_TOKEN or GITHUB_TOKEN.' ],
			'jq'       => [ 'warning', 'Needed by the scripts that clone and update the repositories.' ],
			'composer' => [ 'warning', 'Needed to install the development environment.' ],
		];

		$checks = [];
		foreach ( $tools as $tool => list( $missing_status, $purpose ) ) {
			$found = 0 === Process::create( 'command -v ' . escapeshellarg( $tool ), null, null )->run()->return_code;

			$checks[] = $found
				? $this->result( $tool, 'ok', 'Installed.' )
				: $this->result( $tool, $missing_status, "Not installed. {$purpose}" );
		}

		return $checks;
	}

	private function result( $check, $status, $message ) {
		return [
			'check'   => $check,
			'status'  => $status,
			'message' => $message,
		];
	}
}

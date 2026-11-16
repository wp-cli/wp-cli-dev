<?php namespace WP_CLI\Maintenance;

use cli\Shell;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Checks the locked dependencies of the repositories that commit a
 * composer.lock, such as wp-cli/wp-cli-bundle.
 */
final class Dependencies_Command {

	/**
	 * Checks locked dependencies for known security advisories.
	 *
	 * Runs `composer audit` against the composer.lock of each repository, as
	 * committed at the given ref, without installing anything. This is the
	 * check the Code Quality workflow runs, so a repository it reports as
	 * vulnerable also fails there.
	 *
	 * Packages only required for development are marked as such, as they do
	 * not ship in the Phar. Abandoned packages are reported too, and so are
	 * advisories the composer.json of the repository ignores.
	 *
	 * Exits with an error code when any locked package is affected by an
	 * advisory that is not ignored. Abandoned packages alone do not fail the
	 * audit.
	 *
	 * Needs Composer 2.7 or later. Its global configuration and environment
	 * variables such as COMPOSER_NO_DEV are left out of the audit.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Name(s) of the repository to audit. If no user/org was provided,
	 * 'wp-cli' org is assumed. Defaults to wp-cli/wp-cli-bundle. Repositories
	 * without a composer.lock are skipped with a warning.
	 *
	 * [--ref=<ref>]
	 * : Branch, tag or commit to audit, such as a release tag. Defaults to the
	 * default branch of each repository.
	 *
	 * [--[no-]dev]
	 * : Whether to audit the packages that are only required for development
	 * as well. Pass `--no-dev` to audit only what ships.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a specific format. Defaults to a table in a terminal
	 * and to JSON otherwise. The JSON also holds the commit that was audited,
	 * and the affected version ranges and links of the advisories.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp maintenance dependencies audit
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     | repo                 | package              | version | dev | advisory            | cve             | title                                |
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     | wp-cli/wp-cli-bundle | symfony/yaml         | v5.4.54 | yes | PKSA-nktx-f1n1-b4yd | CVE-2026-123792 | Exponential and Quadratic Parse ...  |
	 *     | wp-cli/wp-cli-bundle | behat/transliterator | v1.5.0  | yes | abandoned           |                 | Abandoned, no replacement suggested. |
	 *     +----------------------+----------------------+---------+-----+---------------------+-----------------+--------------------------------------+
	 *     Error: Locked packages are affected by security advisories in wp-cli/wp-cli-bundle at main.
	 *
	 *     # Audit only what ships in the Phar.
	 *     $ wp maintenance dependencies audit --no-dev
	 *     Success: No known vulnerabilities or abandoned packages in wp-cli/wp-cli-bundle at main.
	 *
	 * @when before_wp_load
	 */
	public function audit( $args, $assoc_args ) {
		$format = Utils\get_flag_value( $assoc_args, 'format', Shell::isPiped() ? 'json' : 'table' );
		$ref    = Utils\get_flag_value( $assoc_args, 'ref', null );
		$dev    = (bool) Utils\get_flag_value( $assoc_args, 'dev', true );
		$repos  = $args ? array_values( array_unique( array_map( [ GitHub::class, 'qualify_repo' ], $args ) ) ) : [ Bundle::REPO ];

		$audits = [];
		foreach ( $repos as $repo ) {
			$audit = Dependency_Audit::fetch( $repo, $ref, $dev );

			if ( null === $audit ) {
				WP_CLI::warning( "{$repo} has no composer.lock" . ( $ref ? " at {$ref}" : '' ) . ', skipping it.' );
				continue;
			}

			$audits[] = $audit;
		}

		if ( ! $audits ) {
			WP_CLI::error( 'None of the repositories has a composer.lock to audit.' );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) json_encode( $audits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$this->render_table( $audits );
		}

		$vulnerable = [];
		foreach ( $audits as $audit ) {
			if ( Dependency_Audit::VULNERABLE === $audit['status'] ) {
				$vulnerable[] = "{$audit['repo']} at {$audit['ref']}";
			}
		}

		if ( $vulnerable ) {
			WP_CLI::error( 'Locked packages are affected by security advisories in ' . implode( ', ', $vulnerable ) . '.' );
		}
	}

	private function render_table( array $audits ) {
		$rows = [];
		foreach ( $audits as $audit ) {
			foreach ( $audit['advisories'] as $advisory ) {
				$rows[] = $this->row( $audit['repo'], $advisory, $advisory['advisory'], $advisory['cve'], $advisory['title'] );
			}

			foreach ( $audit['ignored'] as $advisory ) {
				$rows[] = $this->row(
					$audit['repo'],
					$advisory,
					$advisory['advisory'],
					$advisory['cve'],
					'Ignored' . ( $advisory['ignore_reason'] ? " ({$advisory['ignore_reason']})" : '' ) . ": {$advisory['title']}"
				);
			}

			foreach ( $audit['abandoned'] as $abandoned ) {
				$rows[] = $this->row(
					$audit['repo'],
					$abandoned,
					'abandoned',
					null,
					$abandoned['replacement']
						? "Abandoned, use {$abandoned['replacement']} instead."
						: 'Abandoned, no replacement suggested.'
				);
			}
		}

		if ( $rows ) {
			Utils\format_items( 'table', $rows, [ 'repo', 'package', 'version', 'dev', 'advisory', 'cve', 'title' ] );
		}

		foreach ( $audits as $audit ) {
			if ( Dependency_Audit::OK === $audit['status'] ) {
				WP_CLI::success( "No known vulnerabilities or abandoned packages in {$audit['repo']} at {$audit['ref']}." );
			}
		}
	}

	private function row( $repo, array $package, $advisory, $cve, $title ) {
		// A package the lock does not list may or may not ship.
		$dev = '';
		if ( null !== $package['dev'] ) {
			$dev = $package['dev'] ? 'yes' : 'no';
		}

		return [
			'repo'     => $repo,
			'package'  => $package['package'],
			'version'  => $package['version'],
			'dev'      => $dev,
			'advisory' => $advisory,
			'cve'      => $cve,
			'title'    => $title,
		];
	}
}

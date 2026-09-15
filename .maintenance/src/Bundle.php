<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

/**
 * What a wp-cli/wp-cli-bundle release ships, read from its composer.lock.
 */
final class Bundle {

	const REPO = 'wp-cli/wp-cli-bundle';

	/**
	 * Repositories whose milestones follow the bundle release itself rather
	 * than their own package releases.
	 */
	const RELEASE_REPOS = [
		'wp-cli/wp-cli-bundle',
		'wp-cli/wp-cli',
		'wp-cli/handbook',
	];

	/**
	 * Bundled packages that are not named `*-command`.
	 */
	const OTHER_PACKAGES = [
		'wp-cli/wp-cli-tests',
		'wp-cli/regenerate-readme',
		'wp-cli/autoload-splitter',
		'wp-cli/wp-config-transformer',
		'wp-cli/php-cli-tools',
		'wp-cli/mustangostang-spyc',
	];

	/**
	 * Gets the milestone of a release repository that belongs to a bundle
	 * release: the one titled after the release, or the lowest open one when
	 * no release is given.
	 *
	 * @param string      $repo    Repository name.
	 * @param string|null $release Bundle release version, e.g. '3.0.0'.
	 *
	 * @return object|null
	 */
	public static function get_release_milestone( $repo, $release = null ) {
		if ( $release ) {
			$release = ltrim( $release, 'v' );

			foreach ( GitHub::get_project_milestones( $repo, [ 'state' => 'all' ] ) as $milestone ) {
				if ( ltrim( $milestone->title, 'v' ) === $release ) {
					return $milestone;
				}
			}

			WP_CLI::warning( "Couldn't find milestone '{$release}' in repository '{$repo}'." );

			return null;
		}

		return array_reduce(
			GitHub::get_project_milestones( $repo ),
			static function ( $lowest, $milestone ) {
				if ( null === $lowest ) {
					return $milestone;
				}

				return version_compare( $milestone->title, $lowest->title, '<' ) ? $milestone : $lowest;
			}
		);
	}

	/**
	 * Gets the ref and composer.lock that describe the packages of a bundle
	 * release.
	 *
	 * @param string|null $release    Bundle release version, e.g. '3.0.0'. Its
	 *                                tag is used when it exists already.
	 * @param string|null $bundle_ref Explicit ref; wins over the release tag.
	 *
	 * @return array{0: string, 1: array} Ref and decoded lockfile.
	 */
	public static function get_release_lock( $release = null, $bundle_ref = null ) {
		if ( $bundle_ref ) {
			return [ $bundle_ref, self::get_lock( $bundle_ref ) ];
		}

		if ( $release ) {
			$tag  = 'v' . ltrim( $release, 'v' );
			$lock = self::get_lock( $tag, false );

			if ( $lock ) {
				return [ $tag, $lock ];
			}

			WP_CLI::debug( "Tag {$tag} does not exist in " . self::REPO . ' yet, using the default branch.', 'bundle' );
		}

		$branch = GitHub::get_default_branch( self::REPO );

		return [ $branch, self::get_lock( $branch ) ];
	}

	/**
	 * Gets the ref and composer.lock of the bundle release before the given
	 * one, i.e. the highest closed milestone below it.
	 *
	 * @param string|null $release Bundle release version, e.g. '3.0.0'. Without
	 *                             it, the highest closed milestone is used.
	 *
	 * @return array{0: string|null, 1: array|null} Ref and decoded lockfile,
	 *                                              both null without a
	 *                                              previous release.
	 */
	public static function get_previous_release_lock( $release = null ) {
		$previous = self::find_previous_release(
			GitHub::get_project_milestones( self::REPO, [ 'state' => 'closed' ] ),
			$release
		);

		if ( null === $previous ) {
			return [ null, null ];
		}

		$tag = "v{$previous}";

		return [ $tag, self::get_lock( $tag ) ];
	}

	/**
	 * Picks the highest release version below the given one out of a list of
	 * milestones. Milestones whose title is not a version are ignored.
	 *
	 * @param array       $milestones Milestone objects with a `title`.
	 * @param string|null $release    Bundle release version, e.g. '3.0.0'.
	 *                                Without it, the highest version wins.
	 *
	 * @return string|null Version without leading 'v', or null when there is
	 *                     no release below the given one.
	 */
	public static function find_previous_release( array $milestones, $release = null ) {
		$release  = $release ? ltrim( $release, 'v' ) : null;
		$previous = null;

		foreach ( $milestones as $milestone ) {
			$title = ltrim( $milestone->title, 'v' );

			if ( ! self::is_version( $title ) ) {
				continue;
			}

			if ( $release && ! version_compare( $title, $release, '<' ) ) {
				continue;
			}

			if ( null === $previous || version_compare( $title, $previous, '>' ) ) {
				$previous = $title;
			}
		}

		return $previous;
	}

	/**
	 * Fetches and decodes the composer.lock of wp-cli/wp-cli-bundle at a ref.
	 *
	 * @param string $ref              Branch, tag or commit.
	 * @param bool   $missing_is_fatal Whether a ref without a lockfile (HTTP
	 *                                 404) is fatal. Any other failure always
	 *                                 is, so that a rate limit or an outage
	 *                                 never passes for a missing tag.
	 *
	 * @return array|false Decoded lockfile, or false when the ref has none
	 *                     and that is not fatal.
	 */
	public static function get_lock( $ref, $missing_is_fatal = true ) {
		$url      = sprintf( 'https://raw.githubusercontent.com/%s/%s/composer.lock', self::REPO, $ref );
		$response = Utils\http_request( 'GET', $url );

		if ( 404 === (int) $response->status_code && ! $missing_is_fatal ) {
			return false;
		}

		if ( 200 !== (int) $response->status_code ) {
			WP_CLI::error( sprintf( 'Could not fetch %s (HTTP code %d)', $url, $response->status_code ) );
		}

		return json_decode( $response->body, true );
	}

	/**
	 * Lists the bundled packages of a lockfile with their versions.
	 *
	 * @param array $lock Decoded composer.lock.
	 *
	 * @return array<string, string> Package name => version without leading 'v',
	 *                               sorted by name.
	 */
	public static function get_packages( array $lock ) {
		$packages = [];

		foreach ( [ 'packages', 'packages-dev' ] as $section ) {
			if ( empty( $lock[ $section ] ) ) {
				continue;
			}

			foreach ( $lock[ $section ] as $package ) {
				if ( ! self::is_bundled_package( $package['name'] ) ) {
					continue;
				}

				$packages[ $package['name'] ] = ltrim( $package['version'], 'v' );
			}
		}

		ksort( $packages );

		return $packages;
	}

	/**
	 * Gets the closed milestones of a package that shipped in a bundle
	 * release: newer than the version the previous release locked, and not
	 * newer than the version this release locks. A package without a
	 * previous version is newly bundled, so all of its releases count.
	 *
	 * @param string      $package          Package name.
	 * @param string|null $previous_version Version locked by the previous release.
	 * @param string      $current_version  Version locked by this release.
	 *
	 * @return array
	 */
	public static function get_shipped_milestones( $package, $previous_version, $current_version ) {
		return self::filter_shipped_milestones(
			GitHub::get_project_milestones( $package, [ 'state' => 'closed' ] ),
			$previous_version,
			$current_version
		);
	}

	/**
	 * Keeps the milestones whose version lies in (previous, current]. A
	 * version that cannot be compared, such as `dev-main`, does not bound;
	 * milestones whose title is not a version are dropped.
	 *
	 * @param array       $milestones       Milestone objects with a `title`.
	 * @param string|null $previous_version Version locked by the previous release.
	 * @param string|null $current_version  Version locked by this release.
	 *
	 * @return array
	 */
	public static function filter_shipped_milestones( array $milestones, $previous_version, $current_version ) {
		return array_values(
			array_filter(
				$milestones,
				static function ( $milestone ) use ( $previous_version, $current_version ) {
					$title = ltrim( $milestone->title, 'v' );

					if ( ! self::is_version( $title ) ) {
						return false;
					}

					if ( self::is_version( $previous_version )
						&& ! version_compare( $title, $previous_version, '>' ) ) {
						return false;
					}

					if ( self::is_version( $current_version )
						&& version_compare( $title, $current_version, '>' ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Checks whether a package's contributors and release notes belong to a
	 * bundle release.
	 *
	 * @param string $name Package name.
	 *
	 * @return bool
	 */
	public static function is_bundled_package( $name ) {
		return (bool) preg_match( '#^wp-cli/.+-command$#', $name )
			|| in_array( $name, self::OTHER_PACKAGES, true );
	}

	/**
	 * Checks whether a string is a version that can be compared, such as
	 * `2.12.0` or `3.0.0-beta1`, as opposed to `dev-main` or `3.0.0 (docs)`.
	 *
	 * @param string|null $version Version string, or null when unknown.
	 *
	 * @return bool
	 */
	public static function is_version( $version ) {
		return null !== $version && (bool) preg_match( '/^\d+(\.\d+)*(-[0-9A-Za-z.]+)?$/', $version );
	}
}

<?php

namespace WP_CLI\Maintenance\Tests;

use RuntimeException;
use WpOrg\Requests\Transport;

/**
 * Requests transport that hands every request to the running test instead of
 * sending it. See GitHub_TestCase.
 */
final class Fake_Transport implements Transport {

	/**
	 * Receives the HTTP method, URL and data of each request, and returns the
	 * raw HTTP response.
	 *
	 * @var callable|null
	 */
	public static $handler;

	/**
	 * Makes WP-CLI's HTTP requests use this transport while a test handles them.
	 *
	 * @param array $options Requests options.
	 *
	 * @return array
	 */
	public static function use_for_request( $options ) {
		if ( null !== self::$handler ) {
			$options['transport'] = self::class;
		}

		return $options;
	}

	public function request( $url, $headers = [], $data = [], $options = [] ) {
		if ( null === self::$handler ) {
			throw new RuntimeException( "No test is handling the request to {$url}." );
		}

		return call_user_func( self::$handler, $options['type'], $url, $data );
	}

	public function request_multiple( $requests, $options ) {
		throw new RuntimeException( 'Parallel requests are not supported.' );
	}

	public static function test( $capabilities = [] ) {
		return true;
	}
}

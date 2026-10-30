<?php

namespace WP_CLI\Maintenance\Tests;

use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use WP_CLI;
use WP_CLI\Loggers\Execution;
use WP_CLI\Maintenance\GitHub;
use WP_CLI\Tests\TestCase;

/**
 * Runs the maintenance code against a fake GitHub API.
 *
 * Requests go through WP-CLI's HTTP layer as usual, but reach a fake transport
 * that answers from the responses each test registers, and fails the test on
 * any request it did not expect. Response bodies come from the recordings in
 * tests/fixtures/github, see update.php there.
 */
abstract class GitHub_TestCase extends TestCase {

	/**
	 * Requests made during the test, as "METHOD URL" strings.
	 *
	 * @var string[]
	 */
	protected $requests = [];

	/**
	 * Decoded bodies of the write requests, keyed like $requests.
	 *
	 * @var array<int, mixed>
	 */
	protected $sent_data = [];

	/**
	 * Output of WP_CLI::log() and friends during the test.
	 *
	 * @var Execution
	 */
	protected $logger;

	/**
	 * Registered responses, matched in order.
	 *
	 * @var array<int, array{method: string, path: string, query: array, status: int, headers: array, body: mixed}>
	 */
	private $responses = [];

	/**
	 * @var array<string, mixed>
	 */
	private $original_statics = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// The HTTP layer of WP-CLI is not part of its Composer autoloader.
		$wp_cli_root = dirname( (string) ( new ReflectionClass( WP_CLI::class ) )->getFileName(), 2 );
		require_once "{$wp_cli_root}/php/utils.php";
		require_once "{$wp_cli_root}/bundle/rmccue/requests/src/Autoload.php";
		\WpOrg\Requests\Autoload::register();

		WP_CLI::add_hook( 'http_request_options', [ Fake_Transport::class, 'use_for_request' ] );
	}

	protected function set_up(): void {
		parent::set_up();

		Fake_Transport::$handler = [ $this, 'handle_request' ];

		$this->logger = new Execution();
		// Loggers read the --debug flag from the runner's configuration, which
		// only a real command run fills in.
		$runner = WP_CLI::get_runner();
		$config = new ReflectionProperty( $runner, 'config' );
		if ( PHP_VERSION_ID < 80100 ) {
			$config->setAccessible( true );
		}
		$config->setValue( $runner, array_merge( [ 'debug' => false ], (array) $config->getValue( $runner ) ) );
		$this->swap_static( WP_CLI::class, 'logger', $this->logger );
		$this->swap_static( WP_CLI::class, 'capture_exit', true );
		$this->swap_static(
			GitHub::class,
			'auth',
			[
				'token'  => 'test-token',
				'source' => 'GH_TOKEN',
			]
		);
		$this->swap_static( GitHub::class, 'write_interval', null );
		$this->swap_static( GitHub::class, 'last_write', 0.0 );
		GitHub::set_write_interval( 0 );
	}

	protected function tear_down(): void {
		foreach ( $this->original_statics as $key => $value ) {
			list( $class_name, $property ) = explode( '::', $key );
			$this->static_property( $class_name, $property )->setValue( null, $value );
		}

		Fake_Transport::$handler = null;

		parent::tear_down();
	}

	/**
	 * Registers the response to a request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path below the API root, with any query arguments
	 *                        the request must contain.
	 * @param mixed  $body    Response body, encoded as JSON.
	 * @param int    $status  HTTP status.
	 * @param array  $headers Response headers.
	 */
	protected function respond( $method, $path, $body, $status = 200, array $headers = [] ) {
		$parts = explode( '?', $path, 2 );
		parse_str( isset( $parts[1] ) ? $parts[1] : '', $query );

		$this->responses[] = [
			'method'  => $method,
			'path'    => '/' . $parts[0],
			'query'   => $query,
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
		];
	}

	/**
	 * Gets the body of a recorded response.
	 *
	 * @param string $name Fixture name, see tests/fixtures/github/update.php.
	 *
	 * @return mixed Decoded JSON.
	 */
	protected function fixture( $name ) {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/github/{$name}.json" ) );

		return $fixture->body;
	}

	/**
	 * Takes a recorded object and overrides some of its fields.
	 *
	 * @param string $name      Fixture name.
	 * @param int    $index     Index of the object, for fixtures holding a list.
	 * @param array  $overrides Field => value, objects given as arrays.
	 *
	 * @return object
	 */
	protected function fixture_object( $name, $index, array $overrides ) {
		$body   = $this->fixture( $name );
		$object = null === $index ? $body : $body[ $index ];

		foreach ( $overrides as $field => $value ) {
			$object->$field = is_array( $value ) ? json_decode( (string) json_encode( $value ) ) : $value;
		}

		return $object;
	}

	/**
	 * Answers a request from the fake transport.
	 *
	 * @param string $method
	 * @param string $url
	 * @param mixed  $data
	 *
	 * @return string Raw HTTP response.
	 */
	public function handle_request( $method, $url, $data ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		// Requests hands the arguments of a GET request over separately.
		if ( 'GET' === $method && is_array( $data ) && $data ) {
			$query = array_merge( $query, $data );
			$url   = strtok( $url, '?' ) . '?' . http_build_query( $query );
			$data  = null;
		}

		$this->requests[]  = "{$method} {$url}";
		$this->sent_data[] = is_string( $data ) ? json_decode( $data ) : $data;

		foreach ( $this->responses as $response ) {
			if ( $response['method'] !== $method || $response['path'] !== $path ) {
				continue;
			}

			if ( array_intersect_assoc( $response['query'], $query ) !== $response['query'] ) {
				continue;
			}

			$headers = [ 'Content-Type: application/json' ];
			foreach ( $response['headers'] as $name => $value ) {
				$headers[] = "{$name}: {$value}";
			}

			return "HTTP/1.1 {$response['status']} Status\r\n" . implode( "\r\n", $headers ) . "\r\n\r\n" . json_encode( $response['body'] );
		}

		throw new RuntimeException( "Unexpected request: {$method} {$url}" );
	}

	/**
	 * Sets a static property for the duration of the test.
	 *
	 * @param string $class_name
	 * @param string $property
	 * @param mixed  $value
	 */
	private function swap_static( $class_name, $property, $value ) {
		$reflection = $this->static_property( $class_name, $property );

		$this->original_statics[ "{$class_name}::{$property}" ] = $reflection->getValue();
		$reflection->setValue( null, $value );
	}

	private function static_property( $class_name, $property ) {
		$reflection = new ReflectionProperty( $class_name, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection;
	}
}

<?php
/**
 * Lightweight behavioral contract for bounded frontend cache batching.
 *
 * @package Devenia_Workflow
 */

namespace WpOrg\Requests {
	final class Capability {
		public const SSL = 'ssl';
	}

	final class Response {
		public string $body = '';
		public array $headers = array();
		public int $status_code = 200;
		public string $url = '';
	}

	final class Requests {
		public const GET = 'GET';
		public static array $calls = array();
		public static string $mode = 'normal';

		public static function request_multiple( array $requests, array $options = array() ): array {
			self::$calls[] = array( 'requests' => $requests, 'options' => $options );
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( 'whole-call failure' );
			}
			$responses = array();
			foreach ( $requests as $key => $request ) {
				if ( 'request_7' === $key ) {
					$responses[ $key ] = new \RuntimeException( 'fixture failure' );
					continue;
				}
				$response = new Response();
				$response->body = 'body:' . $key;
				$response->headers = array( 'cf-cache-status' => 'DYNAMIC', 'age' => '3' );
				$response->status_code = 200;
				$response->url = (string) $request['url'];
				$responses[ $key ] = $response;
			}
			if ( 'missing' === self::$mode ) { unset( $responses['request_3'] ); }
			if ( 'extra' === self::$mode ) { $responses['foreign_result'] = new Response(); }
			return $responses;
		}
	}
}

namespace WpOrg\Requests\Transport {
	final class Curl {
		public static bool $available = true;

		public static function test( array $capabilities = array() ): bool {
			return self::$available && true === ( $capabilities['ssl'] ?? false );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WPINC', 'wp-includes' );

	final class WP_HTTP_Requests_Response {
		private \WpOrg\Requests\Response $response;

		public function __construct( \WpOrg\Requests\Response $response ) {
			$this->response = $response;
		}

		public function to_array(): array {
			return array(
				'headers' => $this->response->headers,
				'body' => $this->response->body,
				'response' => array( 'code' => $this->response->status_code, 'message' => 'OK' ),
				'cookies' => array(),
				'filename' => null,
			);
		}
	}

	$GLOBALS['devenia_workflow_test_batch_adapter'] = null;

	function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.valueFound -- Minimal WordPress test stub.
		if ( 'devenia_workflow_frontend_cache_batch_adapter_result' === $hook && is_callable( $GLOBALS['devenia_workflow_test_batch_adapter'] ?? null ) ) {
			return ( $GLOBALS['devenia_workflow_test_batch_adapter'] )( $value, ...$args );
		}
		return $value;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function esc_url_raw( $url ) {
		return $url;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}

	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}

	function wp_remote_retrieve_header( $response, $name ) {
		return $response['headers'][ $name ] ?? '';
	}

	function add_query_arg( $key, $value, $url ) {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}

	function wp_generate_uuid4() {
		static $counter = 0;
		return 'fixture-' . ++$counter;
	}

	function get_bloginfo( $field ) {
		return 'version' === $field ? '7.0' : '';
	}

	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}

	require_once dirname( __DIR__ ) . '/includes/trait-localized-presentation-publication.php';

	final class Devenia_Workflow_Frontend_Cache_Batch_Harness {
		use \Devenia_Workflow_Localized_Presentation_Publication;

		public static function fetch( array $requests, int $timeout ): array {
			return self::fetch_frontend_cache_surfaces( $requests, $timeout );
		}

		private static function wp_remote_final_url( $response, string $fallback ): string {
			return $fallback;
		}
	}

	$requests = array();
	for ( $index = 0; $index < 60; $index++ ) {
		$requests[ 'request_' . $index ] = array(
			'url' => 'https://example.test/surface-' . $index . '/',
			'surface' => 0 === $index % 2 ? 'origin' : 'canonical',
		);
	}
	$responses = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	$batch_sizes = array_map( static fn( array $call ): int => count( $call['requests'] ), \WpOrg\Requests\Requests::$calls );
	if ( array( 60 ) !== $batch_sizes ) {
		fwrite( STDERR, 'Expected one complete concurrent batch: ' . json_encode( $batch_sizes ) . PHP_EOL );
		exit( 1 );
	}
	$origin_request = \WpOrg\Requests\Requests::$calls[0]['requests']['request_0'] ?? array();
	$canonical_request = \WpOrg\Requests\Requests::$calls[0]['requests']['request_1'] ?? array();
	if ( ! str_contains( (string) ( $origin_request['url'] ?? '' ), 'devenia_frontend_integrity=fixture-1' ) || 'no-cache, no-store, max-age=0' !== (string) ( $origin_request['headers']['Cache-Control'] ?? '' ) ) {
		fwrite( STDERR, "Origin cache-bypass request was not preserved.\n" );
		exit( 1 );
	}
	if ( 'https://example.test/surface-1/' !== (string) ( $canonical_request['url'] ?? '' ) || ! empty( $canonical_request['headers'] ?? array() ) ) {
		fwrite( STDERR, "Canonical cache request was not preserved.\n" );
		exit( 1 );
	}
	if ( true !== ( $responses['request_0']['success'] ?? null ) || 'body:request_0' !== ( $responses['request_0']['body'] ?? null ) || 'DYNAMIC' !== ( $responses['request_0']['cf_cache_status'] ?? null ) || '3' !== ( $responses['request_0']['age'] ?? null ) ) {
		fwrite( STDERR, "Successful batch response evidence was not preserved.\n" );
		exit( 1 );
	}
	if ( false !== ( $responses['request_7']['success'] ?? null ) || 'fixture failure' !== ( $responses['request_7']['error'] ?? null ) || 0 !== ( $responses['request_7']['status_code'] ?? null ) ) {
		fwrite( STDERR, "Failed batch response did not fail closed.\n" );
		exit( 1 );
	}
	foreach ( \WpOrg\Requests\Requests::$calls as $call ) {
		if ( 9 !== ( $call['options']['timeout'] ?? null ) || 9 !== ( $call['options']['connect_timeout'] ?? null ) || 3 !== ( $call['options']['redirects'] ?? null ) || ABSPATH . WPINC . '/certificates/ca-bundle.crt' !== ( $call['options']['verify'] ?? null ) || \WpOrg\Requests\Transport\Curl::class !== ltrim( (string) ( $call['options']['transport'] ?? '' ), '\\' ) ) {
			fwrite( STDERR, "Batch transport options changed.\n" );
			exit( 1 );
		}
	}
	foreach ( array( 'missing', 'extra' ) as $mode ) {
		\WpOrg\Requests\Requests::$mode = $mode;
		$mismatch = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
		foreach ( $mismatch as $response ) {
			if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_result_key_mismatch' !== ( $response['code'] ?? null ) ) {
				fwrite( STDERR, "Batch key mismatch did not fail every coordinate closed.\n" );
				exit( 1 );
			}
		}
	}
	\WpOrg\Requests\Requests::$mode = 'throw';
	$thrown = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	foreach ( $thrown as $response ) {
		if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_request_failed' !== ( $response['code'] ?? null ) || 'whole-call failure' !== ( $response['error'] ?? null ) ) {
			fwrite( STDERR, "Whole-call Throwable did not fail every coordinate closed.\n" );
			exit( 1 );
		}
	}
	\WpOrg\Requests\Requests::$mode = 'normal';
	\WpOrg\Requests\Transport\Curl::$available = false;
	$unavailable = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	foreach ( $unavailable as $response ) {
		if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_transport_unavailable' !== ( $response['code'] ?? null ) ) {
			fwrite( STDERR, "Unavailable cURL multi transport did not fail every coordinate closed.\n" );
			exit( 1 );
		}
	}
	\WpOrg\Requests\Transport\Curl::$available = true;
	$calls_before_adapter = count( \WpOrg\Requests\Requests::$calls );
	$GLOBALS['devenia_workflow_test_batch_adapter'] = static function ( $default, array $native ): array {
		$adapted = array();
		foreach ( $native as $key => $request ) {
			$adapted[ $key ] = array(
				'headers' => array( 'cf-cache-status' => 'ADAPTED', 'age' => '0' ),
				'body' => 'adapter:' . $key,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies' => array(),
				'filename' => null,
			);
		}
		return $adapted;
	};
	$adapted = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	if ( $calls_before_adapter !== count( \WpOrg\Requests\Requests::$calls ) || 'adapter:request_0' !== ( $adapted['request_0']['body'] ?? null ) || 'ADAPTED' !== ( $adapted['request_0']['cf_cache_status'] ?? null ) ) {
		fwrite( STDERR, "Plugin-owned batch adapter did not use the shared response normalization path.\n" );
		exit( 1 );
	}
	$GLOBALS['devenia_workflow_test_batch_adapter'] = static function ( $default, array $native ): array {
		array_pop( $native );
		return $native;
	};
	$adapter_mismatch = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	foreach ( $adapter_mismatch as $response ) {
		if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_result_key_mismatch' !== ( $response['code'] ?? null ) ) {
			fwrite( STDERR, "Batch adapter key mismatch bypassed the fail-closed cardinality gate.\n" );
			exit( 1 );
		}
	}

	echo "Frontend cache batch contract passed.\n";
}

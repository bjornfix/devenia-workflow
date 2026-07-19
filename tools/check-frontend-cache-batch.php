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
	$GLOBALS['devenia_workflow_test_concurrency_limit'] = null;

	function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.valueFound -- Minimal WordPress test stub.
		if ( 'devenia_workflow_frontend_cache_batch_adapter_result' === $hook && is_callable( $GLOBALS['devenia_workflow_test_batch_adapter'] ?? null ) ) {
			return ( $GLOBALS['devenia_workflow_test_batch_adapter'] )( $value, ...$args );
		}
		if ( 'devenia_workflow_public_header_self_fetch_concurrency_limit' === $hook && null !== ( $GLOBALS['devenia_workflow_test_concurrency_limit'] ?? null ) ) {
			return $GLOBALS['devenia_workflow_test_concurrency_limit'];
		}
		return $value;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function esc_url_raw( $url, $protocols = null ) {
		unset( $protocols );
		return $url;
	}

	function wp_http_validate_url( $url ) {
		return false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
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
		private const PUBLIC_HEADER_REQUEST_CONCURRENCY_LIMIT = 8;
		private const PUBLIC_HEADER_BATCH_BUDGET_SECONDS = 75;
		private const FRONTEND_EVIDENCE_MAX_BYTES = 2097152;

		public static function fetch( array $requests, int $timeout ): array {
			return self::fetch_frontend_cache_surfaces( $requests, $timeout );
		}

		public static function dispatch_timeout( int $requested_timeout, int $remaining_dispatches, int $wall_remaining, int $minimum_timeout ): int {
			return self::public_header_dispatch_timeout( $requested_timeout, $remaining_dispatches, $wall_remaining, $minimum_timeout );
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
	$offsite = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( array( 'offsite' => array( 'url' => 'https://attacker.example/surface/', 'surface' => 'canonical' ) ), 9 );
	if ( array() !== $offsite ) {
		fwrite( STDERR, "Off-site frontend evidence did not fail closed before transport.\n" );
		exit( 1 );
	}
	$alternate_origin = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( array( 'alternate_origin' => array( 'url' => 'https://example.test:8080/surface/', 'surface' => 'canonical' ) ), 9 );
	if ( array() !== $alternate_origin ) {
		fwrite( STDERR, "Alternate-port frontend evidence did not fail closed before transport.\n" );
		exit( 1 );
	}
	$batch_sizes = array_map( static fn( array $call ): int => count( $call['requests'] ), \WpOrg\Requests\Requests::$calls );
	if ( array( 8, 8, 8, 8, 8, 8, 8, 4 ) !== $batch_sizes ) {
		fwrite( STDERR, 'Expected one complete bounded dispatch plan: ' . json_encode( $batch_sizes ) . PHP_EOL );
		exit( 1 );
	}
	foreach ( \WpOrg\Requests\Requests::$calls as $call_index => $call ) {
		$surfaces = array();
		foreach ( array_keys( $call['requests'] ) as $key ) {
			$numeric_key = (int) substr( $key, strlen( 'request_' ) );
			$surfaces[ 0 === $numeric_key % 2 ? 'origin' : 'canonical' ] = true;
			if ( count( $call['requests'] ) > 8 ) {
				fwrite( STDERR, "A same-site Public Header dispatch exceeded the absolute concurrency cap.\n" );
				exit( 1 );
			}
		}
		if ( count( $call['requests'] ) > 1 && array( 'origin', 'canonical' ) !== array_keys( $surfaces ) ) {
			fwrite( STDERR, "The original keyed cache-surface order was not retained in bounded dispatches.\n" );
			exit( 1 );
		}
	}
	$dispatched = array();
	foreach ( array_slice( \WpOrg\Requests\Requests::$calls, 0, count( $batch_sizes ) ) as $call ) {
		$dispatched += $call['requests'];
	}
	if ( count( $dispatched ) !== count( $requests ) || array_keys( $dispatched ) !== array_keys( $requests ) || ! empty( array_diff_key( $dispatched, $requests ) ) || ! empty( array_diff_key( $requests, $dispatched ) ) ) {
		fwrite( STDERR, "The bounded dispatch plan did not consume every requested key exactly once.\n" );
		exit( 1 );
	}
	$origin_request = $dispatched['request_0'] ?? array();
	$canonical_request = $dispatched['request_1'] ?? array();
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
	foreach ( array( 'canonical', 'origin' ) as $single_surface ) {
		$single_surface_requests = array();
		for ( $index = 0; $index < 30; $index++ ) {
			$single_surface_requests[ $single_surface . '_' . $index ] = array( 'url' => 'https://example.test/' . $single_surface . '-' . $index . '/', 'surface' => $single_surface );
		}
		$single_surface_calls_before = count( \WpOrg\Requests\Requests::$calls );
		$single_surface_responses = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $single_surface_requests, 15 );
		$single_surface_calls = array_slice( \WpOrg\Requests\Requests::$calls, $single_surface_calls_before );
		if ( array( 8, 8, 8, 6 ) !== array_map( static fn( array $call ): int => count( $call['requests'] ), $single_surface_calls ) || count( $single_surface_responses ) !== 30 || ! empty( array_diff_key( $single_surface_responses, $single_surface_requests ) ) || ! empty( array_diff_key( $single_surface_requests, $single_surface_responses ) ) ) {
			fwrite( STDERR, "A complete {$single_surface} request set did not retain every key under the same absolute cap.\n" );
			exit( 1 );
		}
		foreach ( $single_surface_calls as $call ) {
			foreach ( $call['requests'] as $request ) {
				$has_bypass = str_contains( (string) ( $request['url'] ?? '' ), 'devenia_frontend_integrity=' ) && 'no-cache, no-store, max-age=0' === (string) ( $request['headers']['Cache-Control'] ?? '' );
				if ( ( 'origin' === $single_surface ) !== $has_bypass ) {
					fwrite( STDERR, "The {$single_surface} cache-surface URL or header semantics changed while batching.\n" );
					exit( 1 );
				}
			}
		}
	}
	foreach ( array_slice( \WpOrg\Requests\Requests::$calls, 0, count( $batch_sizes ) ) as $call ) {
		if ( 9 !== ( $call['options']['timeout'] ?? null ) || 9 !== ( $call['options']['connect_timeout'] ?? null ) || 0 !== ( $call['options']['redirects'] ?? null ) || 2097152 !== ( $call['options']['max_bytes'] ?? null ) || ABSPATH . WPINC . '/certificates/ca-bundle.crt' !== ( $call['options']['verify'] ?? null ) || \WpOrg\Requests\Transport\Curl::class !== ltrim( (string) ( $call['options']['transport'] ?? '' ), '\\' ) ) {
			fwrite( STDERR, "Batch transport options changed.\n" );
			exit( 1 );
		}
	}
	$live_timeout_calls_before = count( \WpOrg\Requests\Requests::$calls );
	Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 15 );
	$live_timeout_calls = array_slice( \WpOrg\Requests\Requests::$calls, $live_timeout_calls_before );
	if ( array( 8, 8, 8, 8, 8, 8, 8, 4 ) !== array_map( static fn( array $call ): int => count( $call['requests'] ), $live_timeout_calls ) ) {
		fwrite( STDERR, "The production-timeout fixture changed the complete bounded dispatch plan.\n" );
		exit( 1 );
	}
	foreach ( $live_timeout_calls as $call ) {
		if ( 15 !== ( $call['options']['timeout'] ?? null ) || 15 !== ( $call['options']['connect_timeout'] ?? null ) ) {
			fwrite( STDERR, "Instant groups were falsely debited from the production wall-clock timeout.\n" );
			exit( 1 );
		}
	}
	$budget_calls_before = count( \WpOrg\Requests\Requests::$calls );
	Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 30 );
	$budget_calls = array_slice( \WpOrg\Requests\Requests::$calls, $budget_calls_before );
	if ( array( 8, 8, 8, 8, 8, 8, 8, 4 ) !== array_map( static fn( array $call ): int => count( $call['requests'] ), $budget_calls ) ) {
		fwrite( STDERR, "The cumulative-timeout fixture changed the bounded dispatch plan.\n" );
		exit( 1 );
	}
	foreach ( $budget_calls as $call ) {
		if ( 30 !== ( $call['options']['timeout'] ?? null ) || 30 !== ( $call['options']['connect_timeout'] ?? null ) ) {
			fwrite( STDERR, "Fast dispatches did not retain the requested timeout inside the wall deadline.\n" );
			exit( 1 );
		}
	}
	if ( 15 !== Devenia_Workflow_Frontend_Cache_Batch_Harness::dispatch_timeout( 15, 8, 75, 3 ) || 3 !== Devenia_Workflow_Frontend_Cache_Batch_Harness::dispatch_timeout( 15, 5, 15, 3 ) || 2 !== Devenia_Workflow_Frontend_Cache_Batch_Harness::dispatch_timeout( 15, 3, 8, 3 ) ) {
		fwrite( STDERR, "The wall-deadline timeout allocator did not reserve the minimum timeout for every later dispatch.\n" );
		exit( 1 );
	}
	$GLOBALS['devenia_workflow_test_concurrency_limit'] = 100;
	$raised_calls_before = count( \WpOrg\Requests\Requests::$calls );
	Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	$raised_sizes = array_map( static fn( array $call ): int => count( $call['requests'] ), array_slice( \WpOrg\Requests\Requests::$calls, $raised_calls_before ) );
	if ( array( 8, 8, 8, 8, 8, 8, 8, 4 ) !== $raised_sizes ) {
		fwrite( STDERR, "The concurrency seam raised the absolute same-site safety limit.\n" );
		exit( 1 );
	}
	$GLOBALS['devenia_workflow_test_concurrency_limit'] = 4;
	$lowered_calls_before = count( \WpOrg\Requests\Requests::$calls );
	Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $requests, 9 );
	$lowered_sizes = array_map( static fn( array $call ): int => count( $call['requests'] ), array_slice( \WpOrg\Requests\Requests::$calls, $lowered_calls_before ) );
	if ( array_fill( 0, 15, 4 ) !== $lowered_sizes ) {
		fwrite( STDERR, "The concurrency seam could not lower the same-site safety limit.\n" );
		exit( 1 );
	}
	$GLOBALS['devenia_workflow_test_concurrency_limit'] = null;
	$oversized_requests = array();
	for ( $index = 0; $index < 201; $index++ ) {
		$oversized_requests[ 'oversized_' . $index ] = array( 'url' => 'https://example.test/origin-' . $index . '/', 'surface' => 'origin' );
	}
	$calls_before_oversized = count( \WpOrg\Requests\Requests::$calls );
	$oversized = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $oversized_requests, 30 );
	if ( $calls_before_oversized !== count( \WpOrg\Requests\Requests::$calls ) ) {
		fwrite( STDERR, "A request plan outside the cumulative budget reached the transport.\n" );
		exit( 1 );
	}
	foreach ( $oversized as $response ) {
		if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_budget_exceeded' !== ( $response['code'] ?? null ) ) {
			fwrite( STDERR, "An oversized complete request plan did not fail every coordinate closed.\n" );
			exit( 1 );
		}
	}
	$boundary_requests = array();
	for ( $index = 0; $index < 200; $index++ ) {
		$boundary_requests[ 'boundary_' . $index ] = array( 'url' => 'https://example.test/boundary-' . $index . '/', 'surface' => 0 === $index % 2 ? 'origin' : 'canonical' );
	}
	$calls_before_boundary = count( \WpOrg\Requests\Requests::$calls );
	$boundary = Devenia_Workflow_Frontend_Cache_Batch_Harness::fetch( $boundary_requests, 30 );
	if ( $calls_before_boundary !== count( \WpOrg\Requests\Requests::$calls ) ) {
		fwrite( STDERR, "A request plan with no wall-clock margin reached the transport.\n" );
		exit( 1 );
	}
	foreach ( $boundary as $response ) {
		if ( false !== ( $response['success'] ?? null ) || 'public_header_batch_budget_exceeded' !== ( $response['code'] ?? null ) ) {
			fwrite( STDERR, "A request plan at the exact minimum-timeout boundary did not fail closed before transport.\n" );
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

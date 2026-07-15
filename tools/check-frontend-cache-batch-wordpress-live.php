<?php
/**
 * Real same-site WordPress transport proof for the complete Public Header matrix.
 *
 * Run with wp eval-file against the exact installed release candidate.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'This live transport proof requires WP-CLI with Devenia Workflow active.' );
}
if ( false !== has_filter( 'devenia_workflow_frontend_cache_batch_adapter_result' ) ) {
	throw new RuntimeException( 'The live transport proof refuses to run while a frontend batch Adapter is registered.' );
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};

$languages = array_values( array_filter( array_map( 'sanitize_key', (array) $call( 'configured_public_header_languages' ) ) ) );
if ( empty( $languages ) ) {
	throw new RuntimeException( 'The installed site has no configured Public Header languages.' );
}

$timeout = max( 3, min( 30, absint( getenv( 'DEVENIA_WORKFLOW_FRONTEND_TIMEOUT' ) ?: 15 ) ) );
$started_at = microtime( true );
$responses = (array) $call( 'public_header_frontend_cache_response_set', $languages, $timeout );
$elapsed_seconds = microtime( true ) - $started_at;
$coordinates = 0;
$http_200 = 0;
$parser_pass = 0;
$failures = array();

foreach ( $languages as $language ) {
	foreach ( array( 'homepage', 'blog_archive' ) as $surface ) {
		foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
			++$coordinates;
			$response = (array) ( $responses[ $language ][ $surface ][ $cache_surface ] ?? array() );
			$status_code = (int) ( $response['status_code'] ?? 0 );
			$transport_passed = ! empty( $response['success'] ) && 200 === $status_code;
			if ( $transport_passed ) { ++$http_200; }
			$navigation = $transport_passed ? (array) $call( 'primary_navigation_from_html', (string) ( $response['body'] ?? '' ), $language ) : array();
			if ( ! empty( $navigation ) ) { ++$parser_pass; }
			if ( ! $transport_passed || empty( $navigation ) ) {
				$failures[] = array(
					'language' => $language,
					'surface' => $surface,
					'cache_surface' => $cache_surface,
					'code' => (string) ( $response['code'] ?? ( $transport_passed ? 'primary_navigation_missing' : 'frontend_transport_failed' ) ),
					'status_code' => $status_code,
					'error' => substr( (string) ( $response['error'] ?? '' ), 0, 300 ),
				);
			}
		}
	}
}

$expected_coordinates = count( $languages ) * 4;
$result = array(
	'success' => $coordinates === $expected_coordinates && $http_200 === $expected_coordinates && $parser_pass === $expected_coordinates && empty( $failures ),
	'version' => Devenia_Workflow::VERSION,
	'languages' => count( $languages ),
	'coordinates' => $coordinates,
	'http_200' => $http_200,
	'parser_pass' => $parser_pass,
	'elapsed_seconds' => round( $elapsed_seconds, 3 ),
	'failures' => $failures,
);

echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( empty( $result['success'] ) ) { exit( 1 ); }

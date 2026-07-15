<?php
/**
 * Dependency-light translation-index read-model contract checks.
 */

define( 'ABSPATH', __DIR__ . '/' );

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}

function esc_url_raw( $value ): string {
	return (string) $value;
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function get_permalink( int $post_id ): string {
	$permalinks = array(
		12 => 'https://example.test/?page_id=12',
		20 => 'https://example.test/nb/om-oss/tjenester/',
		22 => 'https://example.test/nb/query-source/',
	);
	return $permalinks[ $post_id ] ?? 'https://example.test/post-' . $post_id . '/';
}

function get_post_meta( int $post_id, string $key, bool $single = false ) {
	unset( $single );
	if ( 20 === $post_id && '_canonical_route' === $key && ! empty( $GLOBALS['index_contract_route'] ) ) {
		return $GLOBALS['index_contract_route'];
	}
	return '';
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-index-read-model.php';

final class Devenia_Workflow_Index_Read_Model_Contract {
	use Devenia_Workflow_Translation_Index_Read_Model;

	private const META_LOCALIZED_PATH = '_localized_path';
	private const META_CANONICAL_ROUTE = '_canonical_route';

	private static function sanitize_translation_status( string $status ): string {
		return sanitize_key( $status );
	}

	private static function normalized_url_path( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		return trim( $path, '/' );
	}
}

function invoke_index_read_model_method( string $method, array $arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow_Index_Read_Model_Contract::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

$raw_rows = array(
	array(
		'source_post_id' => 10,
		'translation_post_id' => 20,
		'language' => 'nb',
		'localized_path' => 'nb/tjenester',
		'source_path' => 'services',
		'target_path' => 'nb/tjenester',
		'target_url' => 'https://example.test/nb/tjenester/',
		'translation_status' => 'published',
		'post_status' => 'publish',
		'source_hash' => 'abc',
		'reviewed_at' => '2026-01-01T00:00:00+00:00',
		'linguistic_reviewed_at' => '2026-01-01T00:00:00+00:00',
		'quality_reviewed_at' => '2026-01-01T00:00:00+00:00',
	),
	array(
		'source_post_id' => 11,
		'translation_post_id' => 21,
		'language' => 'nb',
		'post_status' => 'draft',
	),
	array(
		'source_post_id' => 12,
		'translation_post_id' => 22,
		'language' => 'nb',
		'localized_path' => 'nb/query-source',
		'source_path' => '',
		'target_path' => 'nb/query-source',
		'target_url' => 'https://example.test/nb/query-source/',
		'translation_status' => 'published',
		'post_status' => 'publish',
	),
);

$normalized = invoke_index_read_model_method( 'normalize_translation_index_rows', array( $raw_rows, array( 'publish' ) ) );
$GLOBALS['index_contract_route'] = array();
$frontend = invoke_index_read_model_method( 'frontend_rows_from_index_rows', array( $normalized ) );
$failures = array();

if ( 2 !== count( $normalized ) || 20 !== (int) ( $normalized[0]['id'] ?? 0 ) || 22 !== (int) ( $normalized[1]['id'] ?? 0 ) ) {
	$failures[] = 'publish status filtering or row identity changed';
}

$GLOBALS['index_contract_route'] = array(
	'url'  => 'https://example.test/nb/om-oss/',
	'path' => 'nb/om-oss',
);
$contract_frontend = invoke_index_read_model_method( 'frontend_rows_from_index_rows', array( $normalized ) );
$contract_row = $contract_frontend[0] ?? array();
if ( 'nb/om-oss' !== ( $contract_row['target_path'] ?? '' ) || empty( $contract_row['route_drift'] ) ) {
	$failures[] = 'established canonical route did not remain authoritative over observed drift';
}
if ( 'nb/om-oss/tjenester' !== ( $contract_row['observed_target_path'] ?? '' ) ) {
	$failures[] = 'observed drift route was not retained as separate evidence';
}
if ( 2 !== count( $frontend ) ) {
	$failures[] = 'frontend row count changed';
} else {
	$row = $frontend[0];
	if ( 'services' !== ( $row['source_path'] ?? '' ) ) {
		$failures[] = 'source path changed';
	}
	if ( 'nb/om-oss/tjenester' !== ( $row['localized_path'] ?? '' ) ) {
		$failures[] = 'live WordPress permalink did not replace the stale indexed canonical path';
	}
	if ( array( 'nb/tjenester', 'nb/om-oss/tjenester' ) !== ( $row['localized_path_variants'] ?? array() ) ) {
		$failures[] = 'stale indexed path was not preserved as a non-canonical variant';
	}
	if ( 'https://example.test/nb/om-oss/tjenester/' !== ( $row['target_url'] ?? '' ) ) {
		$failures[] = 'live WordPress permalink did not replace stale target URL';
	}
	if ( 'nb/om-oss/tjenester' !== ( $row['target_path'] ?? '' ) ) {
		$failures[] = 'live WordPress permalink did not replace stale target path';
	}
	if ( 'https://example.test/services/' !== ( $row['source_url'] ?? '' ) ) {
		$failures[] = 'source URL shaping changed';
	}
	$query_row = $frontend[1] ?? array();
	if ( 'https://example.test/?page_id=12' !== ( $query_row['source_url'] ?? '' ) || '' !== ( $query_row['source_path'] ?? '' ) ) {
		$failures[] = 'query-ID source URL was collapsed into unrelated root-path authority';
	}
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'normalized_rows' => count( $normalized ), 'frontend_rows' => count( $frontend ) ), JSON_PRETTY_PRINT ) . PHP_EOL;

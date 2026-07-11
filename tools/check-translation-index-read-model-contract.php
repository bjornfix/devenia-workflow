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
		20 => 'https://example.test/nb/om-oss/tjenester/',
	);
	return $permalinks[ $post_id ] ?? 'https://example.test/post-' . $post_id . '/';
}

function get_post_meta( int $post_id, string $key, bool $single = false ): string {
	return '';
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-index-read-model.php';

final class Devenia_AI_Translations_Index_Read_Model_Contract {
	use Devenia_AI_Translations_Translation_Index_Read_Model;

	private const META_LOCALIZED_PATH = '_localized_path';

	private static function sanitize_translation_status( string $status ): string {
		return sanitize_key( $status );
	}

	private static function normalized_url_path( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		return trim( $path, '/' );
	}
}

function invoke_index_read_model_method( string $method, array $arguments ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations_Index_Read_Model_Contract::class, $method );
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
);

$normalized = invoke_index_read_model_method( 'normalize_translation_index_rows', array( $raw_rows, array( 'publish' ) ) );
$frontend = invoke_index_read_model_method( 'frontend_rows_from_index_rows', array( $normalized ) );
$failures = array();

if ( 1 !== count( $normalized ) || 20 !== (int) ( $normalized[0]['id'] ?? 0 ) ) {
	$failures[] = 'publish status filtering or row identity changed';
}
if ( 1 !== count( $frontend ) ) {
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
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'normalized_rows' => count( $normalized ), 'frontend_rows' => count( $frontend ) ), JSON_PRETTY_PRINT ) . PHP_EOL;

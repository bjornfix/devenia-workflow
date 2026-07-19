<?php
/**
 * Runtime contract for bounded menu translation-index batch reads.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}

final class Devenia_Workflow_Menu_Index_WPDB {
	public string $prefix = 'wp_';
	public array $prepared = array();

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->prepared[] = array( 'query' => $query, 'args' => $args );
		return 'prepared:' . ( count( $this->prepared ) - 1 );
	}

	public function get_var( string $prepared ): string {
		unset( $prepared );
		return 'wp_devenia_translation_index';
	}

	public function get_results( string $prepared, string $format ): array {
		unset( $format );
		$index = (int) substr( $prepared, strlen( 'prepared:' ) );
		$args  = $this->prepared[ $index ]['args'] ?? array();
		$rows  = array();
		foreach ( array_slice( $args, 2, -1 ) as $source_id ) {
			$source_id = absint( $source_id );
			if ( $source_id ) {
				$rows[] = array( 'source_post_id' => $source_id, 'translation_post_id' => $source_id + 1000 );
			}
		}
		return $rows;
	}
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-index-read-model.php';

final class Devenia_Workflow_Menu_Index_Harness {
	use Devenia_Workflow_Translation_Index_Read_Model;

	public static function batch( array $source_ids, string $language, array $statuses ): array {
		$method = new ReflectionMethod( self::class, 'translation_index_ids_for_sources_language' );
		$method->setAccessible( true );
		return $method->invoke( null, $source_ids, $language, $statuses );
	}

	private static function sanitize_translation_status( string $status ): string {
		return sanitize_key( $status );
	}
}

$wpdb = new Devenia_Workflow_Menu_Index_WPDB();
$ids  = range( 1, 205 );
$map  = Devenia_Workflow_Menu_Index_Harness::batch( array_merge( $ids, array( 1, 0 ) ), 'NB!', array( 'publish' ) );
$batch_calls = array_values(
	array_filter(
		$wpdb->prepared,
		static fn( array $call ): bool => str_contains( (string) $call['query'], 'source_post_id IN' )
	)
);

$failures = array();
if ( 205 !== count( $map ) || 1001 !== (int) ( $map[1] ?? 0 ) || 1205 !== (int) ( $map[205] ?? 0 ) ) {
	$failures[] = 'source-to-translation mapping changed';
}
if ( 3 !== count( $batch_calls ) || array( 103, 103, 8 ) !== array_map( static fn( array $call ): int => count( $call['args'] ), $batch_calls ) ) {
	$failures[] = 'physical query chunks exceeded or changed the 100-ID bound';
}
foreach ( $batch_calls as $call ) {
	if ( 'wp_devenia_translation_index' !== (string) ( $call['args'][0] ?? '' ) || 'nb' !== (string) ( $call['args'][1] ?? '' ) || 'publish' !== (string) end( $call['args'] ) ) {
		$failures[] = 'table, language, or status binding changed';
		break;
	}
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'source_ids' => count( $map ), 'physical_queries' => count( $batch_calls ) ) ) . PHP_EOL;

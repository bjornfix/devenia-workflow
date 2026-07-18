<?php
/**
 * Separate-process writer used by the Translation Index Readiness runtime.
 *
 * Usage:
 * php translation-index-readiness-worker.php <wp-load.php> <canonical_writer|ensure_ready> <base64-json-payload>
 */

ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
error_reporting( 0 );
ob_start(
	static function (): string {
		return '';
	}
);

/** Emit one exact, diagnostics-free worker result. */
function devenia_workflow_translation_index_worker_finish( array $result ): void {
	if ( function_exists( 'remove_action' ) ) {
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	$payload = array(
		'success'       => true === ( $result['success'] ?? false ),
		'mode'          => in_array( (string) ( $result['mode'] ?? '' ), array( 'canonical_writer', 'ensure_ready' ), true ) ? (string) $result['mode'] : 'invalid',
		'code'          => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $result['code'] ?? 'worker_failed' ) ) ) ?: 'worker_failed',
		'connection_id' => max( 0, (int) ( $result['connection_id'] ?? 0 ) ),
		'engine'        => 'INNODB' === strtoupper( trim( (string) ( $result['engine'] ?? '' ) ) ) ? 'INNODB' : '',
		'wait_ms'       => max( 0, (int) ( $result['wait_ms'] ?? 0 ) ),
		'restored'      => true === ( $result['restored'] ?? false ),
		'ready'         => true === ( $result['ready'] ?? false ),
	);
	$exit_code = $payload['success'] ? 0 : 1;

	register_shutdown_function(
		static function () use ( $payload ): void {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
			if ( is_string( $encoded ) ) {
				fwrite( STDOUT, $encoded . PHP_EOL );
			}
		}
	);

	exit( $exit_code );
}

/** Invoke one private static Workflow method without widening production PHP. */
function devenia_workflow_translation_index_worker_call( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

/** Resolve the canonical Index engine without returning SQL diagnostics. */
function devenia_workflow_translation_index_worker_engine( wpdb $connection ): string {
	$table = $connection->prefix . 'devenia_translation_index';
	$engine = $connection->get_var(
		$connection->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		)
	);
	return strtoupper( trim( (string) $engine ) );
}

$wp_load = isset( $argv[1] ) ? (string) $argv[1] : '';
$mode = isset( $argv[2] ) ? (string) $argv[2] : '';
$encoded_payload = isset( $argv[3] ) ? (string) $argv[3] : '';
$decoded_payload = base64_decode( $encoded_payload, true );
$input = is_string( $decoded_payload ) ? json_decode( $decoded_payload, true ) : null;

if (
	! in_array( $mode, array( 'canonical_writer', 'ensure_ready' ), true )
	|| ! is_array( $input )
	|| '' === $wp_load
	|| ! is_file( $wp_load )
) {
	devenia_workflow_translation_index_worker_finish( array( 'mode' => $mode, 'code' => 'invalid_input' ) );
}

define( 'WP_USE_THEMES', false );
require_once $wp_load;

if ( ! class_exists( 'Devenia_Workflow' ) ) {
	devenia_workflow_translation_index_worker_finish( array( 'mode' => $mode, 'code' => 'plugin_unavailable' ) );
}

global $wpdb;
$connection_id = absint( $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Separate-process connection identity is part of the runtime oracle.
$engine = devenia_workflow_translation_index_worker_engine( $wpdb );
$marker = (string) ( $input['marker'] ?? '' );
if ( 1 !== preg_match( '#^/tmp/devenia-ti-readiness-[a-z0-9_-]+\.ready$#D', $marker ) ) {
	devenia_workflow_translation_index_worker_finish( array( 'mode' => $mode, 'code' => 'marker_invalid', 'connection_id' => $connection_id, 'engine' => $engine ) );
}

$started_at = microtime( true );
try {
	if ( file_exists( $marker ) || is_link( $marker ) ) {
		throw new RuntimeException( 'marker_occupied' );
	}
	$marker_handle = fopen( $marker, 'xb' );
	if ( false === $marker_handle ) {
		throw new RuntimeException( 'marker_failed' );
	}
	$marker_written = false;
	try {
		if ( ! chmod( $marker, 0600 ) || false === fwrite( $marker_handle, (string) $connection_id ) || ! fflush( $marker_handle ) ) {
			throw new RuntimeException( 'marker_write_failed' );
		}
		$marker_written = true;
	} finally {
		fclose( $marker_handle );
		if ( ! $marker_written && ( is_file( $marker ) || is_link( $marker ) ) ) {
			unlink( $marker );
		}
	}

	if ( 'canonical_writer' === $mode ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$meta_key = (string) ( $input['meta_key'] ?? '' );
		$expected = (string) ( $input['expected'] ?? '' );
		$replacement = (string) ( $input['replacement'] ?? '' );
		if ( $post_id < 1 || '_devenia_translation_source_hash' !== $meta_key || '' === $replacement || $expected === $replacement ) {
			throw new RuntimeException( 'writer_input_invalid' );
		}
		$before = (string) get_post_meta( $post_id, $meta_key, true );
		if ( ! hash_equals( $expected, $before ) ) {
			throw new RuntimeException( 'writer_precondition_changed' );
		}
		$changed = update_post_meta( $post_id, $meta_key, $replacement, $expected );
		$after_write = (string) get_post_meta( $post_id, $meta_key, true );
		$ready_after_write = (bool) devenia_workflow_translation_index_worker_call( 'translation_index_available', true );
		$index_after_write = (array) devenia_workflow_translation_index_worker_call( 'translation_index_row_for_translation', $post_id );
		$restored_write = update_post_meta( $post_id, $meta_key, $expected, $replacement );
		$after_restore = (string) get_post_meta( $post_id, $meta_key, true );
		$ready = (bool) devenia_workflow_translation_index_worker_call( 'translation_index_available', true );
		$index_after_restore = (array) devenia_workflow_translation_index_worker_call( 'translation_index_row_for_translation', $post_id );
		$success = false !== $changed
			&& $ready_after_write
			&& hash_equals( $replacement, $after_write )
			&& hash_equals( $replacement, (string) ( $index_after_write['source_hash'] ?? '' ) )
			&& false !== $restored_write
			&& hash_equals( $expected, $after_restore )
			&& $ready
			&& hash_equals( $expected, (string) ( $index_after_restore['source_hash'] ?? '' ) );
		devenia_workflow_translation_index_worker_finish(
			array(
				'success'       => $success,
				'mode'          => $mode,
				'code'          => $success ? 'canonical_writer_blocked_then_restored' : 'canonical_writer_failed',
				'connection_id' => $connection_id,
				'engine'        => $engine,
				'wait_ms'       => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'restored'      => hash_equals( $expected, $after_restore ),
				'ready'         => $ready,
			)
		);
	}

	$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Disposable worker must fail promptly under the recovery predicate lock.
	$prior_suppress_errors = $wpdb->suppress_errors( true );
	try {
		$result = devenia_workflow_translation_index_worker_call( 'translation_index_ensure_ready', 'runtime_recovery_concurrency', true );
	} finally {
		$wpdb->suppress_errors( $prior_suppress_errors );
	}
	$blocked = is_array( $result ) && empty( $result['success'] ) && in_array( (string) ( $result['code'] ?? '' ), array( 'translation_index_upgrade_in_progress', 'translation_index_upgrade_lease_release_failed' ), true );
	$ready = (bool) devenia_workflow_translation_index_worker_call( 'translation_index_available', true );
	devenia_workflow_translation_index_worker_finish(
		array(
			'success'       => $blocked,
			'mode'          => $mode,
			'code'          => $blocked ? 'rebuild_blocked_by_recovery' : 'rebuild_not_blocked_by_recovery',
			'connection_id' => $connection_id,
			'engine'        => $engine,
			'wait_ms'       => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'restored'      => true,
			'ready'         => $ready,
		)
	);
} catch ( Throwable $error ) {
	devenia_workflow_translation_index_worker_finish(
		array(
			'mode'          => $mode,
			'code'          => sanitize_key( $error->getMessage() ),
			'connection_id' => $connection_id,
			'engine'        => $engine,
			'wait_ms'       => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
		)
	);
}

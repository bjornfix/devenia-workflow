<?php
/**
 * WP-CLI cross-process contract for simultaneous Assignment acceptance.
 *
 * Accept mode:
 * wp eval-file <file> accept <session-id> <actor-id> <barrier-file>
 *
 * Cleanup mode:
 * wp eval-file <file> cleanup <session-a> <actor-a> <session-b> <actor-b>
 *
 * Inspect mode:
 * wp eval-file <file> inspect <session-a> <session-b>
 *
 * Fixture cleanup mode:
 * wp eval-file <file> cleanup-fixtures
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This contract must run through WP-CLI.\n" );
	exit( 2 );
}

$invoke = static function ( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$identity_for = static function ( string $session_id, string $actor_id ): array {
	return array(
		'success'          => true,
		'actor_id'         => $actor_id,
		'step_token_label' => $actor_id,
		'agent_session_id' => $session_id,
		'control_scope_id' => $session_id,
		'process_id'       => $session_id,
		'session_origin'   => 'independent_session',
	);
};

$mode = sanitize_key( (string) ( $args[0] ?? '' ) );
if ( 'accept' === $mode ) {
	$session_id = sanitize_text_field( (string) ( $args[1] ?? '' ) );
	$actor_id   = sanitize_key( (string) ( $args[2] ?? '' ) );
	$barrier    = (string) ( $args[3] ?? '' );
	if ( '' === $session_id || '' === $actor_id || '' === $barrier ) {
		fwrite( STDERR, "Accept mode requires session ID, actor ID, and barrier file.\n" );
		exit( 2 );
	}

	$session_key = $invoke( 'assignment_session_option_name', array( $session_id ) );
	delete_option( $session_key );
	file_put_contents( $barrier . '.' . $session_id . '.ready', "ready\n" );
	for ( $attempt = 0; $attempt < 400 && ! file_exists( $barrier ); $attempt++ ) {
		usleep( 25000 );
	}
	if ( ! file_exists( $barrier ) ) {
		fwrite( STDERR, "Concurrency barrier timed out.\n" );
		exit( 3 );
	}

	$identity = $identity_for( $session_id, $actor_id );
	$result = $invoke(
		'assignment_lifecycle_accept',
		array(
			array(
				'agent_session_id' => $session_id,
				'limit'            => 500,
				'ttl_seconds'      => 600,
				'note'             => 'Cross-process Assignment concurrency contract',
			),
			$identity,
		)
	);
	$assignment = isset( $result['assignment'] ) && is_array( $result['assignment'] ) ? $result['assignment'] : array();
	echo wp_json_encode(
		array(
			'success'          => ! empty( $result['success'] ),
			'action'           => sanitize_key( (string) ( $result['action'] ?? '' ) ),
			'mode'             => sanitize_key( (string) ( $result['mode'] ?? '' ) ),
			'code'             => sanitize_key( (string) ( $result['code'] ?? '' ) ),
			'assignment_id'    => sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) ),
			'work_item_id'     => sanitize_text_field( (string) ( $assignment['work_item_id'] ?? '' ) ),
			'revision'         => sanitize_text_field( (string) ( $assignment['revision'] ?? '' ) ),
			'source_id'        => absint( $assignment['selected']['source_id'] ?? 0 ),
			'translation_id'   => absint( $assignment['selected']['translation_id'] ?? 0 ),
			'language'         => sanitize_key( (string) ( $assignment['selected']['language'] ?? '' ) ),
			'skipped_count'    => absint( $result['skipped_count'] ?? 0 ),
			'skipped_sample'   => isset( $result['skipped_sample'] ) && is_array( $result['skipped_sample'] ) ? $result['skipped_sample'] : array(),
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( empty( $result['success'] ) ? 1 : 0 );
}

if ( 'cleanup' === $mode ) {
	$pairs = array_chunk( array_slice( $args, 1 ), 2 );
	$cleaned = array();
	foreach ( $pairs as $pair ) {
		$session_id = sanitize_text_field( (string) ( $pair[0] ?? '' ) );
		$actor_id   = sanitize_key( (string) ( $pair[1] ?? '' ) );
		if ( '' === $session_id || '' === $actor_id ) {
			continue;
		}
		$key = $invoke( 'assignment_session_option_name', array( $session_id ) );
		$stored = get_option( $key, array() );
		$assignment = is_array( $stored ) ? $invoke( 'sanitize_assignment', array( $stored ) ) : array();
		if ( $assignment ) {
			$assignment_id = (string) $assignment['assignment_id'];
			$transition = $assignment;
			$transition['status'] = 'completing';
			$transition['updated_at'] = gmdate( 'c' );
			$transition['pending_outcome'] = array(
				'outcome'          => 'abandoned',
				'blocker_category' => '',
				'evidence_summary' => '',
				'evidence'         => array( 'cross_process_concurrency_contract' ),
				'note'             => 'Dev concurrency contract only; no editorial work was performed.',
				'recorded_at'      => gmdate( 'c' ),
			);
			$swapped = $invoke( 'assignment_compare_and_swap_option', array( $key, $assignment, $transition ) );
			if ( $swapped ) {
				$invoke( 'assignment_finish_terminal_transition', array( $transition ) );
			}
			delete_option( $invoke( 'assignment_outcome_option_name', array( $assignment_id ) ) );
		}
		delete_option( $key );
		$cleaned[] = array( 'session_id' => $session_id, 'had_assignment' => ! empty( $assignment ) );
	}
	echo wp_json_encode( array( 'success' => true, 'cleaned' => $cleaned ), JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 0 );
}

if ( 'inspect' === $mode ) {
	$sessions = array_map( 'sanitize_text_field', array_slice( $args, 1 ) );
	$rows = array();
	foreach ( array_filter( $sessions ) as $session_id ) {
		$key = $invoke( 'assignment_session_option_name', array( $session_id ) );
		$stored = get_option( $key, array() );
		$assignment = is_array( $stored ) ? $invoke( 'sanitize_assignment', array( $stored ) ) : array();
		$lock = $assignment ? $invoke( 'assignment_item_lock_for_work_item', array( $assignment ) ) : array();
		$reservation = $assignment ? $invoke( 'assignment_reservation_for_selected', array( $assignment['selected'] ?? array(), true ) ) : array();
		$rows[] = array(
			'session_id'             => $session_id,
			'assignment_id'          => sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) ),
			'work_item_id'           => sanitize_text_field( (string) ( $assignment['work_item_id'] ?? '' ) ),
			'assignment_status'       => sanitize_key( (string) ( $assignment['status'] ?? '' ) ),
			'lock_assignment_id'      => sanitize_text_field( (string) ( $lock['assignment_id'] ?? '' ) ),
			'lock_agent_session_id'   => sanitize_text_field( (string) ( $lock['agent_session_id'] ?? '' ) ),
			'reservation_assignment_id' => sanitize_text_field( (string) ( $reservation['assignment_id'] ?? '' ) ),
			'reservation_session_id'  => sanitize_text_field( (string) ( $reservation['agent_session_id'] ?? '' ) ),
			'reservation_actor_id'    => sanitize_key( (string) ( $reservation['actor_id'] ?? '' ) ),
		);
	}
	echo wp_json_encode(
		array(
			'success'                   => true,
			'external_object_cache'     => wp_using_ext_object_cache(),
			'wpdb_options_table'         => (string) $GLOBALS['wpdb']->options,
			'assignments'                => $rows,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 0 );
}

if ( 'cleanup-fixtures' === $mode ) {
	global $wpdb;
	$prefixes = array(
		Devenia_AI_Translations::OPTION_TRANSLATION_CLAIM_PREFIX,
		Devenia_AI_Translations::OPTION_WORK_CLAIM_PREFIX,
		Devenia_AI_Translations::OPTION_ASSIGNMENT_PREFIX,
	);
	$clauses = array();
	$params = array();
	foreach ( $prefixes as $prefix ) {
		$clauses[] = 'option_name LIKE %s';
		$params[] = $wpdb->esc_like( $prefix ) . '%';
	}
	$params[] = '%dev-race-%';
	$params[] = '%dev-assignment-%';
	$params[] = '%Assignment Lifecycle dev runtime contract%';
	$params[] = '%Cross-process Assignment concurrency contract%';
	$sql = "SELECT option_name FROM {$wpdb->options} WHERE (" . implode( ' OR ', $clauses ) . ') AND (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s)';
	$names = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev-only runtime fixture cleanup must discover its own generated option rows.
	$deleted = array();
	foreach ( array_unique( array_map( 'strval', $names ) ) as $name ) {
		if ( delete_option( $name ) ) {
			$deleted[] = $name;
		}
	}
	echo wp_json_encode( array( 'success' => true, 'deleted_count' => count( $deleted ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 0 );
}

fwrite( STDERR, "Mode must be accept, inspect, cleanup, or cleanup-fixtures.\n" );
exit( 2 );

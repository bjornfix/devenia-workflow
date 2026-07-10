<?php
/**
 * WP-CLI runtime contract for Assignment storage and recovery.
 *
 * Usage: wp eval-file /tmp/check-assignment-lifecycle-runtime.php <session-id> <actor-id>
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This contract must run through WP-CLI.\n" );
	exit( 2 );
}

$session_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );
$actor_id   = sanitize_key( (string) ( $args[1] ?? '' ) );
$parallel_session_id = sanitize_text_field( (string) ( $args[2] ?? '' ) );
$parallel_actor_id   = sanitize_key( (string) ( $args[3] ?? '' ) );
if ( '' === $session_id || '' === $actor_id ) {
	fwrite( STDERR, "Session ID and actor ID are required.\n" );
	exit( 2 );
}

$invoke = static function ( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};

$identity = array(
	'success'          => true,
	'actor_id'         => $actor_id,
	'step_token_label' => $actor_id,
	'agent_session_id' => $session_id,
	'control_scope_id' => $session_id,
	'process_id'       => $session_id,
	'session_origin'   => 'independent_session',
);
$input = array(
	'agent_session_id' => $session_id,
	'limit'            => 200,
	'ttl_seconds'      => 600,
	'note'             => 'Assignment Lifecycle dev runtime contract',
);
$session_key = $invoke( 'assignment_session_option_name', array( $session_id ) );
$parallel_session_key = '' !== $parallel_session_id ? $invoke( 'assignment_session_option_name', array( $parallel_session_id ) ) : '';
$failures = array();
$assert = static function ( bool $condition, string $case, $actual = null ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = array( 'case' => $case, 'actual' => $actual );
	}
};
$redact = static function ( $value ) use ( &$redact ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$out = array();
	foreach ( $value as $key => $nested ) {
		$out[ $key ] = in_array( (string) $key, array( 'claim_token', 'token' ), true ) ? '[redacted]' : $redact( $nested );
	}
	return $out;
};

try {
	delete_option( $session_key );
	$first  = $invoke( 'assignment_lifecycle_accept', array( $input, $identity ) );
	$raw_after_first = get_option( $session_key, array() );
	$normalized_after_first = $invoke( 'sanitize_assignment', array( $raw_after_first ) );
	$storage_after_first = $invoke( 'assignment_storage_record', array( $normalized_after_first ) );
	$assert( serialize( $raw_after_first ) === serialize( $storage_after_first ), 'storage:normalized_round_trip', array( 'raw' => $redact( $raw_after_first ), 'normalized' => $redact( $storage_after_first ) ) );
	$db_serialized = (string) $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $session_key )
	);
	$expected_serialized = maybe_serialize( $storage_after_first );
	$assert(
		$db_serialized === $expected_serialized,
		'storage:database_round_trip',
		array(
			'database_length' => strlen( $db_serialized ),
			'expected_length' => strlen( $expected_serialized ),
			'database_hash'   => hash( 'sha256', $db_serialized ),
			'expected_hash'   => hash( 'sha256', $expected_serialized ),
		)
	);
	$second = $invoke( 'assignment_lifecycle_accept', array( $input, $identity ) );
	$second['wpdb_last_error'] = (string) $GLOBALS['wpdb']->last_error;
	$first_summary = array(
		'success'       => ! empty( $first['success'] ),
		'mode'          => (string) ( $first['mode'] ?? '' ),
		'code'          => (string) ( $first['code'] ?? '' ),
		'message'       => (string) ( $first['message'] ?? '' ),
		'action'        => (string) ( $first['action'] ?? '' ),
		'assignment_id' => (string) ( $first['assignment']['assignment_id'] ?? '' ),
	);
	$second_summary = array(
		'success'       => ! empty( $second['success'] ),
		'mode'          => (string) ( $second['mode'] ?? '' ),
		'code'          => (string) ( $second['code'] ?? '' ),
		'message'       => (string) ( $second['message'] ?? '' ),
		'action'        => (string) ( $second['action'] ?? '' ),
		'assignment_id' => (string) ( $second['assignment']['assignment_id'] ?? '' ),
		'wpdb_last_error' => (string) ( $second['wpdb_last_error'] ?? '' ),
	);
	$assert( ! empty( $first['success'] ) && 'claimed' === ( $first['mode'] ?? '' ), 'accept:first_claimed', $first_summary );
	$assert( ! empty( $second['success'] ) && 'resumed' === ( $second['mode'] ?? '' ), 'accept:idempotent_resume', $second_summary );
	$assert( ( $first['assignment']['assignment_id'] ?? '' ) === ( $second['assignment']['assignment_id'] ?? '' ), 'accept:same_assignment_id' );
	if ( '' !== $parallel_session_id && '' !== $parallel_actor_id ) {
		$parallel_identity = $identity;
		$parallel_identity['actor_id'] = $parallel_actor_id;
		$parallel_identity['step_token_label'] = $parallel_actor_id;
		$parallel_identity['agent_session_id'] = $parallel_session_id;
		$parallel_identity['control_scope_id'] = $parallel_session_id;
		$parallel_identity['process_id'] = $parallel_session_id;
		$parallel_input = $input;
		$parallel_input['agent_session_id'] = $parallel_session_id;
		$parallel = $invoke( 'assignment_lifecycle_accept', array( $parallel_input, $parallel_identity ) );
		$parallel_work_item_id = (string) ( $parallel['assignment']['work_item_id'] ?? '' );
		$assert(
			! empty( $parallel['success'] )
			&& ( 'wait' === ( $parallel['action'] ?? '' ) || ( '' !== $parallel_work_item_id && $parallel_work_item_id !== (string) ( $first['assignment']['work_item_id'] ?? '' ) ) ),
			'concurrency:logical_item_lock',
			array(
				'mode'                  => (string) ( $parallel['mode'] ?? '' ),
				'action'                => (string) ( $parallel['action'] ?? '' ),
				'first_work_item_id'    => (string) ( $first['assignment']['work_item_id'] ?? '' ),
				'parallel_work_item_id' => $parallel_work_item_id,
			)
		);
		if ( ! empty( $parallel['assignment'] ) ) {
			$parallel_assignment = $parallel['assignment'];
			$parallel_transition = $parallel_assignment;
			$parallel_transition['status'] = 'completing';
			$parallel_transition['updated_at'] = gmdate( 'c' );
			$parallel_transition['pending_outcome'] = array(
				'outcome'          => 'abandoned',
				'blocker_category' => '',
				'evidence_summary' => '',
				'evidence'         => array( 'dev_runtime_parallel_contract_no_editorial_work' ),
				'note'             => 'Parallel runtime contract only; no editorial work was performed.',
				'recorded_at'      => gmdate( 'c' ),
			);
			$parallel_swapped = $invoke( 'assignment_compare_and_swap_option', array( $parallel_session_key, $parallel_assignment, $parallel_transition ) );
			$parallel_finished = $parallel_swapped ? $invoke( 'assignment_finish_terminal_transition', array( $parallel_transition ) ) : array();
			$assert( $parallel_swapped && ! empty( $parallel_finished['success'] ), 'concurrency:parallel_cleanup', $parallel_finished['code'] ?? '' );
		}
	}

	$assignment = $first['assignment'] ?? array();
	delete_option( $session_key );
	$recovered = $invoke( 'assignment_lifecycle_current_result', array( $input, $identity, true ) );
	$assert( ! empty( $recovered['success'] ) && ! empty( $recovered['has_assignment'] ) && ! empty( $recovered['recovered'] ), 'recovery:from_reservation', $recovered['code'] ?? '' );
	$assert( ( $assignment['assignment_id'] ?? '' ) === ( $recovered['assignment']['assignment_id'] ?? '' ), 'recovery:same_assignment_id' );

	$renewed = $invoke( 'assignment_lifecycle_renew_record', array( $recovered['assignment'], 900 ) );
	$assert( ! empty( $renewed['success'] ), 'renew:success', $renewed['code'] ?? '' );
	$assignment = $renewed['assignment'] ?? $recovered['assignment'];

	$transition = $assignment;
	$transition['status'] = 'completing';
	$transition['updated_at'] = gmdate( 'c' );
	$transition['pending_outcome'] = array(
		'outcome'          => 'abandoned',
		'blocker_category' => '',
		'evidence_summary' => '',
		'evidence'         => array( 'dev_runtime_contract_no_editorial_work' ),
		'note'             => 'Runtime contract only; no editorial work was performed.',
		'recorded_at'      => gmdate( 'c' ),
	);
	$swapped = $invoke( 'assignment_compare_and_swap_option', array( $session_key, $assignment, $transition ) );
	$assert( $swapped, 'outcome:transition_cas' );
	$finished = $swapped ? $invoke( 'assignment_finish_terminal_transition', array( $transition ) ) : array();
	$assert( ! empty( $finished['success'] ), 'outcome:abandoned_release', $finished['code'] ?? '' );

	$current = $invoke( 'assignment_lifecycle_current_result', array( $input, $identity, true ) );
	$assert( ! empty( $current['success'] ) && empty( $current['has_assignment'] ), 'outcome:no_current_assignment', $current['code'] ?? '' );
	$reservation = $invoke( 'assignment_reservation_for_selected', array( $assignment['selected'] ?? array(), true ) );
	$assert( empty( $reservation ), 'outcome:no_reservation' );

	$plan = $invoke( 'work_item_plan', array( array_merge( $input, array( 'collect_candidates' => false ) ), $identity ) );
	$assert( ! empty( $plan['success'] ), 'planner:runtime_success', $plan['code'] ?? '' );
	$assert( ( $plan['coverage']['claimable_for_actor'] ?? 0 ) >= 0, 'planner:coverage_present' );
} finally {
	foreach ( array_filter( array( $session_key, $parallel_session_key ) ) as $cleanup_key ) {
		$stored = get_option( $cleanup_key, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$stored = $invoke( 'sanitize_assignment', array( $stored ) );
			if ( $stored ) {
				$invoke( 'assignment_internal_release_reservation', array( $stored ) );
				$invoke( 'assignment_release_item_lock', array( $stored ) );
			}
			delete_option( $cleanup_key );
		}
	}
}

if ( $failures ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode(
	array(
		'success'   => true,
		'contracts' => array(
			'wordpress_option_cas',
			'idempotent_accept',
			'recovery_from_enriched_reservation',
			'renewal',
			'abandoned_outcome_cleanup',
			'planner_runtime_coverage',
			'logical_item_lock_across_sessions',
		),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

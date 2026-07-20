<?php
/** Run with: wp eval-file tools/check-correctable-publish-preflight-wordpress-runtime.php */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};

$token = strtolower( wp_generate_password( 8, false, false ) );
$keys  = array();

try {
	$quality_revision = 'a_runtime_preflight_quality_' . $token;
	$quality_key      = 'devenia_workflow_translation_quality_' . $quality_revision;
	$quality_record   = array( 'quality_revision' => $quality_revision, 'decision' => 'pass' );
	add_option( $quality_key, $quality_record, '', 'off' );
	$keys[] = $quality_key;

	$base_failure = array(
		'code'                 => 'localized_slug_copied_from_source',
		'mutation_started'     => false,
		'transaction_rollback' => array( 'success' => true, 'rolled_back' => true ),
	);
	$cases = array(
		'reopens_exact_safe_failure' => array( 'job_patch' => array(), 'failure_patch' => array(), 'reopened' => true ),
		'rejects_unknown_code'       => array( 'job_patch' => array(), 'failure_patch' => array( 'code' => 'unknown_preflight' ), 'reopened' => false ),
		'rejects_started_mutation'   => array( 'job_patch' => array(), 'failure_patch' => array( 'mutation_started' => true ), 'reopened' => false ),
		'rejects_failed_rollback'    => array( 'job_patch' => array(), 'failure_patch' => array( 'transaction_rollback' => array( 'success' => false, 'rolled_back' => true ) ), 'reopened' => false ),
		'rejects_missing_rollback'   => array( 'job_patch' => array(), 'failure_patch' => array( 'transaction_rollback' => array( 'success' => true, 'rolled_back' => false ) ), 'reopened' => false ),
		'rejects_wrong_job_status'   => array( 'job_patch' => array( 'status' => 'quality_pending' ), 'failure_patch' => array(), 'reopened' => false ),
	);

	foreach ( $cases as $name => $case ) {
		$job_id = 'tj_runtime_preflight_' . sanitize_key( $name ) . '_' . $token;
		$job = array_merge(
			array(
				'job_id'             => $job_id,
				'status'             => 'ready_to_publish',
				'artifact_revision'  => 'a_runtime_preflight_artifact_' . $token,
				'quality_revision'   => $quality_revision,
				'active_run_id'      => 'r_runtime_preflight_' . $token,
			),
			$case['job_patch']
		);
		$job_key = 'devenia_workflow_translation_job_' . $job_id;
		if ( ! add_option( $job_key, $job, '', 'off' ) ) {
			throw new RuntimeException( 'Could not create fixture Job for ' . $name );
		}
		$keys[] = $job_key;

		$failure = array_replace_recursive( $base_failure, $case['failure_patch'] );
		$result  = $call( 'translation_job_reopen_correctable_publish_preflight', $job, $failure );
		$stored  = get_option( $job_key );
		if ( empty( $result['success'] ) || (bool) $case['reopened'] !== ! empty( $result['reopened'] ) || ! is_array( $stored ) ) {
			throw new RuntimeException( 'Unexpected lifecycle result for ' . $name . ': ' . wp_json_encode( $result ) );
		}

		if ( $case['reopened'] ) {
			if (
				'changes_requested' !== (string) ( $stored['status'] ?? '' )
				|| '' !== (string) ( $stored['quality_revision'] ?? '' )
				|| '' !== (string) ( $stored['active_run_id'] ?? '' )
				|| 'localized_slug_copied_from_source' !== (string) ( $stored['publish_preflight_correction']['code'] ?? '' )
				|| (string) $job['artifact_revision'] !== (string) ( $stored['publish_preflight_correction']['artifact_revision'] ?? '' )
			) {
				throw new RuntimeException( 'Safe correction did not preserve the required lifecycle evidence.' );
			}
		} elseif ( maybe_serialize( $job ) !== maybe_serialize( $stored ) ) {
			throw new RuntimeException( 'Rejected preflight mutated the Job for ' . $name );
		}
	}

	if ( maybe_serialize( $quality_record ) !== maybe_serialize( get_option( $quality_key ) ) ) {
		throw new RuntimeException( 'Prior immutable Quality evidence was changed.' );
	}

	echo "Correctable publish preflight WordPress runtime: OK\n";
} finally {
	foreach ( array_reverse( $keys ) as $key ) {
		delete_option( $key );
	}
}

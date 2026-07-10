<?php
/**
 * Dependency-light workflow-state contract checks.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/trait-assignment-authority.php';
require_once dirname( __DIR__ ) . '/includes/trait-translation-reservations.php';
require_once dirname( __DIR__ ) . '/includes/trait-agent-session-identity.php';
require_once dirname( __DIR__ ) . '/includes/trait-translation-provenance.php';
require_once dirname( __DIR__ ) . '/includes/trait-heartbeat-workflow.php';
require_once dirname( __DIR__ ) . '/includes/class-workflow-state-model.php';
require_once dirname( __DIR__ ) . '/includes/trait-workflow-state.php';

final class Devenia_AI_Translations_Workflow_State_Contract {
	use Devenia_AI_Translations_Workflow_State;

	private static function source_work_queue_definition( string $state ): array {
		return array();
	}
}

function invoke_workflow_state_method( string $method, array $arguments ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations_Workflow_State_Contract::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

$state_cases = array(
	'missing' => array(),
	'content_integrity_repair' => array( 'translation_status' => 'needs_review', 'status' => 'publish', 'content_integrity' => array( 'issue_count' => 1 ) ),
	'stale' => array( 'translation_status' => 'stale', 'status' => 'publish' ),
	'needs_review' => array( 'translation_status' => 'needs_review', 'status' => 'publish' ),
	'draft' => array( 'translation_status' => 'draft', 'status' => 'draft' ),
	'needs_linguistic_review' => array( 'translation_status' => 'reviewed', 'status' => 'publish', 'linguistic_review_state' => array( 'passed' => false ) ),
	'ready_to_publish' => array( 'translation_status' => 'reviewed', 'status' => 'publish', 'linguistic_review_state' => array( 'passed' => true ) ),
	'complete' => array( 'translation_status' => 'published', 'status' => 'publish', 'linguistic_review_state' => array( 'passed' => true ) ),
);

$failures = array();
foreach ( $state_cases as $expected => $translation ) {
	$actual = Devenia_AI_Translations_Workflow_State_Model::classify_translation( $translation );
	if ( $expected !== $actual ) {
		$failures[] = array( 'case' => $expected, 'actual' => $actual );
	}
}

$action_cases = array(
	'missing' => 'create_translation',
	'stale' => 'refresh_translation_from_source',
	'needs_review' => 'run_qa_and_review',
	'ready_to_publish' => 'publish_translation',
	'reserved' => 'wait_for_reservation_or_claim_expiry',
	'complete' => 'none',
);
foreach ( $action_cases as $state => $expected ) {
	$actual = Devenia_AI_Translations_Workflow_State_Model::next_action( $state );
	if ( $expected !== $actual ) {
		$failures[] = array( 'case' => 'action:' . $state, 'actual' => $actual );
	}
}

$review_obligation_cases = array(
	'all_missing' => array( '', '', '', 'linguistic_review' ),
	'linguistic_complete' => array( '2026-07-10 00:00:00', '', '', 'quality_review' ),
	'quality_complete' => array( '2026-07-10 00:00:00', '2026-07-10 00:01:00', '', 'final_review' ),
	'all_complete' => array( '2026-07-10 00:00:00', '2026-07-10 00:01:00', '2026-07-10 00:02:00', '' ),
	'out_of_order_quality_evidence' => array( '', '2026-07-10 00:01:00', '', 'linguistic_review' ),
);
foreach ( $review_obligation_cases as $case => $arguments ) {
	$expected = array_pop( $arguments );
	$actual = Devenia_AI_Translations_Workflow_State_Model::next_review_obligation( ...$arguments );
	if ( $expected !== $actual ) {
		$failures[] = array( 'case' => 'review_obligation:' . $case, 'actual' => $actual );
	}
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'state_cases' => count( $state_cases ), 'action_cases' => count( $action_cases ), 'review_obligation_cases' => count( $review_obligation_cases ) ), JSON_PRETTY_PRINT ) . PHP_EOL;

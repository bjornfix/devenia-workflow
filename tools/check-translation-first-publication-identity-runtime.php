<?php
/** Deterministic first-publication Translation Identity Authority contract. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int { return abs( (int) $value ); }
}

require_once dirname( __DIR__ ) . '/includes/trait-translation-job-quality-authority.php';

final class Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test {
	use Devenia_Workflow_Translation_Job_Quality_Authority;

	/** @param array<string,mixed> $job @param array<string,mixed> $artifact @param array<string,mixed> $quality */
	public static function authorized( array $job, array $artifact, array $quality, int $translation_id, bool $require_applied_surface, int $resolved_translation_id ): bool {
		return self::translation_job_translation_identity_authorized( $job, $artifact, $quality, $translation_id, $require_applied_surface, $resolved_translation_id );
	}
}

$existing_job = array( 'status' => 'ready_to_publish', 'translation_id' => 42 );
$existing_artifact = array( 'translation_id' => 42 );
$existing_quality = array( 'translation_id' => 42 );
$first_pending_job = array( 'status' => 'ready_to_publish', 'translation_id' => 0 );
$first_published_job = array( 'status' => 'published', 'translation_id' => 84 );
$first_artifact = array( 'translation_id' => 0 );
$first_quality = array( 'translation_id' => 0 );

$cases = array(
	'existing_exact' => Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( $existing_job, $existing_artifact, $existing_quality, 42, false, 42 ),
	'first_pending' => Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( $first_pending_job, $first_artifact, $first_quality, 0, false, 0 ),
	'first_published' => Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( $first_published_job, $first_artifact, $first_quality, 84, true, 84 ),
	'wrong_relation_denied' => ! Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( $first_published_job, $first_artifact, $first_quality, 84, true, 85 ),
	'premature_transition_denied' => ! Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( array( 'status' => 'ready_to_publish', 'translation_id' => 84 ), $first_artifact, $first_quality, 84, false, 84 ),
	'mutated_quality_denied' => ! Devenia_Workflow_Translation_First_Publication_Identity_Runtime_Test::authorized( $first_published_job, $first_artifact, array( 'translation_id' => 84 ), 84, true, 84 ),
);

if ( in_array( false, $cases, true ) ) {
	throw new RuntimeException( 'First-publication Translation Identity Authority failed: ' . json_encode( $cases ) );
}

echo "First-publication Translation Identity Authority runtime passed.\n";

<?php
/**
 * Dependency-light contract for scope-correct heartbeat Work Item actions.
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

require_once dirname( __DIR__ ) . '/includes/trait-heartbeat-workflow.php';

final class Devenia_AI_Translations_Heartbeat_Work_Item_Scope_Contract {
	use Devenia_AI_Translations_Heartbeat_Workflow;

	private static function source_work_queue_definition( string $work_type ): array {
		return array(
			'work_type'            => $work_type,
			'action'               => 'repair_content_integrity',
			'workflow_step'        => 'draft_write',
			'required_ability'     => 'content/update-post',
			'completion_abilities' => array(
				'content/update-post',
				'ai-translations/mark-source-content-integrity-reviewed',
			),
			'completion_policy'    => 'Resolve the source Work Item.',
		);
	}

	public static function action( string $work_scope, array $source_editor = array() ): array {
		return self::heartbeat_action_for_obligation(
			'content_integrity_repair',
			array( 'work_scope' => $work_scope, 'source_editor' => $source_editor )
		);
	}
}

$source = Devenia_AI_Translations_Heartbeat_Work_Item_Scope_Contract::action( 'source' );
$translation = Devenia_AI_Translations_Heartbeat_Work_Item_Scope_Contract::action( 'translation' );
$builder = Devenia_AI_Translations_Heartbeat_Work_Item_Scope_Contract::action(
	'source',
	array(
		'editor'                 => 'native_builder',
		'available'              => true,
		'read_ability'           => 'builder/get-data',
		'content_write_ability'  => 'builder/update-text',
		'design_write_ability'   => 'builder/update-element',
		'completion_abilities'   => array( 'builder/update-text' ),
		'native_controls_only'   => true,
		'public_route_immutable' => true,
		'instructions'           => 'Use native builder controls. Do not use custom CSS.',
	)
);
$failures = array();

if ( 'content/update-post' !== ( $source['required_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'source_required_ability', 'actual' => $source );
}
if ( ! in_array( 'ai-translations/mark-source-content-integrity-reviewed', $source['completion_abilities'] ?? array(), true ) ) {
	$failures[] = array( 'case' => 'source_noop_marker', 'actual' => $source );
}
if ( 'ai-translations/upsert-page' !== ( $translation['required_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'translation_required_ability', 'actual' => $translation );
}
if ( array( 'ai-translations/upsert-page' ) !== ( $translation['completion_abilities'] ?? array() ) ) {
	$failures[] = array( 'case' => 'translation_completion_ability', 'actual' => $translation );
}
if ( false !== strpos( (string) ( $translation['instructions'] ?? '' ), 'mark-source-content-integrity-reviewed' ) ) {
	$failures[] = array( 'case' => 'translation_source_marker_leak', 'actual' => $translation );
}
if ( 'builder/update-text' !== ( $builder['required_ability'] ?? '' ) ) {
	$failures[] = array( 'case' => 'builder_required_ability', 'actual' => $builder );
}
if ( false === strpos( (string) ( $builder['instructions'] ?? '' ), 'Do not use custom CSS' ) ) {
	$failures[] = array( 'case' => 'builder_native_instructions', 'actual' => $builder );
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'cases' => 7 ), JSON_PRETTY_PRINT ) . PHP_EOL;

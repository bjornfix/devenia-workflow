<?php
/**
 * Dependency-light Assignment Lifecycle and Work Item Planner contracts.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['assignment_contract_options'] = array();

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) ? $GLOBALS['assignment_contract_options'][ $key ] : $default;
}

function add_option( $key, $value ): bool {
	if ( array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) ) {
		return false;
	}
	$GLOBALS['assignment_contract_options'][ $key ] = $value;
	return true;
}

function update_option( $key, $value ): bool {
	$GLOBALS['assignment_contract_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['assignment_contract_options'][ $key ] );
	return true;
}

function wp_cache_delete(): void {}

function maybe_serialize( $value ): string {
	return serialize( $value );
}

function wp_generate_uuid4(): string {
	static $counter = 0;
	++$counter;
	return sprintf( '00000000-0000-4000-8000-%012d', $counter );
}

function get_the_title( $post_id ): string {
	return 'Source ' . absint( $post_id );
}

final class Assignment_Contract_WPDB {
	public $options = 'wp_options';

	public function prepare( string $query, ...$args ): array {
		return array( 'query' => $query, 'args' => $args );
	}

	public function query( array $prepared ): int {
		$query = ltrim( $prepared['query'] );
		$args  = $prepared['args'];
		if ( 0 === strpos( $query, 'INSERT IGNORE ' ) ) {
			list( $key, $serialized ) = $args;
			if ( array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) ) {
				return 0;
			}
			$GLOBALS['assignment_contract_options'][ $key ] = unserialize( $serialized );
			return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE ' ) ) {
			list( $replacement, $key, $expected ) = $args;
			if ( ! array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) || serialize( $GLOBALS['assignment_contract_options'][ $key ] ) !== $expected ) {
				return 0;
			}
			$GLOBALS['assignment_contract_options'][ $key ] = unserialize( $replacement );
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE ' ) ) {
			list( $key, $expected ) = $args;
			if ( ! array_key_exists( $key, $GLOBALS['assignment_contract_options'] ) || serialize( $GLOBALS['assignment_contract_options'][ $key ] ) !== $expected ) {
				return 0;
			}
			unset( $GLOBALS['assignment_contract_options'][ $key ] );
			return 1;
		}

		return 0;
	}

	public function get_col( array $prepared ): array {
		$like   = (string) ( $prepared['args'][0] ?? '' );
		$prefix = rtrim( $like, '%' );
		return array_values(
			array_filter(
				array_keys( $GLOBALS['assignment_contract_options'] ),
				static function ( string $key ) use ( $prefix ): bool {
					return 0 === strpos( $key, $prefix );
				}
			)
		);
	}

	public function esc_like( string $value ): string {
		return $value;
	}
}

$GLOBALS['wpdb'] = new Assignment_Contract_WPDB();

require_once dirname( __DIR__ ) . '/includes/trait-work-item-planner.php';
require_once dirname( __DIR__ ) . '/includes/trait-atomic-option-store.php';
require_once dirname( __DIR__ ) . '/includes/trait-assignment-lifecycle.php';

final class Devenia_AI_Translations_Assignment_Contract {
	use Devenia_AI_Translations_Work_Item_Planner;
	use Devenia_AI_Translations_Atomic_Option_Store;
	use Devenia_AI_Translations_Assignment_Lifecycle;

	const MAX_TRANSLATION_CLAIM_TTL = 14400;
	const OPTION_TRANSLATION_CLAIM_PREFIX = 'translation_claim_';
	const OPTION_WORK_CLAIM_PREFIX = 'source_claim_';
	const OPTION_ASSIGNMENT_PREFIX = 'assignment_';
	const OPTION_ASSIGNMENT_ITEM_PREFIX = 'assignment_item_';
	const OPTION_ASSIGNMENT_OUTCOME_PREFIX = 'assignment_outcome_';
	const OPTION_ASSIGNMENT_LATEST_OUTCOME_PREFIX = 'assignment_latest_outcome_';
	const OPTION_ASSIGNMENT_BLOCK_PREFIX = 'assignment_block_';

	public static $items = array();
	public static $identity = array(
		'success'          => true,
		'actor_id'         => 'ola',
		'step_token_label' => 'ola',
		'agent_session_id' => 'session-ola',
		'control_scope_id' => 'session-ola',
		'process_id'       => 'session-ola',
		'session_origin'   => 'independent_session',
	);

	private static function agent_session_input_schema_properties(): array {
		$field = array( 'type' => 'string' );
		return array(
			'llm_vendor'       => $field,
			'llm_client'       => $field,
			'authority_vendor' => $field,
			'authority_client' => $field,
		);
	}

	private static function session_binding_token_input_schema(): array {
		return array( 'type' => 'string' );
	}

	private static function translation_step_token_gate(): array {
		return self::$identity;
	}

	private static function normalize_control_scope_id( string $value ): string {
		return sanitize_text_field( $value );
	}

	private static function public_heartbeat_identity( array $identity ): array {
		return $identity;
	}

	private static function heartbeat_policy(): array {
		return array( 'mode' => 'assignment_lifecycle' );
	}

	private static function record_heartbeat_state(): void {}

	private static function error( string $message, string $code = 'error', array $extra = array() ): array {
		return array_merge( array( 'success' => false, 'message' => $message, 'code' => $code ), $extra );
	}

	private static function workflow_source_candidates(): array {
		return array( 101 );
	}

	private static function workflow_work_items_for_sources(): array {
		return array(
			'totals' => array( 'needs_draft_work' => count( self::$items ) ),
			'items'  => self::$items,
		);
	}

	private static function heartbeat_supported_obligations(): array {
		return array( 'draft_write' );
	}

	private static function heartbeat_actionable_obligations( $obligations ): array {
		return is_array( $obligations ) && in_array( 'draft_write', $obligations, true ) ? array( 'draft_write' ) : array();
	}

	private static function heartbeat_action_for_obligation(): array {
		return array(
			'action'             => 'write_missing_translation',
			'workflow_step'      => 'draft_write',
			'required_ability'   => 'ai-translations/upsert-page',
			'completion_abilities' => array( 'ai-translations/upsert-page' ),
			'completion_policy'  => 'Resolve the Work Item revision.',
			'instructions'       => 'Write the translation.',
		);
	}

	private static function heartbeat_obligation_uses_draft_work_identity(): bool {
		return true;
	}

	private static function heartbeat_draft_work_eligibility( array $identity ): array {
		return array( 'success' => true, 'draft_writer' => $identity );
	}

	private static function heartbeat_translation_review_eligibility(): array {
		return array( 'success' => true );
	}

	private static function heartbeat_review_surface_guidance(): array {
		return array();
	}

	private static function heartbeat_independence_summary(): array {
		return array( 'server_checked' => true );
	}

	private static function heartbeat_skip_summary( array $item, string $reason ): array {
		return array(
			'reason'         => sanitize_key( $reason ),
			'work_item_id'   => sanitize_text_field( (string) ( $item['work_item_id'] ?? '' ) ),
			'revision'       => sanitize_text_field( (string) ( $item['revision'] ?? '' ) ),
			'source_id'      => absint( $item['source_id'] ?? 0 ),
			'translation_id' => absint( $item['translation_id'] ?? 0 ),
			'language'       => sanitize_key( (string) ( $item['language'] ?? '' ) ),
		);
	}

	private static function is_translation_language( string $language ): bool {
		return '' !== sanitize_key( $language );
	}

	private static function translation_reservation_option_name( int $source_id, string $language ): string {
		return self::OPTION_TRANSLATION_CLAIM_PREFIX . $source_id . '_' . sanitize_key( $language );
	}

	private static function source_work_reservation_option_name( int $source_id, string $work_type ): string {
		return self::OPTION_WORK_CLAIM_PREFIX . $source_id . '_' . sanitize_key( $work_type );
	}

	private static function translation_reservation_for_language( int $source_id, string $language, bool $include_expired = false ): array {
		$claim = get_option( self::translation_reservation_option_name( $source_id, $language ), array() );
		$claim = is_array( $claim ) ? self::sanitize_translation_reservation( $claim ) : array();
		return $claim && ( $include_expired || empty( $claim['expired'] ) ) ? $claim : array();
	}

	private static function source_work_reservation_for_type(): array {
		return array();
	}

	private static function sanitize_translation_reservation( array $claim ): array {
		$claim['expired'] = strtotime( (string) ( $claim['expires_at'] ?? '' ) ) <= time();
		return $claim;
	}

	private static function sanitize_source_work_reservation( array $claim ): array {
		return self::sanitize_translation_reservation( $claim );
	}

	private static function public_translation_reservation( array $claim ): array {
		$public = $claim;
		unset( $public['token'] );
		$public['work_scope'] = 'translation';
		return $public;
	}

	private static function public_source_work_reservation( array $claim ): array {
		$public = $claim;
		unset( $public['token'] );
		$public['work_scope'] = 'source';
		return $public;
	}

	private static function assignment_authority_reservation_input( array $selected, array $identity, array $input, int $ttl_seconds, string $note ): array {
		return array_merge(
			$input,
			array(
				'assignment_id'             => $selected['assignment_id'],
				'work_item_id'              => $selected['work_item_id'],
				'work_item_revision'        => $selected['revision'],
				'assignment_action'         => $selected['action'],
				'assignment_obligation'     => $selected['obligation'],
				'assignment_workflow_step'  => $selected['workflow_step'],
				'assignment_translation_id' => $selected['translation_id'],
				'assignment_work_type'      => $selected['work_type'],
				'source_id'                 => $selected['source_id'],
				'language'                  => $selected['language'],
				'work_scope'                => $selected['work_scope'],
				'work_type'                 => $selected['work_type'],
				'agent_session_id'          => $identity['agent_session_id'],
				'actor_id'                  => $identity['actor_id'],
				'ttl_seconds'               => $ttl_seconds,
				'note'                      => $note,
			)
		);
	}

	private static function reserve_translation_work( array $input ): array {
		$key = self::translation_reservation_option_name( absint( $input['source_id'] ), sanitize_key( (string) $input['language'] ) );
		$token = 'token-' . substr( hash( 'sha256', (string) $input['assignment_id'] ), 0, 12 );
		$claim = array(
			'work_scope'               => 'translation',
			'work_type'                => 'translation',
			'source_id'                => absint( $input['source_id'] ),
			'language'                 => sanitize_key( (string) $input['language'] ),
			'token'                    => $token,
			'agent_session_id'         => sanitize_text_field( (string) $input['agent_session_id'] ),
			'actor_id'                 => sanitize_key( (string) $input['actor_id'] ),
			'assignment_id'            => sanitize_text_field( (string) $input['assignment_id'] ),
			'work_item_id'             => sanitize_text_field( (string) $input['work_item_id'] ),
			'work_item_revision'       => sanitize_text_field( (string) $input['work_item_revision'] ),
			'assignment_action'        => sanitize_key( (string) $input['assignment_action'] ),
			'assignment_obligation'    => sanitize_key( (string) $input['assignment_obligation'] ),
			'assignment_workflow_step' => sanitize_key( (string) $input['assignment_workflow_step'] ),
			'assignment_translation_id'=> absint( $input['assignment_translation_id'] ),
			'assignment_work_type'     => sanitize_key( (string) $input['assignment_work_type'] ),
			'claimed_at'               => gmdate( 'c' ),
			'expires_at'               => gmdate( 'c', time() + absint( $input['ttl_seconds'] ) ),
			'note'                     => sanitize_textarea_field( (string) $input['note'] ),
		);
		if ( ! self::atomic_create_option( $key, $claim ) ) {
			return array( 'success' => false, 'claims' => array(), 'claim_token' => '' );
		}

		return array(
			'success'     => true,
			'claim_token' => $token,
			'claims'      => array( self::public_translation_reservation( $claim ) ),
		);
	}

	private static function assignment_authority_claim_identity( array $claim_result ): array {
		return array(
			'success'     => ! empty( $claim_result['claims'][0] ),
			'reservation' => $claim_result['claims'][0] ?? array(),
		);
	}

	public static function call( string $method, array $arguments = array() ) {
		$reflection = new ReflectionMethod( self::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $arguments );
	}
}

function assignment_contract_item( string $revision = 'r_1' ): array {
	return array(
		'work_item_id'   => 'wi_1',
		'revision'       => $revision,
		'work_type'      => 'draft_write',
		'work_scope'     => 'translation',
		'source_id'      => 101,
		'source_title'   => 'Source 101',
		'translation_id' => 0,
		'language'       => 'nb',
		'post_status'    => '',
		'obligations'    => array( 'draft_write' ),
	);
}

function assignment_contract_reset( string $session = 'session-ola', string $actor = 'ola' ): void {
	$GLOBALS['assignment_contract_options'] = array();
	Devenia_AI_Translations_Assignment_Contract::$items = array( assignment_contract_item() );
	Devenia_AI_Translations_Assignment_Contract::$identity = array(
		'success'          => true,
		'actor_id'         => $actor,
		'step_token_label' => $actor,
		'agent_session_id' => $session,
		'control_scope_id' => $session,
		'process_id'       => $session,
		'session_origin'   => 'independent_session',
	);
}

$failures = array();
$assert = static function ( bool $condition, string $case, $actual = null ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = array( 'case' => $case, 'actual' => $actual );
	}
};

assignment_contract_reset();
$atomic_first = Devenia_AI_Translations_Assignment_Contract::call( 'atomic_create_option', array( 'atomic_lock', array( 'owner' => 'ola' ) ) );
$atomic_second = Devenia_AI_Translations_Assignment_Contract::call( 'atomic_create_option', array( 'atomic_lock', array( 'owner' => 'kari' ) ) );
$assert( $atomic_first, 'atomic_option:first_inserted' );
$assert( ! $atomic_second, 'atomic_option:duplicate_rejected' );
$assert( array( 'owner' => 'ola' ) === get_option( 'atomic_lock' ), 'atomic_option:existing_value_preserved', get_option( 'atomic_lock' ) );

assignment_contract_reset();
$identity = Devenia_AI_Translations_Assignment_Contract::$identity;
$input = array( 'agent_session_id' => 'session-ola', 'ttl_seconds' => 600, 'limit' => 500 );
$kari_identity = $identity;
$kari_identity['actor_id'] = 'kari';
$kari_identity['step_token_label'] = 'kari';
$kari_identity['agent_session_id'] = 'session-kari';
$kari_identity['control_scope_id'] = 'session-kari';
$kari_input = array( 'agent_session_id' => 'session-kari', 'ttl_seconds' => 600, 'limit' => 500 );
$first = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $input, $identity ) );
$second = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $input, $identity ) );
$assert( ! empty( $first['success'] ) && 'claimed' === $first['mode'], 'accept:first_claimed', $first );
$assert( ! empty( $second['success'] ) && 'resumed' === $second['mode'], 'accept:idempotent_resume', $second );
$assert( ( $first['assignment']['assignment_id'] ?? '' ) === ( $second['assignment']['assignment_id'] ?? '' ), 'accept:same_assignment_id' );
$reservation_count = count( array_filter( array_keys( $GLOBALS['assignment_contract_options'] ), static fn( string $key ): bool => 0 === strpos( $key, 'translation_claim_' ) ) );
$assert( 1 === $reservation_count, 'accept:one_reservation', $reservation_count );

$assignment_key = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_session_option_name', array( 'session-ola' ) );
unset( $GLOBALS['assignment_contract_options'][ $assignment_key ] );
$recovered = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_current_result', array( $input, $identity, true ) );
$assert( ! empty( $recovered['success'] ) && ! empty( $recovered['has_assignment'] ) && ! empty( $recovered['recovered'] ), 'recovery:from_reservation', $recovered );
$assert( ( $first['assignment']['assignment_id'] ?? '' ) === ( $recovered['assignment']['assignment_id'] ?? '' ), 'recovery:same_assignment_id' );

$completion_rejected = Devenia_AI_Translations_Assignment_Contract::call(
	'complete_assignment',
	array( array( 'agent_session_id' => 'session-ola', 'outcome' => 'completed' ) )
);
$assert( empty( $completion_rejected['success'] ) && 'assignment_completion_not_resolved' === ( $completion_rejected['code'] ?? '' ), 'completion:reject_current_revision', $completion_rejected );

Devenia_AI_Translations_Assignment_Contract::$items = array();
$completion = Devenia_AI_Translations_Assignment_Contract::call(
	'complete_assignment',
	array( array( 'agent_session_id' => 'session-ola', 'outcome' => 'completed' ) )
);
$assert( ! empty( $completion['success'] ) && ! empty( $completion['released'] ), 'completion:resolved_revision', $completion );
$current_after_completion = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_current_result', array( $input, $identity, true ) );
$assert( ! empty( $current_after_completion['success'] ) && empty( $current_after_completion['has_assignment'] ), 'completion:no_current_assignment', $current_after_completion );

assignment_contract_reset();
$orphan_accept = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $input, $identity ) );
$orphan_selected = $orphan_accept['assignment']['selected'] ?? array();
$orphan_reservation_key = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_reservation_option_name_for_selected', array( $orphan_selected ) );
$foreign_reservation = get_option( $orphan_reservation_key, array() );
$foreign_reservation['token'] = 'foreign-token';
$foreign_reservation['assignment_id'] = 'as_foreign';
$foreign_reservation['agent_session_id'] = 'session-kari';
$foreign_reservation['actor_id'] = 'kari';
update_option( $orphan_reservation_key, $foreign_reservation );
$orphan_current = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_current_result', array( $input, $identity, true ) );
$foreign_after_reconcile = get_option( $orphan_reservation_key, array() );
$assert( ! empty( $orphan_current['success'] ) && empty( $orphan_current['has_assignment'] ) && ! empty( $orphan_current['reconciled'] ), 'orphan:assignment_reconciled', $orphan_current );
$assert( 'as_foreign' === ( $foreign_after_reconcile['assignment_id'] ?? '' ), 'orphan:foreign_reservation_preserved', $foreign_after_reconcile );

assignment_contract_reset();
$blocked_assignment = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $input, $identity ) );
$blocked = Devenia_AI_Translations_Assignment_Contract::call(
	'complete_assignment',
	array(
		array(
			'agent_session_id'  => 'session-ola',
			'outcome'           => 'blocked',
			'blocker_category'  => 'tooling',
			'evidence_summary'  => 'The required ability rejected the documented payload.',
			'evidence'          => array( 'code=ability_contract_rejected' ),
		)
	)
);
$assert( ! empty( $blocked_assignment['success'] ) && ! empty( $blocked['success'] ), 'blocked:recorded', $blocked );
$blocked_plan = Devenia_AI_Translations_Assignment_Contract::call( 'work_item_plan', array( $input, $identity ) );
$assert( 0 === count( $blocked_plan['candidates'] ?? array() ), 'blocked:same_revision_suppressed', $blocked_plan );
$assert( 1 === absint( $blocked_plan['coverage']['skipped_by_reason']['blocked_outcome_current_revision'] ?? 0 ), 'blocked:structured_skip_reason', $blocked_plan['coverage'] ?? array() );

Devenia_AI_Translations_Assignment_Contract::$items = array( assignment_contract_item( 'r_2' ) );
$same_actor_revision_plan = Devenia_AI_Translations_Assignment_Contract::call( 'work_item_plan', array( $input, $identity ) );
$new_revision_plan = Devenia_AI_Translations_Assignment_Contract::call( 'work_item_plan', array( $kari_input, $kari_identity ) );
$assert( 0 === count( $same_actor_revision_plan['candidates'] ?? array() ), 'cursor:same_actor_successor_revision_suppressed', $same_actor_revision_plan );
$assert( 1 === count( $new_revision_plan['candidates'] ?? array() ), 'blocked:new_revision_eligible_for_independent_actor', $new_revision_plan );
$assert( ( $new_revision_plan['coverage']['claimable_for_actor'] ?? 0 ) === count( $new_revision_plan['candidates'] ?? array() ), 'planner:coverage_selector_parity', $new_revision_plan );

Devenia_AI_Translations_Assignment_Contract::$items = array( assignment_contract_item() );
$resolved_block = Devenia_AI_Translations_Assignment_Contract::call(
	'resolve_assignment_block',
	array(
		array(
			'agent_session_id'  => 'session-ola',
			'work_item_id'      => 'wi_1',
			'revision'          => 'r_1',
			'resolution_summary'=> 'The ability contract was fixed and verified.',
			'confirm'           => 'ai-translations/resolve-assignment-block',
		)
	)
);
$resolved_plan = Devenia_AI_Translations_Assignment_Contract::call( 'work_item_plan', array( $kari_input, $kari_identity ) );
$assert( ! empty( $resolved_block['success'] ) && ! empty( $resolved_block['resolved'] ), 'blocked:coordinator_resolution', $resolved_block );
$assert( 1 === count( $resolved_plan['candidates'] ?? array() ), 'blocked:resolved_revision_eligible', $resolved_plan );

assignment_contract_reset();
$ola = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $input, $identity ) );
$kari = Devenia_AI_Translations_Assignment_Contract::call( 'assignment_lifecycle_accept', array( $kari_input, $kari_identity ) );
$assert( ! empty( $ola['success'] ) && 'claimed' === $ola['mode'], 'concurrency:first_session_claimed', $ola );
$assert( ! empty( $kari['success'] ) && 'wait' === $kari['action'], 'concurrency:item_revision_single_owner', $kari );

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode(
	array(
		'success'   => true,
		'contracts' => array(
			'atomic_option_create_only',
			'idempotent_accept',
			'single_active_assignment_per_session',
			'crash_recovery_from_reservation',
			'orphan_assignment_reconciliation',
			'revision_aware_completion',
			'blocked_revision_suppression',
			'new_revision_independent_actor_eligibility',
			'work_item_outcome_cursor',
			'coordinator_block_resolution',
			'planner_coverage_selector_parity',
			'single_owner_per_item_revision',
		),
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

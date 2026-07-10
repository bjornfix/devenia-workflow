<?php
/**
 * Server-owned Assignment Lifecycle for contributor work.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Assignment_Lifecycle {
	/**
	 * Input shared by Assignment identity operations.
	 */
	private static function assignment_identity_input_schema(): array {
		return array(
			'agent_session_id' => array(
				'type'        => 'string',
				'description' => 'Stable independent contributor session identifier.',
			),
			'llm_vendor'            => self::agent_session_input_schema_properties()['llm_vendor'],
			'llm_client'            => self::agent_session_input_schema_properties()['llm_client'],
			'authority_vendor'      => self::agent_session_input_schema_properties()['authority_vendor'],
			'authority_client'      => self::agent_session_input_schema_properties()['authority_client'],
			'session_binding_token' => self::session_binding_token_input_schema(),
		);
	}

	private static function current_assignment_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => self::assignment_identity_input_schema(),
			'additionalProperties' => false,
		);
	}

	private static function renew_assignment_input_schema(): array {
		$properties = self::assignment_identity_input_schema();
		$properties['assignment_id'] = array(
			'type'        => 'string',
			'description' => 'Optional Assignment identifier. When supplied it must match the current server Assignment.',
		);
		$properties['ttl_seconds'] = array(
			'type'        => 'integer',
			'default'     => 600,
			'minimum'     => 60,
			'maximum'     => self::MAX_TRANSLATION_CLAIM_TTL,
			'description' => 'New lease duration for the current Assignment and its internal Reservation.',
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	private static function complete_assignment_input_schema(): array {
		$properties = self::assignment_identity_input_schema();
		$properties['assignment_id'] = array(
			'type'        => 'string',
			'description' => 'Optional Assignment identifier. When supplied it must match the current server Assignment.',
		);
		$properties['outcome'] = array(
			'type'        => 'string',
			'enum'        => array( 'completed', 'blocked', 'abandoned' ),
			'description' => 'Terminal contributor outcome. completed is accepted only after the assigned Work Item revision is resolved.',
		);
		$properties['blocker_category'] = array(
			'type'        => 'string',
			'enum'        => self::assignment_blocker_categories(),
			'description' => 'Required when outcome=blocked.',
		);
		$properties['evidence_summary'] = array(
			'type'        => 'string',
			'description' => 'Required concise evidence when outcome=blocked.',
		);
		$properties['evidence'] = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Optional structured evidence lines, identifiers, or verified checks.',
		);
		$properties['note'] = array( 'type' => 'string' );

		return array(
			'type'                 => 'object',
			'required'             => array( 'outcome' ),
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	private static function resolve_assignment_block_input_schema(): array {
		$properties = self::assignment_identity_input_schema();
		$properties['work_item_id'] = array( 'type' => 'string' );
		$properties['revision'] = array( 'type' => 'string' );
		$properties['resolution_summary'] = array(
			'type'        => 'string',
			'description' => 'Concrete coordinator evidence that the blocker was handled or is no longer actionable.',
		);
		$properties['confirm'] = array(
			'type'        => 'string',
			'description' => 'Must equal ai-translations/resolve-assignment-block.',
		);

		return array(
			'type'                 => 'object',
			'required'             => array( 'work_item_id', 'revision', 'resolution_summary', 'confirm' ),
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Public accept operation. next-heartbeat-action remains a compatibility Adapter.
	 */
	private static function accept_assignment( array $input ): array {
		$input['claim'] = true;
		$identity = self::assignment_lifecycle_identity( $input );
		if ( empty( $identity['success'] ) ) {
			return self::assignment_lifecycle_identity_error( $identity );
		}

		$result = self::assignment_lifecycle_accept( $input, $identity );
		self::record_heartbeat_state( $input, is_array( $result['selected'] ?? null ) ? $result['selected'] : array( 'action' => 'escalate', 'reason' => 'assignment_accept_failed' ), $identity );
		return $result;
	}

	private static function current_assignment( array $input ): array {
		$identity = self::assignment_lifecycle_identity( $input );
		if ( empty( $identity['success'] ) ) {
			return self::assignment_lifecycle_identity_error( $identity );
		}

		return self::assignment_lifecycle_current_result( $input, $identity, true );
	}

	private static function renew_assignment( array $input ): array {
		$identity = self::assignment_lifecycle_identity( $input );
		if ( empty( $identity['success'] ) ) {
			return self::assignment_lifecycle_identity_error( $identity );
		}

		$current = self::assignment_lifecycle_current_result( $input, $identity, true );
		if ( empty( $current['success'] ) || empty( $current['has_assignment'] ) ) {
			return empty( $current['success'] ) ? $current : self::assignment_error( 'No active Assignment exists for this contributor session.', 'assignment_not_found' );
		}

		$assignment = $current['assignment'];
		$expected_assignment_id = sanitize_text_field( (string) ( $input['assignment_id'] ?? '' ) );
		if ( '' !== $expected_assignment_id && ! hash_equals( (string) $assignment['assignment_id'], $expected_assignment_id ) ) {
			return self::assignment_error( 'The requested Assignment does not match the current server Assignment.', 'assignment_id_mismatch' );
		}

		$ttl_seconds = isset( $input['ttl_seconds'] )
			? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
			: 600;
		$renewed = self::assignment_lifecycle_renew_record( $assignment, $ttl_seconds );
		if ( empty( $renewed['success'] ) ) {
			return $renewed;
		}

		return array(
			'success'        => true,
			'has_assignment' => true,
			'assignment'     => self::public_assignment( $renewed['assignment'] ),
		);
	}

	private static function complete_assignment( array $input ): array {
		$identity = self::assignment_lifecycle_identity( $input );
		if ( empty( $identity['success'] ) ) {
			return self::assignment_lifecycle_identity_error( $identity );
		}

		$current = self::assignment_lifecycle_current_result( $input, $identity, true );
		if ( empty( $current['success'] ) || empty( $current['has_assignment'] ) ) {
			return empty( $current['success'] ) ? $current : self::assignment_error( 'No active Assignment exists for this contributor session.', 'assignment_not_found' );
		}

		$assignment = $current['assignment'];
		$expected_assignment_id = sanitize_text_field( (string) ( $input['assignment_id'] ?? '' ) );
		if ( '' !== $expected_assignment_id && ! hash_equals( (string) $assignment['assignment_id'], $expected_assignment_id ) ) {
			return self::assignment_error( 'The requested Assignment does not match the current server Assignment.', 'assignment_id_mismatch' );
		}

		$outcome = sanitize_key( (string) ( $input['outcome'] ?? '' ) );
		if ( ! in_array( $outcome, array( 'completed', 'blocked', 'abandoned' ), true ) ) {
			return self::assignment_error( 'Assignment outcome must be completed, blocked, or abandoned.', 'assignment_outcome_invalid' );
		}
		if ( 'completed' === $outcome && self::assignment_work_item_revision_is_current( $assignment['selected'] ) ) {
			return self::assignment_error(
				'The assigned Work Item revision is still current. Finish the required work or use a truthful blocked/abandoned outcome.',
				'assignment_completion_not_resolved',
				array(
					'work_item_id' => (string) $assignment['work_item_id'],
					'revision'     => (string) $assignment['revision'],
				)
			);
		}

		$blocker_category = sanitize_key( (string) ( $input['blocker_category'] ?? '' ) );
		$evidence_summary = trim( sanitize_textarea_field( (string) ( $input['evidence_summary'] ?? '' ) ) );
		if ( 'blocked' === $outcome && ( ! in_array( $blocker_category, self::assignment_blocker_categories(), true ) || '' === $evidence_summary ) ) {
			return self::assignment_error( 'A blocked Assignment requires a supported blocker_category and evidence_summary.', 'assignment_block_evidence_required' );
		}

		$evidence = array();
		if ( isset( $input['evidence'] ) && is_array( $input['evidence'] ) ) {
			$evidence = array_values( array_filter( array_map( 'sanitize_text_field', $input['evidence'] ) ) );
		}
		$transition = $assignment;
		$transition['status'] = 'completing';
		$transition['updated_at'] = gmdate( 'c' );
		$transition['pending_outcome'] = array(
			'outcome'          => $outcome,
			'blocker_category' => 'blocked' === $outcome ? $blocker_category : '',
			'evidence_summary' => 'blocked' === $outcome ? $evidence_summary : '',
			'evidence'         => $evidence,
			'note'             => sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ),
			'recorded_at'      => gmdate( 'c' ),
		);
		$key = self::assignment_session_option_name( (string) $assignment['agent_session_id'] );
		if ( ! self::assignment_compare_and_swap_option( $key, $assignment, $transition ) ) {
			return self::assignment_error( 'The Assignment changed while its outcome was being recorded. Fetch current-assignment and retry.', 'assignment_transition_conflict' );
		}

		$finished = self::assignment_finish_terminal_transition( $transition );
		if ( empty( $finished['success'] ) ) {
			return $finished;
		}

		return array(
			'success'       => true,
			'has_assignment'=> false,
			'assignment_id' => (string) $assignment['assignment_id'],
			'work_item_id'  => (string) $assignment['work_item_id'],
			'revision'      => (string) $assignment['revision'],
			'outcome'       => $outcome,
			'released'      => true,
		);
	}

	private static function resolve_assignment_block( array $input ): array {
		$identity = self::assignment_lifecycle_identity( $input );
		if ( empty( $identity['success'] ) ) {
			return self::assignment_lifecycle_identity_error( $identity );
		}
		$work_item_id = sanitize_text_field( (string) ( $input['work_item_id'] ?? '' ) );
		$revision = sanitize_text_field( (string) ( $input['revision'] ?? '' ) );
		$resolution_summary = trim( sanitize_textarea_field( (string) ( $input['resolution_summary'] ?? '' ) ) );
		if ( 'ai-translations/resolve-assignment-block' !== (string) ( $input['confirm'] ?? '' ) || '' === $work_item_id || '' === $revision || '' === $resolution_summary ) {
			return self::assignment_error( 'Resolving an Assignment block requires exact Work Item identity, a resolution summary, and explicit confirmation.', 'assignment_block_resolution_confirmation_required' );
		}

		$key = self::assignment_block_option_name( $work_item_id, $revision );
		$outcome = get_option( $key, array() );
		if ( ! is_array( $outcome ) || 'blocked' !== sanitize_key( (string) ( $outcome['outcome'] ?? '' ) ) ) {
			return array(
				'success'      => true,
				'resolved'     => false,
				'already_clear'=> true,
				'work_item_id' => $work_item_id,
				'revision'     => $revision,
			);
		}
		if ( ! hash_equals( $work_item_id, (string) ( $outcome['work_item_id'] ?? '' ) ) || ! hash_equals( $revision, (string) ( $outcome['revision'] ?? '' ) ) ) {
			return self::assignment_error( 'Stored Assignment block identity does not match the requested Work Item revision.', 'assignment_block_identity_mismatch' );
		}

		$outcome['resolved_at'] = gmdate( 'c' );
		$outcome['resolved_by_actor_id'] = sanitize_key( (string) ( $identity['actor_id'] ?? '' ) );
		$outcome['resolution_summary'] = $resolution_summary;
		update_option( self::assignment_outcome_option_name( (string) ( $outcome['assignment_id'] ?? '' ) ), $outcome, false );
		delete_option( $key );

		return array(
			'success'            => true,
			'resolved'           => true,
			'work_item_id'       => $work_item_id,
			'revision'           => $revision,
			'resolution_summary' => $resolution_summary,
		);
	}

	/**
	 * Accept or idempotently resume one server Assignment.
	 *
	 * @param array<string,mixed> $identity Verified contributor identity.
	 */
	private static function assignment_lifecycle_accept( array $input, array $identity ): array {
		$current = self::assignment_lifecycle_current_result( $input, $identity, true );
		if ( empty( $current['success'] ) && 'assignment_initializing' === ( $current['code'] ?? '' ) ) {
			$current = self::assignment_wait_for_initialized_record( $input, $identity );
		}
		if ( empty( $current['success'] ) ) {
			return $current;
		}
		if ( ! empty( $current['has_assignment'] ) ) {
			$ttl_seconds = isset( $input['ttl_seconds'] )
				? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
				: 600;
			$renewed = self::assignment_lifecycle_renew_record( $current['assignment'], $ttl_seconds );
			if ( empty( $renewed['success'] ) ) {
				return $renewed;
			}
			return self::assignment_accept_response( $renewed['assignment'], $identity, 'resumed', array() );
		}

		$agent_session_id = self::assignment_session_id( $identity, $input );
		if ( '' === $agent_session_id ) {
			return self::assignment_error( 'Assignment requires a stable contributor session identifier.', 'assignment_session_required' );
		}
		$assignment_id = 'as_' . wp_generate_uuid4();
		$now = time();
		$pending = array(
			'assignment_id'   => $assignment_id,
			'status'          => 'pending',
			'agent_session_id'=> $agent_session_id,
			'identity'        => self::public_heartbeat_identity( $identity ),
			'created_at'      => gmdate( 'c', $now ),
			'updated_at'      => gmdate( 'c', $now ),
			'expires_at'      => gmdate( 'c', $now + 120 ),
		);
		$pending = self::assignment_storage_record( self::sanitize_assignment( $pending ) );
		$key = self::assignment_session_option_name( $agent_session_id );
		if ( ! self::atomic_create_option( $key, $pending ) ) {
			wp_cache_delete( $key, 'options' );
			$current = self::assignment_wait_for_initialized_record( $input, $identity );
			if ( ! empty( $current['has_assignment'] ) ) {
				return self::assignment_accept_response( $current['assignment'], $identity, 'resumed', array() );
			}
			return empty( $current['success'] ) ? $current : self::assignment_error( 'Another accept request is initializing this session Assignment. Retry current-assignment.', 'assignment_initializing' );
		}

		$plan = self::work_item_assignment_plan( $input, $identity );
		if ( empty( $plan['success'] ) ) {
			self::assignment_compare_and_delete_option( $key, $pending );
			return $plan;
		}

		$skipped = isset( $plan['skipped'] ) && is_array( $plan['skipped'] ) ? $plan['skipped'] : array();
		$ttl_seconds = isset( $input['ttl_seconds'] )
			? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
			: 600;
		$note = sanitize_textarea_field( (string) ( $input['note'] ?? '' ) );
		foreach ( $plan['candidates'] ?? array() as $selected ) {
			if ( ! is_array( $selected ) ) {
				continue;
			}
			$selected['assignment_id'] = $assignment_id;
			$assignment_shell = array(
				'assignment_id'    => $assignment_id,
				'work_item_id'     => sanitize_text_field( (string) ( $selected['work_item_id'] ?? '' ) ),
				'revision'         => sanitize_text_field( (string) ( $selected['revision'] ?? '' ) ),
				'agent_session_id' => $agent_session_id,
				'selected'         => $selected,
			);
			if ( ! self::assignment_acquire_item_lock( $assignment_shell, $ttl_seconds ) ) {
				$skipped[] = self::heartbeat_skip_summary( $selected, 'assignment_conflict' );
				continue;
			}
			$reservation_input = self::assignment_authority_reservation_input( $selected, $identity, $input, $ttl_seconds, $note );
			$claim_result = self::reserve_translation_work( $reservation_input );
			if ( empty( $claim_result['success'] ) ) {
				self::assignment_release_item_lock( $assignment_shell );
				$skipped[] = self::heartbeat_skip_summary( $selected, 'claim_conflict' );
				continue;
			}
			$claim_identity = self::assignment_authority_claim_identity( $claim_result, $identity, $input );
			if ( empty( $claim_identity['success'] ) ) {
				self::assignment_release_claim_result( $selected, $claim_result );
				self::assignment_release_item_lock( $assignment_shell );
				$skipped[] = self::heartbeat_skip_summary( $selected, sanitize_key( (string) ( $claim_identity['code'] ?? 'claim_identity_mismatch' ) ) );
				continue;
			}

			$selected['claim_token'] = sanitize_text_field( (string) ( $claim_result['claim_token'] ?? '' ) );
			$selected['reservation'] = $claim_identity['reservation'];
			$assignment = array(
				'assignment_id'    => $assignment_id,
				'status'           => 'active',
				'work_item_id'     => sanitize_text_field( (string) ( $selected['work_item_id'] ?? '' ) ),
				'revision'         => sanitize_text_field( (string) ( $selected['revision'] ?? '' ) ),
				'agent_session_id' => $agent_session_id,
				'identity'         => self::public_heartbeat_identity( $identity ),
				'selected'         => $selected,
				'claim_token'      => $selected['claim_token'],
				'created_at'       => (string) $pending['created_at'],
				'claimed_at'       => gmdate( 'c' ),
				'updated_at'       => gmdate( 'c' ),
				'expires_at'       => sanitize_text_field( (string) ( $claim_identity['reservation']['expires_at'] ?? gmdate( 'c', time() + $ttl_seconds ) ) ),
				'ttl_seconds'      => $ttl_seconds,
				'note'             => $note,
			);
			if ( ! self::assignment_compare_and_swap_option( $key, $pending, $assignment ) ) {
				self::assignment_internal_release_reservation( $assignment );
				self::assignment_release_item_lock( $assignment );
				return self::assignment_error( 'The Assignment record changed after Reservation creation. The Reservation was released.', 'assignment_store_conflict' );
			}

			return self::assignment_accept_response( $assignment, $identity, 'claimed', $plan, $skipped );
		}

		self::assignment_compare_and_delete_option( $key, $pending );
		$wait = array(
			'action'       => 'wait',
			'reason'       => 'no_safe_action_for_this_actor',
			'instructions' => 'No eligible unreserved Work Item was found for this Assignment. Wait, back off, or let another independent contributor handle the remaining work.',
		);
		return array(
			'success'        => true,
			'action'         => 'wait',
			'mode'           => 'observe',
			'identity'       => self::public_heartbeat_identity( $identity ),
			'selected'       => $wait,
			'totals'         => $plan['totals'] ?? array(),
			'skipped_count'  => count( $skipped ),
			'skipped_sample' => array_slice( $skipped, 0, 10 ),
			'planner'        => array(
				'source_scan_count' => absint( $plan['source_scan_count'] ?? 0 ),
				'item_count'        => absint( $plan['item_count'] ?? 0 ),
			),
			'heartbeat_policy' => self::heartbeat_policy(),
		);
	}

	/**
	 * Let a concurrent accept request finish the same session Assignment.
	 *
	 * @param array<string,mixed> $identity Verified identity.
	 * @return array<string,mixed>
	 */
	private static function assignment_wait_for_initialized_record( array $input, array $identity ): array {
		$current = array( 'success' => false, 'code' => 'assignment_initializing' );
		for ( $attempt = 0; $attempt < 50; $attempt++ ) {
			usleep( 100000 );
			$current = self::assignment_lifecycle_current_result( $input, $identity, true );
			if ( ! empty( $current['success'] ) || 'assignment_initializing' !== ( $current['code'] ?? '' ) ) {
				return $current;
			}
		}

		return $current;
	}

	/**
	 * Fetch, recover, expire, or finish the Assignment owned by one identity.
	 *
	 * @param array<string,mixed> $identity Verified contributor identity.
	 */
	private static function assignment_lifecycle_current_result( array $input, array $identity, bool $recover ): array {
		$agent_session_id = self::assignment_session_id( $identity, $input );
		if ( '' === $agent_session_id ) {
			return self::assignment_error( 'Assignment requires a stable contributor session identifier.', 'assignment_session_required' );
		}

		$key = self::assignment_session_option_name( $agent_session_id );
		$stored = get_option( $key, array() );
		$assignment = is_array( $stored ) ? self::sanitize_assignment( $stored ) : array();
		if ( $assignment && ! self::assignment_identity_matches( $assignment, $identity ) ) {
			return self::assignment_error( 'The current Assignment belongs to a different contributor identity.', 'assignment_identity_mismatch' );
		}

		if ( $assignment && 'completing' === $assignment['status'] ) {
			$finished = self::assignment_finish_terminal_transition( $assignment );
			if ( empty( $finished['success'] ) ) {
				return $finished;
			}
			$assignment = array();
		}
		if ( $assignment && ! empty( $assignment['expired'] ) ) {
			$expired = $assignment;
			$expired['status'] = 'completing';
			$expired['pending_outcome'] = array(
				'outcome'          => 'expired',
				'blocker_category' => '',
				'evidence_summary' => '',
				'evidence'         => array(),
				'note'             => 'Assignment lease expired.',
				'recorded_at'      => gmdate( 'c' ),
			);
			if ( self::assignment_compare_and_swap_option( $key, $assignment, $expired ) ) {
				$finished = self::assignment_finish_terminal_transition( $expired );
				if ( empty( $finished['success'] ) ) {
					return $finished;
				}
			}
			$assignment = array();
		}
		if ( $assignment && 'active' === $assignment['status'] ) {
			$reservation = self::assignment_reservation_for_selected( $assignment['selected'], true );
			$reservation_matches = $reservation && self::assignment_reservation_matches( $reservation, $assignment );
			$remaining_ttl = max( 60, ( strtotime( (string) $assignment['expires_at'] ) ?: time() ) - time() );
			$item_lock_matches = $reservation_matches && self::assignment_acquire_item_lock( $assignment, $remaining_ttl );
			if ( ! $reservation_matches || ! $item_lock_matches ) {
				$orphaned = $assignment;
				$orphaned['status'] = 'completing';
				$orphaned['updated_at'] = gmdate( 'c' );
				$orphaned['pending_outcome'] = array(
					'outcome'          => 'abandoned',
					'blocker_category' => '',
					'evidence_summary' => '',
					'evidence'         => array( $reservation_matches ? 'assignment_item_lock_conflict' : 'assignment_reservation_ownership_lost' ),
					'note'             => 'Server reconciled an Assignment that no longer owned its exclusion locks.',
					'recorded_at'      => gmdate( 'c' ),
				);
				if ( self::assignment_compare_and_swap_option( $key, $assignment, $orphaned ) ) {
					$finished = self::assignment_finish_terminal_transition( $orphaned );
					if ( empty( $finished['success'] ) ) {
						return $finished;
					}
				}
				return array(
					'success'        => true,
					'has_assignment' => false,
					'assignment'     => null,
					'reconciled'     => true,
				);
			}
			return array(
				'success'        => true,
				'has_assignment' => true,
				'assignment'     => $assignment,
			);
		}

		if ( $recover ) {
			$recovered = self::assignment_recover_from_reservation( $agent_session_id, $identity, $assignment );
			if ( $recovered ) {
				return array(
					'success'        => true,
					'has_assignment' => true,
					'assignment'     => $recovered,
					'recovered'      => true,
				);
			}
		}

		if ( $assignment && 'pending' === $assignment['status'] ) {
			return self::assignment_error( 'This contributor session is still initializing an Assignment. Retry current-assignment.', 'assignment_initializing' );
		}

		return array(
			'success'        => true,
			'has_assignment' => false,
			'assignment'     => null,
		);
	}

	/**
	 * Recover a lost session Assignment record from its enriched Reservation.
	 *
	 * @param array<string,mixed> $identity Verified identity.
	 * @param array<string,mixed> $pending Existing pending record, when present.
	 * @return array<string,mixed>
	 */
	private static function assignment_recover_from_reservation( string $agent_session_id, array $identity, array $pending = array() ): array {
		$reservation = self::assignment_reservation_for_session( $agent_session_id );
		if ( ! $reservation || empty( $reservation['assignment_id'] ) || empty( $reservation['work_item_id'] ) || empty( $reservation['work_item_revision'] ) ) {
			return array();
		}
		if ( $pending && 'pending' === ( $pending['status'] ?? '' ) && ! hash_equals( (string) $pending['assignment_id'], (string) $reservation['assignment_id'] ) ) {
			return array();
		}

		$selected = self::assignment_selected_from_reservation( $reservation, $identity );
		$claimed_at = sanitize_text_field( (string) ( $reservation['claimed_at'] ?? gmdate( 'c' ) ) );
		$expires_at = sanitize_text_field( (string) ( $reservation['expires_at'] ?? gmdate( 'c', time() + 600 ) ) );
		$claimed_ts = strtotime( $claimed_at ) ?: time();
		$expires_ts = strtotime( $expires_at ) ?: ( $claimed_ts + 600 );
		$assignment = array(
			'assignment_id'    => sanitize_text_field( (string) $reservation['assignment_id'] ),
			'status'           => 'active',
			'work_item_id'     => sanitize_text_field( (string) $reservation['work_item_id'] ),
			'revision'         => sanitize_text_field( (string) $reservation['work_item_revision'] ),
			'agent_session_id' => $agent_session_id,
			'identity'         => self::public_heartbeat_identity( $identity ),
			'selected'         => $selected,
			'claim_token'      => sanitize_text_field( (string) ( $reservation['token'] ?? '' ) ),
			'created_at'       => $claimed_at,
			'claimed_at'       => $claimed_at,
			'updated_at'       => gmdate( 'c' ),
			'expires_at'       => $expires_at,
			'ttl_seconds'      => max( 60, $expires_ts - $claimed_ts ),
			'note'             => sanitize_textarea_field( (string) ( $reservation['note'] ?? '' ) ),
		);
		if ( ! self::assignment_acquire_item_lock( $assignment, (int) $assignment['ttl_seconds'] ) ) {
			return array();
		}
		$key = self::assignment_session_option_name( $agent_session_id );
		$saved = $pending
			? self::assignment_compare_and_swap_option( $key, $pending, $assignment )
			: self::atomic_create_option( $key, $assignment );
		if ( ! $saved ) {
			wp_cache_delete( $key, 'options' );
			$current = get_option( $key, array() );
			$current = is_array( $current ) ? self::sanitize_assignment( $current ) : array();
			return $current && 'active' === $current['status'] ? $current : array();
		}

		return self::sanitize_assignment( $assignment );
	}

	/**
	 * Find one active enriched Reservation for a contributor session.
	 *
	 * @return array<string,mixed>
	 */
	private static function assignment_reservation_for_session( string $agent_session_id ): array {
		global $wpdb;

		foreach ( array( self::OPTION_TRANSLATION_CLAIM_PREFIX, self::OPTION_WORK_CLAIM_PREFIX ) as $prefix ) {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery must discover an Assignment Reservation when the session record was lost.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 500",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( $option_names as $option_name ) {
				$claim = get_option( (string) $option_name, array() );
				if ( ! is_array( $claim ) ) {
					continue;
				}
				$claim = self::OPTION_WORK_CLAIM_PREFIX === $prefix
					? self::sanitize_source_work_reservation( $claim )
					: self::sanitize_translation_reservation( $claim );
				if (
					$claim
					&& empty( $claim['expired'] )
					&& '' !== (string) ( $claim['assignment_id'] ?? '' )
					&& hash_equals( $agent_session_id, (string) ( $claim['agent_session_id'] ?? '' ) )
				) {
					return $claim;
				}
			}
		}

		return array();
	}

	/**
	 * Rebuild an Assignment candidate snapshot from an enriched Reservation.
	 *
	 * @param array<string,mixed> $reservation Stored Reservation.
	 * @param array<string,mixed> $identity Verified identity.
	 * @return array<string,mixed>
	 */
	private static function assignment_selected_from_reservation( array $reservation, array $identity ): array {
		$obligation     = sanitize_key( (string) ( $reservation['assignment_obligation'] ?? '' ) );
		$action         = self::heartbeat_action_for_obligation( $obligation );
		$translation_id = absint( $reservation['assignment_translation_id'] ?? 0 );
		$language       = sanitize_key( (string) ( $reservation['language'] ?? '' ) );
		$eligibility = self::heartbeat_obligation_uses_draft_work_identity( $obligation )
			? self::heartbeat_draft_work_eligibility( $identity )
			: self::heartbeat_translation_review_eligibility( $translation_id, $identity, $obligation );

		return array(
			'assignment_id'       => sanitize_text_field( (string) ( $reservation['assignment_id'] ?? '' ) ),
			'work_item_id'        => sanitize_text_field( (string) ( $reservation['work_item_id'] ?? '' ) ),
			'revision'            => sanitize_text_field( (string) ( $reservation['work_item_revision'] ?? '' ) ),
			'action'              => sanitize_key( (string) ( $reservation['assignment_action'] ?? $action['action'] ?? '' ) ),
			'workflow_step'       => sanitize_key( (string) ( $reservation['assignment_workflow_step'] ?? $action['workflow_step'] ?? '' ) ),
			'required_ability'    => sanitize_text_field( (string) ( $action['required_ability'] ?? '' ) ),
			'completion_abilities'=> isset( $action['completion_abilities'] ) && is_array( $action['completion_abilities'] ) ? array_values( array_map( 'sanitize_text_field', $action['completion_abilities'] ) ) : array(),
			'completion_policy'   => sanitize_textarea_field( (string) ( $action['completion_policy'] ?? '' ) ),
			'work_scope'          => 'source' === sanitize_key( (string) ( $reservation['work_scope'] ?? '' ) ) ? 'source' : 'translation',
			'work_type'           => sanitize_key( (string) ( $reservation['assignment_work_type'] ?? $reservation['work_type'] ?? $obligation ) ),
			'source_id'           => absint( $reservation['source_id'] ?? 0 ),
			'source_title'        => sanitize_text_field( (string) get_the_title( absint( $reservation['source_id'] ?? 0 ) ) ),
			'translation_id'      => $translation_id,
			'language'            => $language,
			'obligation'          => $obligation,
			'instructions'        => sanitize_textarea_field( (string) ( $action['instructions'] ?? '' ) ),
			'review_surface_guidance' => self::heartbeat_review_surface_guidance( $obligation, $translation_id, $language, '' ),
			'design_ownership'    => isset( $action['design_ownership'] ) && is_array( $action['design_ownership'] ) ? $action['design_ownership'] : array(),
			'claim_required_for_writes' => true,
			'independence'        => ! empty( $eligibility['success'] ) ? self::heartbeat_independence_summary( $eligibility, $obligation ) : array( 'server_checked' => true, 'recovered' => true ),
			'claim_token'        => sanitize_text_field( (string) ( $reservation['token'] ?? '' ) ),
			'reservation'        => 'source' === sanitize_key( (string) ( $reservation['work_scope'] ?? '' ) )
				? self::public_source_work_reservation( $reservation )
				: self::public_translation_reservation( $reservation ),
		);
	}

	/**
	 * Renew the Assignment and its Reservation.
	 *
	 * @param array<string,mixed> $assignment Active Assignment.
	 * @return array<string,mixed>
	 */
	private static function assignment_lifecycle_renew_record( array $assignment, int $ttl_seconds ): array {
		if ( 'active' !== ( $assignment['status'] ?? '' ) ) {
			return self::assignment_error( 'Only an active Assignment can be renewed.', 'assignment_not_active' );
		}
		$expires_at = gmdate( 'c', time() + $ttl_seconds );
		if ( ! self::assignment_renew_item_lock( $assignment, $ttl_seconds ) ) {
			return self::assignment_error( 'The Assignment Work Item lock is owned by another Assignment.', 'assignment_item_lock_conflict' );
		}
		$reservation = self::assignment_reservation_for_selected( $assignment['selected'], true );
		if ( ! $reservation ) {
			if ( self::assignment_work_item_revision_is_current( $assignment['selected'] ) ) {
				return self::assignment_error( 'The active Assignment lost its internal Reservation and could not be renewed safely.', 'assignment_reservation_missing' );
			}
		} else {
			if ( ! self::assignment_reservation_matches( $reservation, $assignment ) ) {
				return self::assignment_error( 'The Assignment Reservation is owned by another Assignment.', 'assignment_reservation_conflict' );
			}
			$reservation['expires_at'] = $expires_at;
			$reservation['expired'] = false;
			update_option( self::assignment_reservation_option_name_for_selected( $assignment['selected'] ), self::assignment_storage_reservation( $reservation ), false );
		}

		$renewed = $assignment;
		$renewed['expires_at'] = $expires_at;
		$renewed['ttl_seconds'] = $ttl_seconds;
		$renewed['updated_at'] = gmdate( 'c' );
		if ( isset( $renewed['selected']['reservation'] ) && is_array( $renewed['selected']['reservation'] ) ) {
			$renewed['selected']['reservation']['expires_at'] = $expires_at;
			$renewed['selected']['reservation']['expired'] = false;
		}
		$key = self::assignment_session_option_name( (string) $assignment['agent_session_id'] );
		if ( ! self::assignment_compare_and_swap_option( $key, $assignment, $renewed ) ) {
			return self::assignment_error( 'The Assignment changed while it was being renewed.', 'assignment_renewal_conflict' );
		}

		return array(
			'success'    => true,
			'assignment' => self::sanitize_assignment( $renewed ),
		);
	}

	/**
	 * Finish a durable terminal transition after releasing its Reservation.
	 *
	 * @param array<string,mixed> $assignment Assignment in completing state.
	 * @return array<string,mixed>
	 */
	private static function assignment_finish_terminal_transition( array $assignment ): array {
		if ( 'completing' !== ( $assignment['status'] ?? '' ) || empty( $assignment['pending_outcome'] ) ) {
			return self::assignment_error( 'Assignment terminal transition is incomplete.', 'assignment_terminal_transition_invalid' );
		}
		if ( ! self::assignment_internal_release_reservation( $assignment ) ) {
			return self::assignment_error( 'The Assignment Reservation could not be released safely.', 'assignment_release_failed' );
		}
		if ( ! self::assignment_release_item_lock( $assignment ) ) {
			return self::assignment_error( 'The Assignment Work Item lock could not be released safely.', 'assignment_item_lock_release_failed' );
		}

		$outcome = self::assignment_outcome_record( $assignment );
		$outcome_key = self::assignment_outcome_option_name( (string) $assignment['assignment_id'] );
		update_option( $outcome_key, $outcome, false );
		if ( in_array( (string) ( $outcome['outcome'] ?? '' ), array( 'completed', 'blocked' ), true ) ) {
			update_option( self::assignment_latest_outcome_option_name( (string) $assignment['work_item_id'] ), $outcome, false );
		}
		if ( 'blocked' === ( $outcome['outcome'] ?? '' ) ) {
			update_option( self::assignment_block_option_name( (string) $assignment['work_item_id'], (string) $assignment['revision'] ), $outcome, false );
		}

		$key = self::assignment_session_option_name( (string) $assignment['agent_session_id'] );
		if ( ! self::assignment_compare_and_delete_option( $key, $assignment ) ) {
			$current = get_option( $key, array() );
			if ( is_array( $current ) && ! empty( $current ) ) {
				return self::assignment_error( 'The terminal Assignment was recorded but its session pointer changed.', 'assignment_terminal_pointer_conflict' );
			}
		}

		return array( 'success' => true, 'outcome' => $outcome );
	}

	/**
	 * Return whether a blocked outcome suppresses this exact Work Item revision.
	 */
	private static function assignment_outcome_blocks_work_item( array $item ): bool {
		$work_item_id = sanitize_text_field( (string) ( $item['work_item_id'] ?? '' ) );
		$revision     = sanitize_text_field( (string) ( $item['revision'] ?? '' ) );
		if ( '' === $work_item_id || '' === $revision ) {
			return false;
		}
		$outcome = get_option( self::assignment_block_option_name( $work_item_id, $revision ), array() );
		return is_array( $outcome )
			&& 'blocked' === sanitize_key( (string) ( $outcome['outcome'] ?? '' ) )
			&& hash_equals( $work_item_id, (string) ( $outcome['work_item_id'] ?? '' ) )
			&& hash_equals( $revision, (string) ( $outcome['revision'] ?? '' ) );
	}

	/**
	 * Keep a contributor from immediately handling its own successor revision.
	 *
	 * @param array<string,mixed> $identity Verified contributor identity.
	 * @return array{success:bool,code:string}
	 */
	private static function assignment_outcome_eligibility_for_work_item( array $item, array $identity ): array {
		$work_item_id = sanitize_text_field( (string) ( $item['work_item_id'] ?? '' ) );
		if ( '' === $work_item_id ) {
			return array( 'success' => true, 'code' => '' );
		}
		$outcome = get_option( self::assignment_latest_outcome_option_name( $work_item_id ), array() );
		if ( ! is_array( $outcome ) || ! in_array( sanitize_key( (string) ( $outcome['outcome'] ?? '' ) ), array( 'completed', 'blocked' ), true ) ) {
			return array( 'success' => true, 'code' => '' );
		}

		$prior_session_id = self::normalize_control_scope_id( (string) ( $outcome['agent_session_id'] ?? '' ) );
		$current_session_id = self::assignment_session_id( $identity, array() );
		$prior_actor_id = sanitize_key( (string) ( $outcome['actor_id'] ?? '' ) );
		$current_actor_id = sanitize_key( (string) ( $identity['actor_id'] ?? '' ) );
		if (
			( '' !== $prior_session_id && '' !== $current_session_id && hash_equals( $prior_session_id, $current_session_id ) )
			|| ( '' !== $prior_actor_id && '' !== $current_actor_id && hash_equals( $prior_actor_id, $current_actor_id ) )
		) {
			return array(
				'success' => false,
				'code'    => 'current_actor_last_handled_work_item',
			);
		}

		return array( 'success' => true, 'code' => '' );
	}

	/**
	 * Test whether the exact assigned Work Item revision is still current.
	 */
	private static function assignment_work_item_revision_is_current( array $selected ): bool {
		$source_id = absint( $selected['source_id'] ?? 0 );
		$work_item_id = sanitize_text_field( (string) ( $selected['work_item_id'] ?? '' ) );
		$revision = sanitize_text_field( (string) ( $selected['revision'] ?? '' ) );
		if ( ! $source_id || '' === $work_item_id || '' === $revision ) {
			return false;
		}
		$sources = self::workflow_source_candidates( $source_id, 1 );
		$catalog = self::workflow_work_items_for_sources( $sources, true, true );
		foreach ( $catalog['items'] ?? array() as $item ) {
			if (
				is_array( $item )
				&& hash_equals( $work_item_id, (string) ( $item['work_item_id'] ?? '' ) )
				&& hash_equals( $revision, (string) ( $item['revision'] ?? '' ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Release the Reservation only when it belongs to this Assignment.
	 */
	private static function assignment_internal_release_reservation( array $assignment ): bool {
		$selected = is_array( $assignment['selected'] ?? null ) ? $assignment['selected'] : array();
		$reservation = self::assignment_reservation_for_selected( $selected, true );
		if ( ! $reservation ) {
			return true;
		}
		if ( ! self::assignment_reservation_matches( $reservation, $assignment ) ) {
			// A foreign Reservation is not this Assignment's lock to delete. The
			// orphan Assignment may still terminate without disturbing its owner.
			return true;
		}

		return delete_option( self::assignment_reservation_option_name_for_selected( $selected ) );
	}

	/**
	 * Return the active Assignment item lock for one Work Item.
	 *
	 * @return array<string,mixed>
	 */
	private static function assignment_item_lock_for_work_item( array $item ): array {
		$work_item_id = sanitize_text_field( (string) ( $item['work_item_id'] ?? '' ) );
		if ( '' === $work_item_id ) {
			return array();
		}
		$key = self::assignment_item_option_name( $work_item_id );
		$lock = get_option( $key, array() );
		$lock = is_array( $lock ) ? self::sanitize_assignment_item_lock( $lock ) : array();
		if ( ! $lock ) {
			return array();
		}
		if ( ! empty( $lock['expired'] ) ) {
			self::assignment_compare_and_delete_option( $key, $lock );
			return array();
		}

		return $lock;
	}

	/**
	 * Atomically acquire or idempotently retain one logical Work Item lock.
	 */
	private static function assignment_acquire_item_lock( array $assignment, int $ttl_seconds ): bool {
		$work_item_id = sanitize_text_field( (string) ( $assignment['work_item_id'] ?? $assignment['selected']['work_item_id'] ?? '' ) );
		if ( '' === $work_item_id ) {
			return false;
		}
		$existing = self::assignment_item_lock_for_work_item( array( 'work_item_id' => $work_item_id ) );
		if ( $existing ) {
			return self::assignment_item_lock_matches( $existing, $assignment );
		}

		$now = time();
		$lock = array(
			'assignment_id'    => sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) ),
			'work_item_id'     => $work_item_id,
			'revision'         => sanitize_text_field( (string) ( $assignment['revision'] ?? $assignment['selected']['revision'] ?? '' ) ),
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $assignment['agent_session_id'] ?? '' ) ),
			'claimed_at'       => gmdate( 'c', $now ),
			'expires_at'       => gmdate( 'c', $now + max( 60, $ttl_seconds ) ),
		);
		$key = self::assignment_item_option_name( $work_item_id );
		if ( self::atomic_create_option( $key, $lock ) ) {
			return true;
		}
		wp_cache_delete( $key, 'options' );
		$existing = self::assignment_item_lock_for_work_item( array( 'work_item_id' => $work_item_id ) );
		return $existing && self::assignment_item_lock_matches( $existing, $assignment );
	}

	private static function assignment_renew_item_lock( array $assignment, int $ttl_seconds ): bool {
		$work_item_id = sanitize_text_field( (string) ( $assignment['work_item_id'] ?? $assignment['selected']['work_item_id'] ?? '' ) );
		$lock = self::assignment_item_lock_for_work_item( array( 'work_item_id' => $work_item_id ) );
		if ( ! $lock ) {
			return self::assignment_acquire_item_lock( $assignment, $ttl_seconds );
		}
		if ( ! self::assignment_item_lock_matches( $lock, $assignment ) ) {
			return false;
		}

		$renewed = $lock;
		$renewed['expires_at'] = gmdate( 'c', time() + max( 60, $ttl_seconds ) );
		return self::assignment_compare_and_swap_option( self::assignment_item_option_name( $work_item_id ), $lock, $renewed );
	}

	private static function assignment_release_item_lock( array $assignment ): bool {
		$work_item_id = sanitize_text_field( (string) ( $assignment['work_item_id'] ?? $assignment['selected']['work_item_id'] ?? '' ) );
		if ( '' === $work_item_id ) {
			return true;
		}
		$lock = self::assignment_item_lock_for_work_item( array( 'work_item_id' => $work_item_id ) );
		if ( ! $lock || ! self::assignment_item_lock_matches( $lock, $assignment ) ) {
			return true;
		}

		return self::assignment_compare_and_delete_option( self::assignment_item_option_name( $work_item_id ), $lock );
	}

	private static function assignment_item_lock_matches( array $lock, array $assignment ): bool {
		$assignment_id = sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) );
		$agent_session_id = self::normalize_control_scope_id( (string) ( $assignment['agent_session_id'] ?? '' ) );
		return '' !== $assignment_id
			&& '' !== $agent_session_id
			&& hash_equals( $assignment_id, (string) ( $lock['assignment_id'] ?? '' ) )
			&& hash_equals( $agent_session_id, (string) ( $lock['agent_session_id'] ?? '' ) );
	}

	/**
	 * Sanitize one stored Assignment item lock.
	 *
	 * @return array<string,mixed>
	 */
	private static function sanitize_assignment_item_lock( array $lock ): array {
		$assignment_id = sanitize_text_field( (string) ( $lock['assignment_id'] ?? '' ) );
		$work_item_id = sanitize_text_field( (string) ( $lock['work_item_id'] ?? '' ) );
		$agent_session_id = self::normalize_control_scope_id( (string) ( $lock['agent_session_id'] ?? '' ) );
		$expires_at = sanitize_text_field( (string) ( $lock['expires_at'] ?? '' ) );
		$expires_ts = strtotime( $expires_at ) ?: 0;
		if ( '' === $assignment_id || '' === $work_item_id || '' === $agent_session_id || ! $expires_ts ) {
			return array();
		}

		return array(
			'assignment_id'    => $assignment_id,
			'work_item_id'     => $work_item_id,
			'revision'         => sanitize_text_field( (string) ( $lock['revision'] ?? '' ) ),
			'agent_session_id' => $agent_session_id,
			'claimed_at'       => sanitize_text_field( (string) ( $lock['claimed_at'] ?? '' ) ),
			'expires_at'       => $expires_at,
			'expired'          => $expires_ts <= time(),
		);
	}

	private static function assignment_release_claim_result( array $selected, array $claim_result ): void {
		$assignment = array(
			'assignment_id' => sanitize_text_field( (string) ( $selected['assignment_id'] ?? '' ) ),
			'claim_token'   => sanitize_text_field( (string) ( $claim_result['claim_token'] ?? '' ) ),
			'selected'      => $selected,
		);
		self::assignment_internal_release_reservation( $assignment );
	}

	/**
	 * Compatibility Adapter for older clients that release a Reservation directly.
	 *
	 * @param array<string,mixed> $reservation Released Reservation.
	 */
	private static function assignment_compatibility_reservation_released( array $reservation ): void {
		$assignment_id = sanitize_text_field( (string) ( $reservation['assignment_id'] ?? '' ) );
		$agent_session_id = self::normalize_control_scope_id( (string) ( $reservation['agent_session_id'] ?? '' ) );
		if ( '' === $assignment_id || '' === $agent_session_id ) {
			return;
		}

		$key = self::assignment_session_option_name( $agent_session_id );
		$stored = get_option( $key, array() );
		$assignment = is_array( $stored ) ? self::sanitize_assignment( $stored ) : array();
		if ( ! $assignment || 'active' !== $assignment['status'] || ! hash_equals( (string) $assignment['assignment_id'], $assignment_id ) ) {
			return;
		}

		$transition = $assignment;
		$transition['status'] = 'completing';
		$transition['updated_at'] = gmdate( 'c' );
		$transition['pending_outcome'] = array(
			'outcome'          => 'abandoned',
			'blocker_category' => '',
			'evidence_summary' => '',
			'evidence'         => array( 'legacy_reservation_release_adapter' ),
			'note'             => 'Reservation was released through the compatibility ability.',
			'recorded_at'      => gmdate( 'c' ),
		);
		if ( self::assignment_compare_and_swap_option( $key, $assignment, $transition ) ) {
			self::assignment_finish_terminal_transition( $transition );
		}
	}

	private static function assignment_reservation_matches( array $reservation, array $assignment ): bool {
		$claim_token = sanitize_text_field( (string) ( $assignment['claim_token'] ?? $assignment['selected']['claim_token'] ?? '' ) );
		$assignment_id = sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) );
		return '' !== $claim_token
			&& hash_equals( $claim_token, (string) ( $reservation['token'] ?? '' ) )
			&& ( '' === (string) ( $reservation['assignment_id'] ?? '' ) || hash_equals( $assignment_id, (string) $reservation['assignment_id'] ) );
	}

	private static function assignment_reservation_for_selected( array $selected, bool $include_expired ): array {
		$source_id = absint( $selected['source_id'] ?? 0 );
		if ( 'source' === sanitize_key( (string) ( $selected['work_scope'] ?? '' ) ) ) {
			return self::source_work_reservation_for_type( $source_id, sanitize_key( (string) ( $selected['work_type'] ?? '' ) ), $include_expired );
		}

		return self::translation_reservation_for_language( $source_id, sanitize_key( (string) ( $selected['language'] ?? '' ) ), $include_expired );
	}

	private static function assignment_reservation_option_name_for_selected( array $selected ): string {
		$source_id = absint( $selected['source_id'] ?? 0 );
		if ( 'source' === sanitize_key( (string) ( $selected['work_scope'] ?? '' ) ) ) {
			return self::source_work_reservation_option_name( $source_id, sanitize_key( (string) ( $selected['work_type'] ?? '' ) ) );
		}

		return self::translation_reservation_option_name( $source_id, sanitize_key( (string) ( $selected['language'] ?? '' ) ) );
	}

	/**
	 * Strip calculated fields before persisting a Reservation again.
	 *
	 * @return array<string,mixed>
	 */
	private static function assignment_storage_reservation( array $reservation ): array {
		unset( $reservation['expired'], $reservation['has_session_binding'] );
		return $reservation;
	}

	/**
	 * Sanitize a stored Assignment.
	 *
	 * @return array<string,mixed>
	 */
	private static function sanitize_assignment( array $assignment ): array {
		$assignment_id = sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) );
		$agent_session_id = self::normalize_control_scope_id( (string) ( $assignment['agent_session_id'] ?? '' ) );
		$status = sanitize_key( (string) ( $assignment['status'] ?? '' ) );
		if ( '' === $assignment_id || '' === $agent_session_id || ! in_array( $status, array( 'pending', 'active', 'completing' ), true ) ) {
			return array();
		}
		$expires_at = sanitize_text_field( (string) ( $assignment['expires_at'] ?? '' ) );
		$expires_ts = strtotime( $expires_at ) ?: 0;
		$selected = isset( $assignment['selected'] ) && is_array( $assignment['selected'] ) ? $assignment['selected'] : array();

		return array(
			'assignment_id'    => $assignment_id,
			'status'           => $status,
			'work_item_id'     => sanitize_text_field( (string) ( $assignment['work_item_id'] ?? $selected['work_item_id'] ?? '' ) ),
			'revision'         => sanitize_text_field( (string) ( $assignment['revision'] ?? $selected['revision'] ?? '' ) ),
			'agent_session_id' => $agent_session_id,
			'identity'         => isset( $assignment['identity'] ) && is_array( $assignment['identity'] ) ? $assignment['identity'] : array(),
			'selected'         => $selected,
			'claim_token'      => sanitize_text_field( (string) ( $assignment['claim_token'] ?? $selected['claim_token'] ?? '' ) ),
			'created_at'       => sanitize_text_field( (string) ( $assignment['created_at'] ?? '' ) ),
			'claimed_at'       => sanitize_text_field( (string) ( $assignment['claimed_at'] ?? '' ) ),
			'updated_at'       => sanitize_text_field( (string) ( $assignment['updated_at'] ?? '' ) ),
			'expires_at'       => $expires_at,
			'expired'          => $expires_ts > 0 && $expires_ts <= time(),
			'ttl_seconds'      => absint( $assignment['ttl_seconds'] ?? 0 ),
			'note'             => sanitize_textarea_field( (string) ( $assignment['note'] ?? '' ) ),
			'pending_outcome'  => isset( $assignment['pending_outcome'] ) && is_array( $assignment['pending_outcome'] ) ? $assignment['pending_outcome'] : array(),
		);
	}

	private static function public_assignment( array $assignment ): array {
		$assignment = self::sanitize_assignment( $assignment );
		unset( $assignment['expired'] );
		return $assignment;
	}

	private static function assignment_identity_matches( array $assignment, array $identity ): bool {
		$session_id = self::assignment_session_id( $identity, array() );
		if ( '' === $session_id || ! hash_equals( (string) $assignment['agent_session_id'], $session_id ) ) {
			return false;
		}
		$stored_actor = sanitize_key( (string) ( $assignment['identity']['actor_id'] ?? '' ) );
		$current_actor = sanitize_key( (string) ( $identity['actor_id'] ?? '' ) );
		return '' === $stored_actor || '' === $current_actor || hash_equals( $stored_actor, $current_actor );
	}

	private static function assignment_session_id( array $identity, array $input ): string {
		return self::normalize_control_scope_id( (string) ( $input['agent_session_id'] ?? $identity['agent_session_id'] ?? $identity['control_scope_id'] ?? '' ) );
	}

	private static function assignment_lifecycle_identity( array $input ): array {
		return self::translation_step_token_gate( 'quality_review', $input );
	}

	private static function assignment_lifecycle_identity_error( array $identity ): array {
		return array(
			'success' => false,
			'action'  => 'escalate',
			'code'    => sanitize_key( (string) ( $identity['code'] ?? 'workflow_identity_not_confirmed' ) ),
			'message' => sanitize_text_field( (string) ( $identity['message'] ?? 'Contributor identity is not confirmed by the workflow authority.' ) ),
			'identity'=> self::public_heartbeat_identity( $identity ),
		);
	}

	/**
	 * Assignment error payload with a stable machine-readable code.
	 *
	 * @return array<string,mixed>
	 */
	private static function assignment_error( string $message, string $code, array $extra = array() ): array {
		return array_merge(
			array(
				'success' => false,
				'message' => $message,
				'code'    => sanitize_key( $code ),
			),
			$extra
		);
	}

	/**
	 * Shape the compatibility response used by accept and heartbeat callers.
	 *
	 * @param array<string,mixed> $identity Verified identity.
	 * @param array<string,mixed> $plan Planner result.
	 * @param array<int,array<string,mixed>> $skipped Skip rows after claim races.
	 */
	private static function assignment_accept_response( array $assignment, array $identity, string $mode, array $plan, array $skipped = array() ): array {
		$assignment = self::sanitize_assignment( $assignment );
		$selected = $assignment['selected'];
		return array(
			'success'        => true,
			'action'         => sanitize_key( (string) ( $selected['action'] ?? '' ) ),
			'mode'           => $mode,
			'identity'       => self::public_heartbeat_identity( $identity ),
			'assignment'     => self::public_assignment( $assignment ),
			'selected'       => $selected,
			'totals'         => $plan['totals'] ?? array(),
			'skipped_count'  => count( $skipped ),
			'skipped_sample' => array_slice( $skipped, 0, 10 ),
			'planner'        => array(
				'source_scan_count' => absint( $plan['source_scan_count'] ?? 0 ),
				'item_count'        => absint( $plan['item_count'] ?? 0 ),
			),
			'heartbeat_policy' => self::heartbeat_policy(),
		);
	}

	private static function assignment_outcome_record( array $assignment ): array {
		$pending = is_array( $assignment['pending_outcome'] ?? null ) ? $assignment['pending_outcome'] : array();
		return array(
			'assignment_id'    => sanitize_text_field( (string) ( $assignment['assignment_id'] ?? '' ) ),
			'work_item_id'     => sanitize_text_field( (string) ( $assignment['work_item_id'] ?? '' ) ),
			'revision'         => sanitize_text_field( (string) ( $assignment['revision'] ?? '' ) ),
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $assignment['agent_session_id'] ?? '' ) ),
			'actor_id'         => sanitize_key( (string) ( $assignment['identity']['actor_id'] ?? '' ) ),
			'outcome'          => sanitize_key( (string) ( $pending['outcome'] ?? '' ) ),
			'blocker_category' => sanitize_key( (string) ( $pending['blocker_category'] ?? '' ) ),
			'evidence_summary' => sanitize_textarea_field( (string) ( $pending['evidence_summary'] ?? '' ) ),
			'evidence'         => isset( $pending['evidence'] ) && is_array( $pending['evidence'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $pending['evidence'] ) ) ) : array(),
			'note'             => sanitize_textarea_field( (string) ( $pending['note'] ?? '' ) ),
			'recorded_at'      => sanitize_text_field( (string) ( $pending['recorded_at'] ?? gmdate( 'c' ) ) ),
		);
	}

	/**
	 * Replace an option only when its serialized value still matches.
	 */
	private static function assignment_compare_and_swap_option( string $key, array $expected, array $replacement ): bool {
		global $wpdb;
		$expected    = self::assignment_storage_record( $expected );
		$replacement = self::assignment_storage_record( $replacement );
		if ( maybe_serialize( $expected ) === maybe_serialize( $replacement ) ) {
			$current = get_option( $key, null );
			return is_array( $current ) && maybe_serialize( $current ) === maybe_serialize( $expected );
		}
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Assignment transitions require compare-and-swap semantics.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				$key,
				maybe_serialize( $expected )
			)
		);
		wp_cache_delete( $key, 'options' );
		return 1 === $updated;
	}

	/**
	 * Delete an option only when its serialized value still matches.
	 */
	private static function assignment_compare_and_delete_option( string $key, array $expected ): bool {
		global $wpdb;
		$expected = self::assignment_storage_record( $expected );
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Assignment transitions require compare-and-delete semantics.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $expected )
			)
		);
		wp_cache_delete( $key, 'options' );
		return 1 === $deleted;
	}

	/**
	 * Remove request-time calculated fields from an Assignment option value.
	 *
	 * @return array<string,mixed>
	 */
	private static function assignment_storage_record( array $assignment ): array {
		unset( $assignment['expired'] );
		if ( empty( $assignment['pending_outcome'] ) ) {
			unset( $assignment['pending_outcome'] );
		}
		return $assignment;
	}

	private static function assignment_session_option_name( string $agent_session_id ): string {
		return self::OPTION_ASSIGNMENT_PREFIX . hash( 'sha256', self::normalize_control_scope_id( $agent_session_id ) );
	}

	private static function assignment_item_option_name( string $work_item_id ): string {
		return self::OPTION_ASSIGNMENT_ITEM_PREFIX . hash( 'sha256', sanitize_text_field( $work_item_id ) );
	}

	private static function assignment_outcome_option_name( string $assignment_id ): string {
		return self::OPTION_ASSIGNMENT_OUTCOME_PREFIX . hash( 'sha256', sanitize_text_field( $assignment_id ) );
	}

	private static function assignment_latest_outcome_option_name( string $work_item_id ): string {
		return self::OPTION_ASSIGNMENT_LATEST_OUTCOME_PREFIX . hash( 'sha256', sanitize_text_field( $work_item_id ) );
	}

	private static function assignment_block_option_name( string $work_item_id, string $revision ): string {
		return self::OPTION_ASSIGNMENT_BLOCK_PREFIX . hash( 'sha256', sanitize_text_field( $work_item_id ) . '|' . sanitize_text_field( $revision ) );
	}

	/**
	 * Controlled categories keep blocked outcomes machine-actionable.
	 *
	 * @return array<int,string>
	 */
	private static function assignment_blocker_categories(): array {
		return array(
			'workflow_support',
			'tooling',
			'presentation',
			'policy',
			'unsafe_assignment',
			'external_dependency',
			'other',
		);
	}
}

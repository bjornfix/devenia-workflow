<?php
/**
 * Work Item planning for assignment and coverage surfaces.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Work_Item_Planner {
	/**
	 * Produce one ordered plan for assignment and coverage callers.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $identity Verified contributor identity, or an empty array for identity-neutral coverage.
	 * @return array<string,mixed>
	 */
	private static function work_item_plan( array $input, array $identity = array() ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$limit     = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 500;
		$collect_candidates = ! array_key_exists( 'collect_candidates', $input ) || ! empty( $input['collect_candidates'] );
		$scan_limit = $source_id ? 1 : max( 500, $limit );
		$sources    = self::workflow_source_candidates( $source_id, $scan_limit );
		if ( $source_id && empty( $sources ) ) {
			return self::error( 'Source content not found.' );
		}

		$catalog = self::workflow_work_items_for_sources( $sources, true, true );
		$supported_obligations = self::heartbeat_supported_obligations();
		$coverage = array(
			'items_seen'             => 0,
			'actionable_items'        => 0,
			'reserved_items'          => 0,
			'uncovered_items'         => 0,
			'claimable_for_actor'     => empty( $identity ) ? null : 0,
			'skipped_for_actor'       => empty( $identity ) ? null : 0,
			'by_obligation'           => array_fill_keys( $supported_obligations, 0 ),
			'claimable_by_obligation' => array_fill_keys( $supported_obligations, 0 ),
			'uncovered_by_obligation' => array_fill_keys( $supported_obligations, 0 ),
			'skipped_by_reason'       => array(),
		);
		$candidates = array();
		$skipped    = array();
		$uncovered  = array();

		foreach ( $catalog['items'] ?? array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			++$coverage['items_seen'];
			$item_obligations = self::heartbeat_actionable_obligations( $item['obligations'] ?? array() );
			if ( empty( $item_obligations ) ) {
				continue;
			}
			++$coverage['actionable_items'];

			$source_id      = absint( $item['source_id'] ?? 0 );
			$translation_id = absint( $item['translation_id'] ?? 0 );
			$language       = sanitize_key( (string) ( $item['language'] ?? '' ) );
			$work_scope     = 'source' === sanitize_key( (string) ( $item['work_scope'] ?? '' ) ) ? 'source' : 'translation';
			$work_type      = sanitize_key( (string) ( $item['work_type'] ?? '' ) );
			if ( ! $source_id || ( 'translation' === $work_scope && ! self::is_translation_language( $language ) ) ) {
				continue;
			}

			$reservation = 'source' === $work_scope
				? self::source_work_reservation_for_type( $source_id, $work_type )
				: self::translation_reservation_for_language( $source_id, $language );
			$item_lock = method_exists( __CLASS__, 'assignment_item_lock_for_work_item' )
				? self::assignment_item_lock_for_work_item( $item )
				: array();
			$is_reserved = ! empty( $reservation ) || ! empty( $item_lock );
			if ( $is_reserved ) {
				++$coverage['reserved_items'];
			}

			$item_uncovered  = false;
			$item_candidate  = array();
			$item_skip_reason = '';
			foreach ( $item_obligations as $obligation ) {
				if ( ! isset( $coverage['by_obligation'][ $obligation ] ) ) {
					continue;
				}
				++$coverage['by_obligation'][ $obligation ];
				$action = self::heartbeat_action_for_obligation( $obligation );
				if ( 'wait' === sanitize_key( (string) ( $action['action'] ?? '' ) ) || '' === (string) ( $action['required_ability'] ?? '' ) ) {
					$item_uncovered = true;
					++$coverage['uncovered_by_obligation'][ $obligation ];
					continue;
				}

				if ( empty( $identity ) ) {
					continue;
				}
				if ( $is_reserved ) {
					$item_skip_reason = $item_lock ? 'assigned' : 'reserved';
					continue;
				}
				if ( method_exists( __CLASS__, 'assignment_outcome_blocks_work_item' ) && self::assignment_outcome_blocks_work_item( $item ) ) {
					$item_skip_reason = 'blocked_outcome_current_revision';
					continue;
				}

				$eligibility = self::heartbeat_obligation_uses_draft_work_identity( $obligation )
					? self::heartbeat_draft_work_eligibility( $identity )
					: self::heartbeat_translation_review_eligibility( $translation_id, $identity, $obligation );
				if ( empty( $eligibility['success'] ) ) {
					$item_skip_reason = sanitize_key( (string) ( $eligibility['code'] ?? 'not_eligible' ) . '_' . $obligation );
					continue;
				}

				$item_candidate = self::work_item_plan_candidate( $item, $obligation, $action, $eligibility );
				++$coverage['claimable_by_obligation'][ $obligation ];
				break;
			}

			if ( $item_uncovered ) {
				++$coverage['uncovered_items'];
				$uncovered[] = self::heartbeat_skip_summary( $item, 'unsupported_assignment_mapping' );
			}
			if ( empty( $identity ) ) {
				continue;
			}
			if ( $item_candidate ) {
				++$coverage['claimable_for_actor'];
				if ( $collect_candidates ) {
					$candidates[] = $item_candidate;
				}
				continue;
			}

			++$coverage['skipped_for_actor'];
			$item_skip_reason = '' !== $item_skip_reason ? $item_skip_reason : 'not_eligible';
			$coverage['skipped_by_reason'][ $item_skip_reason ] = absint( $coverage['skipped_by_reason'][ $item_skip_reason ] ?? 0 ) + 1;
			$skipped[] = self::heartbeat_skip_summary( $item, $item_skip_reason );
		}

		return array(
			'success'           => true,
			'read_model'        => 'work_item_plan',
			'source_scan_count' => count( $sources ),
			'item_count'        => absint( $coverage['items_seen'] ),
			'totals'            => $catalog['totals'] ?? array(),
			'candidates'        => $candidates,
			'skipped'           => $skipped,
			'uncovered'         => $uncovered,
			'coverage'          => $coverage,
		);
	}

	/**
	 * Shape one eligible Work Item as an Assignment candidate.
	 *
	 * @param array<string,mixed> $item Work Item snapshot.
	 * @param array<string,mixed> $action Action definition.
	 * @param array<string,mixed> $eligibility Verified eligibility evidence.
	 * @return array<string,mixed>
	 */
	private static function work_item_plan_candidate( array $item, string $obligation, array $action, array $eligibility ): array {
		$translation_id = absint( $item['translation_id'] ?? 0 );
		$language       = sanitize_key( (string) ( $item['language'] ?? '' ) );

		return array(
			'work_item_id'       => sanitize_text_field( (string) ( $item['work_item_id'] ?? '' ) ),
			'revision'           => sanitize_text_field( (string) ( $item['revision'] ?? '' ) ),
			'action'             => sanitize_key( (string) ( $action['action'] ?? '' ) ),
			'workflow_step'      => sanitize_key( (string) ( $action['workflow_step'] ?? '' ) ),
			'required_ability'   => sanitize_text_field( (string) ( $action['required_ability'] ?? '' ) ),
			'completion_abilities' => isset( $action['completion_abilities'] ) && is_array( $action['completion_abilities'] ) ? array_values( array_map( 'sanitize_text_field', $action['completion_abilities'] ) ) : array(),
			'completion_policy'  => sanitize_textarea_field( (string) ( $action['completion_policy'] ?? '' ) ),
			'work_scope'         => 'source' === sanitize_key( (string) ( $item['work_scope'] ?? '' ) ) ? 'source' : 'translation',
			'work_type'          => sanitize_key( (string) ( $item['work_type'] ?? '' ) ),
			'source_id'          => absint( $item['source_id'] ?? 0 ),
			'source_title'       => sanitize_text_field( (string) ( $item['source_title'] ?? '' ) ),
			'translation_id'     => $translation_id,
			'language'           => $language,
			'post_status'        => sanitize_key( (string) ( $item['post_status'] ?? '' ) ),
			'obligation'         => sanitize_key( $obligation ),
			'instructions'       => sanitize_textarea_field( (string) ( $action['instructions'] ?? '' ) ),
			'review_surface_guidance' => self::heartbeat_review_surface_guidance( $obligation, $translation_id, $language, sanitize_key( (string) ( $item['post_status'] ?? '' ) ) ),
			'design_ownership'   => isset( $action['design_ownership'] ) && is_array( $action['design_ownership'] ) ? $action['design_ownership'] : array(),
			'claim_required_for_writes' => true,
			'independence'       => self::heartbeat_independence_summary( $eligibility, $obligation ),
		);
	}
}

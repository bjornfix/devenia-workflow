<?php
/**
 * Assignment authority helpers for contributor work selection.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Assignment_Authority {
	/**
	 * Build the reservation payload for a selected heartbeat item.
	 *
	 * @param array<string,mixed> $selected Selected work item.
	 * @param array<string,mixed> $identity Verified session identity.
	 * @param array<string,mixed> $input Raw ability input.
	 * @return array<string,mixed>
	 */
	private static function assignment_authority_reservation_input( array $selected, array $identity, array $input, int $ttl_seconds, string $note ): array {
		return array(
			'source_id'             => absint( $selected['source_id'] ?? 0 ),
			'work_scope'            => (string) ( $selected['work_scope'] ?? '' ),
			'work_type'             => (string) ( $selected['work_type'] ?? '' ),
			'language'              => (string) ( $selected['language'] ?? '' ),
			'owner'                 => 'heartbeat:' . (string) ( $identity['actor_id'] ?? $identity['step_token_label'] ?? 'unknown' ),
			'note'                  => '' !== $note ? $note : 'Reserved by next-heartbeat-action.',
			'agent_session_id'      => (string) ( $identity['agent_session_id'] ?? $input['agent_session_id'] ?? '' ),
			'llm_vendor'            => (string) ( $input['llm_vendor'] ?? $identity['llm_vendor'] ?? '' ),
			'llm_client'            => (string) ( $input['llm_client'] ?? $identity['llm_client'] ?? '' ),
			'authority_vendor'      => (string) ( $input['authority_vendor'] ?? $identity['authority_vendor'] ?? $identity['authority'] ?? '' ),
			'authority_client'      => (string) ( $input['authority_client'] ?? $identity['authority_client'] ?? '' ),
			'session_binding_token' => (string) ( $input['session_binding_token'] ?? '' ),
			'actor_id'              => (string) ( $identity['actor_id'] ?? $identity['step_token_label'] ?? '' ),
			'ttl_seconds'           => $ttl_seconds,
		);
	}

	/**
	 * Validate that the reservation returned by storage belongs to this identity.
	 *
	 * @param array<string,mixed> $claim_result Reservation result.
	 * @param array<string,mixed> $identity Verified session identity.
	 * @param array<string,mixed> $input Raw ability input.
	 * @return array{success:bool,reservation:array<string,mixed>,code:string}
	 */
	private static function assignment_authority_claim_identity( array $claim_result, array $identity, array $input ): array {
		$claimed_reservation = isset( $claim_result['claims'][0] ) && is_array( $claim_result['claims'][0] ) ? $claim_result['claims'][0] : array();
		if ( empty( $claimed_reservation ) ) {
			return array(
				'success'     => false,
				'reservation' => array(),
				'code'        => 'claim_missing_reservation',
			);
		}

		$claimed_agent_session_id = self::normalize_control_scope_id( (string) ( $claimed_reservation['agent_session_id'] ?? '' ) );
		$claimed_actor_id         = sanitize_key( (string) ( $claimed_reservation['actor_id'] ?? '' ) );
		$identity_agent_session_id = self::normalize_control_scope_id( (string) ( $identity['agent_session_id'] ?? $input['agent_session_id'] ?? '' ) );
		$identity_actor_id        = sanitize_key( (string) ( $identity['actor_id'] ?? $identity['step_token_label'] ?? '' ) );

		if (
			( '' !== $claimed_agent_session_id && '' !== $identity_agent_session_id && $claimed_agent_session_id !== $identity_agent_session_id )
			|| ( '' !== $claimed_actor_id && '' !== $identity_actor_id && $claimed_actor_id !== $identity_actor_id )
		) {
			return array(
				'success'     => false,
				'reservation' => $claimed_reservation,
				'code'        => 'claim_identity_mismatch',
			);
		}

		return array(
			'success'     => true,
			'reservation' => $claimed_reservation,
			'code'        => '',
		);
	}
}

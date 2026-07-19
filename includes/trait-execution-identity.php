<?php
/**
 * Execution identity and provenance normalization for bounded Workflow Runs.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Execution_Identity {
	private static function normalize_execution_identity_input( array $input ): array {
		$execution_id = self::normalize_control_scope_id( (string) ( $input['execution_id'] ?? '' ) );
		if ( '' !== $execution_id ) {
			$input['execution_id'] = $execution_id;
		}
		foreach ( array( 'llm_vendor', 'llm_client', 'authority_vendor', 'authority_client' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$input[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		return $input;
	}

	private static function execution_id_from_input( array $input ): string {
		$input = self::normalize_execution_identity_input( $input );
		return self::normalize_control_scope_id( (string) ( $input['execution_id'] ?? '' ) );
	}

	private static function execution_identity_schema_properties(): array {
		return array(
			'execution_id' => array(
				'type' => 'string',
				'description' => 'Stable identifier for the bounded Workflow Run performing this operation.',
			),
			'llm_vendor' => array( 'type' => 'string', 'description' => 'Optional model vendor label.' ),
			'llm_client' => array( 'type' => 'string', 'description' => 'Optional calling client label.' ),
			'authority_vendor' => array( 'type' => 'string', 'description' => 'Optional execution authority label.' ),
			'authority_client' => array( 'type' => 'string', 'description' => 'Optional execution authority client label.' ),
		);
	}

	private static function execution_public_identity( array $identity ): array {
		return array(
			'process_id' => self::normalize_process_id( (string) ( $identity['process_id'] ?? '' ) ),
			'control_scope_id' => self::normalize_control_scope_id( (string) ( $identity['control_scope_id'] ?? $identity['execution_id'] ?? '' ) ),
			'execution_id' => self::normalize_control_scope_id( (string) ( $identity['execution_id'] ?? $identity['control_scope_id'] ?? '' ) ),
			'llm_vendor' => sanitize_text_field( (string) ( $identity['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $identity['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $identity['authority_vendor'] ?? $identity['authority'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $identity['authority_client'] ?? '' ) ),
			'session_origin' => self::normalize_session_origin( (string) ( $identity['session_origin'] ?? '' ) ),
			'parent_process_id' => self::normalize_process_id( (string) ( $identity['parent_process_id'] ?? '' ) ),
			'controller_process_id' => self::normalize_process_id( (string) ( $identity['controller_process_id'] ?? '' ) ),
		);
	}

	private static function execution_actor_identity( array $identity ): array {
		$token_label = sanitize_key( (string) ( $identity['step_token_label'] ?? $identity['token_label'] ?? '' ) );
		$actor_id = sanitize_key( (string) ( $identity['actor_id'] ?? $token_label ) );
		$actor = sanitize_text_field( (string) ( $identity['actor'] ?? ( '' !== $actor_id ? 'actor:' . $actor_id : '' ) ) );
		return array( 'actor' => $actor, 'actor_id' => $actor_id, 'token_label' => $token_label );
	}

	private static function execution_provenance_from_identity( array $identity ): array {
		return array_merge( self::execution_public_identity( $identity ), self::execution_actor_identity( $identity ), array( 'recorded_at' => gmdate( 'c' ) ) );
	}

	private static function execution_provenance_from_stored_payload( array $stored ): array {
		return array_merge( self::execution_public_identity( $stored ), self::execution_actor_identity( $stored ), array( 'recorded_at' => sanitize_text_field( (string) ( $stored['recorded_at'] ?? '' ) ) ) );
	}

	/**
	 * Whether two execution provenance records identify the same actor or
	 * controlling run.
	 *
	 * Independence checks are shared by translation read models, runtime-text
	 * provenance, visible-media provenance, and Quality gates. Keep the rule in
	 * the execution-identity module so removing an orchestration adapter cannot
	 * remove the fail-closed identity boundary.
	 */
	private static function reviewer_identity_matches_provenance( array $reviewer, array $provenance ): bool {
		$reviewer_control_scope   = self::normalize_control_scope_id( (string) ( $reviewer['control_scope_id'] ?? '' ) );
		$provenance_control_scope = self::normalize_control_scope_id( (string) ( $provenance['control_scope_id'] ?? '' ) );
		if ( '' !== $reviewer_control_scope && '' !== $provenance_control_scope && $reviewer_control_scope === $provenance_control_scope ) {
			return true;
		}

		$reviewer_process   = self::normalize_process_id( (string) ( $reviewer['process_id'] ?? '' ) );
		$provenance_process = self::normalize_process_id( (string) ( $provenance['process_id'] ?? '' ) );
		if ( '' !== $reviewer_process && '' !== $provenance_process && $reviewer_process === $provenance_process ) {
			return true;
		}

		$reviewer_execution   = self::normalize_control_scope_id( (string) ( $reviewer['execution_id'] ?? '' ) );
		$provenance_execution = self::normalize_control_scope_id( (string) ( $provenance['execution_id'] ?? '' ) );
		if ( '' !== $reviewer_execution && '' !== $provenance_execution && $reviewer_execution === $provenance_execution ) {
			return true;
		}

		$reviewer_actor_id   = sanitize_key( (string) ( $reviewer['actor_id'] ?? '' ) );
		$provenance_actor_id = sanitize_key( (string) ( $provenance['actor_id'] ?? '' ) );
		if ( '' !== $reviewer_actor_id && '' !== $provenance_actor_id && $reviewer_actor_id === $provenance_actor_id ) {
			return true;
		}

		$reviewer_token_label   = sanitize_key( (string) ( $reviewer['token_label'] ?? '' ) );
		$provenance_token_label = sanitize_key( (string) ( $provenance['token_label'] ?? '' ) );
		if ( '' !== $reviewer_token_label && '' !== $provenance_token_label && $reviewer_token_label === $provenance_token_label ) {
			return true;
		}

		$reviewer_actor   = sanitize_text_field( (string) ( $reviewer['actor'] ?? '' ) );
		$provenance_actor = sanitize_text_field( (string) ( $provenance['actor'] ?? '' ) );
		return '' !== $reviewer_actor && '' !== $provenance_actor && $reviewer_actor === $provenance_actor;
	}

	/** Whether a reviewer matches any stored provenance item. */
	private static function reviewer_matches_any_provenance( array $reviewer, array $provenance_items ): bool {
		foreach ( $provenance_items as $provenance ) {
			if ( is_array( $provenance ) && self::reviewer_identity_matches_provenance( $reviewer, $provenance ) ) {
				return true;
			}
		}

		return false;
	}
}

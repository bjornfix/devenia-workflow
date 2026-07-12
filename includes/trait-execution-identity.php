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
}

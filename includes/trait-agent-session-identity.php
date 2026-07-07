<?php
/**
 * Vendor-neutral agent-session identity helpers.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Agent_Session_Identity {
	/**
	 * Normalize LLM/client and authority session fields behind a vendor-neutral Interface.
	 *
	 * New callers must use agent_session_id.
	 *
	 * @param array<string,mixed> $input Raw ability input.
	 * @return array<string,mixed>
	 */
	private static function normalize_agent_session_input( array $input ): array {
		$agent_session_id = self::normalize_control_scope_id( (string) ( $input['agent_session_id'] ?? '' ) );
		if ( '' !== $agent_session_id ) {
			$input['agent_session_id'] = $agent_session_id;
		}

		foreach ( array( 'llm_vendor', 'llm_client', 'authority_vendor', 'authority_client' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$input[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		return $input;
	}

	private static function agent_session_id_from_input( array $input ): string {
		$input = self::normalize_agent_session_input( $input );
		return self::normalize_control_scope_id( (string) ( $input['agent_session_id'] ?? '' ) );
	}

	private static function agent_session_input_schema_properties(): array {
		return array(
			'agent_session_id'  => array(
				'type'        => 'string',
				'description' => 'Vendor-neutral stable agent/client session identifier required for protected workflow calls.',
			),
			'llm_vendor'       => array(
				'type'        => 'string',
				'description' => 'Optional LLM or client vendor label, such as codex, claude, openai, gemini, or local.',
			),
			'llm_client'       => array(
				'type'        => 'string',
				'description' => 'Optional calling application/client label.',
			),
			'authority_vendor' => array(
				'type'        => 'string',
				'description' => 'Optional workflow authority adapter label. The translation workflow treats this as metadata; the installed authority adapter verifies the lease.',
			),
			'authority_client' => array(
				'type'        => 'string',
				'description' => 'Optional authority adapter/client label.',
			),
		);
	}

	private static function neutralize_agent_session_schema( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && array_key_exists( 'agent_session_id', $schema['properties'] ) ) {
			unset( $schema['properties']['agent_session_id'] );
			$schema['properties'] = array_merge( $schema['properties'], self::agent_session_input_schema_properties() );
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				$schema['required'] = array_values( array_unique( array_map( 'strval', $schema['required'] ) ) );
				$schema['required'] = array_values( array_diff( $schema['required'], array( 'agent_session_id' ) ) );
				$schema['required'][] = 'agent_session_id';
			}
		}

		return $schema;
	}

	/**
	 * Public, sanitized agent/session identity fields shared by heartbeat and provenance records.
	 *
	 * @param array<string,mixed> $identity Verified authority identity or stored provenance.
	 * @return array<string,string>
	 */
	private static function agent_session_public_identity( array $identity ): array {
		return array(
			'process_id'            => self::normalize_process_id( (string) ( $identity['process_id'] ?? '' ) ),
			'control_scope_id'      => self::normalize_control_scope_id( (string) ( $identity['control_scope_id'] ?? '' ) ),
			'agent_session_id'      => self::normalize_control_scope_id( (string) ( $identity['agent_session_id'] ?? '' ) ),
			'llm_vendor'            => sanitize_text_field( (string) ( $identity['llm_vendor'] ?? '' ) ),
			'llm_client'            => sanitize_text_field( (string) ( $identity['llm_client'] ?? '' ) ),
			'authority_vendor'      => sanitize_text_field( (string) ( $identity['authority_vendor'] ?? $identity['authority'] ?? '' ) ),
			'authority_client'      => sanitize_text_field( (string) ( $identity['authority_client'] ?? '' ) ),
			'session_origin'        => self::normalize_session_origin( (string) ( $identity['session_origin'] ?? '' ) ),
			'parent_process_id'     => self::normalize_process_id( (string) ( $identity['parent_process_id'] ?? '' ) ),
			'controller_process_id' => self::normalize_process_id( (string) ( $identity['controller_process_id'] ?? '' ) ),
		);
	}

	/**
	 * Sanitized actor/token fields with stable fallback ordering.
	 *
	 * @param array<string,mixed> $identity Verified authority identity or stored provenance.
	 * @return array<string,string>
	 */
	private static function agent_session_actor_identity( array $identity ): array {
		$token_label = sanitize_key( (string) ( $identity['step_token_label'] ?? $identity['token_label'] ?? '' ) );
		$actor_id    = sanitize_key( (string) ( $identity['actor_id'] ?? '' ) );
		if ( '' === $actor_id ) {
			$actor_id = $token_label;
		}

		$actor = sanitize_text_field( (string) ( $identity['actor'] ?? '' ) );
		if ( '' === $actor && '' !== $actor_id ) {
			$actor = 'actor:' . $actor_id;
		}

		return array(
			'actor'       => $actor,
			'actor_id'    => $actor_id,
			'token_label' => $token_label,
		);
	}

	/**
	 * Provenance payload for a fresh verified authority identity.
	 *
	 * @param array<string,mixed> $verified_identity Verified authority identity.
	 * @return array<string,string>
	 */
	private static function agent_session_provenance_from_verified_identity( array $verified_identity ): array {
		return array_merge(
			self::agent_session_public_identity( $verified_identity ),
			self::agent_session_actor_identity( $verified_identity ),
			array(
				'recorded_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Provenance payload loaded from stored JSON.
	 *
	 * @param array<string,mixed> $stored Stored provenance.
	 * @return array<string,string>
	 */
	private static function agent_session_provenance_from_stored_payload( array $stored ): array {
		return array_merge(
			self::agent_session_public_identity( $stored ),
			self::agent_session_actor_identity( $stored ),
			array(
				'recorded_at' => sanitize_text_field( (string) ( $stored['recorded_at'] ?? '' ) ),
			)
		);
	}
}

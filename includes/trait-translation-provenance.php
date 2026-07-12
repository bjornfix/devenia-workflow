<?php
/**
 * Translation writer, media, and runtime mutation provenance helpers.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Provenance {
	/**
	 * Store who authored the current translated content revision.
	 */
	private static function record_translation_writer_provenance( int $translation_id, array $verified_identity ): void {
		$provenance = self::execution_provenance_from_identity( $verified_identity );
		$process_id = (string) ( $provenance['process_id'] ?? '' );
		$control_scope_id = (string) ( $provenance['control_scope_id'] ?? '' );
		$session_origin = (string) ( $provenance['session_origin'] ?? '' );
		$parent_process_id = (string) ( $provenance['parent_process_id'] ?? '' );
		$controller_process_id = (string) ( $provenance['controller_process_id'] ?? '' );
		$actor = (string) ( $provenance['actor'] ?? '' );
		$actor_id = (string) ( $provenance['actor_id'] ?? '' );
		$token_label = (string) ( $provenance['token_label'] ?? '' );
		if ( '' === $process_id || '' === $token_label ) {
			return;
		}

		update_post_meta( $translation_id, self::META_WRITER_PROCESS, $process_id );
		update_post_meta( $translation_id, self::META_WRITER_CONTROL_SCOPE, $control_scope_id );
		update_post_meta( $translation_id, self::META_WRITER_SESSION_ORIGIN, $session_origin );
		update_post_meta( $translation_id, self::META_WRITER_PARENT_PROCESS, $parent_process_id );
		update_post_meta( $translation_id, self::META_WRITER_CONTROLLER_PROCESS, $controller_process_id );
		update_post_meta( $translation_id, self::META_WRITER_ACTOR, $actor );
		update_post_meta( $translation_id, self::META_WRITER_ACTOR_ID, $actor_id );
		update_post_meta( $translation_id, self::META_WRITER_TOKEN_LABEL, $token_label );
		update_post_meta( $translation_id, self::META_WRITER_RECORDED_AT, gmdate( 'c' ) );
	}

	/**
	 * Store who last changed the visible media surface for a translation.
	 */
	private static function record_translation_visible_media_provenance( int $translation_id, array $verified_identity, string $reason ): void {
		$provenance = self::runtime_mutation_provenance_from_verified_identity( $verified_identity );
		if ( empty( $provenance['process_id'] ) && empty( $provenance['control_scope_id'] ) && empty( $provenance['actor_id'] ) && empty( $provenance['token_label'] ) ) {
			return;
		}

		$provenance['reason'] = sanitize_key( $reason );
		$provenance['featured_image_id'] = self::featured_image_id_for_post( $translation_id );
		$provenance['plugin_version'] = self::VERSION;
		update_post_meta( $translation_id, self::META_VISIBLE_MEDIA_PROVENANCE, wp_json_encode( $provenance ) );
	}

	private static function runtime_mutation_provenance_from_verified_identity( array $verified_identity ): array {
		return self::execution_provenance_from_identity( $verified_identity );
	}

	private static function runtime_mutation_registry(): array {
		$registry = get_option( self::OPTION_RUNTIME_MUTATION_PROVENANCE, array() );
		return is_array( $registry ) ? $registry : array();
	}

	private static function record_runtime_language_mutation_provenance( string $language, string $section, string $source, array $verified_identity, string $reason ): void {
		$language = sanitize_key( $language );
		if ( '' === $language || empty( $verified_identity ) ) {
			return;
		}

		$provenance = self::runtime_mutation_provenance_from_verified_identity( $verified_identity );
		$provenance['language'] = $language;
		$provenance['section'] = sanitize_key( $section );
		$provenance['source'] = sanitize_text_field( $source );
		$provenance['reason'] = sanitize_key( $reason );
		$provenance['plugin_version'] = self::VERSION;

		$registry = self::runtime_mutation_registry();
		if ( ! isset( $registry[ $language ] ) || ! is_array( $registry[ $language ] ) ) {
			$registry[ $language ] = array();
		}
		$registry[ $language ]['latest'] = $provenance;
		if ( '' !== $provenance['section'] && '' !== $provenance['source'] ) {
			$registry[ $language ]['by_section'][ $provenance['section'] ][ md5( $provenance['source'] ) ] = $provenance;
		}

		update_option( self::OPTION_RUNTIME_MUTATION_PROVENANCE, $registry, false );
	}

	private static function runtime_language_mutation_provenance( string $language ): array {
		$language = sanitize_key( $language );
		$registry = self::runtime_mutation_registry();
		return isset( $registry[ $language ]['latest'] ) && is_array( $registry[ $language ]['latest'] )
			? $registry[ $language ]['latest']
			: array();
	}

	/**
	 * Current runtime text mutation provenance entries for a language.
	 *
	 * The language-level latest entry is useful for summaries, but it is not
	 * enough for independence checks. A later edit to one runtime key must not
	 * hide another actor's still-current edit to a different runtime key.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function runtime_language_mutation_provenance_items( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return array();
		}

		$registry = self::runtime_mutation_registry();
		$language_registry = isset( $registry[ $language ] ) && is_array( $registry[ $language ] )
			? $registry[ $language ]
			: array();
		$items = array();
		$seen = array();
		$add_item = static function ( $item ) use ( &$items, &$seen ): void {
			if ( ! is_array( $item ) ) {
				return;
			}
			$key = implode(
				'|',
				array(
					(string) ( $item['recorded_at'] ?? '' ),
					(string) ( $item['section'] ?? '' ),
					(string) ( $item['source'] ?? '' ),
					(string) ( $item['actor_id'] ?? '' ),
					(string) ( $item['control_scope_id'] ?? '' ),
				)
			);
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$items[] = $item;
		};

		$add_item( $language_registry['latest'] ?? array() );
		if ( isset( $language_registry['by_section'] ) && is_array( $language_registry['by_section'] ) ) {
			foreach ( $language_registry['by_section'] as $section_items ) {
				if ( ! is_array( $section_items ) ) {
					continue;
				}
				foreach ( $section_items as $item ) {
					$add_item( $item );
				}
			}
		}

		return $items;
	}

	private static function reviewer_matches_runtime_language_mutation( array $reviewer, string $language ): bool {
		foreach ( self::runtime_language_mutation_provenance_items( $language ) as $mutation ) {
			if ( self::reviewer_identity_matches_provenance( $reviewer, $mutation ) ) {
				return true;
			}
		}
		return false;
	}

	private static function translation_visible_media_provenance( int $translation_id ): array {
		$raw = (string) get_post_meta( $translation_id, self::META_VISIBLE_MEDIA_PROVENANCE, true );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_merge(
			self::execution_provenance_from_stored_payload( $decoded ),
			array(
				'reason'            => sanitize_key( (string) ( $decoded['reason'] ?? '' ) ),
				'featured_image_id' => absint( $decoded['featured_image_id'] ?? 0 ),
				'plugin_version'    => sanitize_text_field( (string) ( $decoded['plugin_version'] ?? '' ) ),
			)
		);
	}

	private static function reviewer_matches_visible_media_mutation( array $reviewer, int $translation_id ): bool {
		$provenance = self::translation_visible_media_provenance( $translation_id );
		return ! empty( $provenance ) && self::reviewer_identity_matches_provenance( $reviewer, $provenance );
	}

	private static function visible_media_mutation_after_evidence( int $translation_id, array $evidence ): bool {
		$provenance = self::translation_visible_media_provenance( $translation_id );
		$provenance_at = isset( $provenance['recorded_at'] ) ? strtotime( (string) $provenance['recorded_at'] ) : false;
		$evidence_at = isset( $evidence['recorded_at'] ) ? strtotime( (string) $evidence['recorded_at'] ) : false;
		return false !== $provenance_at && false !== $evidence_at && $provenance_at > $evidence_at;
	}

	private static function runtime_language_mutation_after_evidence( string $language, array $evidence ): bool {
		$evidence_at = isset( $evidence['recorded_at'] ) ? strtotime( (string) $evidence['recorded_at'] ) : false;
		if ( false === $evidence_at ) {
			return false;
		}
		foreach ( self::runtime_language_mutation_provenance_items( $language ) as $mutation ) {
			$mutation_at = isset( $mutation['recorded_at'] ) ? strtotime( (string) $mutation['recorded_at'] ) : false;
			if ( false !== $mutation_at && $mutation_at > $evidence_at ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load writer provenance for one translation.
	 */
	private static function translation_writer_provenance( int $translation_id ): array {
		return array(
			'process_id'  => (string) get_post_meta( $translation_id, self::META_WRITER_PROCESS, true ),
			'control_scope_id' => self::normalize_control_scope_id( (string) get_post_meta( $translation_id, self::META_WRITER_CONTROL_SCOPE, true ) ),
			'execution_id' => self::normalize_control_scope_id( (string) get_post_meta( $translation_id, self::META_WRITER_CONTROL_SCOPE, true ) ),
			'session_origin' => self::normalize_session_origin( (string) get_post_meta( $translation_id, self::META_WRITER_SESSION_ORIGIN, true ) ),
			'parent_process_id' => self::normalize_process_id( (string) get_post_meta( $translation_id, self::META_WRITER_PARENT_PROCESS, true ) ),
			'controller_process_id' => self::normalize_process_id( (string) get_post_meta( $translation_id, self::META_WRITER_CONTROLLER_PROCESS, true ) ),
			'actor'       => (string) get_post_meta( $translation_id, self::META_WRITER_ACTOR, true ),
			'actor_id'    => (string) get_post_meta( $translation_id, self::META_WRITER_ACTOR_ID, true ),
			'token_label' => (string) get_post_meta( $translation_id, self::META_WRITER_TOKEN_LABEL, true ),
			'recorded_at' => (string) get_post_meta( $translation_id, self::META_WRITER_RECORDED_AT, true ),
		);
	}

	/**
	 * Build reviewer provenance from ability input.
	 */
	private static function reviewer_provenance_from_verified_identity( array $verified_identity, int $translation_id ): array {
		$provenance = self::execution_provenance_from_identity( $verified_identity );

		return array(
			'process_id'            => $provenance['process_id'],
			'control_scope_id'      => $provenance['control_scope_id'],
			'execution_id'      => $provenance['execution_id'],
			'llm_vendor'            => $provenance['llm_vendor'],
			'llm_client'            => $provenance['llm_client'],
			'authority_vendor'      => $provenance['authority_vendor'],
			'authority_client'      => $provenance['authority_client'],
			'session_origin'        => $provenance['session_origin'],
			'parent_process_id'     => $provenance['parent_process_id'],
			'controller_process_id' => $provenance['controller_process_id'],
			'actor'                 => $provenance['actor'],
			'actor_id'              => $provenance['actor_id'],
			'token_label'           => $provenance['token_label'],
			'recorded_at'           => $provenance['recorded_at'],
			'writer'      => self::translation_writer_provenance( $translation_id ),
		);
	}

}

<?php
/**
 * Source taxonomy review policy for AI Translation Workflow.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Source_Taxonomy_Review_Policy {
	private static function source_taxonomy_review_hash( WP_Post $source ): string {
		$payload = array(
			'source_hash' => self::source_hash( $source ),
			'terms'       => array(),
		);
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$payload['terms'][ $taxonomy ] = array_map(
				static function ( array $term ): array {
					return array(
						'id'   => absint( $term['id'] ?? 0 ),
						'slug' => sanitize_title( (string) ( $term['slug'] ?? '' ) ),
					);
				},
				self::source_taxonomy_assigned_terms_payload( $source, $taxonomy )
			);
			usort(
				$payload['terms'][ $taxonomy ],
				static function ( array $a, array $b ): int {
					return ( $a['id'] ?? 0 ) <=> ( $b['id'] ?? 0 );
				}
			);
		}

		return hash( 'sha256', wp_json_encode( $payload ) ?: '' );
	}

	private static function source_taxonomy_review_state( WP_Post $source ): array {
		$current_hash = self::source_taxonomy_review_hash( $source );
		$stored_hash  = (string) get_post_meta( (int) $source->ID, self::META_SOURCE_TAXONOMY_REVIEW_HASH, true );
		$reviewed_at  = (string) get_post_meta( (int) $source->ID, self::META_SOURCE_TAXONOMY_REVIEWED_AT, true );
		$passed       = '' !== $current_hash && hash_equals( $current_hash, $stored_hash ) && '' !== $reviewed_at;

		return array(
			'passed'       => $passed,
			'state'        => $passed ? 'reviewed_current' : ( '' === $reviewed_at ? 'needs_source_taxonomy_review' : 'source_taxonomy_review_stale' ),
			'source_id'    => (int) $source->ID,
			'current_hash' => $current_hash,
			'stored_hash'  => $stored_hash,
			'reviewed_at'  => $reviewed_at,
			'reviewer'     => (string) get_post_meta( (int) $source->ID, self::META_SOURCE_TAXONOMY_REVIEWER, true ),
			'taxonomy'     => self::source_taxonomy_review_payload( $source ),
		);
	}

	private static function source_taxonomy_review_payload( WP_Post $source ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$assigned      = self::source_taxonomy_assigned_terms_payload( $source, $taxonomy );
			$source_terms  = self::source_taxonomy_terms_for_review( $taxonomy );
			$assigned_ids  = array_map(
				static function ( array $term ): int {
					return absint( $term['id'] ?? 0 );
				},
				$assigned
			);
			$singleton_terms = array_values(
				array_filter(
					$source_terms,
					static function ( WP_Term $term ): bool {
						return (int) $term->count <= 1;
					}
				)
			);
			$reuse_candidates = array_values(
				array_slice(
					array_map(
						static function ( WP_Term $term ): array {
							return self::source_taxonomy_term_review_payload( $term );
						},
						array_filter(
							$source_terms,
							static function ( WP_Term $term ) use ( $assigned_ids ): bool {
								return ! in_array( (int) $term->term_id, $assigned_ids, true ) && (int) $term->count > 1;
							}
						)
					),
					0,
					25
				)
			);

			$out[ $taxonomy ] = array(
				'assigned'                  => $assigned,
				'singleton_assigned'        => array_values(
					array_filter(
						$assigned,
						static function ( array $term ): bool {
							return (int) ( $term['count'] ?? 0 ) <= 1;
						}
					)
				),
				'existing_reuse_candidates' => $reuse_candidates,
				'summary'                   => array(
					'assigned_count'        => count( $assigned ),
					'total_term_count'      => count( $source_terms ),
					'singleton_term_count'  => count( $singleton_terms ),
					'singleton_term_ratio'  => count( $source_terms ) > 0 ? round( count( $singleton_terms ) / count( $source_terms ), 3 ) : 0,
					'reuse_candidate_count' => count( $reuse_candidates ),
					'review_hint'           => 'Confirm every assigned term is a useful reader archive. For assigned terms with count <= 1, either keep with a concrete archive rationale or replace/remove it through content/update-post before marking reviewed.',
				),
			);
		}

		return $out;
	}

	private static function mark_source_taxonomy_reviewed( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source instanceof WP_Post || 'post' !== (string) $source->post_type || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source post not found.' );
		}

		$required_true = array( 'categories_fit', 'tags_fit', 'no_term_sprawl_reviewed', 'singleton_terms_reviewed', 'existing_terms_considered' );
		foreach ( $required_true as $key ) {
			if ( empty( $input[ $key ] ) ) {
				return self::error( 'Source taxonomy review requires explicit category/tag fit and term-sprawl checks.', 'source_taxonomy_review_checks_required', array( 'missing_check' => $key, 'taxonomy' => self::source_taxonomy_review_payload( $source ) ) );
			}
		}

		$note        = trim( wp_strip_all_tags( (string) ( $input['note'] ?? '' ) ) );
		$sprawl_note = trim( wp_strip_all_tags( (string) ( $input['term_sprawl_note'] ?? '' ) ) );
		if ( strlen( $note ) < 80 || strlen( $sprawl_note ) < 60 ) {
			return self::error( 'Source taxonomy review needs concrete notes about topical fit and term-sprawl decisions.', 'source_taxonomy_review_note_required', array( 'min_note_chars' => 80, 'min_term_sprawl_note_chars' => 60, 'taxonomy' => self::source_taxonomy_review_payload( $source ) ) );
		}

		$term_decisions = self::source_taxonomy_review_term_decisions( $source, $input['term_decisions'] ?? array() );
		if ( empty( $term_decisions['success'] ) ) {
			return $term_decisions;
		}

		$reviewer = sanitize_text_field( (string) ( $input['reviewer'] ?? 'Devenia Workflow' ) );
		$evidence = array(
			'categories_fit'            => true,
			'tags_fit'                  => true,
			'no_term_sprawl_reviewed'   => true,
			'singleton_terms_reviewed'  => true,
			'existing_terms_considered' => true,
			'changes_made'              => sanitize_textarea_field( (string) ( $input['changes_made'] ?? '' ) ),
			'note'                      => sanitize_textarea_field( $note ),
			'term_sprawl_note'          => sanitize_textarea_field( $sprawl_note ),
			'term_decisions'            => $term_decisions['decisions'],
			'taxonomy'                  => self::source_taxonomy_review_payload( $source ),
			'reviewed_at'               => gmdate( 'c' ),
			'reviewer'                  => $reviewer,
		);

		update_post_meta( $source_id, self::META_SOURCE_TAXONOMY_REVIEW_HASH, self::source_taxonomy_review_hash( $source ) );
		update_post_meta( $source_id, self::META_SOURCE_TAXONOMY_REVIEWED_AT, $evidence['reviewed_at'] );
		update_post_meta( $source_id, self::META_SOURCE_TAXONOMY_REVIEWER, $reviewer );
		update_post_meta( $source_id, self::META_SOURCE_TAXONOMY_REVIEW_NOTE, sanitize_textarea_field( $note ) );
		self::update_json_post_meta( $source_id, self::META_SOURCE_TAXONOMY_REVIEW_EVIDENCE, $evidence );

		return array(
			'success'         => true,
			'message'         => 'Source taxonomy review marked complete.',
			'source_id'       => $source_id,
			'source_taxonomy' => self::source_taxonomy_review_state( $source ),
		);
	}

	private static function source_taxonomy_review_term_decisions( WP_Post $source, $input_decisions ): array {
		$input_decisions = is_array( $input_decisions ) ? $input_decisions : array();
		$by_key          = array();
		$decisions       = array();

		foreach ( $input_decisions as $decision ) {
			if ( ! is_array( $decision ) ) {
				continue;
			}
			$taxonomy = sanitize_key( (string) ( $decision['taxonomy'] ?? '' ) );
			$term_id  = absint( $decision['source_term_id'] ?? 0 );
			$choice   = sanitize_key( (string) ( $decision['decision'] ?? '' ) );
			$rationale = trim( wp_strip_all_tags( (string) ( $decision['rationale'] ?? '' ) ) );
			if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) || ! $term_id || ! in_array( $choice, array( 'keep', 'replace', 'remove' ), true ) ) {
				continue;
			}
			if ( strlen( $rationale ) < 24 ) {
				return self::error( 'Each source taxonomy term decision needs a concrete rationale.', 'source_taxonomy_term_decision_rationale_required', array( 'taxonomy' => $taxonomy, 'source_term_id' => $term_id ) );
			}
			$replacement_id = absint( $decision['replacement_term_id'] ?? 0 );
			if ( 'replace' === $choice && ! self::source_taxonomy_replacement_is_valid( $replacement_id, $taxonomy, $term_id ) ) {
				return self::error( 'Replacement taxonomy decisions must point to an existing broader source term in the same taxonomy.', 'source_taxonomy_replacement_invalid', array( 'taxonomy' => $taxonomy, 'source_term_id' => $term_id, 'replacement_term_id' => $replacement_id ) );
			}
			$row = array(
				'taxonomy'            => $taxonomy,
				'source_term_id'      => $term_id,
				'decision'            => $choice,
				'replacement_term_id' => $replacement_id,
				'rationale'           => sanitize_textarea_field( $rationale ),
			);
			$by_key[ $taxonomy . ':' . $term_id ] = $row;
			$decisions[] = $row;
		}

		foreach ( self::source_taxonomy_review_payload( $source ) as $taxonomy => $payload ) {
			foreach ( $payload['singleton_assigned'] ?? array() as $term ) {
				$key = $taxonomy . ':' . absint( $term['id'] ?? 0 );
				if ( empty( $by_key[ $key ] ) ) {
					return self::error(
						'Assigned singleton category/tag terms require an explicit keep, replace, or remove decision before source taxonomy review can be marked complete.',
						'source_taxonomy_singleton_decision_required',
						array(
							'taxonomy'       => $taxonomy,
							'source_term_id' => absint( $term['id'] ?? 0 ),
							'term'           => $term,
							'taxonomy_review'=> self::source_taxonomy_review_payload( $source ),
						)
					);
				}
			}
		}

		return array(
			'success'   => true,
			'decisions' => $decisions,
		);
	}

	private static function source_taxonomy_replacement_is_valid( int $replacement_id, string $taxonomy, int $original_id ): bool {
		if ( ! $replacement_id || $replacement_id === $original_id ) {
			return false;
		}
		$replacement = get_term( $replacement_id, $taxonomy );
		if ( ! $replacement instanceof WP_Term || self::source_taxonomy_term_is_localized_variant( $replacement ) ) {
			return false;
		}

		return (int) $replacement->count > 1;
	}
}

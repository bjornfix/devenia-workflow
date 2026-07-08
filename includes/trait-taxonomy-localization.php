<?php
/**
 * Taxonomy localization for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Taxonomy_Localization {
	/**
	 * Sync translated post categories and tags through language-scoped term variants.
	 *
	 * @param mixed $taxonomy_input Optional per-taxonomy term overrides from the client.
	 * @return array<string,mixed>
	 */
	private static function sync_translated_post_terms( int $translation_id, WP_Post $source, string $language, $taxonomy_input ): array {
		if ( 'post' !== $source->post_type || ! self::is_translation_language( $language ) ) {
			return array( 'success' => true, 'synced' => array(), 'description_decisions' => array() );
		}

		$taxonomy_input = is_array( $taxonomy_input ) ? $taxonomy_input : array();
		$synced = array();
		$description_decisions = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$source_terms = wp_get_post_terms( (int) $source->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $source_terms ) ) {
				return self::error( $source_terms->get_error_message() );
			}

			$input_terms = self::taxonomy_input_by_source_term( $taxonomy_input[ $taxonomy ] ?? array() );
			$target_term_ids = array();
			foreach ( $source_terms as $source_term ) {
				if ( ! $source_term instanceof WP_Term ) {
					continue;
				}

				$term_data = $input_terms[ (int) $source_term->term_id ] ?? array();
				$existing_term_id = self::find_translated_term_id( (int) $source_term->term_id, $language, (string) $taxonomy );
				$term_issue = self::translated_taxonomy_term_guardrail( $source_term, $language, $term_data, $existing_term_id );
				if ( $term_issue ) {
					return $term_issue;
				}
				$description_decision = self::translated_taxonomy_description_decision( $source_term, $language, $term_data, $existing_term_id );
				if ( empty( $description_decision['success'] ) ) {
					return $description_decision;
				}
				$description_decisions[] = $description_decision['decision'];
				$term_id = self::ensure_translated_term( $source_term, $language, $term_data );
				if ( ! $term_id ) {
					return array(
						'success'        => false,
						'message'        => 'Could not create or update the translated taxonomy term. Resolve the term slug collision before saving; WordPress duplicate suffixes such as -2 are not allowed.',
						'code'           => 'localized_taxonomy_term_sync_failed',
						'language'       => sanitize_key( $language ),
						'taxonomy'       => (string) $source_term->taxonomy,
						'source_term_id' => (int) $source_term->term_id,
						'source_slug'    => sanitize_title( (string) $source_term->slug ),
						'expected_slug'  => sanitize_title( sanitize_key( $language ) . '-' . (string) $source_term->slug ),
					);
				}
				$target_term_ids[] = $term_id;
			}

			$result = wp_set_post_terms( $translation_id, $target_term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return self::error( $result->get_error_message() );
			}

			$synced[ $taxonomy ] = array_values( array_map( 'absint', $target_term_ids ) );
		}

		return array(
			'success'               => true,
			'synced'                => $synced,
			'description_decisions' => $description_decisions,
		);
	}

	/**
	 * Validate category/tag input before a translated post is created.
	 *
	 * @param mixed $taxonomy_input Optional per-taxonomy term overrides from the client.
	 * @return array<string,mixed>
	 */
	private static function validate_translated_post_terms_before_save( WP_Post $source, string $language, $taxonomy_input ): array {
		if ( 'post' !== $source->post_type || ! self::is_translation_language( $language ) ) {
			return array( 'success' => true, 'checked' => array(), 'description_decisions' => array(), 'category_assignment_review' => null );
		}

		$taxonomy_input = is_array( $taxonomy_input ) ? $taxonomy_input : array();
		$checked = array();
		$description_decisions = array();
		$category_assignment_review = self::validate_source_category_assignment_review( $source, $language, $taxonomy_input );
		if ( empty( $category_assignment_review['success'] ) ) {
			$category_assignment_review['stage'] = 'taxonomy_preflight';
			return $category_assignment_review;
		}

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$source_terms = wp_get_post_terms( (int) $source->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $source_terms ) ) {
				return self::error( $source_terms->get_error_message() );
			}

			$input_terms = self::taxonomy_input_by_source_term( $taxonomy_input[ $taxonomy ] ?? array() );
			$checked[ $taxonomy ] = array();
			foreach ( $source_terms as $source_term ) {
				if ( ! $source_term instanceof WP_Term ) {
					continue;
				}

				$term_data        = $input_terms[ (int) $source_term->term_id ] ?? array();
				$existing_term_id = self::find_translated_term_id( (int) $source_term->term_id, $language, (string) $taxonomy );
				$term_issue       = self::translated_taxonomy_term_guardrail( $source_term, $language, $term_data, $existing_term_id );
				if ( $term_issue ) {
					$term_issue['stage'] = 'taxonomy_preflight';
					return $term_issue;
				}
				$description_decision = self::translated_taxonomy_description_decision( $source_term, $language, $term_data, $existing_term_id );
				if ( empty( $description_decision['success'] ) ) {
					$description_decision['stage'] = 'taxonomy_preflight';
					return $description_decision;
				}
				$description_decisions[] = $description_decision['decision'];

				$checked[ $taxonomy ][] = array(
					'source_term_id'  => (int) $source_term->term_id,
					'existing_term_id' => $existing_term_id,
				);
			}
		}

		return array(
			'success'               => true,
			'checked'               => $checked,
			'description_decisions' => $description_decisions,
			'category_assignment_review' => $category_assignment_review['review'] ?? null,
		);
	}

	/**
	 * Require a conscious category-fit check before source categories are mirrored.
	 *
	 * @param array<string,mixed> $taxonomy_input Normalized raw taxonomy input.
	 * @return array<string,mixed>
	 */
	private static function validate_source_category_assignment_review( WP_Post $source, string $language, array $taxonomy_input ): array {
		$source_terms = wp_get_post_terms( (int) $source->ID, 'category', array( 'hide_empty' => false ) );
		if ( is_wp_error( $source_terms ) ) {
			return self::error( $source_terms->get_error_message() );
		}
		$source_categories = array_values(
			array_map(
				static function ( WP_Term $term ): array {
					return array(
						'id'          => (int) $term->term_id,
						'name'        => (string) $term->name,
						'slug'        => (string) $term->slug,
						'description' => trim( wp_strip_all_tags( (string) $term->description ) ),
					);
				},
				array_filter(
					is_array( $source_terms ) ? $source_terms : array(),
					static function ( $term ): bool {
						return $term instanceof WP_Term;
					}
				)
			)
		);
		if ( empty( $source_categories ) ) {
			return array( 'success' => true, 'review' => null );
		}

		$review = isset( $taxonomy_input['category_assignment_review'] ) && is_array( $taxonomy_input['category_assignment_review'] )
			? $taxonomy_input['category_assignment_review']
			: array();
		$note = isset( $review['note'] ) ? trim( wp_strip_all_tags( (string) $review['note'] ) ) : '';
		$fits = array_key_exists( 'source_categories_fit', $review ) ? rest_sanitize_boolean( $review['source_categories_fit'] ) : null;

		if ( true !== $fits ) {
			return array(
				'success'           => false,
				'message'           => 'Before translated post categories are mirrored, confirm that the source categories actually fit this article. If they do not fit, stop and report the source category assignment problem instead of copying it into another language.',
				'code'              => 'source_category_assignment_review_required',
				'language'          => sanitize_key( $language ),
				'source_id'         => (int) $source->ID,
				'source_categories' => $source_categories,
				'required_next_step'=> 'Set taxonomies.category_assignment_review.source_categories_fit=true with a concrete note, or report that the English source categories need correction.',
			);
		}

		if ( strlen( $note ) < 24 ) {
			return array(
				'success'           => false,
				'message'           => 'Category assignment confirmation needs a concrete note, not just a checkbox.',
				'code'              => 'source_category_assignment_note_required',
				'language'          => sanitize_key( $language ),
				'source_id'         => (int) $source->ID,
				'source_categories' => $source_categories,
				'required_next_step'=> 'Explain why the source categories fit this article before mirroring them to the translated post.',
			);
		}

		return array(
			'success' => true,
			'review'  => array(
				'source_categories_fit' => true,
				'note'                  => $note,
				'source_categories'     => $source_categories,
			),
		);
	}

	/**
	 * Normalize optional taxonomy input by source term ID.
	 *
	 * @param mixed $terms Raw taxonomy terms.
	 * @return array<int,array{name?:string,slug?:string,description?:string,description_not_useful_reason?:string}>
	 */
	private static function taxonomy_input_by_source_term( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$normalized[ absint( $term ) ] = array();
				continue;
			}
			if ( ! is_array( $term ) ) {
				continue;
			}
			$source_term_id = absint( $term['source_term_id'] ?? 0 );
			if ( ! $source_term_id ) {
				continue;
			}
			$row = array();
			if ( isset( $term['name'] ) ) {
				$row['name'] = sanitize_text_field( (string) $term['name'] );
			}
			if ( isset( $term['slug'] ) ) {
				$row['slug'] = sanitize_title( (string) $term['slug'] );
			}
			if ( isset( $term['description'] ) ) {
				$row['description'] = wp_kses_post( (string) $term['description'] );
			}
			if ( isset( $term['description_not_useful_reason'] ) ) {
				$row['description_not_useful_reason'] = sanitize_textarea_field( (string) $term['description_not_useful_reason'] );
			}
			$normalized[ $source_term_id ] = $row;
		}

		return $normalized;
	}

	/**
	 * Require an explicit reader-value decision for translated taxonomy archive descriptions.
	 *
	 * @param array{name?:string,slug?:string,description?:string,description_not_useful_reason?:string} $term_data Client-provided localized term data.
	 * @return array<string,mixed>
	 */
	private static function translated_taxonomy_description_decision( WP_Term $source_term, string $language, array $term_data, int $existing_term_id = 0 ): array {
		$provided_description = isset( $term_data['description'] ) ? trim( wp_strip_all_tags( (string) $term_data['description'] ) ) : '';
		$existing_description = '';
		if ( $existing_term_id ) {
			$existing_term = get_term( $existing_term_id, (string) $source_term->taxonomy );
			if ( $existing_term instanceof WP_Term ) {
				$existing_description = trim( wp_strip_all_tags( (string) $existing_term->description ) );
			}
		}

		if ( '' !== $provided_description || '' !== $existing_description ) {
			return array(
				'success'  => true,
				'decision' => array(
					'taxonomy'                     => (string) $source_term->taxonomy,
					'source_term_id'                => (int) $source_term->term_id,
					'language'                      => sanitize_key( $language ),
					'decision'                      => 'description_present',
					'provided_description_present'  => '' !== $provided_description,
					'existing_description_present'  => '' !== $existing_description,
				),
			);
		}

		$reason = isset( $term_data['description_not_useful_reason'] )
			? trim( wp_strip_all_tags( (string) $term_data['description_not_useful_reason'] ) )
			: '';
		if ( strlen( $reason ) >= 24 ) {
			return array(
				'success'  => true,
				'decision' => array(
					'taxonomy'                      => (string) $source_term->taxonomy,
					'source_term_id'                 => (int) $source_term->term_id,
					'language'                       => sanitize_key( $language ),
					'decision'                       => 'intentionally_undescribed',
					'description_not_useful_reason' => $reason,
				),
			);
		}

		return array(
			'success'        => false,
			'message'        => 'Translated category/tag archives need an explicit reader-value decision. Add a useful localized archive description, or provide description_not_useful_reason explaining why this term should intentionally have no archive description.',
			'code'           => 'taxonomy_description_decision_required',
			'language'       => sanitize_key( $language ),
			'taxonomy'       => (string) $source_term->taxonomy,
			'source_term_id' => (int) $source_term->term_id,
			'source_name'    => trim( wp_strip_all_tags( (string) $source_term->name ) ),
			'source_slug'    => sanitize_title( (string) $source_term->slug ),
			'reader_value'   => 'Archive descriptions help readers understand why this category or tag exists instead of making them infer it from a title and post list.',
			'required_next_step' => 'Provide taxonomies.category[].description or taxonomies.post_tag[].description when the archive has reader value; otherwise provide description_not_useful_reason with the concrete editorial reason.',
		);
	}

	/**
	 * Find or create one language-scoped category/tag term for a source term.
	 *
	 * @param array{name?:string,slug?:string,description?:string,description_not_useful_reason?:string} $term_data Client-provided localized term data.
	 */
	private static function ensure_translated_term( WP_Term $source_term, string $language, array $term_data ): int {
		$existing_id = self::find_translated_term_id( (int) $source_term->term_id, $language, (string) $source_term->taxonomy );
		if ( $existing_id ) {
			if ( empty( $term_data['slug'] ) ) {
				$term_data['slug'] = sanitize_title( $language . '-' . (string) $source_term->slug );
			}
			self::update_translated_term_details( $existing_id, $source_term, $term_data );
			return $existing_id;
		}

		$name = isset( $term_data['name'] ) && '' !== trim( (string) $term_data['name'] )
			? trim( (string) $term_data['name'] )
			: (string) $source_term->name;
		$slug = isset( $term_data['slug'] ) && '' !== trim( (string) $term_data['slug'] )
			? sanitize_title( (string) $term_data['slug'] )
			: sanitize_title( $language . '-' . (string) $source_term->slug );

		if ( self::language_requires_transliterated_urls( $language ) && ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			return 0;
		}

		$args = array( 'slug' => $slug );
		if ( isset( $term_data['description'] ) && '' !== trim( (string) $term_data['description'] ) ) {
			$args['description'] = (string) $term_data['description'];
		}
		if ( 'category' === $source_term->taxonomy && $source_term->parent ) {
			$translated_parent = self::find_translated_term_id( (int) $source_term->parent, $language, (string) $source_term->taxonomy );
			if ( $translated_parent ) {
				$args['parent'] = $translated_parent;
			}
		}

		$created = wp_insert_term( $name, (string) $source_term->taxonomy, $args );
		if ( is_wp_error( $created ) && 'term_exists' === $created->get_error_code() ) {
			$term_exists_data = $created->get_error_data();
			$term_id          = is_array( $term_exists_data )
				? absint( $term_exists_data['term_id'] ?? 0 )
				: absint( $term_exists_data );
			if ( $term_id === (int) $source_term->term_id || ! self::term_is_translation_of( $term_id, (int) $source_term->term_id, $language ) ) {
				return 0;
			}
		} elseif ( is_wp_error( $created ) ) {
			return 0;
		} else {
			$term_id = absint( $created['term_id'] ?? 0 );
		}

		if ( $term_id === (int) $source_term->term_id ) {
			return 0;
		}

		if ( $term_id ) {
			update_term_meta( $term_id, self::TERM_META_SOURCE_ID, (int) $source_term->term_id );
			update_term_meta( $term_id, self::TERM_META_LANGUAGE, sanitize_key( $language ) );
			self::update_translated_term_details( $term_id, $source_term, $term_data );
		}

		return $term_id;
	}

	/**
	 * Update mutable localized term fields for an existing translated term.
	 *
	 * @param array{name?:string,slug?:string,description?:string,description_not_useful_reason?:string} $term_data Client-provided localized term data.
	 */
	private static function update_translated_term_details( int $term_id, WP_Term $source_term, array $term_data ): void {
		$args = array();
		if ( isset( $term_data['name'] ) && '' !== trim( (string) $term_data['name'] ) ) {
			$args['name'] = trim( (string) $term_data['name'] );
		}
		if ( isset( $term_data['slug'] ) && '' !== trim( (string) $term_data['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $term_data['slug'] );
		}
		if ( isset( $term_data['description'] ) ) {
			$args['description'] = (string) $term_data['description'];
		}
		if ( ! empty( $args ) ) {
			wp_update_term( $term_id, (string) $source_term->taxonomy, $args );
		}
	}

	/**
	 * Block untranslated category/tag data before terms are created.
	 *
	 * @param array{name?:string,slug?:string,description?:string,description_not_useful_reason?:string} $term_data Client-provided localized term data.
	 */
	private static function translated_taxonomy_term_guardrail( WP_Term $source_term, string $language, array $term_data, int $existing_term_id = 0 ): array {
		$source_name = trim( wp_strip_all_tags( (string) $source_term->name ) );
		$source_slug = sanitize_title( (string) $source_term->slug );
		$name        = isset( $term_data['name'] ) ? trim( wp_strip_all_tags( (string) $term_data['name'] ) ) : '';
		$slug        = isset( $term_data['slug'] ) ? sanitize_title( (string) $term_data['slug'] ) : '';
		$language    = sanitize_key( $language );
		$expected_slug = ( '' !== $language && '' !== $source_slug ) ? sanitize_title( $language . '-' . $source_slug ) : '';

		if ( ! $existing_term_id && ( '' === $name || '' === $slug ) ) {
			return array(
				'success'        => false,
				'message'        => 'Localized taxonomy term name and slug are required before creating a new translated category or tag.',
				'code'           => 'localized_taxonomy_required',
				'language'       => $language,
				'taxonomy'       => (string) $source_term->taxonomy,
				'source_term_id' => (int) $source_term->term_id,
				'source_name'    => $source_name,
				'source_slug'    => $source_slug,
			);
		}

		if ( self::has_wordpress_duplicate_slug_suffix( $source_slug ) ) {
			$intended_slug = preg_replace( '/-(?:[2-9]|[1-9]\d|[1-9]\d{2})$/', '', $source_slug );
			$intended_slug = is_string( $intended_slug ) ? sanitize_title( $intended_slug ) : '';
			return array(
				'success'           => false,
				'message'           => 'Source taxonomy slug uses a WordPress duplicate suffix such as -2. Fix the source term collision before creating localized terms.',
				'code'              => 'source_taxonomy_slug_duplicate_suffix',
				'language'          => $language,
				'taxonomy'          => (string) $source_term->taxonomy,
				'source_term_id'    => (int) $source_term->term_id,
				'source_name'       => $source_name,
				'source_slug'       => $source_slug,
				'intended_slug'     => $intended_slug,
				'conflicting_terms' => self::taxonomy_slug_conflicts( $intended_slug, (string) $source_term->taxonomy, (int) $source_term->term_id ),
				'tried'             => array(
					'detected_source_duplicate_suffix',
					'derived_intended_base_slug',
					'looked_for_terms_blocking_base_slug',
				),
				'not_tried'         => array(
					'creating_language_prefixed_terms_from_duplicate_source_slug',
					'using_numeric_suffix_fallback',
				),
			);
		}

		if ( '' !== $slug && self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			$intended_slug = preg_replace( '/-(?:[2-9]|[1-9]\d|[1-9]\d{2})$/', '', $slug );
			$intended_slug = is_string( $intended_slug ) ? sanitize_title( $intended_slug ) : '';
			return array(
				'success'           => false,
				'message'           => 'Localized taxonomy slug uses a WordPress duplicate suffix such as -2. Resolve the term collision before saving; duplicate URLs are not allowed.',
				'code'              => 'localized_taxonomy_slug_duplicate_suffix',
				'language'          => $language,
				'taxonomy'          => (string) $source_term->taxonomy,
				'source_term_id'    => (int) $source_term->term_id,
				'source_slug'       => $source_slug,
				'localized_slug'    => $slug,
				'intended_slug'     => $intended_slug,
				'conflicting_terms' => self::taxonomy_slug_conflicts( $intended_slug, (string) $source_term->taxonomy, $existing_term_id ),
				'tried'             => array(
					'detected_localized_duplicate_suffix',
					'derived_intended_base_slug',
					'looked_for_terms_blocking_base_slug',
				),
				'not_tried'         => array(
					'accepting_or_publishing_duplicate_slug',
					'using_numeric_suffix_fallback',
				),
			);
		}

		if ( '' !== $slug && $slug !== $expected_slug ) {
			return array(
				'success'        => false,
				'message'        => 'Localized taxonomy slug must use the language-prefix standard: language code, hyphen, source slug.',
				'code'           => 'localized_taxonomy_slug_not_language_prefixed',
				'language'       => $language,
				'taxonomy'       => (string) $source_term->taxonomy,
				'source_term_id' => (int) $source_term->term_id,
				'source_slug'    => $source_slug,
				'localized_slug' => $slug,
				'expected_slug'  => $expected_slug,
			);
		}

		$slug_conflicts = self::taxonomy_slug_conflicts( $expected_slug, (string) $source_term->taxonomy, $existing_term_id );
		if ( $slug_conflicts ) {
			return array(
				'success'           => false,
				'message'           => 'Localized taxonomy slug is blocked by another term. Resolve or rename the collision before saving; WordPress duplicate suffixes such as -2 are not allowed.',
				'code'              => 'localized_taxonomy_slug_collision',
				'language'          => $language,
				'taxonomy'          => (string) $source_term->taxonomy,
				'source_term_id'    => (int) $source_term->term_id,
				'source_slug'       => $source_slug,
				'expected_slug'     => $expected_slug,
				'existing_term_id'  => $existing_term_id,
				'conflicting_terms' => $slug_conflicts,
				'tried'             => array(
					'checked_language_prefix_source_slug_standard',
					'looked_for_terms_blocking_expected_slug',
				),
				'not_tried'         => array(
					'using_numeric_suffix_fallback',
					'accepting_wordpress_duplicate_slug',
				),
			);
		}

		if ( '' !== $name && self::translated_taxonomy_name_copies_source( $name, $source_name ) ) {
			return array(
				'success'        => false,
				'message'        => 'Localized taxonomy name must be translated unless it is a short brand, acronym, or protected technical term.',
				'code'           => 'localized_taxonomy_name_copied_from_source',
				'language'       => $language,
				'taxonomy'       => (string) $source_term->taxonomy,
				'source_term_id' => (int) $source_term->term_id,
				'source_name'    => $source_name,
				'localized_name' => $name,
			);
		}

		return array();
	}

	private static function translated_taxonomy_name_copies_source( string $name, string $source_name ): bool {
		$name        = self::normalize_review_text( $name );
		$source_name = self::normalize_review_text( $source_name );
		if ( '' === $name || '' === $source_name || 0 !== strcasecmp( $name, $source_name ) ) {
			return false;
		}

		if ( preg_match( '/^[A-Z0-9][A-Z0-9 +.#_-]{1,12}$/', $source_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether an existing term is already the requested language variant.
	 */
	private static function term_is_translation_of( int $term_id, int $source_term_id, string $language ): bool {
		if ( ! $term_id || $term_id === $source_term_id ) {
			return false;
		}

		return $source_term_id === absint( get_term_meta( $term_id, self::TERM_META_SOURCE_ID, true ) )
			&& sanitize_key( $language ) === sanitize_key( (string) get_term_meta( $term_id, self::TERM_META_LANGUAGE, true ) );
	}

	/**
	 * Find taxonomy terms blocking a requested slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function taxonomy_slug_conflicts( string $slug, string $taxonomy, int $exclude_term_id = 0 ): array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof WP_Term || ( $exclude_term_id && (int) $term->term_id === $exclude_term_id ) ) {
			return array();
		}

		return array(
			array(
				'id'             => (int) $term->term_id,
				'name'           => (string) $term->name,
				'slug'           => (string) $term->slug,
				'taxonomy'       => (string) $term->taxonomy,
				'count'          => (int) $term->count,
				'source_term_id' => absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) ),
				'language'       => sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) ),
			),
		);
	}

	/**
	 * Remove translation metadata from source terms that were accidentally reused as translations.
	 */
	private static function repair_source_term_translation_meta(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrow upgrade repair for contaminated source term metadata.
						array(
							'key'     => self::TERM_META_LANGUAGE,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term_id ) {
				$term_id        = absint( $term_id );
				$source_term_id = absint( get_term_meta( $term_id, self::TERM_META_SOURCE_ID, true ) );
				if ( ! $term_id || ( $source_term_id && $source_term_id !== $term_id ) ) {
					continue;
				}

				delete_term_meta( $term_id, self::TERM_META_SOURCE_ID );
				delete_term_meta( $term_id, self::TERM_META_LANGUAGE );
			}
		}
	}

	/**
	 * Find one translated term by source term/language/taxonomy.
	 */
	private static function find_translated_term_id( int $source_term_id, string $language, string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrow operator workflow lookup for term translations.
					'relation' => 'AND',
					array(
						'key'   => self::TERM_META_SOURCE_ID,
						'value' => (string) $source_term_id,
					),
					array(
						'key'   => self::TERM_META_LANGUAGE,
						'value' => sanitize_key( $language ),
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return absint( $terms[0] );
	}
}

<?php
/**
 * Translation/source read models and payload shaping helpers.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Translation_Read_Models {
	/**
	 * Translation rows for a source page.
	 */
	private static function translation_rows_for_source( int $source_id, array $post_status = array() ): array {
		$rows = array();
		foreach ( self::translation_posts_for_source( $source_id, $post_status ) as $post ) {
			$rows[] = self::translation_payload( $post );
		}

		return $rows;
	}

	/**
	 * Translation post objects for a source page without expanding review payload.
	 *
	 * @return WP_Post[]
	 */
	private static function translation_posts_for_source( int $source_id, array $post_status = array() ): array {
		static $cache = array();

		$post_status = self::sanitize_translation_post_statuses( $post_status, false );
		$status_key  = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key   = $source_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$posts = array();
		$translation_ids = self::translation_index_ids_for_source( $source_id, $post_status );
		if ( ! empty( $translation_ids ) ) {
			foreach ( $translation_ids as $translation_id ) {
				$post = get_post( $translation_id );
				if ( $post instanceof WP_Post ) {
					$posts[] = $post;
				}
			}
			$cache[ $cache_key ] = $posts;
			return $cache[ $cache_key ];
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => $post_status,
				'posts_per_page' => 1000,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post && $source_id === absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) ) ) {
				$posts[] = $post;
			}
		}

		$cache[ $cache_key ] = $posts;

		return $cache[ $cache_key ];
	}

	/**
	 * Source hash for stale detection.
	 */
	private static function source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			self::normalize_gutenberg_content_for_storage( (string) $post->post_content )
		);
	}

	/**
	 * Legacy raw source hash used before saved-markup normalization became the hash boundary.
	 */
	private static function legacy_source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			(string) $post->post_content
		);
	}

	/**
	 * Hash source fields with a single, stable field separator contract.
	 */
	private static function source_hash_from_values( string $title, string $excerpt, string $content ): string {
		return hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content );
	}

	/**
	 * Basic post payload.
	 */
	private static function post_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => self::featured_image_id_for_post( $post ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
			'source_generation' => self::source_generation_status_for_source( (int) $post->ID ),
		);
	}

	/**
	 * Compact source payload for queue listings.
	 */
	private static function source_summary_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => self::featured_image_id_for_post( $post ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
		);
	}

	/**
	 * Translation payload.
	 */
	private static function translation_payload( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}

		$source_id               = absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
		$source                  = $source_id ? get_post( $source_id ) : null;
		$hash                    = (string) get_post_meta( $post->ID, self::META_SOURCE_HASH, true );
		$current                 = $source ? self::source_hash( $source ) : '';
		$language                = sanitize_key( (string) get_post_meta( $post->ID, self::META_LANGUAGE, true ) );
		$linguistic_review_state = self::linguistic_review_state_for_post( (int) $post->ID );
		$quality_review_state    = self::quality_review_readiness_for_post( $post, $language );
		$final_review_state      = self::final_review_readiness_for_post( $post, $language );
		$generated_source_id     = absint( get_post_meta( $post->ID, self::META_GENERATED_SOURCE_ID, true ) );
		$featured_image_id       = self::featured_image_id_for_post( $post );

		return array(
			'id'                 => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'source_id'          => $source_id,
			'language'           => $language,
			'title'              => get_the_title( $post ),
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'translation_status' => (string) get_post_meta( $post->ID, self::META_STATUS, true ),
			'url'                => get_permalink( $post ),
			'featured_image_id'  => $featured_image_id,
			'featured_image_alt' => self::localized_featured_image_alt_for_post( (int) $post->ID, $featured_image_id ),
			'localized_path'     => (string) get_post_meta( $post->ID, self::META_LOCALIZED_PATH, true ),
			'source_hash'        => $hash,
			'current_source_hash'=> $current,
			'is_stale'           => $hash && $current && $hash !== $current,
			'design_inheritance_state' => self::translation_source_design_state( $post, $source ),
			'route_integrity'    => self::translation_route_integrity( (int) $post->ID, $language ),
			'reviewed_at'        => (string) get_post_meta( $post->ID, self::META_REVIEWED_AT, true ),
			'writer_provenance'  => self::translation_writer_provenance( (int) $post->ID ),
			'visible_media_provenance' => self::translation_visible_media_provenance( (int) $post->ID ),
			'linguistic_reviewed_at' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWED_AT, true ),
			'linguistic_reviewer'    => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWER, true ),
			'linguistic_reviewer_process' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWER_PROCESS, true ),
			'linguistic_review_note' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEW_NOTE, true ),
			'linguistic_review_checks' => self::linguistic_review_checks_for_post( $post->ID ),
			'linguistic_review_evidence' => self::linguistic_review_evidence_for_post( $post->ID ),
			'linguistic_review_state' => $linguistic_review_state,
			'quality_reviewed_at' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWED_AT, true ),
			'quality_reviewer'    => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWER, true ),
			'quality_reviewer_process' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWER_PROCESS, true ),
			'quality_review_note' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEW_NOTE, true ),
			'quality_review_checks' => self::quality_review_checks_for_post( $post->ID ),
			'quality_review_evidence' => self::quality_review_evidence_for_post( $post->ID ),
			'quality_review_state' => $quality_review_state,
			'final_reviewed_at' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWED_AT, true ),
			'final_reviewer'    => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWER, true ),
			'final_reviewer_process' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWER_PROCESS, true ),
			'final_review_note' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEW_NOTE, true ),
			'final_review_checks' => self::final_review_checks_for_post( $post->ID ),
			'final_review_evidence' => self::final_review_evidence_for_post( $post->ID ),
			'final_review_state' => $final_review_state,
			'copy_feedback_open_count' => count( self::open_copy_feedback_for_post( (int) $post->ID ) ),
			'copy_feedback' => self::copy_feedback_for_post( (int) $post->ID ),
			'taxonomies'         => self::post_taxonomy_payload( $post ),
			'authored_original'  => array(
				'is_authored_original' => $generated_source_id > 0,
				'generated_source_id'  => $generated_source_id,
				'authored_language'    => sanitize_key( (string) get_post_meta( $post->ID, self::META_AUTHORED_LANGUAGE, true ) ),
				'source_generation_status' => $generated_source_id ? self::source_generation_status_for_source( $generated_source_id ) : array(),
			),
		);
	}

	/**
	 * Category and tag payload for posts.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function post_taxonomy_payload( WP_Post $post ): array {
		if ( 'post' !== $post->post_type ) {
			return array();
		}

		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $post->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = array();
				continue;
			}
			$out[ $taxonomy ] = array_map(
				static function ( WP_Term $term ): array {
					return array(
						'id'             => (int) $term->term_id,
						'name'           => (string) $term->name,
						'slug'           => (string) $term->slug,
						'taxonomy'       => (string) $term->taxonomy,
						'parent'         => (int) $term->parent,
						'source_term_id' => absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) ),
						'language'       => sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) ),
					);
				},
				is_array( $terms ) ? $terms : array()
			);
		}

		return $out;
	}

	/**
	 * Translation-aware source category/tag list for contributor guidance.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function list_translation_taxonomy_terms( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$taxonomy  = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$language  = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$limit     = isset( $input['limit'] ) ? min( 500, max( 1, absint( $input['limit'] ) ) ) : 200;
		$search    = sanitize_text_field( (string) ( $input['search'] ?? '' ) );

		if ( '' !== $taxonomy && ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return self::error( 'Invalid taxonomy.' );
		}
		if ( '' !== $language && ! self::is_translation_language( $language ) ) {
			return self::error( 'Invalid translation language.' );
		}

		$source = null;
		if ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source instanceof WP_Post || 'post' !== (string) $source->post_type ) {
				return self::error( 'Source post not found or does not support categories/tags.' );
			}
		}

		$taxonomies = '' !== $taxonomy ? array( $taxonomy ) : array( 'category', 'post_tag' );
		$languages  = '' !== $language ? array( $language ) : array_keys(
			array_filter(
				self::compact_language_registry(),
				static function ( array $config ): bool {
					return empty( $config['source'] );
				}
			)
		);

		$out = array();
		foreach ( $taxonomies as $tax ) {
			$terms = $source instanceof WP_Post
				? wp_get_post_terms( (int) $source->ID, $tax, array( 'hide_empty' => false ) )
				: self::source_taxonomy_terms_for_listing( $tax, $limit, ! empty( $input['hide_empty'] ), $search );

			if ( is_wp_error( $terms ) ) {
				return self::error( $terms->get_error_message() );
			}

			$out[ $tax ] = array_values(
				array_map(
					static function ( WP_Term $term ) use ( $languages ): array {
						return self::translation_source_taxonomy_term_payload( $term, $languages );
					},
					is_array( $terms ) ? $terms : array()
				)
			);
		}

		return array(
			'success'     => true,
			'source_id'   => $source_id,
			'language'    => $language,
			'taxonomies'  => $taxonomies,
			'languages'   => array_values( $languages ),
			'terms'       => $out,
			'instructions'=> 'Use terms.<taxonomy>[].source_term_id in ai-translations/upsert-page taxonomies.category[] or taxonomies.post_tag[]. For existing localized terms, reuse the listed name/slug/description when they fit. For missing localized terms, provide a useful localized name, the expected language-prefixed slug, and either a reader-useful description or description_not_useful_reason.',
		);
	}

	/**
	 * Source terms for translation taxonomy listing.
	 *
	 * @return WP_Term[]
	 */
	private static function source_taxonomy_terms_for_listing( string $taxonomy, int $limit, bool $hide_empty, string $search ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
			'number'     => $limit,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_values(
			array_filter(
				is_array( $terms ) ? $terms : array(),
				static function ( $term ): bool {
					if ( ! $term instanceof WP_Term ) {
						return false;
					}
					$language       = sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) );
					$source_term_id = absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) );
					return '' === $language && ( 0 === $source_term_id || $source_term_id === (int) $term->term_id );
				}
			)
		);
	}

	/**
	 * Source term plus localized variants for one term.
	 *
	 * @param string[] $languages Target languages to include.
	 * @return array<string,mixed>
	 */
	private static function translation_source_taxonomy_term_payload( WP_Term $term, array $languages ): array {
		$translations = array();
		foreach ( $languages as $language ) {
			$language = sanitize_key( (string) $language );
			if ( '' === $language || ! self::is_translation_language( $language ) ) {
				continue;
			}

			$translated_id = self::find_translated_term_id( (int) $term->term_id, $language, (string) $term->taxonomy );
			$translated    = $translated_id ? get_term( $translated_id, (string) $term->taxonomy ) : null;
			$expected_slug = sanitize_title( $language . '-' . (string) $term->slug );
			$item          = array(
				'language'      => $language,
				'exists'        => $translated instanceof WP_Term && ! is_wp_error( $translated ),
				'expected_slug' => $expected_slug,
				'slug_contract' => 'language code, hyphen, source slug',
			);

			if ( $translated instanceof WP_Term && ! is_wp_error( $translated ) ) {
				$url = get_term_link( $translated );
				$item = array_merge(
					$item,
					array(
						'id'             => (int) $translated->term_id,
						'name'           => (string) $translated->name,
						'slug'           => (string) $translated->slug,
						'description'    => (string) $translated->description,
						'description_present' => '' !== trim( (string) $translated->description ),
						'count'          => (int) $translated->count,
						'url'            => is_wp_error( $url ) ? '' : (string) $url,
						'source_term_id' => absint( get_term_meta( (int) $translated->term_id, self::TERM_META_SOURCE_ID, true ) ),
					)
				);
			}

			$translations[ $language ] = $item;
		}

		$source_url = get_term_link( $term );

		return array(
			'source_term_id' => (int) $term->term_id,
			'id'             => (int) $term->term_id,
			'name'           => (string) $term->name,
			'slug'           => (string) $term->slug,
			'description'    => (string) $term->description,
			'description_present' => '' !== trim( (string) $term->description ),
			'taxonomy'       => (string) $term->taxonomy,
			'parent'         => (int) $term->parent,
			'count'          => (int) $term->count,
			'url'            => is_wp_error( $source_url ) ? '' : (string) $source_url,
			'translations'   => $translations,
		);
	}

}
